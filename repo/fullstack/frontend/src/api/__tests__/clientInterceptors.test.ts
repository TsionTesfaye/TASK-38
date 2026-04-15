import { describe, it, expect, beforeEach } from 'vitest';
import client from '../client';

/**
 * Tests the axios client's interceptors:
 *   - request interceptor adds Bearer token from localStorage
 *   - response interceptor unwraps single-entity responses ({data: payload})
 *   - response interceptor preserves paginated responses ({data, meta})
 *   - response interceptor sanitizes UUIDs from error messages
 *
 * These tests run the interceptors against synthetic response objects —
 * they do NOT exercise the HTTP wire. The real API flow is covered by
 * the Playwright E2E suite.
 */
describe('API client interceptors', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it('request interceptor adds Bearer token when present', async () => {
    localStorage.setItem('access_token', 'test-token-123');

    const interceptor = (client.interceptors.request as any).handlers[0];
    const config = { headers: {} as any };
    const result = await interceptor.fulfilled(config);
    expect(result.headers.Authorization).toBe('Bearer test-token-123');
  });

  it('request interceptor does not add header when no token', async () => {
    const interceptor = (client.interceptors.request as any).handlers[0];
    const config = { headers: {} as any };
    const result = await interceptor.fulfilled(config);
    expect(result.headers.Authorization).toBeUndefined();
  });

  it('response interceptor unwraps {data: payload}', () => {
    const interceptor = (client.interceptors.response as any).handlers[0];
    const response = { data: { data: { id: 'u-1', name: 'User' } } };
    const result = interceptor.fulfilled(response);
    expect(result.data).toEqual({ id: 'u-1', name: 'User' });
  });

  it('response interceptor preserves paginated envelope', () => {
    const interceptor = (client.interceptors.response as any).handlers[0];
    const response = {
      data: {
        data: [{ id: '1' }, { id: '2' }],
        meta: { page: 1, per_page: 10, total: 2, has_next: false },
      },
    };
    const result = interceptor.fulfilled(response);
    expect(result.data.data).toHaveLength(2);
    expect(result.data.meta.total).toBe(2);
  });

  it('response interceptor preserves non-object responses', () => {
    const interceptor = (client.interceptors.response as any).handlers[0];
    const response = { data: 'plain string' };
    const result = interceptor.fulfilled(response);
    expect(result.data).toBe('plain string');
  });

  it('error interceptor sanitizes UUIDs in error messages', async () => {
    const interceptor = (client.interceptors.response as any).handlers[0];
    const err: any = {
      config: { _retry: true },
      response: {
        status: 404,
        data: { message: 'Booking abc12345-e29b-41d4-a716-446655440000 not found' },
      },
    };

    try {
      await interceptor.rejected(err);
      expect.fail('Should have rejected');
    } catch (rejected: any) {
      expect(rejected.response.data.message).not.toContain('abc12345-e29b-41d4-a716-446655440000');
      expect(rejected.response.data.message).toContain('****');
    }
  });

  it('error interceptor passes through non-401 errors unchanged', async () => {
    const interceptor = (client.interceptors.response as any).handlers[0];
    const err = {
      config: { _retry: false },
      response: { status: 500, data: { message: 'Server error' } },
    };

    try {
      await interceptor.rejected(err);
      expect.fail('Should have rejected');
    } catch (rejected: any) {
      expect(rejected.response.status).toBe(500);
    }
  });
});
