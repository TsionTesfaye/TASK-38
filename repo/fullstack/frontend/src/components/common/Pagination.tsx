import React from 'react';

interface PaginationProps {
  page: number;
  perPage: number;
  total: number;
  onPageChange: (page: number) => void;
}

export const Pagination: React.FC<PaginationProps> = ({
  page,
  perPage,
  total,
  onPageChange,
}) => {
  const totalPages = Math.max(1, Math.ceil(total / perPage));
  if (totalPages <= 1) return null;

  const pages: number[] = [];
  const startPage = Math.max(1, page - 2);
  const endPage = Math.min(totalPages, page + 2);
  for (let i = startPage; i <= endPage; i++) {
    pages.push(i);
  }

  const btnBase: React.CSSProperties = {
    padding: '0.375rem 0.75rem',
    border: '1px solid #d1d5db',
    background: '#fff',
    borderRadius: '0.375rem',
    cursor: 'pointer',
    fontSize: '0.875rem',
  };

  const btnActive: React.CSSProperties = {
    ...btnBase,
    background: '#3b82f6',
    color: '#fff',
    borderColor: '#3b82f6',
  };

  const btnDisabled: React.CSSProperties = {
    ...btnBase,
    cursor: 'not-allowed',
    opacity: 0.5,
  };

  return (
    <div
      style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        gap: '0.25rem',
        padding: '1rem 0',
        flexWrap: 'wrap',
      }}
    >
      <button
        style={page <= 1 ? btnDisabled : btnBase}
        disabled={page <= 1}
        onClick={() => onPageChange(page - 1)}
      >
        Prev
      </button>
      {startPage > 1 && (
        <>
          <button style={btnBase} onClick={() => onPageChange(1)}>
            1
          </button>
          {startPage > 2 && <span style={{ padding: '0 0.25rem' }}>...</span>}
        </>
      )}
      {pages.map((p) => (
        <button
          key={p}
          style={p === page ? btnActive : btnBase}
          onClick={() => onPageChange(p)}
        >
          {p}
        </button>
      ))}
      {endPage < totalPages && (
        <>
          {endPage < totalPages - 1 && (
            <span style={{ padding: '0 0.25rem' }}>...</span>
          )}
          <button style={btnBase} onClick={() => onPageChange(totalPages)}>
            {totalPages}
          </button>
        </>
      )}
      <button
        style={page >= totalPages ? btnDisabled : btnBase}
        disabled={page >= totalPages}
        onClick={() => onPageChange(page + 1)}
      >
        Next
      </button>
      <span style={{ marginLeft: '0.5rem', fontSize: '0.875rem', color: '#6b7280' }}>
        {total} total
      </span>
    </div>
  );
};
