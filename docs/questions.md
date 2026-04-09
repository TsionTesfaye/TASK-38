# questions.md

## 1. Question: What is the exact ownership and tenancy model for all entities?

Assumption:  
The system is multi-tenant at the organization level. All core entities such as inventory, holds, bookings, bills, payments, refunds, notifications, terminal records, and reconciliation runs are strictly scoped by `organization_id`. Users belong to one organization, and cross-organization access is never allowed in normal operation.

Solution:  
- Add `organization_id` to all core tables
- Resolve organization context from the authenticated user, never from client input
- Enforce scope in all read and write service methods
- Reject all cross-organization access at the service layer


## 2. Question: How is inventory held and released during booking?

Assumption:  
Inventory is pre-deducted during hold creation and must be enforced server-side. Holds expire after 10 minutes if not confirmed.

Solution:  
- Create a `BookingHold` entity with `expires_at`
- Deduct inventory when the hold is created
- Restore inventory on hold expiration or explicit release
- Validate hold validity before booking confirmation
- Treat the UI countdown as informational only


## 3. Question: What defines “real-time availability” in an offline system?

Assumption:  
Real-time availability means immediate consistency inside the local system, not external synchronization.

Solution:  
- Compute availability from current database state
- Include active holds and active bookings in availability checks
- Use row-level locking during hold/booking creation
- Return the latest committed state via API responses
- Do not require websockets for correctness


## 4. Question: How are overlapping bookings handled?

Assumption:  
Bookings cannot exceed inventory capacity within the requested date/time range.

Solution:  
- Enforce overlap checks at the service layer
- Include capacity rules in transactional validation
- Prevent overbooking using row-level locking
- Reject conflicting requests explicitly


## 5. Question: What is the exact booking state machine?

Assumption:  
The booking entity begins at `confirmed`. Pre-confirmation lifecycle is handled entirely by the `BookingHold` entity, not by booking states.

Solution:  
Booking states:
- confirmed
- active
- completed
- canceled
- no_show

Valid transitions:
- confirmed → active
- active → completed
- confirmed → canceled
- active → canceled
- active → no_show

Rules:
- `completed`, `canceled`, and `no_show` are terminal
- no direct transition from `confirmed` to `completed`
- no transition is allowed from a terminal state
- hold conversion is modeled as a cross-entity rule: `BookingHold.converted → Booking.confirmed`


## 6. Question: When is a booking considered a no-show?

Assumption:  
A booking becomes a no-show if the tenant has not checked in by the time the booking start plus the configured grace period has passed.

Solution:  
- Add `checked_in_at` to the booking entity
- Add `no_show_grace_period_minutes` to settings
- Mark no-show only if `checked_in_at IS NULL` and `start_at + grace_period < now`
- Automatically generate a penalty bill when no-show rules apply
- Record a booking event for no-show marking


## 7. Question: How is check-in represented?

Assumption:  
No-show logic requires a real check-in signal and cannot rely only on time.

Solution:  
- Add `checked_in_at` to the booking entity
- Add a Property Manager check-in action/API
- Allow `confirmed → active` through the defined check-in/activation flow
- Use check-in state in no-show evaluation


## 8. Question: How are cancellation rules enforced at boundaries?

Assumption:  
- If cancellation occurs at least 24 hours before start time, it is free
- If cancellation occurs less than 24 hours before start time, a 20% fee applies
- Backend time is authoritative

Solution:  
- Compare timestamps using backend UTC time
- Enforce the rule strictly in the service layer
- Generate a penalty bill when the fee applies
- Record the fee calculation in booking/billing audit trail


## 9. Question: How are billing types structured and enforced?

Assumption:  
The system supports four bill types:
- initial
- recurring
- supplemental
- penalty

Solution:  
- Implement `bill_type` enum
- Link bills to bookings and ledger entries where applicable
- Schedule recurring billing monthly on the 1st at 9:00 AM local time
- Use supplemental bills for post-booking adjustments
- Use penalty bills for cancellation and no-show charges


## 10. Question: What is the bill state machine?

Assumption:  
Bill states must be explicit because financial workflows cannot rely on implicit transitions.

Solution:  
Bill states:
- open
- partially_paid
- paid
- partially_refunded
- voided

Valid transitions:
- open → partially_paid
- open → paid
- open → voided
- partially_paid → paid
- partially_paid → voided only after refund settlement if required by policy
- paid → partially_refunded

