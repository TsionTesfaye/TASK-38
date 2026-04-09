# RentOps API Specification

**Base URL:** `/api/v1`
**Content-Type:** `application/json`
**Authentication:** Bearer token in `Authorization` header (except public endpoints)

---

## Authentication

### POST /auth/login
Login with username and password. Returns JWT tokens and user profile.

**Auth:** Public

**Request:**
```json
{
  "username": "string (required)",
  "password": "string (required)",
  "device_label": "string (required)",
  "client_device_id": "string (required)"
}
```

**Response 200:**
```json
{
  "data": {
    "access_token": "string",
    "refresh_token": "string",
    "expires_in": 900,
    "session_id": "uuid",
    "user": {
      "id": "uuid",
      "username": "string",
      "display_name": "string",
      "role": "administrator | property_manager | tenant | finance_clerk",
      "is_active": true,
      "is_frozen": false,
      "organization_id": "uuid",
      "created_at": "ISO-8601"
    }
  }
}
```

**Errors:** 401 (invalid credentials), 409 (account frozen), 422 (missing fields)

---

### POST /auth/refresh
Refresh an expired access token using a valid refresh token.

**Auth:** Public

**Request:**
```json
{
  "refresh_token": "string (required)"
}
```

**Response 200:** Same shape as login response.

**Errors:** 401 (invalid/expired/revoked refresh token)

---

### POST /auth/logout
Revoke the current device session.

**Auth:** Authenticated

**Request:**
```json
{
  "session_id": "uuid (required)"
}
```

**Response 200:**
```json
{ "data": { "message": "Logged out successfully" } }
```

**Errors:** 401, 403 (session does not belong to user), 404

---

### POST /auth/change-password
Change the current user's password. Revokes all existing sessions.

**Auth:** Authenticated

**Request:**
```json
{
  "current_password": "string (required)",
  "new_password": "string (required)"
}
```

**Response 200:**
```json
{ "data": { "message": "Password changed successfully" } }
```

**Errors:** 401 (wrong current password)

---

## Bootstrap

### POST /bootstrap
Create the first organization and administrator. Disabled after the first admin exists.

**Auth:** Public

**Request:**
```json
{
  "organization_name": "string (required)",
  "organization_code": "string (required)",
  "admin_username": "string (required)",
  "admin_password": "string (required, min 8 chars)",
  "admin_display_name": "string (required)",
  "default_currency": "string (optional, default: USD)"
}
```

**Response 201:**
```json
{
  "data": {
    "organization": { "id": "uuid", "code": "string", "name": "string" },
    "user": { ...user object }
  }
}
```

**Errors:** 409 (bootstrap already completed), 422 (validation)

---

## Health

### GET /health
System health check.

**Auth:** Public

**Response 200:**
```json
{
  "data": {
    "status": "ok",
    "timestamp": "ISO-8601",
    "checks": { "database": "ok" }
  }
}
```

---

## Users

### GET /users/me
Get the authenticated user's profile.

**Auth:** Authenticated

**Response 200:** `{ "data": { ...user object } }`

---

### GET /users
List users in the authenticated user's organization.

**Auth:** Authenticated | **RBAC:** VIEW_ORG

**Query:** `page` (default 1), `per_page` (default 25, max 100), `role`, `is_active`, `search`

**Response 200:** Paginated list of user objects.

---

### POST /users
Create a new user in the admin's organization.

**Auth:** Authenticated | **RBAC:** MANAGE_USERS

**Request:**
```json
{
  "username": "string (required, globally unique)",
  "password": "string (required)",
  "display_name": "string (required)",
  "role": "administrator | property_manager | tenant | finance_clerk (required)"
}
```

**Response 201:** `{ "data": { ...user object } }`

**Errors:** 403, 409 (username exists), 422 (invalid enum/validation)

---

### GET /users/{id}
Get a user by ID (within the same organization).

**Auth:** Authenticated | **RBAC:** VIEW_ORG

**Response 200:** `{ "data": { ...user object } }`

**Errors:** 403, 404

---

### PUT /users/{id}
Update a user. Admins can update any user in their org.

**Auth:** Authenticated | **RBAC:** MANAGE_USERS

