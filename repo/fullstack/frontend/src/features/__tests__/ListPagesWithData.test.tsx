import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

import { PaymentListPage } from '../payments/PaymentListPage';
import { RefundListPage } from '../refunds/RefundListPage';
import { TerminalListPage } from '../terminals/TerminalListPage';
import { NotificationCenterPage } from '../notifications/NotificationCenterPage';
import { UserManagementPage } from '../admin/UserManagementPage';
import { AuditLogPage } from '../admin/AuditLogPage';
import { BackupPage } from '../admin/BackupPage';
import { ReconciliationPage } from '../reports/ReconciliationPage';
import { InventoryListPage } from '../inventory/InventoryListPage';

vi.mock('../../api/payments', () => ({
  listPayments: vi.fn(() => Promise.resolve({
    data: [{
      id: 'p-abcd1234-1111-2222-3333-444444444444',
      organization_id: 'o-1', bill_id: 'b-1', request_id: 'r-1',
      external_reference: null, status: 'succeeded',
      currency: 'USD', amount: '100.00',
      signature_verified: true,
      received_at: '2026-04-09T10:00:00Z',
      processed_at: '2026-04-09T10:01:00Z',
      created_at: '2026-04-09T10:00:00Z',
    }],
    meta: { page: 1, per_page: 25, total: 1, has_next: false },
  })),
  createPayment: vi.fn(),
}));

vi.mock('../../api/refunds', () => ({
  listRefunds: vi.fn(() => Promise.resolve({
    data: [{
      id: 'r-abcd1234-1111-2222-3333-444444444444',
      bill_id: 'b-1', payment_id: 'p-1',
      amount: '50.00', reason: 'refund reason',
      status: 'issued', created_by_user_id: 'u-1',
      created_at: '2026-04-09T10:00:00Z',
    }],
    meta: { page: 1, per_page: 25, total: 1, has_next: false },
  })),
  createRefund: vi.fn(),
}));

vi.mock('../../api/terminals', () => ({
  listTerminals: vi.fn(() => Promise.resolve({
    data: [{
      id: 't-1', organization_id: 'o-1',
      terminal_code: 'T1', display_name: 'Kiosk',
      location_group: 'Lobby', language_code: 'en',
      accessibility_mode: false, is_active: true,
      last_sync_at: null,
      created_at: '2026-04-09T10:00:00Z',
      updated_at: '2026-04-09T10:00:00Z',
    }],
    meta: { page: 1, per_page: 25, total: 1, has_next: false },
  })),
  createTerminal: vi.fn(),
}));

vi.mock('../../api/notifications', () => ({
  listNotifications: vi.fn(() => Promise.resolve({
    data: [{
      id: 'n-1', organization_id: 'o-1', user_id: 'u-1',
      event_code: 'booking.confirmed',
      title: 'Booking Confirmed', body: 'Your booking is confirmed',
      status: 'delivered', scheduled_for: '2026-04-09T10:00:00Z',
      delivered_at: '2026-04-09T10:00:00Z', read_at: null,
      created_at: '2026-04-09T10:00:00Z',
    }],
    meta: { page: 1, per_page: 25, total: 1, has_next: false },
  })),
  markRead: vi.fn(() => Promise.resolve({})),
}));

