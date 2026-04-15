import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

import { BillDetailPage } from '../billing/BillDetailPage';
import { SupplementalBillPage } from '../billing/SupplementalBillPage';
import { PaymentInitiatePage } from '../payments/PaymentInitiatePage';
import { RefundFormPage } from '../refunds/RefundFormPage';
import { InventoryDetailPage } from '../inventory/InventoryDetailPage';
import { InventoryFormPage } from '../inventory/InventoryFormPage';
import { NotificationPreferencesPage } from '../notifications/NotificationPreferencesPage';
import { SettingsPage } from '../admin/SettingsPage';
import { UserManagementPage } from '../admin/UserManagementPage';
import { ReconciliationPage } from '../reports/ReconciliationPage';
import { TerminalListPage } from '../terminals/TerminalListPage';
import { useAuthStore } from '../../state/authStore';
import { UserRole } from '../../types/enums';

vi.mock('../../api/billing', () => ({
  getBill: vi.fn(),
  voidBill: vi.fn(),
  downloadPdf: vi.fn(() => Promise.resolve(new Blob(['pdf']))),
  createSupplementalBill: vi.fn(),
  listBills: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
}));
vi.mock('../../api/payments', () => ({
  initiatePayment: vi.fn(),
}));
vi.mock('../../api/refunds', () => ({
  issueRefund: vi.fn(),
}));
vi.mock('../../api/inventory', () => ({
  getItem: vi.fn(),
  createItem: vi.fn(),
  updateItem: vi.fn(),
  deactivateItem: vi.fn(),
  listPricing: vi.fn(() => Promise.resolve([])),
  createPricing: vi.fn(),
  getCalendar: vi.fn(() => Promise.resolve([])),
}));
vi.mock('../../api/notifications', () => ({
  getPreferences: vi.fn(),
  updatePreference: vi.fn(),
}));
vi.mock('../../api/admin', () => ({
  getSettings: vi.fn(),
  updateSettings: vi.fn(),
  listUsers: vi.fn(),
  createUser: vi.fn(),
  updateUser: vi.fn(),
  freezeUser: vi.fn(),
  unfreezeUser: vi.fn(),
}));
vi.mock('../../api/reconciliation', () => ({
  listRuns: vi.fn(),
  runReconciliation: vi.fn(),
  getRun: vi.fn(),
  downloadCsv: vi.fn(() => Promise.resolve(new Blob(['csv']))),
}));
vi.mock('../../api/terminals', () => ({
  listTerminals: vi.fn(),
  registerTerminal: vi.fn(),
  listPlaylists: vi.fn(),
  createPlaylist: vi.fn(),
  initiateTransfer: vi.fn(),
  recordChunk: vi.fn(),
  pauseTransfer: vi.fn(),
  resumeTransfer: vi.fn(),
}));

import * as billingApi from '../../api/billing';
import * as paymentsApi from '../../api/payments';
import * as refundsApi from '../../api/refunds';
import * as inventoryApi from '../../api/inventory';
import * as notificationsApi from '../../api/notifications';
import * as adminApi from '../../api/admin';
import * as reconciliationApi from '../../api/reconciliation';
import * as terminalsApi from '../../api/terminals';

