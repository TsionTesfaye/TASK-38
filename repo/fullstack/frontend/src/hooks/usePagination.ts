import { useState, useCallback } from 'react';
import { DEFAULT_PAGE_SIZE } from '@/utils/constants';

interface PaginationState {
  page: number;
  perPage: number;
  setPage: (page: number) => void;
  setPerPage: (perPage: number) => void;
  reset: () => void;
}

export function usePagination(
  initialPage = 1,
  initialPerPage = DEFAULT_PAGE_SIZE,
): PaginationState {
  const [page, setPageState] = useState(initialPage);
  const [perPage, setPerPageState] = useState(initialPerPage);

  const setPage = useCallback((p: number) => {
    setPageState(Math.max(1, p));
  }, []);

  const setPerPage = useCallback((pp: number) => {
    setPerPageState(pp);
    setPageState(1);
  }, []);

  const reset = useCallback(() => {
    setPageState(initialPage);
    setPerPageState(initialPerPage);
  }, [initialPage, initialPerPage]);

  return { page, perPage, setPage, setPerPage, reset };
}
