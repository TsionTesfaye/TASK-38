# design.md

## 1. System Overview

Local RentOps Billing & Booking Platform is a fully offline, role-based full-stack application for managing rentable assets such as storage units, small equipment, and short-term spaces. It supports inventory setup, availability search, booking placement, booking hold management, rescheduling, cancellation, no-show handling, billing, payments, refunds, reconciliation, in-app notifications, finance exports, and optional display terminal content management.

The system serves these primary roles:

- Administrator
- Property Manager
- Tenant
- Finance Clerk

The system uses:

- Frontend: React web application
- Backend: Symfony REST-style API
- Database: MySQL
- File storage: local disk for PDFs, exports, backups, logs, and terminal packages
- Background execution: Symfony console commands / scheduler
- Authentication: local username/password only
- Notifications: in-app only
- Payment integration: offline external processor simulator only

The platform must function without internet access. No external APIs, no third-party authentication, no cloud queues, no online notification providers, no SaaS payment gateways, and no remote monitoring services are allowed.

Primary business capabilities:

- mobile-friendly local login with username/password
- tenant portal for booking, rescheduling, canceling, paying, and downloading receipts/invoices
- manager console for inventory, calendars, availability, exceptions, no-show handling, and refund/penalty actions
- finance workspace for bills, payments, refunds, ledger review, reconciliation, and CSV exports
- strict 10-minute booking hold flow with automatic release of unconfirmed inventory
- billing support for initial, recurring, supplemental, and penalty bills
- offline processor simulator with local shared-secret signature verification and idempotent callbacks
- in-app notification center with opt-in/out, DND windows, and delivery/read status
- optional display terminal registration, grouping, playlists, and offline package transfer with pause/resume
- immutable auditability, baseline operational metrics, and encrypted backup/restore

---

## 2. Design Goals

- Full offline functionality with zero dependence on external services
- Clear separation between controllers, services, repositories, jobs, storage, and UI
- Strict server-side validation, authorization, concurrency control, and financial correctness
- Deterministic state machines for holds, bookings, bills, payments, refunds, sessions, notifications, terminal transfers, and scheduled jobs
- Strong auditability with append-only financial and security history where required
- Docker-first runtime that starts cleanly via `docker compose up`
- Explicit module boundaries so implementation can be reviewed against the prompt and QA criteria
- No silent fallbacks, no placeholder-only workflows, and no UI-only enforcement
- Clear API and domain contracts so implementation, tests, and docs remain aligned
- Future-proof structure so storage or processor adapters can evolve without rewriting core business logic

---

## 3. Scope, Deployment, and Trust Model

### 3.1 Deployment Modes

Supported deployment modes:

- single workstation
- local network deployment within one business environment

In both modes:

- frontend, backend, and database run locally through Docker Compose
- file-based assets are stored on mounted local volumes
- all users authenticate against the local system
- the backend clock is the source of truth for holds, cancellations, recurring billing, DND release, reconciliation, and retention jobs

### 3.2 Trust Boundary

The system is offline, but the frontend is still treated as semi-trusted.

Trust rules:

- UI visibility is not security
- business correctness is enforced only in backend services
- all reads and writes must enforce role and ownership scope
- database is never directly accessible to users
- client-supplied IDs, totals, statuses, or organization context are never trusted without backend verification

### 3.3 Time Standard

- all timestamps are stored in UTC
- UI renders timestamps in configured business timezone
- recurring billing and scheduled jobs run in configured business timezone
- cancellation windows, hold expiration, no-show grace periods, and DND decisions are computed using backend time only

### 3.4 Offline Operating Constraint

The system must remain usable with no internet connection.

Therefore:

- all authentication is local
- all payment simulation is local
- all notifications are local
- all file generation is local
- all metrics and logs are local
- no feature may depend on remote fetches to complete a core workflow

---

## 4. High-Level Architecture

### 4.1 Layered Architecture

```text
React Web UI
    ↓
API Client / Auth State / Route Guards / Feature Modules
    ↓
Symfony Controllers / Security / Request DTO Validation
    ↓
Application Services
    ↓
Repositories / Transaction + Locking Layer
    ↓
MySQL + Local File Storage
    ↓
Background Jobs / Scheduler / PDF Generator / Backup Engine / Terminal Package Processor
```

### 4.2 Backend Modules

- auth
- users
- organizations
- inventory
- holds
- bookings
- billing
- recurring_billing
- payments
- refunds
- ledger
- reconciliation
- notifications
- subscriptions
- terminals
- terminal_packages
- reports
- audit
- metrics
- backup_restore
- settings
- scheduler

### 4.3 Frontend Modules