**Request:** Any subset of: `display_name`, `role`, `is_active`, `password`

**Response 200:** `{ "data": { ...user object } }`

**Errors:** 403, 404

**Side effect:** If `password` is changed, all of the target user's sessions are revoked.

---

### POST /users/{id}/freeze
Freeze a user account (prevent login).

**Auth:** Authenticated | **RBAC:** MANAGE_USERS

**Response 200:** `{ "data": { ...user object, "is_frozen": true } }`

---

### POST /users/{id}/unfreeze
Unfreeze a user account.

**Auth:** Authenticated | **RBAC:** MANAGE_USERS

**Response 200:** `{ "data": { ...user object, "is_frozen": false } }`

---

## Inventory

### GET /inventory
List inventory items in the organization.

**Auth:** Authenticated | **RBAC:** VIEW_OWN (all roles)

**Query:** `page`, `per_page`, `asset_type`, `location_name`, `is_active`, `search`

**Response 200:**
```json
{
  "data": [{
    "id": "uuid",
    "organization_id": "uuid",
    "asset_code": "string",
    "name": "string",
    "asset_type": "string",
    "location_name": "string",
    "capacity_mode": "discrete_units | single_slot",
    "total_capacity": 10,
    "timezone": "America/New_York",
    "is_active": true,
    "created_at": "ISO-8601",
    "updated_at": "ISO-8601"
  }],
  "meta": { "page": 1, "per_page": 25, "total": 42, "has_next": true }
}
```

---

### POST /inventory
Create an inventory item.

**Auth:** Authenticated | **RBAC:** MANAGE_INVENTORY

**Request:**
```json
{
  "asset_code": "string (required)",
  "name": "string (required)",
  "asset_type": "string (required)",
  "location_name": "string (required)",
  "capacity_mode": "discrete_units | single_slot (required)",
  "total_capacity": "integer (required, >= 1)",
  "timezone": "string (optional, default: UTC)"
}
```

**Response 201:** `{ "data": { ...inventory item } }`

---

### GET /inventory/{id}
Get a single inventory item.

**Auth:** Authenticated | **RBAC:** VIEW_OWN

**Response 200:** `{ "data": { ...inventory item } }`

---

### PUT /inventory/{id}
Update an inventory item.

**Auth:** Authenticated | **RBAC:** MANAGE_INVENTORY

**Request:** Any subset of: `name`, `location_name`, `total_capacity`, `timezone`, `is_active`

---

### POST /inventory/{id}/deactivate
Deactivate an inventory item.

**Auth:** Authenticated | **RBAC:** MANAGE_INVENTORY

---

### GET /inventory/{id}/availability
Check availability for a date range.

**Auth:** Authenticated | **RBAC:** VIEW_OWN

**Query:** `start_at` (ISO-8601), `end_at` (ISO-8601), `units` (default 1)

**Response 200:**
```json
{
  "data": {
    "available_units": 3,
    "requested_units": 1,
    "total_capacity": 10,
    "can_reserve": true
  }
}
```

---

### GET /inventory/{id}/calendar
Get daily availability for a date range.

**Auth:** Authenticated | **RBAC:** VIEW_OWN

**Query:** `from` (date, default: today), `to` (date, default: +30 days)

---

## Pricing

### GET /inventory/{itemId}/pricing
List pricing rules for an inventory item.

**Auth:** Authenticated | **RBAC:** VIEW_OWN

**Response 200:**
```json
{
  "data": [{
    "id": "uuid",
    "inventory_item_id": "uuid",
    "rate_type": "hourly | daily | monthly | flat",
    "amount": "150.00",
    "currency": "USD",
    "effective_from": "ISO-8601",
    "effective_to": "ISO-8601 | null",
    "created_at": "ISO-8601"
  }]
}
```

---

### POST /inventory/{itemId}/pricing
Create a pricing rule.

**Auth:** Authenticated | **RBAC:** MANAGE_INVENTORY

**Request:**
```json
{
  "rate_type": "hourly | daily | monthly | flat (required)",
  "amount": "150.00 (required)",
  "currency": "USD (required)",
  "effective_from": "ISO-8601 (optional, default: now)",
  "effective_to": "ISO-8601 (optional, null = open-ended)"
}
```

