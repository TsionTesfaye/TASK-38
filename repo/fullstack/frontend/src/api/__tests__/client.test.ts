import { describe, it, expect } from 'vitest';

// Test the response interceptor logic in isolation.
// The interceptor: unwraps {data: payload} envelope for single-entity responses,
// leaving paginated responses untouched (they have a 'meta' key alongside 'data').

describe('axios response interceptor: envelope unwrapping', () => {
  // Replicate the exact interceptor condition from client.ts
  function applyInterceptor(responseData: unknown): unknown {
    if (
      responseData &&
      typeof responseData === 'object' &&
      'data' in (responseData as Record<string, unknown>) &&
      !('meta' in (responseData as Record<string, unknown>))
    ) {
      return (responseData as Record<string, unknown>).data;
    }
    return responseData;
  }

  it('unwraps single entity envelope {data: entity}', () => {
    const raw = { data: { id: 'abc', status: 'open' } };
    expect(applyInterceptor(raw)).toEqual({ id: 'abc', status: 'open' });
  });

  it('does not unwrap paginated envelope {data: [...], meta: {...}}', () => {
    const raw = {
      data: [{ id: '1' }],
      meta: { page: 1, per_page: 20, total: 1, has_next: false },
    };
    // Has 'meta', so interceptor leaves it intact
    expect(applyInterceptor(raw)).toEqual(raw);
  });

  it('paginated response is accessible as res.data (array) and res.meta.total', () => {
    const raw = {
      data: [{ id: '1' }],
      meta: { page: 1, per_page: 20, total: 1, has_next: false },
    };
    const result = applyInterceptor(raw) as { data: unknown[]; meta: { total: number } };
    expect(result.data).toEqual([{ id: '1' }]);
    expect(result.meta.total).toBe(1);
  });

  it('does not unwrap non-envelope objects (no data key)', () => {
    const raw = { access_token: 'tok', refresh_token: 'rtok' };
    expect(applyInterceptor(raw)).toEqual(raw);
  });

  it('does not unwrap null', () => {
    expect(applyInterceptor(null)).toBeNull();
  });

  it('does not unwrap strings', () => {
    expect(applyInterceptor('text')).toBe('text');
  });
});

describe('PaginatedResponse field access after interceptor', () => {
  it('list page should read .data for items and .meta.total for count', () => {
    // Simulates what a list page receives after API function returns
    const paginatedResponse = {
      data: [{ id: '1', status: 'open' }, { id: '2', status: 'paid' }],
      meta: {
        page: 1,
        per_page: 20,
        total: 2,
        has_next: false,
      },
    };

    const items = paginatedResponse.data;
    const total = paginatedResponse.meta.total;

    expect(items).toHaveLength(2);
    expect(total).toBe(2);
  });
});
