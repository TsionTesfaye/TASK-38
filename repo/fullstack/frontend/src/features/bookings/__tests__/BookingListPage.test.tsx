import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { BookingListPage } from '../BookingListPage';
import type { Booking } from '../../../types';
import { BookingStatus } from '../../../types/enums';

vi.mock('../../../api/bookings', () => ({
  listBookings: vi.fn(),
}));

import * as bookingsApi from '../../../api/bookings';

const makeBooking = (overrides: Partial<Booking> = {}): Booking => ({
  id: 'abc12345-1111-2222-3333-444444444444',
  organization_id: 'org-1',
  inventory_item_id: 'item-1',
  tenant_user_id: 't-1',
  source_hold_id: null,
  status: BookingStatus.CONFIRMED,
  start_at: '2026-04-10T10:00:00Z',
  end_at: '2026-04-11T10:00:00Z',
  booked_units: 1,
  currency: 'USD',
  base_amount: '100.00',
  final_amount: '100.00',
  cancellation_fee_amount: '0.00',
  no_show_penalty_amount: '0.00',
  checked_in_at: null,
  canceled_at: null,
  completed_at: null,
  no_show_marked_at: null,
  created_at: '2026-04-09T00:00:00Z',
  updated_at: '2026-04-09T00:00:00Z',
  ...overrides,
});

describe('BookingListPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders empty state when no bookings', async () => {
    (bookingsApi.listBookings as any).mockResolvedValue({
      data: [],
      meta: { page: 1, per_page: 25, total: 0, has_next: false },
    });

    render(
      <MemoryRouter>
        <BookingListPage />
      </MemoryRouter>,
    );

    await waitFor(() => {
      expect(screen.getByText('No bookings found')).toBeInTheDocument();
    });
  });

  it('renders booking rows', async () => {
    (bookingsApi.listBookings as any).mockResolvedValue({
      data: [
        makeBooking({ id: 'abc12345-1111-aaaa-bbbb-cccccccccccc', status: BookingStatus.CONFIRMED }),
        makeBooking({ id: 'def98765-2222-dddd-eeee-ffffffffffff', status: BookingStatus.COMPLETED }),
      ],
      meta: { page: 1, per_page: 25, total: 2, has_next: false },
    });

    render(
      <MemoryRouter>
        <BookingListPage />
      </MemoryRouter>,
    );

    await waitFor(() => {
      expect(screen.getByText('Bookings')).toBeInTheDocument();
      expect(screen.getByText('Confirmed')).toBeInTheDocument();
      expect(screen.getByText('Completed')).toBeInTheDocument();
    });
  });

  it('renders error message when API fails', async () => {
    (bookingsApi.listBookings as any).mockRejectedValue({
      response: { data: { message: 'Server error' } },
    });

    render(
      <MemoryRouter>
        <BookingListPage />
      </MemoryRouter>,
    );

    await waitFor(() => {
      expect(screen.getByText('Server error')).toBeInTheDocument();
    });
  });
});
