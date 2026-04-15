import { describe, it, expect } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { usePagination } from '../usePagination';

describe('usePagination', () => {
  it('defaults to page 1 with default perPage', () => {
    const { result } = renderHook(() => usePagination());
    expect(result.current.page).toBe(1);
    expect(result.current.perPage).toBeGreaterThan(0);
  });

  it('accepts custom initial values', () => {
    const { result } = renderHook(() => usePagination(3, 50));
    expect(result.current.page).toBe(3);
    expect(result.current.perPage).toBe(50);
  });

  it('setPage clamps to >= 1', () => {
    const { result } = renderHook(() => usePagination());
    act(() => result.current.setPage(5));
    expect(result.current.page).toBe(5);
    act(() => result.current.setPage(-10));
    expect(result.current.page).toBe(1);
    act(() => result.current.setPage(0));
    expect(result.current.page).toBe(1);
  });

  it('setPerPage resets page to 1', () => {
    const { result } = renderHook(() => usePagination(5, 25));
    act(() => result.current.setPerPage(100));
    expect(result.current.perPage).toBe(100);
    expect(result.current.page).toBe(1);
  });

  it('reset restores initial values', () => {
    const { result } = renderHook(() => usePagination(2, 30));
    act(() => result.current.setPage(10));
    act(() => result.current.setPerPage(99));
    act(() => result.current.reset());
    expect(result.current.page).toBe(2);
    expect(result.current.perPage).toBe(30);
  });
});
