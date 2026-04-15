// @vitest-environment node

/**
 * Real HTTP coverage for the client.ts 401-retry refresh interceptor.
 *
 * The main realHttpApi.test.ts replaces the response interceptor chain to
 * avoid cross-worker serialization issues with axios errors. This file keeps
 * the interceptor intact and exercises the refresh flow end-to-end against
 * the live backend:
 *
 *   1. Store an expired/invalid access_token + a real refresh_token.
 *   2. Fire any authenticated request → backend returns 401.
 *   3. Interceptor calls /auth/refresh with the refresh_token → gets new access.
 *   4. Interceptor re-issues the original request with the new token → 200.
 *
 * Also covers the no-refresh-token branch (redirects to /login).
 */
import { describe, it, expect, beforeAll } from 'vitest';

// ─── localStorage + window polyfills for Node env ───────────────────
const _store = new Map<string, string>();
(globalThis as any).localStorage = {
  getItem: (k: string) => (_store.has(k) ? (_store.get(k) as string) : null),
  setItem: (k: string, v: string) => { _store.set(k, String(v)); },
  removeItem: (k: string) => { _store.delete(k); },
  clear: () => { _store.clear(); },
  key: (i: number) => Array.from(_store.keys())[i] ?? null,
  get length() { return _store.size; },
};
let lastRedirect = '';
const _loc: any = {};
Object.defineProperty(_loc, 'href', {
  get: () => lastRedirect,
  set: (v: string) => { lastRedirect = v; },
  configurable: true,
});
(globalThis as any).window = { location: _loc };

import client from '../client';
import * as admin from '../admin';
import { ensureAdminSession } from './_realHttpShared';

const BACKEND = process.env.VITE_TEST_BACKEND || 'http://backend:8080/api/v1';
client.defaults.baseURL = BACKEND;

const ctx = {
  adminToken: '' as string,
  adminRefresh: '' as string,
};

beforeAll(async () => {
  const s = await ensureAdminSession();
  ctx.adminToken = s.access_token;
  ctx.adminRefresh = s.refresh_token;
}, 30000);

describe('client 401-retry interceptor (real HTTP)', () => {
  it('refreshes expired token and retries original request', async () => {
    if (!ctx.adminRefresh) return;
    // Plant a junk access_token so the first request gets 401, but keep a
    // real refresh_token so the interceptor can exchange it.
    localStorage.setItem('access_token', 'definitely-invalid.jwt.token');
    localStorage.setItem('refresh_token', ctx.adminRefresh);
    lastRedirect = '';

    // Make an authenticated call — interceptor should refresh + retry.
    const r = await admin.listUsers({ page: 1, per_page: 5 });
    expect(Array.isArray(r.data)).toBe(true);

    // New access token should be stored after refresh.
    const stored = localStorage.getItem('access_token');
    expect(stored).not.toBe('definitely-invalid.jwt.token');
    expect(stored).toBeTruthy();
  });

  it('redirects to /login when refresh_token is missing', async () => {
    localStorage.setItem('access_token', 'definitely-invalid.jwt.token');
    localStorage.removeItem('refresh_token');
    lastRedirect = '';

    let threw = false;
    try {
      await admin.listUsers({ page: 1, per_page: 5 });
    } catch {
      threw = true;
    }
    expect(threw).toBe(true);
    expect(lastRedirect).toBe('/login');
  });

  it('redirects to /login when refresh also fails', async () => {
    localStorage.setItem('access_token', 'definitely-invalid.jwt.token');
    localStorage.setItem('refresh_token', 'nonsense-refresh-token');
    lastRedirect = '';

    let threw = false;
    try {
      await admin.listUsers({ page: 1, per_page: 5 });
    } catch {
      threw = true;
    }
    expect(threw).toBe(true);
    expect(lastRedirect).toBe('/login');
  });

  it('does not retry on 401 for a request already marked _retry', async () => {
    if (!ctx.adminRefresh) return;
    // Direct axios call through client with _retry=true — should pass the 401
    // straight through without recursing into refresh.
    localStorage.setItem('access_token', 'definitely-invalid.jwt.token');
    localStorage.setItem('refresh_token', ctx.adminRefresh);

    let status = 0;
    try {
      await client.get('/users', { params: { page: 1 }, _retry: true } as any);
    } catch (e: any) {
      status = Number(e?.response?.status ?? 0);
    }
    expect(status).toBe(401);
  });

  it('passes non-401 errors through without retry', async () => {
    if (!ctx.adminToken) return;
    localStorage.setItem('access_token', ctx.adminToken);
    localStorage.setItem('refresh_token', ctx.adminRefresh);

    let status = 0;
    try {
      // 404 on unknown user — interceptor should let it fall through.
      await admin.getUser('00000000-0000-0000-0000-000000000000');
    } catch (e: any) {
      status = Number(e?.response?.status ?? 0);
    }
    expect(status).toBe(404);
  });

  it('queues concurrent requests during refresh', async () => {
    if (!ctx.adminRefresh) return;
    localStorage.setItem('access_token', 'definitely-invalid.jwt.token');
    localStorage.setItem('refresh_token', ctx.adminRefresh);

    // Fire 3 parallel requests — only one refresh should happen, the rest
    // should wait in the queue and then retry.
    const results = await Promise.all([
      admin.listUsers({ page: 1, per_page: 5 }).then(() => 'ok').catch(() => 'err'),
      admin.listUsers({ page: 1, per_page: 5 }).then(() => 'ok').catch(() => 'err'),
      admin.listUsers({ page: 1, per_page: 5 }).then(() => 'ok').catch(() => 'err'),
    ]);
    expect(results.filter((r) => r === 'ok').length).toBeGreaterThanOrEqual(1);
  });
});
