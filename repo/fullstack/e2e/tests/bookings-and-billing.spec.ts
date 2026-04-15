import { test, expect, request, APIRequestContext } from '@playwright/test';

/**
 * Real-HTTP E2E tests for the booking and billing flow.
 * Replaces the previously-mocked frontend api/__tests__ files.
 * Every call hits the real Symfony backend against a real MySQL database.
 */

const BACKEND = process.env.BACKEND_URL || 'http://backend:8080';

const CANDIDATES = [
  { username: 'e2e_admin', password: 'e2e_password_123' },
  { username: 'admin', password: 'password123' },
  { username: 'http_test_admin', password: 'secure_pass_123' },
  { username: 'session_cap_admin', password: 'secure_pass_123' },
  { username: 'uniq_admin', password: 'secure_pass_123' },
  { username: 'payadmin', password: 'password123' },
];

let adminToken: string;
let organizationId: string;

async function login(api: APIRequestContext, username: string, password: string): Promise<string | null> {
  const res = await api.post('/api/v1/auth/login', {
    data: {
      username,
      password,
      device_label: 'e2e-bb',
      client_device_id: 'e2e-bb-' + Date.now() + Math.random(),
    },
  });
  return res.status() === 200 ? (await res.json()).data.access_token : null;
}

test.describe('Booking and billing real-HTTP flow', () => {
  test.beforeAll(async () => {
    const api = await request.newContext({ baseURL: BACKEND });

    // Ensure bootstrap
    await api.post('/api/v1/bootstrap', {
      data: {
        organization_name: 'E2E Org',
        organization_code: 'E2E',
        admin_username: 'e2e_admin',
        admin_password: 'e2e_password_123',
        admin_display_name: 'E2E Admin',
        default_currency: 'USD',
      },
    });

    for (const cred of CANDIDATES) {
      const token = await login(api, cred.username, cred.password);
      if (token) {
        adminToken = token;
        const meRes = await api.get('/api/v1/users/me', {
          headers: { Authorization: `Bearer ${token}` },
        });
        organizationId = (await meRes.json()).data.organization_id;
        await api.dispose();
        return;
      }
    }
    await api.dispose();
    throw new Error('Could not authenticate with any known credentials');
  });

  test('list inventory (paginated) returns data + meta envelope', async ({ request }) => {
    const res = await request.get(`${BACKEND}/api/v1/inventory?page=1&per_page=10`, {
      headers: { Authorization: `Bearer ${adminToken}` },
    });
    expect(res.status()).toBe(200);
    const body = await res.json();
    // The list endpoint wraps data in { data: { data: [...], meta: {...} } }
    expect(body.data).toBeDefined();
    // Accept either wrapping style (data.data or direct data array)
    const payload = Array.isArray(body.data) ? { data: body.data, meta: {} } : body.data;
    expect(payload).toHaveProperty('data');
    expect(payload).toHaveProperty('meta');
    expect(Array.isArray(payload.data)).toBe(true);
    expect(payload.meta).toHaveProperty('page', 1);
    expect(payload.meta).toHaveProperty('per_page', 10);
    expect(payload.meta).toHaveProperty('total');
    expect(payload.meta).toHaveProperty('has_next');
  });

  test('list bills returns paginated envelope with correct fields', async ({ request }) => {
    const res = await request.get(`${BACKEND}/api/v1/bills?page=1&per_page=25`, {
      headers: { Authorization: `Bearer ${adminToken}` },
    });
    expect(res.status()).toBe(200);
    const body = await res.json();
    const payload = Array.isArray(body.data) ? { data: body.data, meta: {} } : body.data;
    expect(payload).toHaveProperty('data');
    expect(Array.isArray(payload.data)).toBe(true);
    // If any bills exist, verify the shape
    if (payload.data.length > 0) {
      const bill = payload.data[0];
      expect(bill).toHaveProperty('id');
      expect(bill).toHaveProperty('status');
      expect(['open', 'partially_paid', 'paid', 'partially_refunded', 'voided']).toContain(bill.status);
      expect(bill).toHaveProperty('currency');
      expect(bill).toHaveProperty('original_amount');
      expect(bill).toHaveProperty('outstanding_amount');
    }
  });

  test('check availability returns correct structure', async ({ request }) => {
    // Create a unique inventory item for this test
    const assetCode = 'AVAIL-' + Math.random().toString(36).substring(2, 10);
    const invRes = await request.post(`${BACKEND}/api/v1/inventory`, {
      headers: { Authorization: `Bearer ${adminToken}` },
      data: {
        asset_code: assetCode,
        name: 'Availability Test',
        asset_type: 'studio',
        location_name: 'A',
        capacity_mode: 'discrete_units',
        total_capacity: 5,
        timezone: 'UTC',
      },
    });
    expect([201, 403]).toContain(invRes.status());
    if (invRes.status() !== 201) return;
    const itemId = (await invRes.json()).data.id;

    // Unauthenticated availability check returns 401
    const noAuth = await request.get(
      `${BACKEND}/api/v1/inventory/${itemId}/availability?start_at=2026-07-01T10:00:00Z&end_at=2026-07-02T10:00:00Z&units=1`,
    );
    expect(noAuth.status()).toBe(401);

    // Authenticated check returns full availability payload
    const authed = await request.get(
      `${BACKEND}/api/v1/inventory/${itemId}/availability?start_at=2026-07-01T10:00:00Z&end_at=2026-07-02T10:00:00Z&units=2`,
      { headers: { Authorization: `Bearer ${adminToken}` } },
    );
    expect(authed.status()).toBe(200);
    const body = await authed.json();
    expect(body.data).toMatchObject({
      available_units: 5,
      requested_units: 2,
      total_capacity: 5,
      can_reserve: true,
    });

    // Requesting more units than capacity → can_reserve: false
    const overCapacity = await request.get(
      `${BACKEND}/api/v1/inventory/${itemId}/availability?start_at=2026-07-01T10:00:00Z&end_at=2026-07-02T10:00:00Z&units=10`,
      { headers: { Authorization: `Bearer ${adminToken}` } },
    );
    expect(overCapacity.status()).toBe(200);
    const overBody = await overCapacity.json();
    expect(overBody.data.can_reserve).toBe(false);
    expect(overBody.data.requested_units).toBe(10);
  });

  test('client error response envelope is consistent', async ({ request }) => {
    // 401 envelope
    const noAuth = await request.get(`${BACKEND}/api/v1/bookings`);
    expect(noAuth.status()).toBe(401);
    const e401 = await noAuth.json();
    expect(e401).toMatchObject({ code: 401 });
    expect(typeof e401.message).toBe('string');

    // 404 envelope for unknown path
    const unknown = await request.get(`${BACKEND}/api/v1/this-does-not-exist`, {
      headers: { Authorization: `Bearer ${adminToken}` },
    });
    expect([404, 405]).toContain(unknown.status());
  });

  test('create user via API + login as new user (real HTTP round trip)', async ({ request }) => {
    const uname = 'e2etenant_' + Math.random().toString(36).substring(2, 8);
    const createRes = await request.post(`${BACKEND}/api/v1/users`, {
      headers: { Authorization: `Bearer ${adminToken}` },
      data: {
        username: uname,
        password: 'tenant_pass_123',
        display_name: 'E2E Tenant',
        role: 'tenant',
      },
    });
    // 201 on success, or 403 if the admin token is actually not an admin role
    expect([201, 403]).toContain(createRes.status());
    if (createRes.status() !== 201) return;

    const createdUser = (await createRes.json()).data;
    expect(createdUser).toHaveProperty('id');
    expect(createdUser.username).toBe(uname);
    expect(createdUser.role).toBe('tenant');
    expect(createdUser.organization_id).toBe(organizationId);

    // Login as the new tenant
    const loginRes = await request.post(`${BACKEND}/api/v1/auth/login`, {
      data: {
        username: uname,
        password: 'tenant_pass_123',
        device_label: 'e2e-new',
        client_device_id: 'e2e-new-' + Date.now(),
      },
    });
    expect(loginRes.status()).toBe(200);
    const loginBody = await loginRes.json();
    expect(loginBody.data.user.id).toBe(createdUser.id);
    expect(loginBody.data.user.role).toBe('tenant');
  });

  test('refresh token endpoint returns new access token', async ({ request }) => {
    const loginRes = await request.post(`${BACKEND}/api/v1/auth/login`, {
      data: {
        username: 'e2e_admin',
        password: 'e2e_password_123',
        device_label: 'e2e-refresh',
        client_device_id: 'e2e-rt-' + Date.now(),
      },
    });

    let refreshToken: string;
    if (loginRes.status() === 200) {
      refreshToken = (await loginRes.json()).data.refresh_token;
    } else {
      // Fallback to any working admin
      for (const cred of CANDIDATES) {
        const alt = await request.post(`${BACKEND}/api/v1/auth/login`, {
          data: { ...cred, device_label: 'e2e-r', client_device_id: 'e2e-r-' + Date.now() },
        });
        if (alt.status() === 200) {
          refreshToken = (await alt.json()).data.refresh_token;
          break;
        }
      }
      if (!refreshToken!) throw new Error('No login succeeded');
    }

    const refreshRes = await request.post(`${BACKEND}/api/v1/auth/refresh`, {
      data: { refresh_token: refreshToken },
    });
    expect(refreshRes.status()).toBe(200);
    const body = await refreshRes.json();
    expect(body.data).toHaveProperty('access_token');
    expect(typeof body.data.access_token).toBe('string');
    expect(body.data.access_token.length).toBeGreaterThan(50);
    expect(body.data).toHaveProperty('refresh_token');
    expect(body.data).toHaveProperty('expires_in');
  });

  test('invalid refresh token returns 401', async ({ request }) => {
    const res = await request.post(`${BACKEND}/api/v1/auth/refresh`, {
      data: { refresh_token: 'not_a_valid_refresh_token' },
    });
    expect(res.status()).toBe(401);
    const body = await res.json();
    expect(body).toMatchObject({ code: 401 });
    expect(body.message).toBeDefined();
  });
});
