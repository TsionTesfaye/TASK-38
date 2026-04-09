import { renderHook, act } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { useHoldTimer } from '../useHoldTimer';

describe('useHoldTimer', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('returns correct countdown for a future expiry', () => {
    const expiresAt = new Date(Date.now() + 5 * 60 * 1000).toISOString(); // 5 min
    const { result } = renderHook(() => useHoldTimer(expiresAt));

    expect(result.current.isExpired).toBe(false);
    expect(result.current.remainingSeconds).toBeGreaterThanOrEqual(299);
    expect(result.current.remainingSeconds).toBeLessThanOrEqual(300);
    expect(result.current.formatted).toMatch(/^[45]:\d{2}$/);
  });

  it('decrements every second', () => {
    const expiresAt = new Date(Date.now() + 10_000).toISOString(); // 10s
    const { result } = renderHook(() => useHoldTimer(expiresAt));

    const initial = result.current.remainingSeconds;

    act(() => { vi.advanceTimersByTime(1000); });
    expect(result.current.remainingSeconds).toBe(initial - 1);

    act(() => { vi.advanceTimersByTime(1000); });
    expect(result.current.remainingSeconds).toBe(initial - 2);
  });

  it('formats as MM:SS correctly', () => {
    const expiresAt = new Date(Date.now() + 125_000).toISOString(); // 2m 5s
    const { result } = renderHook(() => useHoldTimer(expiresAt));

    expect(result.current.formatted).toBe('2:05');
  });

  it('marks expired when time runs out', () => {
    const expiresAt = new Date(Date.now() + 2000).toISOString(); // 2s
    const { result } = renderHook(() => useHoldTimer(expiresAt));

    expect(result.current.isExpired).toBe(false);

    act(() => { vi.advanceTimersByTime(3000); });
    expect(result.current.isExpired).toBe(true);
    expect(result.current.remainingSeconds).toBe(0);
    expect(result.current.formatted).toBe('0:00');
  });

  it('returns expired immediately for past date', () => {
    const expiresAt = new Date(Date.now() - 60_000).toISOString();
    const { result } = renderHook(() => useHoldTimer(expiresAt));

    expect(result.current.isExpired).toBe(true);
    expect(result.current.remainingSeconds).toBe(0);
  });

  it('returns expired for null/undefined input', () => {
    const { result: nullResult } = renderHook(() => useHoldTimer(null));
    expect(nullResult.current.isExpired).toBe(true);
    expect(nullResult.current.remainingSeconds).toBe(0);

    const { result: undefinedResult } = renderHook(() => useHoldTimer(undefined));
    expect(undefinedResult.current.isExpired).toBe(true);
    expect(undefinedResult.current.remainingSeconds).toBe(0);
  });

  it('clears interval after expiry (no memory leak)', () => {
    const clearSpy = vi.spyOn(global, 'clearInterval');
    const expiresAt = new Date(Date.now() + 1000).toISOString(); // 1s
    renderHook(() => useHoldTimer(expiresAt));

    act(() => { vi.advanceTimersByTime(2000); });

    // clearInterval should have been called (either on expiry or cleanup)
    expect(clearSpy).toHaveBeenCalled();
    clearSpy.mockRestore();
  });

  it('resets when expiresAt changes', () => {
    const first = new Date(Date.now() + 60_000).toISOString(); // 60s
    const second = new Date(Date.now() + 300_000).toISOString(); // 300s

    const { result, rerender } = renderHook(
      ({ exp }) => useHoldTimer(exp),
      { initialProps: { exp: first } },
    );

    expect(result.current.remainingSeconds).toBeLessThanOrEqual(60);

    rerender({ exp: second });
    expect(result.current.remainingSeconds).toBeGreaterThan(200);
  });
});
