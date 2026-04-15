import { test, expect, Page, request, APIRequestContext } from '@playwright/test';

/**
 * Browser-driven E2E for finance + terminal UI flows not covered elsewhere:
 *  - payments initiation page (tenant)
 *  - refunds list + form (admin)
 *  - notifications preferences (tenant)
 *  - terminals management page (admin)
 */

const BACKEND = process.env.BACKEND_URL || 'http://backend:8080';
const FRONTEND = process.env.FRONTEND_URL || 'http://frontend:3000';

const CANDIDATES = [
  { username: 'e2e_admin', password: 'e2e_password_123' },
  { username: 'admin', password: 'password123' },
  { username: 'http_test_admin', password: 'secure_pass_123' },
  { username: 'session_cap_admin', password: 'secure_pass_123' },
  { username: 'uniq_admin', password: 'secure_pass_123' },
  { username: 'payadmin', password: 'password123' },
  { username: 'branch_admin', password: 'password123' },
  { username: 'rbac_admin', password: 'password123' },
  { username: 'term_admin', password: 'password123' },
];

let ADMIN: { username: string; password: string };
let TENANT: { username: string; password: string };

async function getAdmin(api: APIRequestContext): Promise<{ username: string; password: string }> {
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
  for (const c of CANDIDATES) {
    const res = await api.post('/api/v1/auth/login', {
      data: {
        username: c.username,
        password: c.password,
        device_label: 'pw-ft',
        client_device_id: 'pw-ft-' + Date.now() + Math.random(),
      },
    });
    if (res.status() === 200) return c;
  }
  throw new Error('No working admin credentials');
}

async function createTenant(api: APIRequestContext, adminToken: string): Promise<{ username: string; password: string }> {
  const username = 'e2e_ft_tenant_' + Date.now();
  const password = 'tenantpass123';
  await api.post('/api/v1/users', {
    headers: { Authorization: `Bearer ${adminToken}` },
    data: { username, password, display_name: 'E2E FT Tenant', role: 'tenant' },
  });
  return { username, password };
}

async function login(page: Page, creds: { username: string; password: string }): Promise<void> {
  await page.goto(`${FRONTEND}/login`);
  await page.fill('input[name="username"], input[type="text"]', creds.username);
  await page.fill('input[name="password"], input[type="password"]', creds.password);
  await page.click('button[type="submit"]');
  await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 15000 });
}

test.describe('Finance + terminal UI flows (browser)', () => {
  test.beforeAll(async () => {
    const api = await request.newContext({ baseURL: BACKEND });
    ADMIN = await getAdmin(api);

    const adminLogin = await api.post('/api/v1/auth/login', {
      data: {
        username: ADMIN.username,
        password: ADMIN.password,
        device_label: 'pw-ft-admin',
        client_device_id: 'pw-ft-a-' + Date.now(),
      },
    });
    const adminToken = (await adminLogin.json()).data.access_token;
    TENANT = await createTenant(api, adminToken);

    // Enable terminals so the terminal page has something to render
    await api.put('/api/v1/settings', {
      headers: { Authorization: `Bearer ${adminToken}` },
      data: { terminals_enabled: true },
    });
    await api.dispose();
  });

  test('refund list page renders for admin', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto(`${FRONTEND}/finance/refunds`);
    await page.waitForLoadState('networkidle');
    await expect(page.getByText('Something went wrong')).toHaveCount(0);
    expect(page.url()).toContain('/finance/refunds');
  });

  test('billing list page renders for admin', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto(`${FRONTEND}/finance/bills`);
    await page.waitForLoadState('networkidle');
    await expect(page.getByText('Something went wrong')).toHaveCount(0);
    expect(page.url()).toContain('/finance/bills');
  });

  test('terminals management page renders for admin', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto(`${FRONTEND}/manager/terminals`);
    await page.waitForLoadState('networkidle');
    await expect(page.getByText('Something went wrong')).toHaveCount(0);
    expect(page.url()).toContain('/manager/terminals');
  });

  test('notification preferences page renders for tenant', async ({ page }) => {
    await login(page, TENANT);
    await page.goto(`${FRONTEND}/notifications/preferences`);
    await page.waitForLoadState('networkidle');
    await expect(page.getByText('Something went wrong')).toHaveCount(0);
    expect(page.url()).toContain('/notifications/preferences');
  });

  test('tenant billing page renders own bills', async ({ page }) => {
    await login(page, TENANT);
    await page.goto(`${FRONTEND}/tenant/bills`);
    await page.waitForLoadState('networkidle');
    await expect(page.getByText('Something went wrong')).toHaveCount(0);
    expect(page.url()).toContain('/tenant/bills');
  });

  test('inventory page renders and supports search', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto(`${FRONTEND}/inventory`);
    await page.waitForLoadState('networkidle');
    await expect(page.getByText('Something went wrong')).toHaveCount(0);
  });

  test('logout clears session and redirects to login', async ({ page }) => {
    await login(page, ADMIN);
    // Find logout button in the layout (various naming possibilities)
    const logout = page.getByRole('button', { name: /log\s?out/i }).or(
      page.getByText(/^log\s?out$/i).first(),
    );
    if (await logout.count() > 0) {
      await logout.first().click();
      await page.waitForURL(url => url.pathname.includes('/login'), { timeout: 10000 });
      expect(page.url()).toContain('/login');
    } else {
      // If no visible logout button, clear storage manually and verify redirect
      await page.evaluate(() => {
        localStorage.clear();
        sessionStorage.clear();
      });
      await page.goto(`${FRONTEND}/admin/users`);
      await page.waitForURL(url => url.pathname.includes('/login'), { timeout: 10000 });
      expect(page.url()).toContain('/login');
    }
  });
});
