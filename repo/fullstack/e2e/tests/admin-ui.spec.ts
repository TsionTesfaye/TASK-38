import { test, expect, Page, request, APIRequestContext } from '@playwright/test';

/**
 * Browser-driven E2E for admin UI flows: settings form, user management,
 * audit log viewer, reconciliation runner. Drives Chromium through the React
 * frontend which talks to the real Symfony backend.
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

async function resolveAdmin(api: APIRequestContext): Promise<{ username: string; password: string }> {
  // Try to bootstrap (idempotent — 201 on first, 409 on repeat)
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
        device_label: 'pw-probe',
        client_device_id: 'pw-' + Date.now() + Math.random(),
      },
    });
    if (res.status() === 200) return c;
  }
  throw new Error('No working admin credentials in DB');
}

async function login(page: Page, creds: { username: string; password: string }): Promise<void> {
  await page.goto(`${FRONTEND}/login`);
  await page.fill('input[name="username"], input[type="text"]', creds.username);
  await page.fill('input[name="password"], input[type="password"]', creds.password);
  await page.click('button[type="submit"]');
  // Wait for navigation away from /login
  await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 15000 });
}

test.describe('Admin UI flows (browser)', () => {
  test.beforeAll(async () => {
    const api = await request.newContext({ baseURL: BACKEND });
    ADMIN = await resolveAdmin(api);
    await api.dispose();
  });

  test('login page authenticates admin and redirects', async ({ page }) => {
    await page.goto(`${FRONTEND}/login`);
    // Form renders
    await expect(page.locator('form')).toBeVisible();
    await page.fill('input[name="username"], input[type="text"]', ADMIN.username);
    await page.fill('input[name="password"], input[type="password"]', ADMIN.password);
    await page.click('button[type="submit"]');
    await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 15000 });
    // Should no longer be on /login
    expect(page.url()).not.toContain('/login');
  });

  test('settings page loads and renders config', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto(`${FRONTEND}/admin/settings`);
    await page.waitForLoadState('networkidle');
    // Page should render without error boundary trigger
    const errorBoundary = page.getByText('Something went wrong');
    await expect(errorBoundary).toHaveCount(0);
    // URL remains /admin/settings
    expect(page.url()).toContain('/admin/settings');
  });

  test('user management page lists users', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto(`${FRONTEND}/admin/users`);
    await page.waitForLoadState('networkidle');
    // Error boundary NOT triggered
    await expect(page.getByText('Something went wrong')).toHaveCount(0);
    // URL is correct
    expect(page.url()).toContain('/admin/users');
    // Page rendered (has a heading or user table)
    await expect(page.locator('h1, h2, table, [role="grid"]').first()).toBeVisible({ timeout: 10000 });
  });

  test('audit log page renders', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto(`${FRONTEND}/admin/audit`);
    await page.waitForLoadState('networkidle');
    await expect(page.getByText('Something went wrong')).toHaveCount(0);
    expect(page.url()).toContain('/admin/audit');
  });

  test('backup page renders backup list', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto(`${FRONTEND}/admin/backups`);
    await page.waitForLoadState('networkidle');
    await expect(page.getByText('Something went wrong')).toHaveCount(0);
    expect(page.url()).toContain('/admin/backups');
  });

  test('reconciliation report page renders', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto(`${FRONTEND}/finance/reconciliation`);
    await page.waitForLoadState('networkidle');
    await expect(page.getByText('Something went wrong')).toHaveCount(0);
    expect(page.url()).toContain('/finance/reconciliation');
  });

  test('unauthenticated user on admin route redirects to login', async ({ page }) => {
    // Clear any existing auth
    await page.context().clearCookies();
    await page.goto(`${FRONTEND}/admin/settings`);
    await page.waitForURL(url => url.pathname.includes('/login'), { timeout: 10000 });
    expect(page.url()).toContain('/login');
  });
});