Rules:
- `voided` is terminal
- no payments are allowed on voided bills
- void actions must be logged and must produce the required ledger effect
- invalid transitions are rejected at the service layer

- successful payment MUST update bill state:
  - open → partially_paid or paid
  - partially_paid → paid
- booking confirmation MUST generate initial bill when pricing applies


## 11. Question: How are payments validated against bills?

Assumption:  
Payments must match the bill exactly unless partial payments are enabled globally.

Solution:  
- Add config flag `allow_partial_payments`
- If disabled: require exact amount and exact currency match
- If enabled: require payment amount to be less than or equal to remaining payable balance
- Reject mismatched or excess amounts
- Reject payments for voided bills


## 12. Question: What is the payment state machine?

Assumption:  
Payment callbacks may arrive asynchronously, so terminal states must be explicit.

Solution:  
Payment states:
- pending
- succeeded
- failed
- rejected

Valid transitions:
- pending → succeeded
- pending → failed
- pending → rejected

Rules:
- `succeeded`, `failed`, and `rejected` are terminal
- no callback may mutate a payment after terminal state
- reversals must be handled through refunds, not by mutating a succeeded payment
- duplicate callbacks must return the same outcome without reprocessing


## 13. Question: What is the refund constraint model?

Assumption:  
Refunds are bill-level, not tied to one specific payment, and total refunds may never exceed the total successfully paid amount.

Solution:  
- Model refunds against the bill as a whole
- Allow multiple partial refunds
- Validate remaining refundable balance before issuing refund
- Keep `payment_id` nullable if retained for optional reference only
- Store immutable refund records


## 14. Question: What is the refund state model?

Assumption:  
Refunds are local system actions and do not require a separate external async callback in the initial version.

Solution:  
- Treat refunds as immediately issued once validated and executed
- Do not model a long-lived async pending refund lifecycle in the initial design
- If a pending state is retained in implementation, it must be fully specified and not left ambiguous
- Record all refund actions in the ledger and audit log
- refund issuance MUST create ledger entry and update bill state atomically


## 15. Question: How is financial correctness guaranteed?

Assumption:  
The ledger is the source of truth and must be append-only.

Solution:  
- Implement append-only ledger entries:
  - bill_issued
  - payment_received
  - refund_issued
  - penalty_applied
  - bill_voided
- Derive balances from original bill amount plus successful payments/refunds/void actions
- Do not edit historical ledger entries
- Reconciliation detects mismatches instead of silently repairing them


## 16. Question: Should `outstanding_amount` be stored or derived?

Assumption:  
Duplicating financial truth creates avoidable divergence risk.

Solution:  
- Treat `outstanding_amount` as a derived financial value
- If persisted for performance, require it to be updated in the exact same DB transaction as ledger/payment changes
- Roll back the full transaction if any financial write in the unit of work fails
- Prefer derivation over duplicated stored truth where practical


## 17. Question: How is idempotency enforced for booking?

Assumption:  
Booking idempotency is scoped per user plus request key.

Solution:  
- Store `(user_id, request_key)` with 24-hour retention
- Return the original result for duplicates within the window
- Reject duplicate reprocessing
- Require a new client request key after the idempotency window expires


## 18. Question: How are idempotency keys cleaned up?

Assumption:  
MySQL does not provide native TTL row expiry, so cleanup must be an explicit job.

Solution:  
- Add an hourly background cleanup job
- Remove idempotency records older than 24 hours
- Treat stale keys older than the retention window as expired
- Require the client to generate a new request key for a new attempt after expiry


## 19. Question: How is concurrency throttling implemented?

Assumption:  
Each inventory item is protected by a rate limiter to reduce high-concurrency bursts.

Solution:  
- Apply token-bucket style throttling per inventory item
- Limit to 30 booking attempts per minute per item
- Return HTTP 429 on throttle exceed
- Include structured error response
- Treat throttle as applying to hold creation, not confirmation of an already-held resource


## 20. Question: How is hold confirmation protected from expiration races?

Assumption:  
Background expiration alone is not sufficient because a hold may be past `expires_at` before the cleanup job runs.

Solution:  
- Confirmation endpoint must directly validate `expires_at >= now`
- Background expiration job exists to restore inventory and mark stale holds
- Do not rely only on hold status for expiration enforcement