**Errors:** 409 (currency mismatch with org default, overlapping effective period)

---

## Booking Holds

### POST /holds
Create a temporary hold on inventory units. Holds expire after the configured duration (default 10 minutes).

**Auth:** Authenticated

**Request:**
```json
{
  "inventory_item_id": "uuid (required)",
  "held_units": "integer (required, >= 1)",
  "start_at": "ISO-8601 (required)",
  "end_at": "ISO-8601 (required)",
  "request_key": "string (required, idempotency key)"
}
```

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "inventory_item_id": "uuid",
    "tenant_user_id": "uuid",
    "held_units": 1,
    "start_at": "ISO-8601",
    "end_at": "ISO-8601",
    "expires_at": "ISO-8601",
    "status": "active",
    "created_at": "ISO-8601"
  }
}
```

**Errors:** 409 (insufficient capacity, duplicate request_key), 429 (throttled)

---

### POST /holds/{id}/confirm
Convert a hold into a confirmed booking. Issues the initial bill.

**Auth:** Authenticated

**Request:**
```json
{
  "request_key": "string (required, idempotency key)"
}
```

**Response 200:** Hold object with `status: "converted"` and `confirmed_booking_id`.

**Errors:** 404, 409 (hold not active), 410 (hold expired)

---

### POST /holds/{id}/release
Release a hold and free the reserved capacity.

**Auth:** Authenticated

**Response 200:** `{ "data": { "message": "Hold released" } }`

---

### GET /holds/{id}
Get hold details.

**Auth:** Authenticated

---

## Bookings

### GET /bookings
List bookings. Tenants see their own; managers/admins see all in org.

**Auth:** Authenticated

**Query:** `page`, `per_page`, `status`, `inventory_item_id`, `tenant_user_id`

**Response 200:**
```json
{
  "data": [{
    "id": "uuid",
    "inventory_item_id": "uuid",
    "tenant_user_id": "uuid",
    "source_hold_id": "uuid | null",
    "status": "confirmed | active | completed | canceled | no_show",
    "start_at": "ISO-8601",
    "end_at": "ISO-8601",
    "booked_units": 1,
    "currency": "USD",
    "base_amount": "500.00",
    "final_amount": "500.00",
    "cancellation_fee_amount": "0.00",
    "no_show_penalty_amount": "0.00",
    "checked_in_at": "ISO-8601 | null",
    "canceled_at": "ISO-8601 | null",
    "completed_at": "ISO-8601 | null",
    "no_show_marked_at": "ISO-8601 | null",
    "created_at": "ISO-8601",
    "updated_at": "ISO-8601"
  }],
  "meta": { ... }
}
```

---

### GET /bookings/{id}
Get booking details.

**Auth:** Authenticated

---

### POST /bookings/{id}/check-in
Check in a guest. Only allowed from `confirmed` status.

**Auth:** Authenticated | **RBAC:** CHECK_IN

**Response 200:** Booking with `status: "active"` and `checked_in_at` timestamp.

---

### POST /bookings/{id}/complete
Complete a booking. Only allowed from `active` status.

**Auth:** Authenticated | **RBAC:** MANAGE_BOOKINGS

---

### POST /bookings/{id}/cancel
Cancel a booking. Tenants can cancel their own; managers can cancel any. A cancellation fee applies if less than 24 hours before start.

**Auth:** Authenticated

**Response 200:** Booking with `status: "canceled"`, `cancellation_fee_amount`.

---

### POST /bookings/{id}/no-show
Mark a booking as no-show. Applies penalty fee. Only after grace period has elapsed.

**Auth:** Authenticated | **RBAC:** MARK_NOSHOW

**Response 200:** Booking with `status: "no_show"`, `no_show_penalty_amount`.

---

### POST /bookings/{id}/reschedule
Reschedule a booking using a new hold.

**Auth:** Authenticated

**Request:**
```json
{
  "new_hold_id": "uuid (required)"
}
```

---

## Bills

### GET /bills
List bills.

**Auth:** Authenticated | **RBAC:** VIEW_FINANCE or tenant (own bills)

**Query:** `page`, `per_page`, `status`, `bill_type`, `booking_id`, `tenant_user_id`

**Response 200:**
```json
{
  "data": [{
    "id": "uuid",
    "booking_id": "uuid | null",
    "tenant_user_id": "uuid",
    "bill_type": "initial | recurring | supplemental | penalty",
    "status": "open | partially_paid | paid | partially_refunded | voided",
    "currency": "USD",
    "original_amount": "500.00",
    "outstanding_amount": "500.00",
    "due_at": "ISO-8601 | null",
    "issued_at": "ISO-8601",
    "paid_at": "ISO-8601 | null",
    "voided_at": "ISO-8601 | null"
  }],
  "meta": { ... }
}
```

---

### POST /bills
Create a supplemental bill for a booking.

**Auth:** Authenticated | **RBAC:** MANAGE_BILLING

**Request:**
```json
{
  "booking_id": "uuid (required)",
  "amount": "100.00 (required)",
  "reason": "string (required)"
}
```

---

### GET /bills/{id}
Get bill details.

---

### POST /bills/{id}/void
Void an open/partially-paid bill.

**Auth:** Authenticated | **RBAC:** MANAGE_BILLING

---

### GET /bills/{id}/pdf
Download bill as PDF.

**Auth:** Authenticated

**Response:** `Content-Type: application/pdf`

---

## Payments

### POST /payments
Initiate a payment for a bill.

**Auth:** Authenticated

**Request:**
```json
{
  "bill_id": "uuid (required)",
  "amount": "500.00 (required)",
  "currency": "USD (required)"
}
```

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "bill_id": "uuid",
    "request_id": "uuid",
    "status": "pending",
    "currency": "USD",
    "amount": "500.00",
    "created_at": "ISO-8601"
  }
}
```