- auth and session
- tenant portal
- manager console
- finance console
- admin console
- notifications center
- terminal management
- shared app shell, tables, filters, dialogs, forms, charts, and export/PDF flows

### 4.4 Architecture Rules

Mandatory rules:

- controllers only parse requests, invoke services, and return structured responses
- services contain business logic, validation, authorization, transitions, and financial rules
- repositories contain DB access only
- file operations are isolated in storage/PDF/backup services
- frontend never talks directly to MySQL or local storage volumes
- every read path and write path enforces scope and masking rules
- all critical actions are auditable
- tests target service rules and API behavior, not just UI happy paths

Forbidden:

- DB access in controllers
- financial calculations in React components
- authorization enforced only in the UI
- service bypasses for “internal use only”
- silent fallback from validated flow to permissive/mock logic
- partial execution without explicit success/failure state

---

## 5. Repository and Package Layout

### 5.1 Required Root Structure

```text
prompt.md
questions.md
docs/
  design.md
  api-spec.md
fullstack/
  README.md
  docker-compose.yml
  run_tests.sh
  frontend/
  backend/
  unit_tests/
  API_tests/
  storage/
```

### 5.2 Backend Layout

```text
backend/
  config/
  migrations/
  public/
  src/
    Controller/
    DTO/
    Entity/
    Enum/
    Exception/
    Message/
    MessageHandler/
    Repository/
    Security/
    Service/
    Scheduler/
    PDF/
    Storage/
    Audit/
    Metrics/
    ValueObject/
    Command/
  tests/
```

### 5.3 Frontend Layout

```text
frontend/
  src/
    app/
    api/
    components/
    features/
      auth/
      inventory/
      bookings/
      billing/
      payments/
      refunds/
      notifications/
      terminals/
      reports/
      admin/
    hooks/
    routes/
    state/
    utils/
    types/
```

### 5.4 Project Structure Principles

- each module has clear ownership and API boundaries
- no giant mixed-responsibility files
- DTOs, services, API tests, and docs must stay aligned
- runtime, tests, and README must use the same commands and ports
- storage directories must be explicit and Docker-mounted

---

## 6. Domain Model

## 6.1 Organization

Fields:

- id
- code
- name
- is_active
- created_at
- updated_at
- default_currency

Rules:

- every user belongs to one organization
- all business records are organization-scoped
- no cross-organization references are allowed
- inactive organizations are retained for audit/history but cannot receive new transactional data
- all financial records MUST use organization currency
- mismatched currency requests must be rejected

## 6.2 User

Fields:

- id
- organization_id
- username
- password_hash
- display_name
- role
- is_active
- is_frozen
- password_changed_at
- created_at
- updated_at

Rules:

- username is globally unique
- password stored using bcrypt
- frozen users cannot authenticate or execute critical actions
- password change invalidates all sessions

## 6.3 DeviceSession

Fields:

- id
- user_id
- refresh_token_hash
- device_label
- client_device_id
- issued_at
- expires_at
- last_seen_at
- revoked_at_nullable

Rules:

- access token lifetime = 15 minutes
- refresh token lifetime = 14 days
- max 5 active device sessions per user
- oldest active session is revoked when the cap would be exceeded
- all sessions are revoked on password change

## 6.4 InventoryItem

Fields:

- id
- organization_id
- asset_code
- name
- asset_type
- location_name
- capacity_mode
- total_capacity
- timezone
- is_active
- created_at
- updated_at

Capacity mode enum:

- discrete_units
- single_slot

Rules:

- inventory item belongs to exactly one organization
- inactive inventory cannot receive new holds or bookings
- throttling is enforced per inventory item
- pricing references must be valid for the item before billing actions are allowed

## 6.5 BookingHold

Fields:

- id
- organization_id
- inventory_item_id
- tenant_user_id
- request_key
- held_units
- start_at
- end_at
- expires_at
- status
- created_at
- confirmed_booking_id_nullable

Status enum:

- active
- expired
- released
- converted

Rules:

- hold lasts exactly 10 minutes from creation
- inventory is pre-deducted when hold becomes active
- hold must expire server-side even if UI timer is stale
- duplicate `(tenant_user_id, request_key)` within 24 hours must return an idempotent response
- converted holds cannot be reused

## 6.6 Booking

Fields:

- id
- organization_id
- inventory_item_id
- tenant_user_id
- source_hold_id_nullable
- status
- start_at
- end_at
- booked_units
- currency
- base_amount
- final_amount
- cancellation_fee_amount
- no_show_penalty_amount
- created_at
- updated_at
- canceled_at_nullable
- completed_at_nullable
- no_show_marked_at_nullable
- checked_in_at_nullable