## 21. Question: How are device sessions managed?

Assumption:  
Max 5 active sessions per user, with oldest active session removed on overflow.

Solution:  
- Track issued sessions with expiry and revocation data
- Revoke oldest active session when login would exceed the cap
- Force logout on password change
- Treat session validity through explicit state checks


## 22. Question: What is the session state model?

Assumption:  
Session validity should not be inferred informally from nullable columns alone.

Solution:  
Session states:
- active
- expired
- revoked

Rules:
- active = not revoked and not past expiry
- expired = past expiry, terminal
- revoked = explicitly revoked, terminal
- terminal sessions are never reactivated


## 23. Question: How are notifications handled in an offline system?

Assumption:  
Notifications are in-app only and stored as records.

Solution:  
Notification states:
- pending
- delivered
- read

Rules:
- delivered means persisted and visible in-app
- read is user-driven
- no external delivery channel exists


## 24. Question: Are notification preferences enabled by default?

Assumption:  
New users should receive notifications unless they explicitly opt out.

Solution:  
- Default all event types to enabled
- Create preferences lazily
- If no record exists, treat it as enabled with default DND window


## 25. Question: How is Do Not Disturb enforced?

Assumption:  
Notifications are delayed, not dropped.

Solution:  
- Queue notifications during DND
- Deliver them after the DND window ends
- Preserve original event timestamp
- Make clear that DND affects notification delivery only, not API response success


## 26. Question: How are overnight DND windows handled?

Assumption:  
The default DND window crosses midnight and must be handled explicitly.

Solution:  
- If `dnd_start > dnd_end`, interpret the DND window as crossing midnight
- Use logic:
  - current_time >= start OR current_time < end
- Do not use naive same-day interval comparison


## 27. Question: How are reconciliation mismatches defined?

Assumption:  
Mismatch occurs when bill, payment, and ledger states are not financially consistent.

Solution:  
Flag mismatch when, for example:
- bill balance does not match successful payments minus refunds
- bill status conflicts with paid/refunded totals
- ledger totals do not match corresponding bill/payment/refund events

Also:
- run reconciliation daily
- store a reconciliation run record
- expose mismatches in the finance dashboard
- allow CSV export


## 28. Question: What is the RBAC enforcement model?

Assumption:  
Strict role-based permissions must be enforced at service layer.

Solution:  
Roles:
- Administrator
- Property Manager
- Tenant
- Finance Clerk

Rules:
- all actions validated in backend services
- no UI-only enforcement
- ownership and organization checks apply to reads as well as writes


## 29. Question: How are read operations secured?

Assumption:  
All read paths must enforce scope, not just writes.

Solution:  
- Apply scope/ownership checks to:
  - get
  - list
  - search
  - export
  - PDF retrieval
- Reject unauthorized access explicitly
- Never trust client-supplied ownership filters


## 30. Question: How are terminal devices and offline transfers handled?

Assumption:  
Terminal content is transferred through local offline packages.

Solution:  
- Use chunked package transfer
- Persist chunk progress
- Allow pause/resume
- Verify final integrity via checksum
- Audit terminal registration and transfer actions


## 31. Question: What is the backup and restore model?

Assumption:  
Only full-system backup/restore is supported in the initial version.

Solution:  
- Use AES-256 encrypted database dumps
- Include configured local assets in backup scope
- Require restore lock/maintenance mode
- Keep restore preview read-only if implemented
- Audit all backup and restore actions


## 32. Question: How is API versioning implemented?

Assumption:  
Versioning is path-based.

Solution:  
- Use `/api/v1/...`
- Keep version contract explicit across frontend, backend, tests, and docs


## 33. Question: What is the error handling contract?

Assumption:  
All APIs must return structured errors.

Solution:  
Use response format:
- `code`
- `message`
- `details` (optional)

Rules:
- no raw stack traces exposed
- use correct HTTP status codes
- return explicit errors for conflicts, authorization failures, and validation failures


## 34. Question: How is system bootstrap handled?

Assumption:  
First run must create both the first organization and the first administrator in one bootstrap flow.

Solution:  
- Bootstrap only available if no admin exists
- Collect:
  - organization_name
  - organization_code
  - admin_username
  - admin_password
  - admin_display_name
