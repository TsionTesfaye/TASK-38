import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import { StatusBadge } from '../StatusBadge';
import { ErrorMessage } from '../ErrorMessage';
import { LoadingSpinner } from '../LoadingSpinner';
import { EmptyState } from '../EmptyState';
import { PermissionDenied } from '../PermissionDenied';
import { ConfirmDialog } from '../ConfirmDialog';
import { Pagination } from '../Pagination';

describe('StatusBadge', () => {
  it('renders status with label', () => {
    render(<StatusBadge status="confirmed" />);
    expect(screen.getByText('Confirmed')).toBeInTheDocument();
  });

  it('converts snake_case to Title Case', () => {
    render(<StatusBadge status="partially_paid" />);
    expect(screen.getByText('Partially Paid')).toBeInTheDocument();
  });

  it('accepts size prop', () => {
    const { container } = render(<StatusBadge status="active" size="md" />);
    expect(container.firstChild).toBeTruthy();
  });

  it('uses fallback color for unknown status', () => {
    render(<StatusBadge status="unknown_status" />);
    expect(screen.getByText('Unknown Status')).toBeInTheDocument();
  });
});

describe('ErrorMessage', () => {
  it('returns null when no message', () => {
    const { container } = render(<ErrorMessage message={null} />);
    expect(container.firstChild).toBeNull();
  });

  it('renders sanitized message', () => {
    render(<ErrorMessage message="Booking abc12345-e29b-41d4-a716-446655440000 not found" />);
    expect(screen.getByText(/Booking \*\*\*\* not found/)).toBeInTheDocument();
  });

  it('calls onRetry when button clicked', () => {
    const onRetry = vi.fn();
    render(<ErrorMessage message="Failed" onRetry={onRetry} />);
    fireEvent.click(screen.getByText('Retry'));
    expect(onRetry).toHaveBeenCalled();
  });

  it('does not render retry button when no handler', () => {
    render(<ErrorMessage message="Failed" />);
    expect(screen.queryByText('Retry')).not.toBeInTheDocument();
  });
});

describe('LoadingSpinner', () => {
  it('renders', () => {
    const { container } = render(<LoadingSpinner />);
    expect(container.firstChild).toBeTruthy();
  });

  it('renders with message', () => {
    render(<LoadingSpinner message="Loading data..." />);
    expect(screen.getByText('Loading data...')).toBeInTheDocument();
  });

  it('accepts custom size', () => {
    const { container } = render(<LoadingSpinner size={64} />);
    expect(container.firstChild).toBeTruthy();
  });
});

describe('EmptyState', () => {
  it('renders default text', () => {
    render(<EmptyState />);
    expect(screen.getByText('No items found')).toBeInTheDocument();
  });

  it('renders custom title', () => {
    render(<EmptyState title="No items" />);
    expect(screen.getByText('No items')).toBeInTheDocument();
  });

  it('renders optional message', () => {
    render(<EmptyState title="Empty" message="Try adjusting filters" />);
    expect(screen.getByText('Try adjusting filters')).toBeInTheDocument();
  });

  it('renders action node', () => {
    render(<EmptyState action={<button>Create</button>} />);
    expect(screen.getByText('Create')).toBeInTheDocument();
  });
});

describe('PermissionDenied', () => {
  it('renders with permission denied message', () => {
    render(
      <MemoryRouter>
        <PermissionDenied />
      </MemoryRouter>,
    );
    expect(screen.getByText('Permission Denied')).toBeInTheDocument();
  });

  it('has home navigation button', () => {
    render(
      <MemoryRouter>
        <PermissionDenied />
      </MemoryRouter>,
    );
    expect(screen.getByText('Go to Home')).toBeInTheDocument();
  });
});