Status enum:

- confirmed
- active
- completed
- canceled
- no_show

Rules:

- tenant bookings MUST originate from a hold
- managers MAY create bookings directly (bypass hold) but must still pass capacity validation
- invalid state jumps are forbidden
- booking cannot exceed inventory capacity for the requested range
- canceled and no_show are terminal
- completed is terminal for operational workflow
- booking confirmation from hold MUST be idempotent using the same request_key

Additional Rules:

- confirmed -> active
- active -> completed
- confirmed -> canceled
- active -> canceled
- active -> no_show
- no transitions allowed from terminal states
- confirmed cannot transition directly to completed
- confirmed -> active occurs when check-in is recorded (checked_in_at is set)

No-show rule:

- booking is no_show if:
  checked_in_at IS NULL AND start_at + no_show_grace_period < NOW

## 6.7 BookingEvent

Fields:

- id
- booking_id
- actor_user_id
- event_type
- before_status_nullable
- after_status_nullable
- details_json_nullable
- created_at

Event types:

- created
- hold_converted
- rescheduled
- activated
- completed
- canceled
- no_show_marked
- cancellation_fee_applied
- no_show_penalty_applied

Rules:

- all state changes and major booking actions create events
- used for audit, timeline, and troubleshooting

## 6.8 Bill

Fields:

- id
- organization_id
- booking_id_nullable
- tenant_user_id
- bill_type
- status
- currency
- original_amount
- outstanding_amount
- due_at_nullable
- issued_at
- paid_at_nullable
- voided_at_nullable
- pdf_path_nullable

Bill type enum:

- initial
- recurring
- supplemental
- penalty

Status enum:

- open
- paid
- partially_paid
- partially_refunded
- voided

State Machine:

- open -> partially_paid -> paid
- open -> voided
- partially_paid -> paid
- partially_paid -> voided (ONLY after refunds)
- paid -> partially_refunded
- voided = terminal

Rules:

- bills are the authoritative billing documents
- recurring bills are generated monthly on the 1st at 9:00 AM local business time
- penalty bills can be created from cancellation or no-show rules
- receipts/invoices must render state clearly as Paid, Partially Refunded, or Voided
- voided bills remain auditable and may not accept new payments
- outstanding_amount is a derived value from ledger and payments
- if stored, it MUST be updated atomically with all financial writes
- ledger remains the source of truth

Additional Rules:

- no payments allowed on voided bills
- bill may only be voided if:
  - no successful payments exist
  OR
  - all payments have been fully refunded
- all void actions must create ledger entry

## 6.9 Payment

Fields:

- id
- organization_id
- bill_id
- external_reference
- request_id
- status
- currency
- amount
- signature_verified
- received_at
- processed_at
- raw_callback_payload_json
- created_at

Status enum:

- pending
- succeeded
- failed
- rejected

State Machine:

- pending -> succeeded
- pending -> failed
- pending -> rejected

Terminal states:

- succeeded
- failed
- rejected

Rules:

- callbacks are idempotent by request_id
- signature verification uses local shared secret
- amount/currency must match bill rules exactly unless partial payments are enabled
- duplicate callback must not duplicate ledger effects
- failed/rejected payments remain visible for audit and troubleshooting
- successful payment MUST create a ledger entry atomically

Additional Rules:

- no transitions allowed from terminal states
- no reprocessing allowed

## 6.10 Refund

Fields:

- id
- organization_id
- bill_id
- payment_id_nullable
- amount
- reason
- status
- created_by_user_id
- created_at

Status enum:

- issued
- rejected

Rules:

- cumulative refunds cannot exceed total successful paid amount
- full and partial refunds are supported
- issued refunds are immutable
- every refund creates ledger and audit entries
- refund creation MUST atomically:
  - create refund record
  - create ledger entry
  - update bill state

Additional Rules:

- refunds are BILL-level (not tied strictly to one payment)
- refund is created directly as issued (no async pending lifecycle required)

## 6.11 LedgerEntry

Fields:

- id
- organization_id
- booking_id_nullable
- bill_id_nullable
- payment_id_nullable
- refund_id_nullable
- entry_type
- amount
- currency
- occurred_at
- metadata_json_nullable

Entry type enum:

- bill_issued
- payment_received
- refund_issued
- penalty_applied
- bill_voided

Rules:

- ledger is append-only
- balances are derived from ledger and bill state together
- financial history is never updated in place

## 6.12 NotificationPreference

Fields:

- id
- user_id
- event_code
- is_enabled
- dnd_start_local
- dnd_end_local
- updated_at

Rules:

- notifications are in-app only
- default DND = 9:00 PM to 8:00 AM local time
- DND delays delivery, not creation