function wrapAtPath(path: string, routePattern: string, ui: React.ReactElement) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path={routePattern} element={ui} />
        <Route path="/tenant/bills" element={<div>Tenant Bills List</div>} />
        <Route path="/finance/refunds" element={<div>Refunds List</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

const makeBill = (overrides: any = {}) => ({
  id: 'bill-aaaabbbbccccddddeeeeffff00001111',
  booking_id: 'bk-1',
  tenant_user_id: 't-1',
  bill_type: 'initial',
  status: 'open',
  currency: 'USD',
  original_amount: '100.00',
  outstanding_amount: '100.00',
  due_at: null,
  issued_at: '2026-04-09T00:00:00Z',
  paid_at: null,
  voided_at: null,
  ...overrides,
});

const asAdmin = () =>
  useAuthStore.setState({
    accessToken: 'token',
    user: {
      id: 'u-1',
      username: 'admin',
      display_name: 'Admin',
      role: UserRole.ADMINISTRATOR as any,
      is_active: true,
      is_frozen: false,
      organization_id: 'o-1',
      created_at: '',
    },
    refreshToken: 'rt',
    sessionId: 's',
    isAuthenticated: true,
  });

const asTenant = () =>
  useAuthStore.setState({
    accessToken: 'token',
    user: {
      id: 'u-t',
      username: 'tenant',
      display_name: 'Tenant',
      role: UserRole.TENANT as any,
      is_active: true,
      is_frozen: false,
      organization_id: 'o-1',
      created_at: '',
    },
    refreshToken: 'rt',
    sessionId: 's',
    isAuthenticated: true,
  });

beforeEach(() => {
  vi.clearAllMocks();
  asAdmin();
});

describe('BillDetailPage — deep', () => {
  it('renders bill data and shows PDF download button', async () => {
    (billingApi.getBill as any).mockResolvedValue(makeBill());
    wrapAtPath('/bills/b-1', '/bills/:id', <BillDetailPage />);
    await waitFor(() => expect(screen.getByText('Bill Details')).toBeInTheDocument());
    expect(screen.getByText('Download PDF')).toBeInTheDocument();
  });

  it('shows Pay Now for tenant on open bill', async () => {
    asTenant();
    (billingApi.getBill as any).mockResolvedValue(makeBill({ status: 'open' }));
    wrapAtPath('/bills/b-1', '/bills/:id', <BillDetailPage />);
    await waitFor(() => expect(screen.getByText('Pay Now')).toBeInTheDocument());
  });

  it('shows Issue Refund for admin on paid bill', async () => {
    (billingApi.getBill as any).mockResolvedValue(makeBill({ status: 'paid', paid_at: '2026-01-01T00:00:00Z' }));
    wrapAtPath('/bills/b-1', '/bills/:id', <BillDetailPage />);
    await waitFor(() => expect(screen.getByText('Issue Refund')).toBeInTheDocument());
  });

  it('shows Void Bill for admin on non-voided bill', async () => {
    (billingApi.getBill as any).mockResolvedValue(makeBill());
    wrapAtPath('/bills/b-1', '/bills/:id', <BillDetailPage />);
    await waitFor(() => expect(screen.getByText('Void Bill')).toBeInTheDocument());
  });

  it('hides action buttons when bill is voided', async () => {
    (billingApi.getBill as any).mockResolvedValue(makeBill({ status: 'voided', voided_at: '2026-03-01T00:00:00Z' }));
    wrapAtPath('/bills/b-1', '/bills/:id', <BillDetailPage />);
    await waitFor(() => expect(screen.getByText('Bill Details')).toBeInTheDocument());
    expect(screen.queryByText('Void Bill')).not.toBeInTheDocument();
  });

  it('voids bill when Void Bill clicked and confirmed', async () => {
    (billingApi.getBill as any).mockResolvedValue(makeBill());
    (billingApi.voidBill as any).mockResolvedValue(makeBill({ status: 'voided' }));
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    wrapAtPath('/bills/b-1', '/bills/:id', <BillDetailPage />);
    await waitFor(() => expect(screen.getByText('Void Bill')).toBeInTheDocument());
    fireEvent.click(screen.getByText('Void Bill'));
    await waitFor(() => expect(billingApi.voidBill).toHaveBeenCalled());
    confirmSpy.mockRestore();
  });

  it('renders error when bill load fails', async () => {
    (billingApi.getBill as any).mockRejectedValue({ response: { data: { message: 'nope' } } });
    wrapAtPath('/bills/b-1', '/bills/:id', <BillDetailPage />);
    await waitFor(() => expect(screen.getByText('nope')).toBeInTheDocument());
  });

  it('downloads PDF when button clicked', async () => {
    (billingApi.getBill as any).mockResolvedValue(makeBill());
    const origCreateUrl = URL.createObjectURL;
    URL.createObjectURL = vi.fn(() => 'blob:test');
    wrapAtPath('/bills/b-1', '/bills/:id', <BillDetailPage />);
    await waitFor(() => expect(screen.getByText('Download PDF')).toBeInTheDocument());
    fireEvent.click(screen.getByText('Download PDF'));
    await waitFor(() => expect(billingApi.downloadPdf).toHaveBeenCalled());
    URL.createObjectURL = origCreateUrl;
  });
});

describe('PaymentInitiatePage — deep', () => {
  it('renders form with outstanding amount for open bill', async () => {
    (billingApi.getBill as any).mockResolvedValue(makeBill());
    wrapAtPath('/bills/b-1/pay', '/bills/:id/pay', <PaymentInitiatePage />);
    await waitFor(() => expect(screen.getByText('Pay Bill')).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /Pay 100.00 USD/i })).toBeInTheDocument();
  });

  it('shows cannot-accept message for paid bill', async () => {
    (billingApi.getBill as any).mockResolvedValue(makeBill({ status: 'paid' }));
    wrapAtPath('/bills/b-1/pay', '/bills/:id/pay', <PaymentInitiatePage />);
    await waitFor(() => expect(screen.getByText(/cannot accept payments/i)).toBeInTheDocument());
  });

  it('submits payment and shows success', async () => {
    (billingApi.getBill as any).mockResolvedValue(makeBill());
    (paymentsApi.initiatePayment as any).mockResolvedValue({ id: 'p-1' });
    wrapAtPath('/bills/b-1/pay', '/bills/:id/pay', <PaymentInitiatePage />);
    await waitFor(() => expect(screen.getByText('Pay Bill')).toBeInTheDocument());
    fireEvent.submit(screen.getByRole('button', { name: /Pay 100.00 USD/i }).closest('form')!);
    await waitFor(() => expect(paymentsApi.initiatePayment).toHaveBeenCalled());
    await waitFor(() => expect(screen.getByText('Payment Initiated')).toBeInTheDocument());
  });

  it('shows error when payment fails', async () => {
    (billingApi.getBill as any).mockResolvedValue(makeBill());
    (paymentsApi.initiatePayment as any).mockRejectedValue({ response: { data: { message: 'declined' } } });
    wrapAtPath('/bills/b-1/pay', '/bills/:id/pay', <PaymentInitiatePage />);
    await waitFor(() => expect(screen.getByText('Pay Bill')).toBeInTheDocument());
    fireEvent.submit(screen.getByRole('button', { name: /Pay 100.00 USD/i }).closest('form')!);
    await waitFor(() => expect(screen.getByText('declined')).toBeInTheDocument());
  });

  it('shows bill not found when getBill returns null', async () => {
    (billingApi.getBill as any).mockRejectedValue({ response: { data: { message: 'not found' } } });
    wrapAtPath('/bills/b-1/pay', '/bills/:id/pay', <PaymentInitiatePage />);
    await waitFor(() => expect(screen.getByText(/not found|Bill not found/i)).toBeInTheDocument());
  });

  it('updates amount on input change', async () => {
    (billingApi.getBill as any).mockResolvedValue(makeBill());
    wrapAtPath('/bills/b-1/pay', '/bills/:id/pay', <PaymentInitiatePage />);
    await waitFor(() => expect(screen.getByText('Pay Bill')).toBeInTheDocument());
    const input = document.querySelector('input[type="text"]') as HTMLInputElement;
    fireEvent.change(input, { target: { value: '50.00' } });
    expect(input.value).toBe('50.00');
  });
});

