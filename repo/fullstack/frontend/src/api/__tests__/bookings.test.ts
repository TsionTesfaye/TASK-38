import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../client', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
  },
}));

import client from '../client';
import {
  listBookings,
  getBooking,
  createHold,
  confirmHold,
  cancel,
  checkIn,
  complete,
  markNoShow,
} from '../bookings';
import type { Booking, BookingHold, PaginatedResponse } from '../../types';

const mockGet = client.get as ReturnType<typeof vi.fn>;
const mockPost = client.post as ReturnType<typeof vi.fn>;

const makeBooking = (overrides: Partial<Booking> = {}): Booking => ({
  id: 'booking-uuid-1234',
  organization_id: 'org-uuid-1234',
  inventory_item_id: 'item-uuid-1234',
  tenant_user_id: 'user-uuid-1234',
  source_hold_id: 'hold-uuid-1234',
  status: 'confirmed',
  start_at: '2026-05-01T09:00:00+00:00',
  end_at: '2026-05-01T12:00:00+00:00',
  booked_units: 1,
  currency: 'USD',
  base_amount: '300.00',
  final_amount: '300.00',
  cancellation_fee_amount: '0.00',
  no_show_penalty_amount: '0.00',
  checked_in_at: null,
  canceled_at: null,
  completed_at: null,
  no_show_marked_at: null,
  created_at: '2026-04-07T12:00:00+00:00',
  updated_at: '2026-04-07T12:00:00+00:00',
  ...overrides,
});

const makeHold = (): BookingHold => ({
  id: 'hold-uuid-1234',
  inventory_item_id: 'item-uuid-1234',
  tenant_user_id: 'user-uuid-1234',
  request_key: 'req-key-abc',
  held_units: 1,
  start_at: '2026-05-01T09:00:00+00:00',
  end_at: '2026-05-01T12:00:00+00:00',
  expires_at: '2026-04-07T12:10:00+00:00',
  status: 'active',
  created_at: '2026-04-07T12:00:00+00:00',
  confirmed_booking_id: null,
});

describe('bookings API adapter', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('listBookings', () => {
    it('returns PaginatedResponse<Booking> with items and total', async () => {
      const paginated: PaginatedResponse<Booking> = {
        data: [makeBooking()],
        page: 1,
        per_page: 20,
        total: 1,
        has_next: false,
      };
      mockGet.mockResolvedValueOnce({ data: paginated });

      const result = await listBookings({ page: 1, per_page: 20 });

      expect(result.data).toHaveLength(1);
      expect(result.total).toBe(1);
      expect(result.data[0].status).toBe('confirmed');
    });

    it('passes status filter to API', async () => {
      mockGet.mockResolvedValueOnce({ data: { data: [], page: 1, per_page: 20, total: 0, has_next: false } });

      await listBookings({ status: 'active' });

      expect(mockGet).toHaveBeenCalledWith('/bookings', { params: { status: 'active' } });
    });
  });

  describe('getBooking', () => {
    it('fetches single booking by ID', async () => {
      mockGet.mockResolvedValueOnce({ data: makeBooking() });

      const result = await getBooking('booking-uuid-1234');

      expect(mockGet).toHaveBeenCalledWith('/bookings/booking-uuid-1234');
      expect(result.id).toBe('booking-uuid-1234');
    });
  });

  describe('createHold', () => {
    it('POSTs hold data and returns BookingHold', async () => {
      const hold = makeHold();
      mockPost.mockResolvedValueOnce({ data: hold });

      const result = await createHold({
        inventory_item_id: 'item-uuid-1234',
        request_key: 'req-key-abc',
        held_units: 1,
        start_at: '2026-05-01T09:00:00+00:00',
        end_at: '2026-05-01T12:00:00+00:00',
      });

      expect(mockPost).toHaveBeenCalledWith('/holds', expect.objectContaining({
        inventory_item_id: 'item-uuid-1234',
        held_units: 1,
      }));
      expect(result.id).toBe('hold-uuid-1234');
      expect(result.expires_at).toBeDefined();
    });
  });

  describe('confirmHold', () => {
    it('POSTs to confirm endpoint and returns Booking', async () => {
      const booking = makeBooking();
      mockPost.mockResolvedValueOnce({ data: booking });

      const result = await confirmHold('hold-uuid-1234', { request_key: 'req-key-abc' });

      expect(mockPost).toHaveBeenCalledWith('/holds/hold-uuid-1234/confirm', { request_key: 'req-key-abc' });
      expect(result.id).toBe('booking-uuid-1234');
      expect(result.status).toBe('confirmed');
    });
  });

  describe('booking state transitions', () => {
    it('cancel POSTs to cancel endpoint', async () => {
      const canceled = makeBooking({ status: 'canceled', canceled_at: '2026-04-07T13:00:00+00:00' });
      mockPost.mockResolvedValueOnce({ data: canceled });

      const result = await cancel('booking-uuid-1234');

      expect(mockPost).toHaveBeenCalledWith('/bookings/booking-uuid-1234/cancel');
      expect(result.status).toBe('canceled');
    });

    it('checkIn POSTs to check-in endpoint', async () => {
      const active = makeBooking({ status: 'active', checked_in_at: '2026-05-01T09:05:00+00:00' });
      mockPost.mockResolvedValueOnce({ data: active });

      const result = await checkIn('booking-uuid-1234');

      expect(mockPost).toHaveBeenCalledWith('/bookings/booking-uuid-1234/check-in');
      expect(result.status).toBe('active');
      expect(result.checked_in_at).not.toBeNull();
    });

    it('complete POSTs to complete endpoint', async () => {
      const completed = makeBooking({ status: 'completed', completed_at: '2026-05-01T12:05:00+00:00' });
      mockPost.mockResolvedValueOnce({ data: completed });

      const result = await complete('booking-uuid-1234');

      expect(mockPost).toHaveBeenCalledWith('/bookings/booking-uuid-1234/complete');
      expect(result.status).toBe('completed');
    });

    it('markNoShow POSTs to no-show endpoint', async () => {
      const noShow = makeBooking({ status: 'no_show', no_show_marked_at: '2026-05-01T10:00:00+00:00' });
      mockPost.mockResolvedValueOnce({ data: noShow });

      const result = await markNoShow('booking-uuid-1234');

      expect(mockPost).toHaveBeenCalledWith('/bookings/booking-uuid-1234/no-show');
      expect(result.status).toBe('no_show');
    });
  });
});