## 6.13 Notification

Fields:

- id
- organization_id
- user_id
- event_code
- title
- body
- status
- scheduled_for
- delivered_at_nullable
- read_at_nullable
- created_at

Status enum:

- pending
- delivered
- read

Rules:

- delivered means persisted and visible in the in-app center
- read is user-driven
- delayed notifications must be released after DND ends

## 6.14 Terminal

Fields:

- id
- organization_id
- terminal_code
- display_name
- location_group
- language_code
- accessibility_mode
- is_active
- last_sync_at_nullable
- created_at
- updated_at

Rules:

- terminal registration is optional and feature-gated
- terminals are grouped by location
- accessibility-friendly layouts are configured per package/playlist

## 6.15 TerminalPlaylist

Fields:

- id
- organization_id
- name
- location_group
- schedule_rule
- is_active
- created_at
- updated_at

Rules:

- playlist assignment is scoped by organization and location group
- schedule rules determine active content rotation at local time

## 6.16 TerminalPackageTransfer

Fields:

- id
- organization_id
- terminal_id
- package_name
- checksum
- total_chunks
- transferred_chunks
- status
- started_at
- completed_at_nullable

Status enum:

- pending
- in_progress
- paused
- completed
- failed

Rules:

- transfers support pause/resume
- progress is persisted by chunk count/index
- checksum must verify before marking complete
- failed transfers remain diagnosable and retryable

## 6.17 ReconciliationRun

Fields:

- id
- organization_id
- run_date
- status
- mismatch_count
- output_csv_path_nullable
- started_at
- completed_at_nullable

Status enum:

- running
- completed
- failed

Rules:

- reconciliation compares bill state, payment state, and ledger state daily
- mismatches are reviewable by Finance Clerk
- results are exportable as CSV

## 6.18 AuditLog

Fields:

- id
- organization_id
- actor_user_id_nullable
- actor_username_snapshot
- action_code
- object_type
- object_id
- before_json_nullable
- after_json_nullable
- client_device_id_nullable
- created_at

Rules:

- audit log is immutable
- security-sensitive and financial actions must always be logged
- enough snapshots must be kept so records remain meaningful after archival

## 6.19 Settings

Fields:

- id
- organization_id
- timezone
- allow_partial_payments
- cancellation_fee_pct
- no_show_fee_pct
- no_show_first_day_rent_enabled
- hold_duration_minutes
- no_show_grace_period_minutes
- max_devices_per_user
- recurring_bill_day
- recurring_bill_hour
- booking_attempts_per_item_per_minute
- terminals_enabled
- created_at
- updated_at

Default values:

- cancellation fee = 20%
- no-show fee = 50%
- hold duration = 10 minutes
- max devices = 5
- recurring bill schedule = 1st day at 09:00
- per-item throttle = 30 attempts per minute

## 6.20 InventoryPricing

Fields:

- id
- organization_id
- inventory_item_id
- rate_type
- amount
- currency
- effective_from
- effective_to

Rules:

- pricing must exist before booking is allowed
- base_amount is derived from pricing
- pricing must match organization currency
- overlapping pricing ranges must be rejected

---

## 7. Role Model and Authorization

### 7.1 Roles

#### Administrator

Capabilities:

- manage users
- manage system settings
- manage feature toggles
- view audit logs and metrics
- manage terminals
- run backups and restore previews
- override operational actions where policy allows

#### Property Manager

Capabilities:

- manage inventory
- view and manage availability calendars
- create/adjust bookings
- resolve exceptions
- mark no-shows
- create supplemental and penalty bills where allowed
- initiate refunds where allowed
- manage terminals within scope

#### Tenant

Capabilities:

- authenticate locally
- search availability
- create holds and bookings
- reschedule/cancel within allowed rules
- view own bills, payments, receipts, and notifications
- submit payment through the processor simulator workflow
- download own receipts/invoices

#### Finance Clerk

Capabilities:

- view bills, payments, refunds, and ledger entries
- run reconciliation
- export finance CSVs
- review mismatches
- issue/approve refunds if allowed by policy

### 7.2 Authorization Rules

- all records are organization-scoped
- tenant users can read only self-owned bookings, bills, payments, refunds, and notifications
- manager and finance roles can read only within organization and allowed module scope
- every read path enforces scope, not only writes
- controller code must never trust client-supplied organization or ownership IDs
- service layer is the final authorization authority

### 7.3 Permission Strategy

This system uses primary roles plus explicit policy checks.

Authorization decisions combine:

- authenticated user role
- organization scope
- object ownership
- current entity state
- feature flags/settings when relevant

Examples:

- Tenant can cancel only own eligible booking
- Property Manager can mark no-show only on organization-owned booking
- Finance Clerk can export finance data only within organization
- Administrator can freeze/unfreeze accounts but cannot bypass audit

---

## 8. Authentication, Sessions, and Security

### 8.1 Authentication Model

- username/password only
- bcrypt password hashing
- JWT access tokens with 15-minute expiry
- refresh tokens with 14-day expiry
- no email login, no social login, no OAuth, no magic links

### 8.2 Session Rules

- max 5 active device sessions per account
- oldest active session revoked when cap is exceeded
- all sessions revoked on password change
- access tokens are short-lived and renewed through backend-validated refresh flow

### 8.3 Password Change Behavior

- current password must be validated
- all refresh tokens revoked immediately
- all devices forced to re-authenticate

### 8.4 Account Freeze Rules

- frozen users cannot log in
- frozen users cannot execute critical finance/admin actions
- freeze/unfreeze actions are auditable

### 8.5 Sensitive Data and Masking

Sensitive UI/log fields are masked where relevant.

Examples:

- identifiers shown as last 4 characters only in logs/UI summaries where required
- secrets never returned in API responses
- raw callback/signature details retained only where needed for audit/debug and not exposed to normal users

### 8.6 Security Boundaries

- backend is authoritative for all financial and inventory decisions
- route guards are not sufficient by themselves
- object-level checks are mandatory
- no “internal-only” API endpoints may skip auth/authorization

---

## 9. Booking and Availability Design

### 9.1 Availability Calculation

Availability is based on:

- active inventory items
- overlapping active holds
- overlapping confirmed/active bookings
- capacity mode and total capacity

Rules:

- active holds reduce availability immediately
- expired/released holds restore availability
- no overselling under concurrent requests
- all calculations happen in backend transaction scope

### 9.2 Hold Flow

Flow:

1. tenant selects inventory and time range
2. backend validates inventory activity, range validity, throttle, and available capacity
3. hold is created and inventory is pre-deducted
4. UI displays 10-minute countdown
5. if confirmed before expiry, hold converts to booking
6. if expired or released, availability is restored

Rules:

- hold expiration is enforced server-side
- UI timer is informational only
- confirm endpoint fails if hold has expired or been released
- hold creation is idempotent via request_key
- booking duration must not exceed max_booking_duration_days from settings

Critical Rule:

- confirmation MUST validate expires_at >= NOW

### 9.3 Booking State Machine

Allowed transitions:

- confirmed -> active
- active -> completed
- confirmed -> canceled
- active -> canceled
- active -> no_show

Forbidden:

- completed -> active
- canceled -> active
- no_show -> active
- confirmed -> completed directly

### 9.4 Cancellation Rules

- free cancellation if booking start is at least 24 hours away
- otherwise a 20% cancellation fee applies
- rule is computed using backend timestamps only
- cancellation fee produces a penalty bill when applicable

### 9.5 No-Show Rules

- no-show determined after configured grace period beyond start time
- no-show penalty = 50% fee plus first day’s rent when enabled
- penalty bill MUST be generated automatically
- booking moved to `no_show` terminal state

### 9.6 Rescheduling

Rescheduling is modeled as controlled booking modification.

Rules:

- capacity must be revalidated
- hold/booking timing must remain consistent
- billing deltas may create supplemental or adjusted bills if policy requires it
- audit trail must preserve original booking history

Additional Rules:

- only allowed in confirmed state
- requires new hold validation
- must execute atomically:
  - release old allocation
  - acquire new allocation

---

## 10. Billing, Payments, Refunds, and Financial Correctness

### 10.1 Bill Lifecycle

Flow:

- issue bill
- accept zero or more valid payments
- mark paid or partially_paid
- optionally refund
- optionally void if allowed by policy

Rules:

- no bill may become paid without valid payment processing
- outstanding amount derived from successful payments minus refunds
- PDF invoice/receipt regenerated or re-labeled as needed when state changes
- voided bills remain auditable and may not accept new payments

### 10.2 Bill Types

Supported bill types:

- initial
- recurring
- supplemental
- penalty

Meaning:

- initial = first charge tied to booking creation/confirmation
- recurring = monthly repeating charge
- supplemental = additional non-penalty charge created after original bill
- penalty = cancellation/no-show related charge

### 10.3 Recurring Billing

Requirement:

- monthly on the 1st at 9:00 AM local time

Design:

- scheduler identifies eligible recurring billing records
- creates exactly one recurring bill per period
- reruns are idempotent by organization + booking/account + period

Additional Rule:

- recurring billing stops when booking status != active
- generation must validate billing period overlap
- no duplicate billing for same period