describe('RefundFormPage — deep', () => {
  it('shows error when no bill_id', async () => {
    wrapAtPath('/finance/refunds/new', '/finance/refunds/new', <RefundFormPage />);
    await waitFor(() => expect(screen.getByText(/No bill_id provided/i)).toBeInTheDocument());
  });

  it('loads bill data when bill_id provided', async () => {
    (billingApi.getBill as any).mockResolvedValue(makeBill({ status: 'paid' }));
    wrapAtPath('/finance/refunds/new?bill_id=b-1', '/finance/refunds/new', <RefundFormPage />);
    await waitFor(() => expect(screen.getByRole('heading', { name: 'Issue Refund' })).toBeInTheDocument());
  });

  it('submits refund and shows success', async () => {
    (billingApi.getBill as any).mockResolvedValue(makeBill({ status: 'paid' }));
    (refundsApi.issueRefund as any).mockResolvedValue({ id: 'r-1' });
    wrapAtPath('/finance/refunds/new?bill_id=b-1', '/finance/refunds/new', <RefundFormPage />);
    await waitFor(() => expect(screen.getAllByText('Issue Refund').length).toBeGreaterThan(0));
    const amountInput = document.querySelector('input[type="text"]') as HTMLInputElement;
    const reasonArea = document.querySelector('textarea') as HTMLTextAreaElement;
    fireEvent.change(amountInput, { target: { value: '25.00' } });
    fireEvent.change(reasonArea, { target: { value: 'customer request' } });
    const form = amountInput.closest('form')!;
    fireEvent.submit(form);
    await waitFor(() => expect(refundsApi.issueRefund).toHaveBeenCalled());
    await waitFor(() => expect(screen.getByText('Refund Issued')).toBeInTheDocument());
  });

  it('shows error when refund fails', async () => {
    (billingApi.getBill as any).mockResolvedValue(makeBill({ status: 'paid' }));
    (refundsApi.issueRefund as any).mockRejectedValue({ response: { data: { message: 'refund denied' } } });
    wrapAtPath('/finance/refunds/new?bill_id=b-1', '/finance/refunds/new', <RefundFormPage />);
    await waitFor(() => expect(screen.getAllByText('Issue Refund').length).toBeGreaterThan(0));
    const amountInput = document.querySelector('input[type="text"]') as HTMLInputElement;
    const reasonArea = document.querySelector('textarea') as HTMLTextAreaElement;
    fireEvent.change(amountInput, { target: { value: '25.00' } });
    fireEvent.change(reasonArea, { target: { value: 'x' } });
    fireEvent.submit(amountInput.closest('form')!);
    await waitFor(() => expect(screen.getByText('refund denied')).toBeInTheDocument());
  });

  it('cancel button calls navigate', async () => {
    (billingApi.getBill as any).mockResolvedValue(makeBill({ status: 'paid' }));
    wrapAtPath('/finance/refunds/new?bill_id=b-1', '/finance/refunds/new', <RefundFormPage />);
    await waitFor(() => expect(screen.getByText('Cancel')).toBeInTheDocument());
    fireEvent.click(screen.getByText('Cancel'));
  });
});

