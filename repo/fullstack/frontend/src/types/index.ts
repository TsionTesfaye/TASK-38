import type {
  UserRole,
  CapacityMode,
  BookingHoldStatus,
  BookingStatus,
  BillType,
  BillStatus,
  PaymentStatus,
  RefundStatus,
  LedgerEntryType,
  NotificationStatus,
  TerminalTransferStatus,
  RateType,
  ReconciliationRunStatus,
} from './enums';

export interface Organization {
  id: string;
  code: string;
  name: string;
  is_active: boolean;
  default_currency: string;
  created_at: string;
}

export interface User {
  id: string;
  username: string;
  display_name: string;
  role: UserRole;
  is_active: boolean;
  is_frozen: boolean;
  organization_id: string;
  created_at: string;
}

export interface Settings {
  id: string;
  organization_id: string;
  timezone: string;
  allow_partial_payments: boolean;
  cancellation_fee_pct: string;
  no_show_fee_pct: string;
  no_show_first_day_rent_enabled: boolean;
  hold_duration_minutes: number;
  no_show_grace_period_minutes: number;
  max_devices_per_user: number;
  recurring_bill_day: number;
  recurring_bill_hour: number;
  booking_attempts_per_item_per_minute: number;
  max_booking_duration_days: number;
  terminals_enabled: boolean;
  created_at: string;
  updated_at: string;
}

export interface InventoryItem {
  id: string;
  asset_code: string;
  name: string;
  asset_type: string;
  location_name: string;
  capacity_mode: CapacityMode;
  total_capacity: number;
  timezone: string;
  is_active: boolean;
  created_at: string;
}

export interface InventoryPricing {
  id: string;
  inventory_item_id: string;
  rate_type: RateType;
  amount: string;
  currency: string;
  effective_from: string;
  effective_to: string | null;
}

export interface BookingHold {
  id: string;
  inventory_item_id: string;
  tenant_user_id: string;
  request_key: string;
  held_units: number;
  start_at: string;
  end_at: string;
  expires_at: string;
  status: BookingHoldStatus;
  created_at: string;
  confirmed_booking_id: string | null;
}

export interface Booking {
  id: string;
  organization_id: string;
  inventory_item_id: string;
  tenant_user_id: string;
  source_hold_id: string | null;
  status: BookingStatus;
  start_at: string;
  end_at: string;
  booked_units: number;
  currency: string;
  base_amount: string;
  final_amount: string;
  cancellation_fee_amount: string;
  no_show_penalty_amount: string;
  checked_in_at: string | null;
  canceled_at: string | null;
  completed_at: string | null;
  no_show_marked_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface Bill {
  id: string;
  booking_id: string | null;
  tenant_user_id: string;
  bill_type: BillType;
  status: BillStatus;
  currency: string;
  original_amount: string;
  outstanding_amount: string;
  due_at: string | null;
  issued_at: string;
  paid_at: string | null;
  voided_at: string | null;
  pdf_path: string | null;
}

export interface Payment {
  id: string;
  bill_id: string;
  external_reference: string | null;
  request_id: string;
  status: PaymentStatus;
  currency: string;
  amount: string;
  signature_verified: boolean;
  received_at: string;
  processed_at: string | null;
  created_at: string;
}

export interface Refund {
  id: string;
  bill_id: string;
  payment_id: string | null;
  amount: string;
  reason: string;
  status: RefundStatus;
  created_by_user_id: string;
  created_at: string;
}

export interface LedgerEntry {
  id: string;
  organization_id: string;
  booking_id: string | null;
  bill_id: string | null;
  payment_id: string | null;
  refund_id: string | null;
  entry_type: LedgerEntryType;
  amount: string;
  currency: string;
  occurred_at: string;
  metadata_json: Record<string, unknown> | null;
}

export interface Notification {
  id: string;
  event_code: string;
  title: string;
  body: string;
  status: NotificationStatus;
  scheduled_for: string;
  delivered_at: string | null;
  read_at: string | null;
  created_at: string;
}

export interface NotificationPreference {
  id: string;
  event_code: string;
  is_enabled: boolean;
  dnd_start_local: string;
  dnd_end_local: string;
}

export interface Terminal {
  id: string;
  terminal_code: string;
  display_name: string;
  location_group: string;
  language_code: string;
  accessibility_mode: boolean;
  is_active: boolean;
  last_sync_at: string | null;
}

export interface TerminalPlaylist {
  id: string;
  name: string;
  location_group: string;
  schedule_rule: string;
  is_active: boolean;
}

export interface TerminalPackageTransfer {
  id: string;
  terminal_id: string;
  package_name: string;
  checksum: string;
  total_chunks: number;
  transferred_chunks: number;
  status: TerminalTransferStatus;
  started_at: string;
  completed_at: string | null;
}

export interface ReconciliationRun {
  id: string;
  run_date: string;
  status: ReconciliationRunStatus;
  mismatch_count: number;
  output_csv_path: string | null;
  started_at: string;
  completed_at: string | null;
}

export interface AuditLog {
  id: string;
  actor_user_id: string | null;
  actor_username_snapshot: string;
  action_code: string;
  object_type: string;
  object_id: string;
  created_at: string;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    page: number;
    per_page: number;
    total: number;
    has_next: boolean;
  };
}

export interface ErrorResponse {
  code: number;
  message: string;
  details: Record<string, unknown> | null;
}

export interface AuthTokenResponse {
  access_token: string;
  refresh_token: string;
  expires_in: number;
  session_id: string;
  user: User;
}