### 10.4 Offline Processor Simulator

The processor simulator provides:

- local shared-secret signature validation
- idempotent callbacks
- amount/currency match checks
- configurable partial payment support
- explicit failure modes for testing/admin operations if feature-gated

Rules:

- simulator follows a real callback contract internally
- no payment success without signature and amount validation
- duplicate callback does not duplicate ledger effects

### 10.5 Refund Rules

- refunds can be full or partial
- total refunds cannot exceed total successful paid amount
- refund issue updates bill state and ledger
- immutable audit trail required
- repeated refund attempts beyond remaining refundable amount must fail explicitly
- issuing a refund MUST update bill status:
  - paid → partially_refunded
  - partially_paid → partially_refunded (if applicable)

### 10.6 Financial Source of Truth

- ledger is append-only
- bill state is derived and cross-validated against ledger/payment state
- reconciliation detects mismatches instead of silently repairing them

---

## 11. Reconciliation, Reporting, and Exports

### 11.1 Daily Reconciliation

Checks:

- bill outstanding vs payment/refund totals
- bill paid status vs successful payment totals
- ledger totals vs bill/payment/refund events

Outputs:

- reconciliation run record
- mismatch list
- CSV export

### 11.2 Finance Exports

Supported:

- CSV for accounting/reconciliation
- PDF receipts/invoices per bill

Rules:

- exports respect authorization and scope
- exported financial data must match backend-calculated source of truth
- no export bypasses masking rules

### 11.3 PDF Generation

Receipts and invoices must be downloadable as PDF and clearly marked:

- Paid
- Partially Refunded
- Voided

Rules:

- PDFs are produced server-side
- generated files are stored locally with tracked path/metadata
- regeneration must be idempotent and auditable

### 11.4 Report Consistency Rules

- generated report contents must come from the same validated service layer used by UI/API
- CSV/PDF formats must not reimplement business calculations separately in ad hoc code
- report filters must not allow out-of-scope data

---

## 12. Notification and Subscription Design

### 12.1 Notification Center

Users can:

- opt in/out per event type
- view notification history
- view delivery and read status
- configure DND window

All notifications are in-app only.

### 12.2 Delivery Semantics

Notification states:

- pending
- delivered
- read

Meaning:

- pending = created but deferred, usually due to DND or queue timing
- delivered = visible in notification center
- read = explicitly acknowledged/opened by user

### 12.3 DND Rules

Default DND:

- 9:00 PM to 8:00 AM local time

Rules:

- notifications generated during DND are scheduled for release after DND ends
- notifications are not dropped
- original event time is retained for audit/history

Edge case:

- if DND crosses midnight:
  start > end → (time >= start OR time < end)

### 12.4 Notification Generation Rules

Core booking/finance/terminal events should trigger notifications through explicit services.

Examples:

- hold created/expiring
- booking confirmed/canceled/no-show
- bill issued
- payment recorded
- refund issued/rejected
- reconciliation mismatch summary
- terminal package transfer completion/failure

---

## 13. Terminal and Offline Package Transfer Design

### 13.1 Terminal Feature Scope

If terminals are enabled, managers can:

- register terminals
- group terminals by location
- assign playlists
- schedule playlist rotations
- transfer offline packages to terminals

### 13.2 Playlist and Rotation

- playlist assignment is scoped by organization and location group
- schedule rules determine active content at local time
- multilingual and accessibility-friendly layouts are configured as playlist/package metadata

### 13.3 Package Transfer

Rules:

- transfers are chunked
- transfer can be paused/resumed
- progress is persisted
- checksum validated before marking complete
- failed transfers remain diagnosable and retryable

### 13.4 Terminal Integrity Rules

- terminal cannot receive package from another organization
- incomplete transfer cannot be marked complete
- paused/resumed transfer must preserve exact chunk progress
- terminal package history remains auditable

---

## 14. Audit, Metrics, Logging, and Diagnostics

### 14.1 Audit Coverage

Audit logs must cover:

- authentication events
- password changes
- booking creation, rescheduling, cancellation, and no-show marking
- bill issuance, voids, payments, refunds
- reconciliation runs
- exports
- settings changes
- terminal registrations and transfers
- backup and restore actions

### 14.2 Metrics

Baseline local metrics:

- API latency
- error rate
- queue depth / pending notifications / pending transfers
- reconciliation mismatch counts
- failed background job counts

### 14.3 Logging Rules

- structured logs only
- meaningful business context
- no raw passwords
- no unmasked sensitive IDs where masking policy applies
- logs stored locally and available for diagnosis/export

### 14.4 Observability Boundaries

Because the system is offline:

- no external APM or remote log sink is used
- health checks, job status, and log summaries must be visible through local admin/ops tooling where appropriate
- job failures must be explicit and testable

---

## 15. Backup and Restore Design

### 15.1 Backup Scope

Nightly encrypted backups include:

- MySQL dump/snapshot
- generated PDFs and exports if configured
- terminal asset/package storage
- backup manifest and checksum metadata

### 15.2 Encryption

- AES-256 encrypted dumps
- encryption material managed locally through secure config/process
- key version tracked in metadata where required

### 15.3 Restore Rules

- restore is full-system restore only
- no partial restore in initial version
- restore requires system maintenance lock
- restore preview may inspect metadata before execution if implemented
- restore actions are heavily audited

### 15.4 Backup Integrity Rules

- backup failure must be explicit
- corrupted or checksum-mismatched backup must not restore silently
- restore preview must not mutate the live system
- live restore must block conflicting scheduled jobs

---

## 16. Data Integrity and Concurrency Rules

### 16.1 Core Integrity Rules

- no cross-organization references
- no orphan financial records
- booking, bill, payment, refund, and ledger relationships must remain valid
- inventory item and booking must belong to same organization
- tenant-owned records must belong to tenant’s organization and identity context

### 16.2 Concurrency Rules

To prevent overselling:

- inventory checked inside DB transaction
- row-level locking used during hold/booking creation
- active holds included in capacity calculation
- per-item throttle limits attempts to 30 per minute

### 16.3 Idempotency Rules

Idempotent operations include:

- booking placement by request_key with 24-hour dedupe window
- payment callback handling by request identifier
- recurring bill generation by period
- scheduled reconciliation/report jobs by execution key

### 16.4 No Silent Conflict Handling

If an action fails because of:

- duplicate request
- stale hold
- insufficient capacity
- mismatched payment
- exhausted refund balance

the system must return an explicit structured result rather than silently adjusting behavior

### 16.5 Idempotency Cleanup

- background job runs hourly
- deletes keys older than 24h

---

## 17. API Design Principles

### 17.1 API Style

- REST-style JSON APIs
- DTO-based request/response contracts
- standard HTTP status codes
- structured JSON errors

Error shape:

```json
{
  "code": 403,
  "message": "Forbidden",
  "details": null
}
```

### 17.2 Security and Validation

- authenticated routes require valid JWT
- service layer validates role, organization scope, and object ownership
- request DTO validation handles shape and format
- domain/business rules are enforced in services
- controllers never trust client-supplied totals, statuses, ownership, or organization data

### 17.3 API Coverage Areas

- auth/session
- inventory/availability
- holds/bookings
- billing/payments/refunds
- notifications/subscriptions
- reconciliation/reports
- terminals/transfers
- audit/metrics
- settings/backup

### 17.4 API Contract Stability

- request/response DTOs are part of the contract
- schema drift across controller, frontend, tests, and docs is considered a defect
- no silent field additions/removals without coordinated update

---

## 18. Frontend UX and State Design

### 18.1 App Shell

Must provide:

- role-aware navigation
- current session/user context
- clear module separation
- notification access
- loading and error boundaries

### 18.2 Required States

All major views must support:

- loading
- empty
- validation feedback
- success feedback
- permission denied
- recoverable error state
- disabled/submitting state for critical actions

### 18.3 UI-Service Parity

Rules:

- UI should hide or disable impossible actions when state/permissions are already known
- backend remains the final authority
- hold countdown shown in UI but expiry decided only by backend
- billing/payment/refund UI must render real backend-calculated state only

### 18.4 Mobile-Friendliness

- login and tenant portal must be usable on small screens
- manager/finance/admin consoles are desktop-first but must still be usable on tablet widths
- major tables must remain readable or provide responsive stacked patterns where needed

### 18.5 Accessibility Rules

- semantic buttons and form controls
- keyboard support for dialogs and menus
- focus management for modals
- clear disabled/loading states
- no dead-end flows for core business tasks

---

## 19. Scheduler and Background Jobs

### 19.1 Job Types

- hold expiration cleanup
- recurring bill generation
- daily reconciliation
- DND notification release
- backup generation
- terminal transfer housekeeping
- no-show evaluation

### 19.2 Startup Reconciliation

On startup, the system should:

- expire stale holds
- release overdue DND notifications
- process missed recurring billing windows idempotently if required
- record/report failed prior jobs
- continue incomplete terminal transfers where resumable

### 19.3 Job Idempotency

Jobs must not create duplicate business artifacts for the same logical window.

Examples:

- one recurring bill per period
- one reconciliation run per organization/day unless explicitly rerun
- one hold expiration effect per hold
- one delivered notification per queued notification event