---

### POST /payments/callback
Payment provider webhook. Verifies signature, updates payment status, creates ledger entry.

**Auth:** Public (signature-verified via `X-Payment-Signature` header)

**Request:**
```json
{
  "request_id": "string (required)",
  "status": "succeeded | failed | rejected (required)",
  "amount": "500.00 (required)",
  "currency": "USD (required)"
}
```

**Errors:** 401 (invalid signature), 409 (amount/currency mismatch)

---

### GET /payments
List payments.

**Auth:** Authenticated | **RBAC:** VIEW_FINANCE or tenant (own payments)

---

### GET /payments/{id}
Get payment details.

---

## Refunds

### POST /refunds
Issue a refund against a bill.

**Auth:** Authenticated | **RBAC:** PROCESS_REFUND

**Request:**
```json
{
  "bill_id": "uuid (required)",
  "amount": "50.00 (required)",
  "reason": "string (required)"
}
```

---

### GET /refunds
List refunds.

**Auth:** Authenticated | **RBAC:** VIEW_FINANCE

---

### GET /refunds/{id}
Get refund details.

---

## Ledger

### GET /ledger
List all ledger entries for the organization.

**Auth:** Authenticated | **RBAC:** VIEW_FINANCE

**Query:** `page`, `per_page`, `entry_type`, `booking_id`, `bill_id`

**Response 200:**
```json
{
  "data": [{
    "id": "uuid",
    "entry_type": "bill_issued | payment_received | refund_issued | penalty_applied | bill_voided",
    "amount": "500.00",
    "currency": "USD",
    "booking_id": "uuid | null",
    "bill_id": "uuid | null",
    "payment_id": "uuid | null",
    "refund_id": "uuid | null",
    "occurred_at": "ISO-8601"
  }],
  "meta": { ... }
}
```

---

### GET /ledger/bill/{billId}
Get ledger entries for a specific bill.

---

### GET /ledger/booking/{bookingId}
Get ledger entries for a specific booking.

---

## Notifications

### GET /notifications
List notifications for the authenticated user.

**Auth:** Authenticated

**Query:** `page`, `per_page`

**Response 200:**
```json
{
  "data": [{
    "id": "uuid",
    "event_code": "booking.confirmed",
    "title": "string",
    "body": "string",
    "status": "pending | delivered | read",
    "scheduled_for": "ISO-8601",
    "delivered_at": "ISO-8601 | null",
    "read_at": "ISO-8601 | null",
    "created_at": "ISO-8601"
  }],
  "meta": { ... }
}
```

