import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { BookingDetailPage } from '../BookingDetailPage';
import { useAuthStore } from '../../../state/authStore';
import { UserRole, BookingStatus } from '../../../types/enums';
import type { Booking } from '../../../types';

// Mock the bookings API
vi.mock('../../../api/bookings', () => ({
  getBooking: vi.fn(),
  cancel: vi.fn(),
  markNoShow: vi.fn(),
  checkIn: vi.fn(),
  complete: vi.fn(),
}));

import * as bookingsApi from '../../../api/bookings';

const makeBooking = (overrides: Partial<Booking> = {}): Booking => ({
  id: 'booking-123',
  organization_id: 'org-1',
  inventory_item_id: 'item-1',
  tenant_user_id: 'tenant-1',
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
  created_at: '2026-04-09T08:00:00Z',
  updated_at: '2026-04-09T08:00:00Z',
  ...overrides,
});

function renderWithRouter(booking: Booking, role: string = UserRole.PROPERTY_MANAGER) {
  // Set up auth store
  useAuthStore.setState({
    accessToken: 'test-token',
    user: { id: 'user-1', username: 'mgr', display_name: 'Manager', role: role as any, is_active: true, is_frozen: false, organization_id: 'org-1', created_at: '' },
    refreshToken: 'rt',
    sessionId: 's1',
    isAuthenticated: true,
  });

  (bookingsApi.getBooking as any).mockResolvedValue(booking);

  return render(
    <MemoryRouter initialEntries={['/bookings/booking-123']}>
      <Routes>
        <Route path="/bookings/:id" element={<BookingDetailPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('BookingDetailPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  // ═══════════════════════════════════════════════════════════════
  // 1. Cancellation rules display
  // ═══════════════════════════════════════════════════════════════

  it('shows cancel button for confirmed booking', async () => {
    renderWithRouter(makeBooking({ status: BookingStatus.CONFIRMED }));

    await waitFor(() => {
      expect(screen.getByText('Cancel Booking')).toBeInTheDocument();
    });
  });

  it('shows cancellation fee warning in confirm dialog', async () => {
    renderWithRouter(makeBooking({ status: BookingStatus.CONFIRMED }));

    await waitFor(() => {
      expect(screen.getByText('Cancel Booking')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByText('Cancel Booking'));

    await waitFor(() => {
      expect(screen.getByText(/cancellation fee will apply/i)).toBeInTheDocument();
    });
  });

  it('does not show cancel button for completed booking', async () => {
    renderWithRouter(makeBooking({ status: BookingStatus.COMPLETED }));

    await waitFor(() => {
      expect(screen.getByText('Booking Details')).toBeInTheDocument();
    });
    expect(screen.queryByText('Cancel Booking')).not.toBeInTheDocument();
  });

  it('displays cancellation fee amount when non-zero', async () => {
    renderWithRouter(makeBooking({
      status: BookingStatus.CANCELED,
      cancellation_fee_amount: '20.00',
    }));

    await waitFor(() => {
      expect(screen.getByText('Cancel Fee:')).toBeInTheDocument();
    });
  });

  // ═══════════════════════════════════════════════════════════════
  // 2. No-show penalty messaging
  // ═══════════════════════════════════════════════════════════════

  it('shows no-show button for active booking without check-in', async () => {
    renderWithRouter(makeBooking({
      status: BookingStatus.ACTIVE,
      checked_in_at: null,
    }));

    await waitFor(() => {
      expect(screen.getByText('Mark No-Show')).toBeInTheDocument();
    });
  });

  it('shows penalty warning in no-show confirm dialog', async () => {
    renderWithRouter(makeBooking({
      status: BookingStatus.ACTIVE,
      checked_in_at: null,
    }));

    await waitFor(() => {
      expect(screen.getByText('Mark No-Show')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByText('Mark No-Show'));

    await waitFor(() => {
      expect(screen.getByText(/penalty fee will be charged/i)).toBeInTheDocument();
    });
  });

  it('does not show no-show button when guest has checked in', async () => {
    renderWithRouter(makeBooking({
      status: BookingStatus.ACTIVE,
      checked_in_at: '2026-04-10T10:30:00Z',
    }));

    await waitFor(() => {
      expect(screen.getByText('Booking Details')).toBeInTheDocument();
    });
    expect(screen.queryByText('Mark No-Show')).not.toBeInTheDocument();
  });

  it('displays no-show penalty amount when non-zero', async () => {
    renderWithRouter(makeBooking({
      status: BookingStatus.NO_SHOW,
      no_show_penalty_amount: '75.00',
    }));

    await waitFor(() => {
      expect(screen.getByText('No-Show Penalty:')).toBeInTheDocument();
    });
  });

  // ═══════════════════════════════════════════════════════════════
  // 3. Role-based action visibility
  // ═══════════════════════════════════════════════════════════════

  it('hides check-in button from tenants', async () => {
    renderWithRouter(
      makeBooking({ status: BookingStatus.CONFIRMED }),
      UserRole.TENANT,
    );

    await waitFor(() => {
      expect(screen.getByText('Booking Details')).toBeInTheDocument();
    });
    expect(screen.queryByText('Check In')).not.toBeInTheDocument();
  });

  it('shows check-in button for property managers', async () => {
    renderWithRouter(
      makeBooking({ status: BookingStatus.CONFIRMED }),
      UserRole.PROPERTY_MANAGER,
    );

    await waitFor(() => {
      expect(screen.getByText('Check In')).toBeInTheDocument();
    });
  });

  it('hides no-show button from tenants', async () => {
    renderWithRouter(
      makeBooking({ status: BookingStatus.ACTIVE, checked_in_at: null }),
      UserRole.TENANT,
    );

    await waitFor(() => {
      expect(screen.getByText('Booking Details')).toBeInTheDocument();
    });
    expect(screen.queryByText('Mark No-Show')).not.toBeInTheDocument();
  });
});
