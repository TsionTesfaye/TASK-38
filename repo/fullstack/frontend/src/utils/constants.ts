export const API_BASE_URL =
  import.meta.env.VITE_API_BASE_URL ?? '/api/v1';

export const DEFAULT_PAGE_SIZE = 20;
export const MAX_PAGE_SIZE = 100;
export const HOLD_DURATION_SECONDS = 600; // 10 minutes
export const NOTIFICATION_POLL_INTERVAL_MS = 30_000;

export const STATUS_COLORS: Record<string, string> = {
  // Booking statuses
  confirmed: '#3b82f6',
  active: '#8b5cf6',
  completed: '#10b981',
  canceled: '#ef4444',
  no_show: '#6b7280',

  // Hold statuses
  expired: '#9ca3af',
  released: '#6b7280',
  converted: '#10b981',

  // Bill statuses
  open: '#3b82f6',
  partially_paid: '#f59e0b',
  paid: '#10b981',
  partially_refunded: '#f97316',
  voided: '#ef4444',

  // Payment statuses
  pending: '#f59e0b',
  succeeded: '#10b981',
  failed: '#ef4444',
  rejected: '#ef4444',

  // Refund statuses
  issued: '#10b981',

  // Terminal transfer statuses
  in_progress: '#3b82f6',
  paused: '#f59e0b',

  // Reconciliation statuses
  running: '#3b82f6',

  // Generic
  true: '#10b981',
  false: '#ef4444',
};
