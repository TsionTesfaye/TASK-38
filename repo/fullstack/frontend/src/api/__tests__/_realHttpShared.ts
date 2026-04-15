/**
 * Shared bootstrap helper for real-HTTP API test files.
 *
 * Uses a deterministic org + admin identity so multiple test files in the same
 * vitest run can coexist: the first file to reach `ensureAdminToken()` bootstraps
 * the org and mints the admin, every subsequent file just logs in with the same
 * credentials.
 *
 * The DB is assumed to be dropped + migrated once at the start of the test run
 * (handled by run_tests.sh or explicit docker-compose commands).
 */
import * as auth from '../auth';

export const SHARED = {
  orgName: 'RealHttpTestOrg',
  orgCode: 'RHTT',
  adminUsername: 'real_http_admin',
  adminPassword: 'password123',
  adminDisplayName: 'Real HTTP Admin',
  defaultCurrency: 'USD',
};

let cachedToken: {
  access_token: string;
  refresh_token: string;
  session_id: string;
  user_id: string;
  organization_id: string;
} | null = null;

/** Bootstrap (first caller only) then log in. Returns admin tokens + ids.
 *  Repeated callers return the cached tokens from the same process. */
export async function ensureAdminSession(): Promise<{
  access_token: string;
  refresh_token: string;
  session_id: string;
  user_id: string;
  organization_id: string;
}> {
  if (cachedToken) return cachedToken;

  try {
    await auth.bootstrap({
      organization_name: SHARED.orgName,
      organization_code: SHARED.orgCode,
      admin_username: SHARED.adminUsername,
      admin_password: SHARED.adminPassword,
      admin_display_name: SHARED.adminDisplayName,
      default_currency: SHARED.defaultCurrency,
    });
  } catch (e: any) {
    // 409 = already bootstrapped by a previous test file in this run — ok.
    const status = Number(e?.response?.status ?? 0);
    if (status !== 409) {
      throw new Error(
        `bootstrap failed: ${status} ${JSON.stringify(e?.response?.data)}`,
      );
    }
  }

  const login = await auth
    .login(
      SHARED.adminUsername,
      SHARED.adminPassword,
      'real-http-test',
      `rht-${Date.now().toString(36)}`,
    )
    .catch((e: any) => {
      throw new Error(
        `login failed: ${e?.response?.status} ${JSON.stringify(e?.response?.data)}`,
      );
    });

  cachedToken = {
    access_token: login.access_token,
    refresh_token: login.refresh_token,
    session_id: login.session_id,
    user_id: login.user.id,
    organization_id: login.user.organization_id,
  };
  return cachedToken;
}
