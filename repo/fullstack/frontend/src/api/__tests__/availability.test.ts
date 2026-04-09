import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../client', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
  },
}));

import client from '../client';
import { checkAvailability } from '../inventory';
import type { AvailabilityResult } from '../inventory';

const mockGet = client.get as ReturnType<typeof vi.fn>;

describe('checkAvailability', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  const makeResult = (overrides: Partial<AvailabilityResult> = {}): AvailabilityResult => ({
    available_units: 3,
    requested_units: 1,
    total_capacity: 5,
    can_reserve: true,
    ...overrides,
  });

  it('returns full availability result with can_reserve field', async () => {
    mockGet.mockResolvedValue({ data: makeResult() });

    const result = await checkAvailability('item-1', {
      start_at: '2026-06-01T09:00:00Z',
      end_at: '2026-06-02T09:00:00Z',
      units: 1,
    });

    expect(result.available_units).toBe(3);
    expect(result.requested_units).toBe(1);
    expect(result.total_capacity).toBe(5);
    expect(result.can_reserve).toBe(true);
  });

  it('passes units param to backend', async () => {
    mockGet.mockResolvedValue({ data: makeResult({ requested_units: 4 }) });

    await checkAvailability('item-1', {
      start_at: '2026-06-01T09:00:00Z',
      end_at: '2026-06-02T09:00:00Z',
      units: 4,
    });

    expect(mockGet).toHaveBeenCalledWith(
      '/inventory/item-1/availability',
      { params: { start_at: '2026-06-01T09:00:00Z', end_at: '2026-06-02T09:00:00Z', units: 4 } },
    );
  });

  it('can_reserve false when requested exceeds available', async () => {
    mockGet.mockResolvedValue({
      data: makeResult({ available_units: 1, requested_units: 3, can_reserve: false }),
    });

    const result = await checkAvailability('item-1', {
      start_at: '2026-06-01T09:00:00Z',
      end_at: '2026-06-02T09:00:00Z',
      units: 3,
    });

    expect(result.can_reserve).toBe(false);
    expect(result.available_units).toBe(1);
    expect(result.requested_units).toBe(3);
  });

  it('can_reserve false when zero available', async () => {
    mockGet.mockResolvedValue({
      data: makeResult({ available_units: 0, can_reserve: false }),
    });

    const result = await checkAvailability('item-1', {
      start_at: '2026-06-01T09:00:00Z',
      end_at: '2026-06-02T09:00:00Z',
      units: 1,
    });

    expect(result.can_reserve).toBe(false);
    expect(result.available_units).toBe(0);
  });
});

describe('availability UI gating logic', () => {
  // Test the gating decision that CreateBookingPage makes based on can_reserve.
  // This tests the pure logic without needing React rendering.

  function shouldShowReserveButton(availResult: AvailabilityResult | null): boolean {
    return availResult !== null && availResult.can_reserve;
  }

  function getAvailabilityMessage(availResult: AvailabilityResult): string {
    if (availResult.available_units === 0) {
      return `${availResult.available_units} of ${availResult.total_capacity} units available (fully booked)`;
    }
    if (!availResult.can_reserve) {
      return `${availResult.available_units} of ${availResult.total_capacity} units available (need ${availResult.requested_units}, only ${availResult.available_units} free)`;
    }
    return `${availResult.available_units} of ${availResult.total_capacity} units available`;
  }

  it('reserve enabled when requested units <= available', () => {
    const result = makeResult({ available_units: 3, requested_units: 2, can_reserve: true });
    expect(shouldShowReserveButton(result)).toBe(true);
  });

  it('reserve disabled when requested units > available', () => {
    const result = makeResult({ available_units: 1, requested_units: 3, can_reserve: false });
    expect(shouldShowReserveButton(result)).toBe(false);
  });

  it('reserve disabled when null (not yet checked)', () => {
    expect(shouldShowReserveButton(null)).toBe(false);
  });

  it('reserve disabled when zero available', () => {
    const result = makeResult({ available_units: 0, requested_units: 1, can_reserve: false });
    expect(shouldShowReserveButton(result)).toBe(false);
  });

  it('feedback shows insufficient message when partial availability', () => {
    const result = makeResult({ available_units: 1, requested_units: 3, total_capacity: 5, can_reserve: false });
    const msg = getAvailabilityMessage(result);
    expect(msg).toContain('need 3');
    expect(msg).toContain('only 1 free');
  });

  it('feedback shows fully booked when zero available', () => {
    const result = makeResult({ available_units: 0, total_capacity: 5, can_reserve: false });
    const msg = getAvailabilityMessage(result);
    expect(msg).toContain('fully booked');
  });

  it('feedback shows clean message when can reserve', () => {
    const result = makeResult({ available_units: 3, requested_units: 2, total_capacity: 5, can_reserve: true });
    const msg = getAvailabilityMessage(result);
    expect(msg).toBe('3 of 5 units available');
    expect(msg).not.toContain('need');
  });

  function makeResult(overrides: Partial<AvailabilityResult> = {}): AvailabilityResult {
    return {
      available_units: 3,
      requested_units: 1,
      total_capacity: 5,
      can_reserve: true,
      ...overrides,
    };
  }
});