---

### POST /notifications/{id}/read
Mark a notification as read.

**Auth:** Authenticated

---

### GET /notifications/preferences
List notification preferences for the authenticated user.

**Auth:** Authenticated

**Response 200:**
```json
{
  "data": [{
    "id": "uuid",
    "event_code": "booking.confirmed",
    "is_enabled": true,
    "dnd_start_local": "21:00",
    "dnd_end_local": "08:00"
  }]
}
```

---

### PUT /notifications/preferences/{eventCode}
Update a notification preference.

**Auth:** Authenticated

**Request:**
```json
{
  "enabled": true,
  "dnd_start": "22:00",
  "dnd_end": "07:00"
}
```

---

## Settings

### GET /settings
Get organization settings.

**Auth:** Authenticated | **RBAC:** VIEW_SETTINGS

**Response 200:**
```json
{
  "data": {
    "id": "uuid",
    "organization_id": "uuid",
    "max_devices_per_user": 5,
    "hold_duration_minutes": 10,
    "cancellation_fee_pct": "20.00",
    "no_show_fee_pct": "50.00",
    "no_show_first_day_rent_enabled": true,
    "no_show_grace_period_minutes": 30,
    "allow_partial_payments": true,
    "recurring_bill_day": 1,
    "recurring_bill_hour": 9,
    "max_booking_duration_days": 365,
    "booking_attempts_per_item_per_minute": 10,
    "terminals_enabled": false,
    "created_at": "ISO-8601",
    "updated_at": "ISO-8601"
  }
}
```

---

### PUT /settings
Update organization settings.

**Auth:** Authenticated | **RBAC:** MANAGE_SETTINGS

**Request:** Any subset of the settings fields above.

**Validation:**
- `max_devices_per_user`: 1-5
- `hold_duration_minutes`: 1-60
- `cancellation_fee_pct`: 0.00-100.00
- `no_show_fee_pct`: 0.00-100.00

---

## Terminals

### GET /terminals
List terminals.

**Auth:** Authenticated | **RBAC:** MANAGE_TERMINALS

**Query:** `page`, `per_page`, `location_group`, `is_active`

---

### POST /terminals
Register a new terminal.

**Auth:** Authenticated | **RBAC:** MANAGE_TERMINALS

**Request:**
```json
{
  "terminal_code": "string (required, unique)",
  "display_name": "string (required)",
  "location_group": "string (required)",
  "language_code": "string (optional, default: en)",
  "accessibility_mode": false
}
```

---

### GET /terminals/{id}
Get terminal details.

---

### PUT /terminals/{id}
Update terminal settings.

---

### GET /terminal-playlists
List playlists.

**Auth:** Authenticated | **RBAC:** MANAGE_TERMINALS

---

### POST /terminal-playlists
Create a playlist.

---

### POST /terminal-transfers
Initiate a package transfer to a terminal.

**Auth:** Authenticated | **RBAC:** MANAGE_TERMINALS

**Request:**
```json
{
  "terminal_id": "uuid (required)",
  "package_name": "string (required)",
  "checksum": "string (required)",
  "total_chunks": "integer (required)"
}
```

---

### POST /terminal-transfers/{id}/chunk
Upload a chunk of the package.

**Request:**
```json
{
  "chunk_index": "integer (required, 0-based)",
  "chunk_data": "string (required, base64-encoded)"
}
```

---

### POST /terminal-transfers/{id}/pause
Pause a transfer.

---

### POST /terminal-transfers/{id}/resume
Resume a paused transfer.

---

### GET /terminal-transfers/{id}
Get transfer status.

---

## Reconciliation

### POST /reconciliation/run
Trigger a reconciliation run. Compares ledger entries against bills/payments.

**Auth:** Authenticated | **RBAC:** VIEW_FINANCE

---

### GET /reconciliation/runs
List reconciliation runs.

**Query:** `page`, `per_page`

---

### GET /reconciliation/runs/{id}
Get reconciliation run details.

---

### GET /reconciliation/runs/{id}/csv
Download reconciliation report as CSV.

**Response:** `Content-Type: text/csv`

---

## Audit Logs

