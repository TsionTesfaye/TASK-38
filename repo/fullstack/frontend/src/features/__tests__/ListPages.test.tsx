import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

import { InventoryListPage } from '../inventory/InventoryListPage';
import { PaymentListPage } from '../payments/PaymentListPage';
import { RefundListPage } from '../refunds/RefundListPage';
import { TerminalListPage } from '../terminals/TerminalListPage';
import { NotificationCenterPage } from '../notifications/NotificationCenterPage';
import { UserManagementPage } from '../admin/UserManagementPage';
import { AuditLogPage } from '../admin/AuditLogPage';
import { SettingsPage } from '../admin/SettingsPage';
import { BackupPage } from '../admin/BackupPage';
import { ReconciliationPage } from '../reports/ReconciliationPage';

vi.mock('../../api/inventory', () => ({
  listItems: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  getItem: vi.fn(),
}));
vi.mock('../../api/payments', () => ({
  listPayments: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  createPayment: vi.fn(),
}));
vi.mock('../../api/refunds', () => ({
  listRefunds: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  createRefund: vi.fn(),
}));
vi.mock('../../api/terminals', () => ({
  listTerminals: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  createTerminal: vi.fn(),
}));
vi.mock('../../api/notifications', () => ({
  listNotifications: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  markRead: vi.fn(),
}));
vi.mock('../../api/admin', () => ({
  listUsers: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  listAuditLogs: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  getSettings: vi.fn(() => Promise.resolve({})),
  updateSettings: vi.fn(),
  listBackups: vi.fn(() => Promise.resolve([])),
  createBackup: vi.fn(),
  createUser: vi.fn(),
  updateUser: vi.fn(),
  freezeUser: vi.fn(),
  unfreezeUser: vi.fn(),
  previewBackup: vi.fn(),
  restoreBackup: vi.fn(),
}));
vi.mock('../../api/reconciliation', () => ({
  listRuns: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  runReconciliation: vi.fn(),
}));

const wrap = (ui: React.ReactElement) => render(<MemoryRouter>{ui}</MemoryRouter>);

describe('List pages render without crashing', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('InventoryListPage renders', async () => {
    wrap(<InventoryListPage />);
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
  });

  it('PaymentListPage renders', async () => {
    wrap(<PaymentListPage />);
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
  });

  it('RefundListPage renders', async () => {
    wrap(<RefundListPage />);
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
  });

  it('TerminalListPage renders', async () => {
    wrap(<TerminalListPage />);
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
  });

  it('NotificationCenterPage renders', async () => {
    wrap(<NotificationCenterPage />);
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
  });

  it('UserManagementPage renders', async () => {
    wrap(<UserManagementPage />);
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
  });

  it('AuditLogPage renders', async () => {
    wrap(<AuditLogPage />);
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
  });

  it('SettingsPage renders', async () => {
    wrap(<SettingsPage />);
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
  });

  it('BackupPage renders', async () => {
    wrap(<BackupPage />);
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
  });

  it('ReconciliationPage renders', async () => {
    wrap(<ReconciliationPage />);
    await waitFor(() => expect(document.body.innerHTML).not.toBe(''));
  });
});