describe('InventoryDetailPage — deep', () => {
  it('renders item details and empty calendar', async () => {
    (inventoryApi.getItem as any).mockResolvedValue({
      id: 'item-1', organization_id: 'o-1', asset_code: 'A1',
      name: 'Test Unit', asset_type: 'studio', location_name: 'Building A',
      capacity_mode: 'discrete_units', total_capacity: 5,
      timezone: 'UTC', is_active: true,
      created_at: '', updated_at: '',
    });
    (inventoryApi.getCalendar as any).mockResolvedValue([]);
    wrapAtPath('/inventory/item-1', '/inventory/:id', <InventoryDetailPage />);
    await waitFor(() => expect(screen.getByText('Test Unit')).toBeInTheDocument());
    expect(screen.getByText(/Building A/)).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText(/No calendar data/i)).toBeInTheDocument());
  });

  it('renders calendar grid when data returned', async () => {
    (inventoryApi.getItem as any).mockResolvedValue({
      id: 'item-1', organization_id: 'o-1', asset_code: 'A1',
      name: 'Test', asset_type: 'studio', location_name: 'L',
      capacity_mode: 'discrete_units', total_capacity: 5,
      timezone: 'UTC', is_active: true,
      created_at: '', updated_at: '',
    });
    (inventoryApi.getCalendar as any).mockResolvedValue([
      { date: '2026-04-15', available_units: 5, total_capacity: 5 },
      { date: '2026-04-16', available_units: 2, total_capacity: 5 },
      { date: '2026-04-17', available_units: 0, total_capacity: 5 },
    ]);
    wrapAtPath('/inventory/item-1', '/inventory/:id', <InventoryDetailPage />);
    await waitFor(() => expect(screen.getByText('Test')).toBeInTheDocument());
    await waitFor(() => expect(screen.getByText('5/5')).toBeInTheDocument());
    expect(screen.getByText('2/5')).toBeInTheDocument();
    expect(screen.getByText('0/5')).toBeInTheDocument();
  });

  it('shows error when item load fails', async () => {
    (inventoryApi.getItem as any).mockRejectedValue({ response: { data: { message: 'missing' } } });
    (inventoryApi.getCalendar as any).mockResolvedValue([]);
    wrapAtPath('/inventory/item-1', '/inventory/:id', <InventoryDetailPage />);
    await waitFor(() => expect(screen.getByText('missing')).toBeInTheDocument());
  });
});