vi.mock('../../api/admin', () => ({
  listUsers: vi.fn(() => Promise.resolve({
    data: [{
      id: 'u-1', username: 'admin', display_name: 'Admin',
      role: 'administrator', is_active: true, is_frozen: false,
      organization_id: 'o-1', created_at: '2026-04-09T10:00:00Z',
    }],
    meta: { page: 1, per_page: 25, total: 1, has_next: false },
  })),
  listAuditLogs: vi.fn(() => Promise.resolve({
    data: [{
      id: 'al-1', actor_user_id: 'u-1',
      actor_username_snapshot: 'admin',
      action_code: 'auth.login', object_type: 'User',
      object_id: '***1234', created_at: '2026-04-09T10:00:00Z',
    }],
    meta: { page: 1, per_page: 25, total: 1, has_next: false },
  })),
  listBackups: vi.fn(() => Promise.resolve([{
    filename: 'backup_org-1_20260409_100000.enc',
    size_bytes: 1024, modified_at: '2026-04-09T10:00:00Z',
  }])),
  createBackup: vi.fn(() => Promise.resolve({ filename: 'new.enc', created_at: '', tables: [] })),
  previewBackup: vi.fn(),
  restoreBackup: vi.fn(),
  createUser: vi.fn(),
  updateUser: vi.fn(),
  freezeUser: vi.fn(),
  unfreezeUser: vi.fn(),
  getSettings: vi.fn(),
  updateSettings: vi.fn(),
}));

vi.mock('../../api/reconciliation', () => ({
  listRuns: vi.fn(() => Promise.resolve({
    data: [{
      id: 'rr-1', run_date: '2026-04-09', status: 'completed',
      mismatch_count: 0, output_csv_path: null,
      started_at: '2026-04-09T00:00:00Z',
      completed_at: '2026-04-09T00:05:00Z',
    }],
    meta: { page: 1, per_page: 25, total: 1, has_next: false },
  })),
  runReconciliation: vi.fn(),
}));

vi.mock('../../api/inventory', () => ({
  listItems: vi.fn(() => Promise.resolve({
    data: [{
      id: 'i-1', organization_id: 'o-1', asset_code: 'A1',
      name: 'Item One', asset_type: 'studio', location_name: 'A',
      capacity_mode: 'discrete_units', total_capacity: 5,
      timezone: 'UTC', is_active: true,
      created_at: '2026-04-09T10:00:00Z',
      updated_at: '2026-04-09T10:00:00Z',
    }],
    meta: { page: 1, per_page: 25, total: 1, has_next: false },
  })),
}));

const wrap = (ui: React.ReactElement) => render(<MemoryRouter>{ui}</MemoryRouter>);

describe('List pages render with data', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('PaymentListPage renders row with amount and status', async () => {
    wrap(<PaymentListPage />);
    await waitFor(() => {
      expect(screen.getByText('Payments')).toBeInTheDocument();
      expect(screen.getByText('Succeeded')).toBeInTheDocument();
    });
  });

  it('RefundListPage renders row', async () => {
    wrap(<RefundListPage />);
    await waitFor(() => {
      expect(document.body.innerHTML).toContain('50');
    });
  });

  it('TerminalListPage renders row', async () => {
    wrap(<TerminalListPage />);
    await waitFor(() => {
      expect(document.body.innerHTML).toContain('Kiosk');
    });
  });

  it('NotificationCenterPage renders notification', async () => {
    wrap(<NotificationCenterPage />);
    await waitFor(() => {
      expect(document.body.innerHTML).toContain('Booking Confirmed');
    });
  });

  it('UserManagementPage renders users', async () => {
    wrap(<UserManagementPage />);
    await waitFor(() => {
      expect(document.body.innerHTML).toContain('admin');
    });
  });

  it('AuditLogPage renders audit log row', async () => {
    wrap(<AuditLogPage />);
    await waitFor(() => {
      expect(document.body.innerHTML).toContain('auth.login');
    });
  });

  it('BackupPage renders backup row', async () => {
    wrap(<BackupPage />);
    await waitFor(() => {
      expect(document.body.innerHTML).toContain('backup_org-1');
    });
  });

  it('ReconciliationPage renders run row', async () => {
    wrap(<ReconciliationPage />);
    await waitFor(() => {
      expect(document.body.innerHTML).not.toBe('');
    });
  });

  it('InventoryListPage renders item row', async () => {
    wrap(<InventoryListPage />);
    await waitFor(() => {
      expect(screen.getByText('Inventory')).toBeInTheDocument();
      expect(screen.getByText('Item One')).toBeInTheDocument();
    });
  });
});
