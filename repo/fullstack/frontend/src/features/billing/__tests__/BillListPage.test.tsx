import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { BillListPage } from '../BillListPage';
import type { Bill } from '../../../types';
import { BillStatus, BillType } from '../../../types/enums';

vi.mock('../../../api/billing', () => ({
  listBills: vi.fn(),
}));

import * as billingApi from '../../../api/billing';

const makeBill = (overrides: Partial<Bill> = {}): Bill => ({
  id: 'bill-abc12345-1111-2222-3333-444444444444',
  booking_id: 'b-1',
  tenant_user_id: 't-1',
  bill_type: BillType.INITIAL,
  status: BillStatus.OPEN,
  currency: 'USD',
  original_amount: '100.00',
  outstanding_amount: '100.00',
  due_at: null,
  issued_at: '2026-04-09T00:00:00Z',
  paid_at: null,
  voided_at: null,
  ...overrides,
} as any);

describe('BillListPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders empty state when no bills', async () => {
    (billingApi.listBills as any).mockResolvedValue({
      data: [],
      meta: { page: 1, per_page: 25, total: 0, has_next: false },
    });

    render(
      <MemoryRouter>
        <BillListPage />
      </MemoryRouter>,
    );

    await waitFor(() => {
      expect(screen.getByText('No bills found')).toBeInTheDocument();
    });
  });

  it('renders bill rows with amount + status', async () => {
    (billingApi.listBills as any).mockResolvedValue({
      data: [
        makeBill({ id: 'bill-a', status: BillStatus.OPEN, original_amount: '100.00' }),
        makeBill({ id: 'bill-b', status: BillStatus.PAID, original_amount: '200.00', outstanding_amount: '0.00' }),
      ],
      meta: { page: 1, per_page: 25, total: 2, has_next: false },
    });

    render(
      <MemoryRouter>
        <BillListPage />
      </MemoryRouter>,
    );

    await waitFor(() => {
      expect(screen.getByText('Bills')).toBeInTheDocument();
      expect(screen.getByText('Open')).toBeInTheDocument();
      expect(screen.getByText('Paid')).toBeInTheDocument();
    });
  });

  it('renders error on API failure', async () => {
    (billingApi.listBills as any).mockRejectedValue({
      response: { data: { message: 'DB down' } },
    });

    render(
      <MemoryRouter>
        <BillListPage />
      </MemoryRouter>,
    );

    await waitFor(() => {
      expect(screen.getByText('DB down')).toBeInTheDocument();
    });
  });
});