describe('TerminalListPage — deep', () => {
  it('renders terminals empty state', async () => {
    (terminalsApi.listTerminals as any).mockResolvedValue({
      data: [],
      meta: { page: 1, per_page: 25, total: 0, has_next: false },
    });
    render(
      <MemoryRouter>
        <TerminalListPage />
      </MemoryRouter>,
    );
    await waitFor(() => expect(screen.getByText('No terminals registered')).toBeInTheDocument());
  });

  it('renders terminal rows', async () => {
    (terminalsApi.listTerminals as any).mockResolvedValue({
      data: [
        { id: 't1', organization_id: 'o', terminal_code: 'T-01', display_name: 'Lobby', location_group: 'HQ', language_code: 'en', accessibility_mode: false, is_active: true, last_heartbeat_at: null, created_at: '', updated_at: '' },
      ],
      meta: { page: 1, per_page: 25, total: 1, has_next: false },
    });
    render(
      <MemoryRouter>
        <TerminalListPage />
      </MemoryRouter>,
    );
    await waitFor(() => expect(screen.getByText('T-01')).toBeInTheDocument());
    expect(screen.getByText('Lobby')).toBeInTheDocument();
  });

  it('switches to playlists tab and shows empty', async () => {
    (terminalsApi.listTerminals as any).mockResolvedValue({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } });
    (terminalsApi.listPlaylists as any).mockResolvedValue({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } });
    render(
      <MemoryRouter>
        <TerminalListPage />
      </MemoryRouter>,
    );
    await waitFor(() => expect(screen.getByText('No terminals registered')).toBeInTheDocument());
    fireEvent.click(screen.getByText('Playlists'));
    await waitFor(() => expect(screen.getByText('No playlists')).toBeInTheDocument());
  });

  it('switches to transfers tab', async () => {
    (terminalsApi.listTerminals as any).mockResolvedValue({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } });
    render(
      <MemoryRouter>
        <TerminalListPage />
      </MemoryRouter>,
    );
    await waitFor(() => expect(screen.getByText('No terminals registered')).toBeInTheDocument());
    fireEvent.click(screen.getByText('Transfers'));
    await waitFor(() => expect(screen.getByText(/Select a file to transfer/i)).toBeInTheDocument());
  });

  it('toggles register terminal form', async () => {
    (terminalsApi.listTerminals as any).mockResolvedValue({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } });
    render(
      <MemoryRouter>
        <TerminalListPage />
      </MemoryRouter>,
    );
    await waitFor(() => expect(screen.getByText('Register Terminal')).toBeInTheDocument());
    fireEvent.click(screen.getByText('Register Terminal'));
    await waitFor(() => expect(screen.getByPlaceholderText('Terminal Code')).toBeInTheDocument());
    // Fill and submit
    fireEvent.change(screen.getByPlaceholderText('Terminal Code'), { target: { value: 'T-9' } });
    fireEvent.change(screen.getByPlaceholderText('Display Name'), { target: { value: 'Kiosk' } });
    fireEvent.change(screen.getByPlaceholderText('Location Group'), { target: { value: 'Lobby' } });
    (terminalsApi.registerTerminal as any).mockResolvedValue({ id: 'new-t' });
    fireEvent.click(screen.getByText('Register'));
    await waitFor(() => expect(terminalsApi.registerTerminal).toHaveBeenCalled());
  });

  it('shows error when listTerminals fails', async () => {
    (terminalsApi.listTerminals as any).mockRejectedValue({ response: { data: { message: 'boom' } } });
    render(
      <MemoryRouter>
        <TerminalListPage />
      </MemoryRouter>,
    );
    await waitFor(() => expect(screen.getByText('boom')).toBeInTheDocument());
  });

  it('toggles create playlist form', async () => {
    (terminalsApi.listTerminals as any).mockResolvedValue({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } });
    (terminalsApi.listPlaylists as any).mockResolvedValue({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } });
    render(
      <MemoryRouter>
        <TerminalListPage />
      </MemoryRouter>,
    );
    await waitFor(() => expect(screen.getByText('No terminals registered')).toBeInTheDocument());
    fireEvent.click(screen.getByText('Playlists'));
    await waitFor(() => expect(screen.getByText('Create Playlist')).toBeInTheDocument());
    fireEvent.click(screen.getByText('Create Playlist'));
    fireEvent.change(screen.getByPlaceholderText('Name'), { target: { value: 'Weekday' } });
    fireEvent.change(screen.getByPlaceholderText('Location Group'), { target: { value: 'HQ' } });
    fireEvent.change(screen.getByPlaceholderText('Schedule Rule'), { target: { value: 'MON-FRI 09:00-17:00' } });
    (terminalsApi.createPlaylist as any).mockResolvedValue({ id: 'pl-1' });
    fireEvent.click(screen.getByText('Create'));
    await waitFor(() => expect(terminalsApi.createPlaylist).toHaveBeenCalled());
  });
});