- Create organization and admin atomically
- Disable bootstrap after first successful completion
- Audit bootstrap completion


## 35. Question: How is data masking enforced?

Assumption:  
Sensitive fields must be masked unless explicitly permitted.

Solution:  
- Enforce masking in:
  - API serializers
  - UI views
  - exports
  - logs where applicable
- Never expose full sensitive values without explicit permission


## 36. Question: How are exports secured?

Assumption:  
Exports must obey the same authorization and masking rules as normal reads.

Solution:  
- Restrict export by role and scope
- Apply masking rules during export generation
- Log export actions
- Prevent out-of-scope filters from being applied in exports


## 37. Question: How are metrics collected?

Assumption:  
Metrics are local and aggregated.

Solution:  
Track examples such as:
- latency
- error rate
- queue depth
- failed job counts

Rules:
- keep metrics local
- no external monitoring dependency


## 38. Question: How are logs handled securely?

Assumption:  
Logs must not expose sensitive data.

Solution:  
- Mask identifiers when required
- Use structured logging
- Do not log raw passwords, secrets, or unmasked sensitive fields
- Keep logs suitable for audit/debug without leaking confidential data


## 39. Question: How is offline constraint enforced?

Assumption:  
System must function with zero internet dependency.

Solution:  
- no external APIs
- all modules local
- no cloud dependencies
- no feature may require remote connectivity to complete core workflow


## 40. Question: How is UI-service parity ensured?

Assumption:  
UI must reflect service constraints, but services remain authoritative.

Solution:  
- disable impossible actions in UI when state/permissions are already known
- reflect RBAC and state-machine constraints visually
- prevent predictable invalid actions from appearing as available
- still enforce all rules again in backend services


## 41. Question: How does rescheduling work?

Assumption:  
Rescheduling is a controlled booking change that affects availability, audit trail, and potentially billing.

Solution:  
- allow rescheduling only in `confirmed` state
- require a new valid hold for the new time range
- atomically release old allocation and claim new allocation
- generate supplemental billing adjustment if needed
- record a booking event with before/after ranges


## 42. Question: What is the currency model?

Assumption:  
Each organization operates in one default currency in the initial version, with no currency conversion support.

Solution:  
- add `default_currency` to organization or settings
- require all bills, payments, refunds, and ledger entries to use that currency
- reject any cross-currency operation at service layer
- do not support conversion in the initial version


## 43. Question: What is the pricing model?

Assumption:  
Booking amounts must be derived from explicit pricing data, not manual user entry.

Solution:  
- add `InventoryPricing`
- include:
  - inventory_item_id
  - rate_type
  - amount
  - currency
  - effective_from
  - effective_to
- derive `base_amount` from active pricing rules
- use pricing to compute recurring amounts and “first day’s rent” penalties where applicable


## 44. Question: What are the void rules for bills?

Assumption:  
Voiding a bill must not create unresolved financial contradictions.

Solution:  
- open bills with no payments may be voided directly
- partially/fully paid bills must be refunded first, or the design must explicitly execute refund-before-void as one controlled flow
- create `bill_voided` ledger entry
- do not let void change booking state directly
- reject payments against voided bills


## 45. Question: When does recurring billing stop?

Assumption:  
Recurring billing must not continue forever after a booking is no longer billable.

Solution:  
- generate recurring bills only while booking remains in the active recurring-billable state
- stop recurring generation on:
  - completed
  - canceled
  - no_show
- define billing-period overlap checks before generating a new recurring bill


## 46. Question: What are the pagination defaults?

Assumption:  
List endpoints need consistent pagination behavior.

Solution:  
- default page size = 25
- max page size = 100
- use explicit pagination contract in API spec
- apply same pagination rules across frontend, backend, and tests


## 47. Question: Is there a maximum booking duration?

Assumption:  
Extremely long bookings should be capped to prevent operational and billing abuse.

Solution:  
- add `max_booking_duration_days` to settings
- default to a reasonable cap such as 365 days
- reject bookings exceeding the configured maximum


## 48. Question: How are reconciliation runs protected from duplication?

Assumption:  
Manual and scheduled reconciliation for the same organization/date must not create inconsistent duplicate run artifacts.

Solution:  
- make reconciliation idempotent by `(organization_id, run_date)`
- if a run is already running or completed, return existing run or reject with conflict
- add lock/unique constraint to enforce this behavior