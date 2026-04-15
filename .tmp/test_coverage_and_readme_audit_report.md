# Test Coverage Evaluation

## Categories Present and Materiality

| Category                        | Present                        | Material | Quality                                                                 |
|---------------------------------|--------------------------------|----------|-------------------------------------------------------------------------|
| Backend unit tests              | ✅ 65 files, ~911 tests        | High     | Mostly mock-based service isolation with dedicated real-entity tests   |
| Backend integration tests       | ✅ 20 files, ~226 tests        | High     | Real HTTP via Symfony WebTestCase against real MySQL (no mocking)      |
| Frontend component tests        | ✅ 16 files, ~227 tests        | Medium   | Uses vi.mock() on API adapters; tests component logic and routing      |
| Frontend real-HTTP API tests    | ✅ ~96 tests                   | High     | Real backend, covers API adapter functions end-to-end                  |
| E2E tests                       | ✅ 4 Playwright files, 28 tests| High     | Real browser + frontend + backend + DB                                 |

---

## Test Infrastructure

- `run_tests.sh` is fully Docker-containerized  
- Uses `docker compose exec` throughout  
- Handles DB resets between test suites:
  - Unit → Integration → Coverage → Frontend → E2E  
- No local Node/PHP/Python dependencies required  
- Exit codes are correctly propagated  

---

## What the Tests Cover

- **RBAC matrix**
  - `RbacMatrixTest.php` (68 cases)
  - `ZRbacHttpMatrixTest.php` (40+ endpoints)

- **Booking state machine**
  - Valid/invalid transitions
  - RBAC enforcement per transition

- **Payment callback**
  - Signature verification
  - Idempotency
  - Voided-bill edge cases

- **Billing**
  - Initial, recurring, supplemental, penalty bills
  - Void logic (`BillVoidException`)
  - Ledger side effects

- **Reconciliation**
  - Ledger mismatch detection
  - CSV export

- **Terminals**
  - Chunked transfer with SHA-256 verification
  - Path traversal protection
  - Playlist management

- **Notifications**
  - Do-not-disturb enforcement
  - Preference persistence
  - Delivery validation

- **Session & concurrency**
  - Real DB concurrent session enforcement

- **Backup / Restore**
  - Full AES-256-GCM lifecycle testing

- **Security**
  - Tenant isolation
  - Org scoping
  - ID masking
  - Error leakage prevention

---

## Coverage Metrics

- **Backend:** 90.87%  
- **Frontend:** 92.79%  

---

## Test Coverage Score

**94 / 100**

---

## Score Rationale

The test suite is production-grade across all major dimensions:

- Real HTTP used at multiple layers:
  - Integration tests
  - Frontend API tests
  - E2E Playwright tests

- Critical systems covered:
  - RBAC
  - State machines
  - Payments
  - Billing
  - Concurrency

- Assertions are behavior-driven, not just status checks

---

## Why Not Higher Than 94

1. **Heavy unit test mocking**
   - Most tests mock entities instead of using real ones
   - Risks missing entity-level invariants

2. **Shallow DTO coverage**
   - Tests validate presence/type only
   - Missing:
     - Serialization round-trips
     - Edge cases (null/optional fields)

3. **Limited E2E breadth**
   - Only 28 tests
   - Missing full financial lifecycle flows in UI

---

## Key Gaps

### 1. Unit-Level Entity Mocking
- Booking, Bill, Payment entities not instantiated in unit tests
- Risks missing constructor and invariant bugs

---

### 2. DTO / Enum Test Depth
- Current tests are mechanical
- Missing:
  - Serialization validation
  - Edge-case handling

---

### 3. E2E Financial Workflow Coverage
Missing full browser-driven flow:
Tenant books → Admin bills → Tenant pays → Admin voids/refunds


- Currently only tested at API level
- Not validated through UI

---

### 4. Lower Coverage in Critical Services
- `BackupService` and `PaymentService`: ~83–87%
- Complex branches under-tested:
  - Encryption failure scenarios
  - Partial payment state handling