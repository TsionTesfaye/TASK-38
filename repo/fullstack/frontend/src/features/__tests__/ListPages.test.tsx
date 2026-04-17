import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
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
  updateSettings: vi.fn(() => Promise.resolve({})),
  listBackups: vi.fn(() => Promise.resolve([])),
  createBackup: vi.fn(() => Promise.resolve({ filename: 'backup_test_20260101_000000.enc', size_bytes: 512, tables: [] })),
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

describe('List pages — empty state and key UI elements', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('InventoryListPage shows heading, empty state, and Create Item button', async () => {
    wrap(<InventoryListPage />);
    await waitFor(() => {
      expect(screen.getByText('Inventory')).toBeInTheDocument();
      expect(screen.getByText('No inventory items found')).toBeInTheDocument();
      expect(screen.getByRole('button', { name: /create item/i })).toBeInTheDocument();
    });
  });

  it('PaymentListPage shows heading', async () => {
    wrap(<PaymentListPage />);
    await waitFor(() => {
      expect(screen.getByText('Payments')).toBeInTheDocument();
    });
  });

  it('RefundListPage shows heading', async () => {
    wrap(<RefundListPage />);
    await waitFor(() => {
      expect(screen.getByText('Refunds')).toBeInTheDocument();
    });
  });

  it('TerminalListPage shows heading', async () => {
    wrap(<TerminalListPage />);
    await waitFor(() => {
      expect(screen.getByText('Terminals')).toBeInTheDocument();
    });
  });

  it('NotificationCenterPage shows heading', async () => {
    wrap(<NotificationCenterPage />);
    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /notification/i })).toBeInTheDocument();
    });
  });

  it('UserManagementPage shows heading, empty state, and Create User button', async () => {
    wrap(<UserManagementPage />);
    await waitFor(() => {
      expect(screen.getByText('User Management')).toBeInTheDocument();
      expect(screen.getByText('No users')).toBeInTheDocument();
      expect(screen.getByRole('button', { name: /create user/i })).toBeInTheDocument();
    });
  });

  it('UserManagementPage: clicking Create User toggles the create form', async () => {
    wrap(<UserManagementPage />);
    const btn = await screen.findByRole('button', { name: /create user/i });
    fireEvent.click(btn);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /cancel/i })).toBeInTheDocument();
    });
  });

  it('AuditLogPage shows heading', async () => {
    wrap(<AuditLogPage />);
    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /audit log/i })).toBeInTheDocument();
    });
  });

  it('SettingsPage shows heading, field labels, and Save button', async () => {
    wrap(<SettingsPage />);
    await waitFor(() => {
      expect(screen.getByText('Organization Settings')).toBeInTheDocument();
      expect(screen.getByText(/hold duration/i)).toBeInTheDocument();
      expect(screen.getByText(/cancellation fee/i)).toBeInTheDocument();
      expect(screen.getByRole('button', { name: /save settings/i })).toBeInTheDocument();
    });
  });

  it('BackupPage shows heading, empty state, and Create Backup button', async () => {
    wrap(<BackupPage />);
    await waitFor(() => {
      expect(screen.getByText('Backups')).toBeInTheDocument();
      expect(screen.getByText('No backups found')).toBeInTheDocument();
      expect(screen.getByRole('button', { name: /create backup/i })).toBeInTheDocument();
    });
  });

  it('ReconciliationPage shows heading', async () => {
    wrap(<ReconciliationPage />);
    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /reconciliation/i })).toBeInTheDocument();
    });
  });
});
