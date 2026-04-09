export const UserRole = { ADMINISTRATOR: 'administrator', PROPERTY_MANAGER: 'property_manager', TENANT: 'tenant', FINANCE_CLERK: 'finance_clerk' } as const;
export type UserRole = typeof UserRole[keyof typeof UserRole];

export const CapacityMode = { DISCRETE_UNITS: 'discrete_units', SINGLE_SLOT: 'single_slot' } as const;
export type CapacityMode = typeof CapacityMode[keyof typeof CapacityMode];

export const BookingHoldStatus = { ACTIVE: 'active', EXPIRED: 'expired', RELEASED: 'released', CONVERTED: 'converted' } as const;
export type BookingHoldStatus = typeof BookingHoldStatus[keyof typeof BookingHoldStatus];

export const BookingStatus = { CONFIRMED: 'confirmed', ACTIVE: 'active', COMPLETED: 'completed', CANCELED: 'canceled', NO_SHOW: 'no_show' } as const;
export type BookingStatus = typeof BookingStatus[keyof typeof BookingStatus];

export const BillType = { INITIAL: 'initial', RECURRING: 'recurring', SUPPLEMENTAL: 'supplemental', PENALTY: 'penalty' } as const;
export type BillType = typeof BillType[keyof typeof BillType];

export const BillStatus = { OPEN: 'open', PARTIALLY_PAID: 'partially_paid', PAID: 'paid', PARTIALLY_REFUNDED: 'partially_refunded', VOIDED: 'voided' } as const;
export type BillStatus = typeof BillStatus[keyof typeof BillStatus];

export const PaymentStatus = { PENDING: 'pending', SUCCEEDED: 'succeeded', FAILED: 'failed', REJECTED: 'rejected' } as const;
export type PaymentStatus = typeof PaymentStatus[keyof typeof PaymentStatus];

export const RefundStatus = { ISSUED: 'issued', REJECTED: 'rejected' } as const;
export type RefundStatus = typeof RefundStatus[keyof typeof RefundStatus];

export const LedgerEntryType = { BILL_ISSUED: 'bill_issued', PAYMENT_RECEIVED: 'payment_received', REFUND_ISSUED: 'refund_issued', PENALTY_APPLIED: 'penalty_applied', BILL_VOIDED: 'bill_voided' } as const;
export type LedgerEntryType = typeof LedgerEntryType[keyof typeof LedgerEntryType];

export const NotificationStatus = { PENDING: 'pending', DELIVERED: 'delivered', READ: 'read' } as const;
export type NotificationStatus = typeof NotificationStatus[keyof typeof NotificationStatus];

export const TerminalTransferStatus = { PENDING: 'pending', IN_PROGRESS: 'in_progress', PAUSED: 'paused', COMPLETED: 'completed', FAILED: 'failed' } as const;
export type TerminalTransferStatus = typeof TerminalTransferStatus[keyof typeof TerminalTransferStatus];

export const RateType = { HOURLY: 'hourly', DAILY: 'daily', MONTHLY: 'monthly', FLAT: 'flat' } as const;
export type RateType = typeof RateType[keyof typeof RateType];

export const ReconciliationRunStatus = { RUNNING: 'running', COMPLETED: 'completed', FAILED: 'failed' } as const;
export type ReconciliationRunStatus = typeof ReconciliationRunStatus[keyof typeof ReconciliationRunStatus];