### GET /audit-logs
List audit log entries.

**Auth:** Authenticated | **RBAC:** VIEW_AUDIT

**Query:** `page`, `per_page`, `actor_user_id`, `action_code`, `object_type`

**Response 200:**
```json
{
  "data": [{
    "id": "uuid",
    "actor_user_id": "uuid | null",
    "actor_username_snapshot": "string",
    "action_code": "AUTH_LOGIN | BOOKING_CHECKED_IN | ...",
    "object_type": "Booking | Bill | User | ...",
    "object_id": "uuid",
    "created_at": "ISO-8601"
  }],
  "meta": { ... }
}
```

---

## Backups

### POST /backups
Create an encrypted backup of the organization's data.

**Auth:** Authenticated | **RBAC:** MANAGE_BACKUPS

**Response 201:**
```json
{
  "data": {
    "filename": "backup_uuid_20260409_120000.enc",
    "created_at": "ISO-8601",
    "tables": ["bills", "bookings", "users", "..."]
  }
}
```

---

### GET /backups
List available backups.

**Auth:** Authenticated | **RBAC:** MANAGE_BACKUPS

**Query:** `page` (default 1), `per_page` (default 25, max 100)

---

### POST /backups/preview
Preview what a backup restore would contain.

**Auth:** Authenticated | **RBAC:** MANAGE_BACKUPS

**Request:**
```json
{
  "filename": "backup_uuid_20260409_120000.enc (required)"
}
```

**Response 200:**
```json
{
  "data": {
    "metadata": { "organization_id": "uuid", "created_at": "ISO-8601", "created_by": "admin" },
    "record_counts": { "bills": 42, "bookings": 15, "users": 8 }
  }
}
```

---

### POST /backups/restore
Restore organization data from an encrypted backup.

**Auth:** Authenticated | **RBAC:** MANAGE_BACKUPS

**Request:**
```json
{
  "filename": "backup_uuid_20260409_120000.enc (required)"
}
```

---

## Metrics

### GET /metrics
Get operational metrics for the organization.

**Auth:** Authenticated | **RBAC:** VIEW_AUDIT

---

## Roles and Permissions

| Action | administrator | property_manager | finance_clerk | tenant |
|--------|:---:|:---:|:---:|:---:|
| VIEW_OWN | Y | - | - | Y |
| VIEW_ORG | Y | Y | Y | - |
| MANAGE_INVENTORY | Y | Y | - | - |
| MANAGE_BOOKINGS | Y | Y | - | - |
| MANAGE_BILLING | Y | Y | - | - |
| MANAGE_USERS | Y | - | - | - |
| VIEW_FINANCE | Y | - | Y | - |
| EXPORT_FINANCE | Y | - | Y | - |
| MANAGE_TERMINALS | Y | Y | - | - |
| MANAGE_SETTINGS | Y | - | - | - |
| VIEW_SETTINGS | Y | Y | Y | - |
| VIEW_AUDIT | Y | - | - | - |
| MANAGE_BACKUPS | Y | - | - | - |
| PROCESS_REFUND | Y | Y | Y | - |
| MARK_NOSHOW | Y | Y | - | - |
| CHECK_IN | Y | Y | - | - |

## Error Response Format

All errors follow:
```json
{
  "code": 401,
  "message": "Human-readable error message",
  "details": null
}
```

UUIDs in error messages are masked (last 4 chars only). Stack traces are never returned.

**Common codes:** 401 (unauthenticated), 403 (forbidden), 404 (not found), 409 (conflict/invalid state), 422 (validation/bad enum), 429 (throttled), 500 (internal)

## Pagination

All list endpoints support:
- `page` (integer, default: 1)
- `per_page` (integer, default: 25, max: 100)

Response metadata:
```json
{
  "meta": {
    "page": 1,
    "per_page": 25,
    "total": 142,
    "has_next": true
  }
}
```

## Data Types

- **IDs:** UUID v4 strings
- **Timestamps:** ISO-8601 (`2026-04-09T16:00:00+00:00`)
- **Money:** Decimal strings (`"500.00"`) to preserve precision
- **Times:** `HH:MM` 24-hour format (for DND windows)
- **Enums:** Lowercase snake_case strings
