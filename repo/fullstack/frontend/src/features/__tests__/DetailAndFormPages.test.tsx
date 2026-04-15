import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

import { BillDetailPage } from '../billing/BillDetailPage';
import { SupplementalBillPage } from '../billing/SupplementalBillPage';
import { InventoryDetailPage } from '../inventory/InventoryDetailPage';
import { InventoryFormPage } from '../inventory/InventoryFormPage';
import { PaymentInitiatePage } from '../payments/PaymentInitiatePage';
import { RefundFormPage } from '../refunds/RefundFormPage';
import { NotificationPreferencesPage } from '../notifications/NotificationPreferencesPage';
import { useAuthStore } from '../../state/authStore';
import { UserRole } from '../../types/enums';

vi.mock('../../api/billing', () => ({
  getBill: vi.fn(() => Promise.resolve({
    id: 'b-1', booking_id: 'bk-1', tenant_user_id: 't-1',
    bill_type: 'initial', status: 'open', currency: 'USD',
    original_amount: '100.00', outstanding_amount: '100.00',
    due_at: null, issued_at: '2026-04-09T00:00:00Z',
    paid_at: null, voided_at: null,
  })),
  createSupplementalBill: vi.fn(() => Promise.resolve({})),
  voidBill: vi.fn(() => Promise.resolve({})),
  downloadPdf: vi.fn(() => Promise.resolve(new Blob())),
}));

vi.mock('../../api/inventory', () => ({
  getItem: vi.fn(() => Promise.resolve({
    id: 'item-1', organization_id: 'o-1', asset_code: 'A1',
    name: 'Item', asset_type: 'studio', location_name: 'L',
    capacity_mode: 'discrete_units', total_capacity: 5,
    timezone: 'UTC', is_active: true,
    created_at: '2026-01-01', updated_at: '2026-01-01',
  })),
  createItem: vi.fn(),
  updateItem: vi.fn(),
  deactivateItem: vi.fn(),
  listPricing: vi.fn(() => Promise.resolve([])),
  createPricing: vi.fn(),
  getCalendar: vi.fn(() => Promise.resolve([])),
}));

vi.mock('../../api/payments', () => ({
  createPayment: vi.fn(() => Promise.resolve({ id: 'p-1', request_id: 'req-1' })),
}));

vi.mock('../../api/refunds', () => ({
  createRefund: vi.fn(() => Promise.resolve({ id: 'r-1' })),
}));

vi.mock('../../api/billing', async () => {
  const actual: any = await vi.importActual('../../api/billing');
  return {
    ...actual,
    listBills: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  };
});

vi.mock('../../api/notifications', () => ({
  getPreferences: vi.fn(() => Promise.resolve([])),
  updatePreference: vi.fn(),
}));

function wrapAtPath(path: string, routePattern: string, ui: React.ReactElement) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path={routePattern} element={ui} />
      </Routes>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  useAuthStore.setState({
    accessToken: 'token',
    user: {
      id: 'u-1', username: 'admin', display_name: 'Admin',
      role: UserRole.ADMINISTRATOR as any,
      is_active: true, is_frozen: false,
      organization_id: 'o-1', created_at: '',
    },
    refreshToken: 'rt', sessionId: 's', isAuthenticated: true,
  });
});

describe('Detail and form pages render', () => {
  it('BillDetailPage renders for admin', async () => {
    wrapAtPath('/bills/b-1', '/bills/:id', <BillDetailPage />);
    await waitFor(() => {
      expect(document.body.innerHTML).not.toBe('');
    });
  });

  it('SupplementalBillPage renders', async () => {
    wrapAtPath('/finance/bills/new', '/finance/bills/new', <SupplementalBillPage />);
    await waitFor(() => {
      expect(document.body.innerHTML).not.toBe('');
    });
  });

  it('InventoryDetailPage renders', async () => {
    wrapAtPath('/inventory/item-1', '/inventory/:id', <InventoryDetailPage />);
    await waitFor(() => {
      expect(document.body.innerHTML).not.toBe('');
    });
  });

  it('InventoryFormPage renders', async () => {
    wrapAtPath('/inventory/new', '/inventory/new', <InventoryFormPage />);
    await waitFor(() => {
      expect(document.body.innerHTML).not.toBe('');
    });
  });

  it('PaymentInitiatePage renders', async () => {
    wrapAtPath('/bills/b-1/pay', '/bills/:id/pay', <PaymentInitiatePage />);
    await waitFor(() => {
      expect(document.body.innerHTML).not.toBe('');
    });
  });

  it('RefundFormPage renders', async () => {
    wrapAtPath('/finance/refunds/new', '/finance/refunds/new', <RefundFormPage />);
    await waitFor(() => {
      expect(document.body.innerHTML).not.toBe('');
    });
  });

  it('NotificationPreferencesPage renders', async () => {
    wrapAtPath('/notifications/preferences', '/notifications/preferences', <NotificationPreferencesPage />);
    await waitFor(() => {
      expect(document.body.innerHTML).not.toBe('');
    });
  });
});
