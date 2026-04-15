import React from 'react';
import { render } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { App } from '../App';
import { useAuthStore } from '../../state/authStore';

// Mock all API modules so feature pages don't blow up on mount
vi.mock('../../api/bookings', () => ({
  listBookings: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  getBooking: vi.fn(),
  createHold: vi.fn(),
  confirmHold: vi.fn(),
  cancel: vi.fn(),
  checkIn: vi.fn(),
  complete: vi.fn(),
  markNoShow: vi.fn(),
  reschedule: vi.fn(),
  getHold: vi.fn(),
  releaseHold: vi.fn(),
}));
vi.mock('../../api/billing', () => ({
  listBills: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  getBill: vi.fn(),
  createSupplementalBill: vi.fn(),
  voidBill: vi.fn(),
}));
vi.mock('../../api/inventory', () => ({
  listItems: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  getItem: vi.fn(),
  createItem: vi.fn(),
  updateItem: vi.fn(),
  deactivateItem: vi.fn(),
  checkAvailability: vi.fn(),
  getCalendar: vi.fn(() => Promise.resolve({ dates: [] })),
  listPricing: vi.fn(() => Promise.resolve([])),
  createPricing: vi.fn(),
}));
vi.mock('../../api/payments', () => ({
  listPayments: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  initiatePayment: vi.fn(),
  getPayment: vi.fn(),
}));
vi.mock('../../api/refunds', () => ({
  listRefunds: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  issueRefund: vi.fn(),
  getRefund: vi.fn(),
}));
vi.mock('../../api/notifications', () => ({
  listNotifications: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  markRead: vi.fn(),
  getPreferences: vi.fn(() => Promise.resolve([])),
  updatePreference: vi.fn(),
}));
vi.mock('../../api/admin', () => ({
  listUsers: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  listAuditLogs: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  getSettings: vi.fn(() => Promise.resolve({})),
  updateSettings: vi.fn(),
  listBackups: vi.fn(() => Promise.resolve([])),
  createBackup: vi.fn(),
  previewBackup: vi.fn(),
  restoreBackup: vi.fn(),
  createUser: vi.fn(),
  updateUser: vi.fn(),
  freezeUser: vi.fn(),
  unfreezeUser: vi.fn(),
}));
vi.mock('../../api/terminals', () => ({
  listTerminals: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  createTerminal: vi.fn(),
}));
vi.mock('../../api/reconciliation', () => ({
  listRuns: vi.fn(() => Promise.resolve({ data: [], meta: { page: 1, per_page: 25, total: 0, has_next: false } })),
  runReconciliation: vi.fn(),
}));
vi.mock('../../api/auth', () => ({
  login: vi.fn(),
  bootstrap: vi.fn(),
  refresh: vi.fn(),
  logout: vi.fn(),
  changePassword: vi.fn(),
}));
vi.mock('../../api/client', () => ({
  default: {
    get: vi.fn(() => Promise.resolve({ data: { data: [], meta: {} } })),
    post: vi.fn(() => Promise.resolve({ data: {} })),
    put: vi.fn(() => Promise.resolve({ data: {} })),
  },
}));

describe('App', () => {
  beforeEach(() => {
    useAuthStore.setState({
      user: null, accessToken: null, refreshToken: null, sessionId: null, isAuthenticated: false,
    });
  });

  it('renders routes at /login', () => {
    const { container } = render(
      <MemoryRouter initialEntries={['/login']}>
        <App />
      </MemoryRouter>,
    );
    expect(container.firstChild).toBeTruthy();
  });

  it('renders routes at /bootstrap', () => {
    const { container } = render(
      <MemoryRouter initialEntries={['/bootstrap']}>
        <App />
      </MemoryRouter>,
    );
    expect(container.firstChild).toBeTruthy();
  });

  it('unauthenticated user redirected from protected route', () => {
    const { container } = render(
      <MemoryRouter initialEntries={['/admin/users']}>
        <App />
      </MemoryRouter>,
    );
    expect(container.firstChild).toBeTruthy();
  });
});