### 19.4 Scheduler Failure Rules

- job failure must be persisted and visible
- rerun safety must be designed explicitly
- no background job may partially mutate critical financial data without explicit completion/failure status

---

## 20. Error Handling Strategy

- validation failures return user-safe structured messages
- unauthorized and forbidden actions return 401/403
- missing records return 404
- state conflicts and duplicate submissions return 409 where appropriate
- payment, refund, reconciliation, backup, and transfer failures surface explicit status and message

No silent fallbacks:

- no partial booking confirmation without explicit result
- no payment callback accepted without validation
- no export/report generated with hidden omissions
- no transfer resume that ignores missing chunks silently
- no restore that silently skips assets or DB steps

---

## 21. Testing Strategy

### 21.1 Unit Tests

Cover:

- password hashing and verification
- session/token lifetime logic
- device session cap logic
- availability calculation
- hold expiration logic
- booking state machine
- cancellation fee calculation
- no-show penalty calculation
- bill outstanding amount derivation
- payment validation rules
- refund remaining balance logic
- ledger derivation helpers
- DND scheduling logic
- terminal transfer chunk/checksum logic
- backup manifest validation

### 21.2 API Tests

Cover:

- login success/failure/frozen-user handling
- refresh token rotation/revocation
- tenant self-only access boundaries
- manager/finance/admin scope enforcement
- hold creation success/failure/duplicate request_key
- booking confirm with expired hold
- valid and invalid booking transitions
- cancellation window enforcement
- no-show marking rules
- bill issuance and retrieval
- payment callback idempotency
- mismatched payment amount/currency rejection
- refund overage rejection
- reconciliation run outputs
- terminal transfer state transitions
- backup/restore preview authorization

### 21.3 Frontend/Integration Tests

Cover:

- auth routing and logout on invalid session
- tenant booking flow and hold countdown rendering
- tenant bill/payment/receipt screens
- manager inventory and calendar interactions
- finance reconciliation and export UI
- notifications center status rendering
- terminal transfer progress UI
- error and permission-denied states
- responsive login and tenant flow

### 21.4 End-to-End Flows

Must include:

- first admin bootstrap
- tenant login -> availability search -> hold -> booking confirm
- tenant cancellation with free and fee scenarios
- no-show detection and penalty bill generation
- recurring bill generation flow
- payment callback success and duplicate callback handling
- partial or full refund flow
- reconciliation mismatch review flow
- notification generation and DND-delayed delivery
- terminal package transfer pause/resume/complete
- backup run and restore preview flow

### 21.5 Required Test Structure

At repository root:

- unit_tests/
- API_tests/
- run_tests.sh

Rules:

- tests are runnable through one command
- output must show clear pass/fail summary
- every regression fix must include a regression test
- tests must cover negative and authorization paths, not only happy flows

---

## 22. Docker and Runtime Design

### 22.1 Docker Compose Services

Required services:

- frontend
- backend
- mysql

Optional supporting mounts:

- PDFs/exports volume
- terminal assets volume
- backup volume
- logs volume

### 22.2 Canonical Startup

- `docker compose up`

Rules:

- no manual DB bootstrapping outside documented startup
- no hidden environment assumptions
- no host-only DB dependency
- no runtime internet dependency

### 22.3 Bootstrap Path

On first clean startup:

- if no admin exists, system exposes first-run admin creation flow
- after first admin creation, bootstrap route is disabled
- bootstrap completion is audited

### 22.4 Environment Contract

README, Docker config, API ports, frontend URLs, and test instructions must match exactly.

If runtime/docs diverge, that is a delivery defect.

---

## 23. Future Integration Readiness

The design remains future-ready because:

- repositories isolate persistence
- services own workflows
- processor simulator is encapsulated
- masking, audit, backup, PDF generation, and reporting are modular
- frontend depends on DTOs and APIs, not DB implementation
- local file storage abstraction can later be swapped for another storage adapter if policy allows

---

## 24. Non-Negotiable Constraints

- no external APIs
- no mock-only production behavior
- all validation in backend services
- all reads enforce scope
- all writes enforce scope
- financial correctness must be ledger-backed
- field masking must be centralized and export-safe
- audit logs are immutable
- ledger is append-only
- hold expiry is backend authoritative
- critical actions must be explicit and auditable
- recurring billing is idempotent
- callbacks are idempotent
- session timeout and token expiry rules must match implementation and docs
- account cap = 5 active device sessions
- attachments/packages/backups must use explicit storage rules
- encrypted backups include DB plus configured local assets
- restore preview/read-only boundaries must be explicit
- Docker Compose is the canonical runtime path