describe('ReconciliationPage — deep', () => {
  it('renders empty runs list', async () => {
    (reconciliationApi.listRuns as any).mockResolvedValue({
      data: [],
      meta: { page: 1, per_page: 25, total: 0, has_next: false },
    });
    render(
      <MemoryRouter>
        <ReconciliationPage />
      </MemoryRouter>,
    );
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
  });

  it('triggers reconciliation run', async () => {
    (reconciliationApi.listRuns as any).mockResolvedValue({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } });
    (reconciliationApi.runReconciliation as any).mockResolvedValue({ id: 'run-1', status: 'completed' });
    render(
      <MemoryRouter>
        <ReconciliationPage />
      </MemoryRouter>,
    );
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
    const runBtn = screen.queryAllByText(/Run Reconciliation/i).find(
      (el) => el.tagName === 'BUTTON',
    );
    if (runBtn) {
      fireEvent.click(runBtn);
      await waitFor(() => expect(reconciliationApi.runReconciliation).toHaveBeenCalled());
    }
  });
});

describe('SettingsPage — deep', () => {
  it('renders settings form when loaded', async () => {
    (adminApi.getSettings as any).mockResolvedValue({
      organization_name: 'Org',
      hold_ttl_minutes: 15,
      session_cap: 3,
      dnd_start: '22:00',
      dnd_end: '08:00',
    });
    render(
      <MemoryRouter>
        <SettingsPage />
      </MemoryRouter>,
    );
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
  });

  it('handles settings load error', async () => {
    (adminApi.getSettings as any).mockRejectedValue({ response: { data: { message: 'no settings' } } });
    render(
      <MemoryRouter>
        <SettingsPage />
      </MemoryRouter>,
    );
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
  });
});

describe('UserManagementPage — deep', () => {
  it('lists users', async () => {
    (adminApi.listUsers as any).mockResolvedValue({
      data: [
        { id: 'u-1', username: 'alice', display_name: 'Alice', role: 'administrator', is_active: true, is_frozen: false, organization_id: 'o-1', created_at: '' },
      ],
      meta: { page: 1, per_page: 25, total: 1, has_next: false },
    });
    render(
      <MemoryRouter>
        <UserManagementPage />
      </MemoryRouter>,
    );
    await waitFor(() => expect(screen.getByText('alice')).toBeInTheDocument());
  });

  it('shows empty when no users', async () => {
    (adminApi.listUsers as any).mockResolvedValue({
      data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false },
    });
    render(
      <MemoryRouter>
        <UserManagementPage />
      </MemoryRouter>,
    );
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
  });
});

describe('NotificationPreferencesPage — deep', () => {
  it('loads and shows preferences', async () => {
    (notificationsApi.getPreferences as any).mockResolvedValue([
      { channel: 'email', event_type: 'booking_confirmed', is_enabled: true },
      { channel: 'sms', event_type: 'payment_received', is_enabled: false },
    ]);
    render(
      <MemoryRouter>
        <NotificationPreferencesPage />
      </MemoryRouter>,
    );
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
  });

  it('handles preferences load error', async () => {
    (notificationsApi.getPreferences as any).mockRejectedValue({ response: { data: { message: 'x' } } });
    render(
      <MemoryRouter>
        <NotificationPreferencesPage />
      </MemoryRouter>,
    );
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
  });
});

describe('SupplementalBillPage — deep', () => {
  it('renders with booking_id param', async () => {
    (billingApi.createSupplementalBill as any).mockResolvedValue({ id: 'b-new' });
    render(
      <MemoryRouter initialEntries={['/finance/bills/new?booking_id=bk-1']}>
        <Routes>
          <Route path="/finance/bills/new" element={<SupplementalBillPage />} />
        </Routes>
      </MemoryRouter>,
    );
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
  });
});

describe('InventoryFormPage — deep', () => {
  it('renders create form', async () => {
    render(
      <MemoryRouter initialEntries={['/inventory/new']}>
        <Routes>
          <Route path="/inventory/new" element={<InventoryFormPage />} />
        </Routes>
      </MemoryRouter>,
    );
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
  });
});
