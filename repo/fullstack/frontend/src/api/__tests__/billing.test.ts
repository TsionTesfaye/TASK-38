import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the axios client before importing billing API
vi.mock('../client', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
  },
}));

import client from '../client';
import { listBills, getBill, voidBill, createSupplementalBill } from '../billing';
import type { Bill, PaginatedResponse } from '../../types';

const mockGet = client.get as ReturnType<typeof vi.fn>;
const mockPost = client.post as ReturnType<typeof vi.fn>;

const makeBill = (overrides: Partial<Bill> = {}): Bill => ({
  id: 'bill-uuid-1234',
  booking_id: 'booking-uuid-1234',
  tenant_user_id: 'user-uuid-1234',
  bill_type: 'initial',
  status: 'open',
  currency: 'USD',
  original_amount: '1200.00',
  outstanding_amount: '1200.00',
  due_at: '2026-05-01T00:00:00+00:00',
  issued_at: '2026-04-07T12:00:00+00:00',
  paid_at: null,
  voided_at: null,
  pdf_path: null,
  ...overrides,
});

describe('billing API adapter', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('listBills', () => {
    it('returns PaginatedResponse<Bill> directly from client', async () => {
      const paginated: PaginatedResponse<Bill> = {
        data: [makeBill()],
        page: 1,
        per_page: 20,
        total: 1,
        has_next: false,
      };
      mockGet.mockResolvedValueOnce({ data: paginated });

      const result = await listBills({ page: 1, per_page: 20 });

      expect(mockGet).toHaveBeenCalledWith('/bills', { params: { page: 1, per_page: 20 } });
      expect(result.data).toHaveLength(1);
      expect(result.total).toBe(1);
      expect(result.data[0].id).toBe('bill-uuid-1234');
    });

    it('passes filter params to GET request', async () => {
      mockGet.mockResolvedValueOnce({ data: { data: [], page: 1, per_page: 20, total: 0, has_next: false } });

      await listBills({ status: 'open', bill_type: 'initial' });

      expect(mockGet).toHaveBeenCalledWith('/bills', {
        params: { status: 'open', bill_type: 'initial' },
      });
    });
  });

  describe('getBill', () => {
    it('returns a single Bill from client', async () => {
      const bill = makeBill({ status: 'paid', paid_at: '2026-04-08T10:00:00+00:00' });
      mockGet.mockResolvedValueOnce({ data: bill });

      const result = await getBill('bill-uuid-1234');

      expect(mockGet).toHaveBeenCalledWith('/bills/bill-uuid-1234');
      expect(result.id).toBe('bill-uuid-1234');
      expect(result.status).toBe('paid');
    });
  });

  describe('voidBill', () => {
    it('POSTs to void endpoint and returns updated bill', async () => {
      const voided = makeBill({ status: 'voided', voided_at: '2026-04-07T15:00:00+00:00' });
      mockPost.mockResolvedValueOnce({ data: voided });

      const result = await voidBill('bill-uuid-1234');

      expect(mockPost).toHaveBeenCalledWith('/bills/bill-uuid-1234/void');
      expect(result.status).toBe('voided');
      expect(result.voided_at).not.toBeNull();
    });
  });

  describe('createSupplementalBill', () => {
    it('POSTs supplemental bill data and returns new bill', async () => {
      const newBill = makeBill({ bill_type: 'supplemental', original_amount: '250.00' });
      mockPost.mockResolvedValueOnce({ data: newBill });

      const result = await createSupplementalBill({
        booking_id: 'booking-uuid-1234',
        amount: '250.00',
        reason: 'Damage fee',
      });

      expect(mockPost).toHaveBeenCalledWith('/bills', {
        booking_id: 'booking-uuid-1234',
        amount: '250.00',
        reason: 'Damage fee',
      });
      expect(result.bill_type).toBe('supplemental');
    });
  });
});