describe('ConfirmDialog', () => {
  it('returns null when closed', () => {
    const { container } = render(
      <ConfirmDialog
        open={false}
        title="T"
        message="M"
        onConfirm={() => {}}
        onCancel={() => {}}
      />,
    );
    expect(container.firstChild).toBeNull();
  });

  it('renders title and message when open', () => {
    render(
      <ConfirmDialog
        open={true}
        title="Are you sure?"
        message="This cannot be undone"
        onConfirm={() => {}}
        onCancel={() => {}}
      />,
    );
    expect(screen.getByText('Are you sure?')).toBeInTheDocument();
    expect(screen.getByText('This cannot be undone')).toBeInTheDocument();
  });

  it('fires onConfirm', () => {
    const onConfirm = vi.fn();
    render(
      <ConfirmDialog
        open={true}
        title="T"
        message="M"
        onConfirm={onConfirm}
        onCancel={() => {}}
      />,
    );
    fireEvent.click(screen.getByText('Confirm'));
    expect(onConfirm).toHaveBeenCalled();
  });

  it('fires onCancel', () => {
    const onCancel = vi.fn();
    render(
      <ConfirmDialog
        open={true}
        title="T"
        message="M"
        onConfirm={() => {}}
        onCancel={onCancel}
      />,
    );
    fireEvent.click(screen.getByText('Cancel'));
    expect(onCancel).toHaveBeenCalled();
  });

  it('fires onCancel on Escape key', () => {
    const onCancel = vi.fn();
    render(
      <ConfirmDialog
        open={true}
        title="T"
        message="M"
        onConfirm={() => {}}
        onCancel={onCancel}
      />,
    );
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(onCancel).toHaveBeenCalled();
  });

  it('supports danger variant', () => {
    render(
      <ConfirmDialog
        open={true}
        title="T"
        message="M"
        variant="danger"
        onConfirm={() => {}}
        onCancel={() => {}}
      />,
    );
    expect(screen.getByText('Confirm')).toBeInTheDocument();
  });
});

describe('Pagination', () => {
  it('renders page controls with total', () => {
    render(
      <Pagination page={1} perPage={10} total={50} onPageChange={() => {}} />,
    );
    expect(screen.getByText('50 total')).toBeInTheDocument();
  });

  it('returns null for single page', () => {
    const { container } = render(
      <Pagination page={1} perPage={10} total={5} onPageChange={() => {}} />,
    );
    expect(container.firstChild).toBeNull();
  });

  it('calls onPageChange when Next clicked', () => {
    const onPageChange = vi.fn();
    render(
      <Pagination page={1} perPage={10} total={50} onPageChange={onPageChange} />,
    );
    fireEvent.click(screen.getByText('Next'));
    expect(onPageChange).toHaveBeenCalledWith(2);
  });

  it('calls onPageChange when Prev clicked', () => {
    const onPageChange = vi.fn();
    render(
      <Pagination page={3} perPage={10} total={50} onPageChange={onPageChange} />,
    );
    fireEvent.click(screen.getByText('Prev'));
    expect(onPageChange).toHaveBeenCalledWith(2);
  });

  it('disables Prev on first page', () => {
    render(
      <Pagination page={1} perPage={10} total={50} onPageChange={() => {}} />,
    );
    expect(screen.getByText('Prev')).toBeDisabled();
  });

  it('disables Next on last page', () => {
    render(
      <Pagination page={5} perPage={10} total={50} onPageChange={() => {}} />,
    );
    expect(screen.getByText('Next')).toBeDisabled();
  });

  it('shows ellipsis on far pages', () => {
    render(
      <Pagination page={10} perPage={10} total={200} onPageChange={() => {}} />,
    );
    const ellipsis = screen.queryAllByText('...');
    expect(ellipsis.length).toBeGreaterThan(0);
  });

  it('clicks a specific page number', () => {
    const onPageChange = vi.fn();
    render(
      <Pagination page={3} perPage={10} total={100} onPageChange={onPageChange} />,
    );
    fireEvent.click(screen.getByText('5'));
    expect(onPageChange).toHaveBeenCalledWith(5);
  });
});
