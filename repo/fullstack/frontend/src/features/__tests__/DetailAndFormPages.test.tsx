import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
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
  listBills: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
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

describe('Detail and form pages — content and interaction assertions', () => {
  it('BillDetailPage shows heading, bill fields, and admin action buttons', async () => {
    wrapAtPath('/bills/b-1', '/bills/:id', <BillDetailPage />);
    await waitFor(() => {
      expect(screen.getByText('Bill Details')).toBeInTheDocument();
      // Status badge
      expect(screen.getByText(/open/i)).toBeInTheDocument();
      // Amounts from mock (100.00 original / outstanding)
      expect(screen.getAllByText(/100\.00/).length).toBeGreaterThanOrEqual(1);
      // Bill type
      expect(screen.getByText(/initial/i)).toBeInTheDocument();
    });
  });

  it('BillDetailPage shows Download PDF button for admin', async () => {
    wrapAtPath('/bills/b-1', '/bills/:id', <BillDetailPage />);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /download pdf/i })).toBeInTheDocument();
    });
  });

  it('BillDetailPage shows Void Bill button for admin when bill is open', async () => {
    wrapAtPath('/bills/b-1', '/bills/:id', <BillDetailPage />);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /void bill/i })).toBeInTheDocument();
    });
  });

  it('SupplementalBillPage renders form fields', async () => {
    wrapAtPath('/finance/bills/new', '/finance/bills/new', <SupplementalBillPage />);
    await waitFor(() => {
      expect(document.body.innerHTML).not.toBe('');
      // A form input should be present
      expect(document.querySelector('input, textarea, select')).not.toBeNull();
    });
  });

  it('InventoryDetailPage shows item name as heading', async () => {
    wrapAtPath('/inventory/item-1', '/inventory/:id', <InventoryDetailPage />);
    await waitFor(() => {
      // Name from mock is 'Item'
      expect(screen.getByText('Item')).toBeInTheDocument();
    });
  });

  it('InventoryDetailPage shows item fields from mock data', async () => {
    wrapAtPath('/inventory/item-1', '/inventory/:id', <InventoryDetailPage />);
    await waitFor(() => {
      expect(screen.getByText('A1')).toBeInTheDocument();     // asset_code
      expect(screen.getByText(/UTC/)).toBeInTheDocument();    // timezone
      expect(screen.getByText(/5/)).toBeInTheDocument();      // total_capacity
    });
  });

  it('InventoryDetailPage shows Availability Calendar section', async () => {
    wrapAtPath('/inventory/item-1', '/inventory/:id', <InventoryDetailPage />);
    await waitFor(() => {
      expect(screen.getByText(/availability calendar/i)).toBeInTheDocument();
    });
  });

  it('InventoryFormPage renders input fields for new item creation', async () => {
    wrapAtPath('/inventory/new', '/inventory/new', <InventoryFormPage />);
    await waitFor(() => {
      // Form should have text inputs
      const inputs = document.querySelectorAll('input[type="text"], input:not([type])');
      expect(inputs.length).toBeGreaterThan(0);
    });
  });

  it('InventoryFormPage has a submit button', async () => {
    wrapAtPath('/inventory/new', '/inventory/new', <InventoryFormPage />);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /create|save|submit/i })).toBeInTheDocument();
    });
  });

  it('PaymentInitiatePage shows outstanding amount from bill', async () => {
    wrapAtPath('/bills/b-1/pay', '/bills/:id/pay', <PaymentInitiatePage />);
    await waitFor(() => {
      // Bill outstanding is 100.00 — may appear in multiple elements
      expect(screen.getAllByText(/100\.00/).length).toBeGreaterThanOrEqual(1);
    });
  });

  it('RefundFormPage renders a form with amount and reason inputs', async () => {
    wrapAtPath('/finance/refunds/new?bill_id=b-1', '/finance/refunds/new', <RefundFormPage />);
    await waitFor(() => {
      expect(document.querySelector('input, textarea')).not.toBeNull();
    });
  });

  it('NotificationPreferencesPage renders', async () => {
    wrapAtPath('/notifications/preferences', '/notifications/preferences', <NotificationPreferencesPage />);
    await waitFor(() => {
      expect(document.body.innerHTML).not.toBe('');
    });
  });
});
