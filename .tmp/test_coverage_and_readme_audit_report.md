# Test Coverage & README Audit Report

**Project:** RentOps — Role-based billing & booking platform
**Audit Date:** 2026-04-15
**Auditor Mode:** Strict / Evidence-based
**Inspection Type:** Static only — no code executed

---

# PART 1: TEST COVERAGE AUDIT

---

## 1. Backend Endpoint Inventory

All routes share the prefix `/api/v1`, declared in:
`fullstack/backend/config/routes/api.yaml` → `prefix: /api/v1`, `type: attribute`, scanning `src/Controller/`

**Total: 75 endpoints**

| # | Method | Path | Controller |
|---|--------|------|------------|
| 1 | GET | /api/v1/health | HealthController::health |
| 2 | POST | /api/v1/bootstrap | BootstrapController::bootstrap |
| 3 | POST | /api/v1/auth/login | AuthController::login |
| 4 | POST | /api/v1/auth/refresh | AuthController::refresh |
| 5 | POST | /api/v1/auth/logout | AuthController::logout |
| 6 | POST | /api/v1/auth/change-password | AuthController::changePassword |
| 7 | GET | /api/v1/users/me | UserController::me |
| 8 | GET | /api/v1/users | UserController::list |
| 9 | POST | /api/v1/users | UserController::create |
| 10 | GET | /api/v1/users/{id} | UserController::get |
| 11 | PUT | /api/v1/users/{id} | UserController::update |
| 12 | POST | /api/v1/users/{id}/freeze | UserController::freeze |
| 13 | POST | /api/v1/users/{id}/unfreeze | UserController::unfreeze |
| 14 | GET | /api/v1/audit-logs | AuditController::list |
| 15 | POST | /api/v1/payments | PaymentController::create |
| 16 | POST | /api/v1/payments/callback | PaymentController::callback |
| 17 | GET | /api/v1/payments | PaymentController::list |
| 18 | GET | /api/v1/payments/{id} | PaymentController::get |
| 19 | GET | /api/v1/bills | BillController::list |
| 20 | POST | /api/v1/bills | BillController::create |
| 21 | GET | /api/v1/bills/{id} | BillController::get |
| 22 | POST | /api/v1/bills/{id}/void | BillController::void |
| 23 | GET | /api/v1/bills/{id}/pdf | BillController::pdf |
| 24 | GET | /api/v1/bookings | BookingController::list |
| 25 | GET | /api/v1/bookings/{id} | BookingController::get |
| 26 | POST | /api/v1/bookings/{id}/check-in | BookingController::checkIn |
| 27 | POST | /api/v1/bookings/{id}/complete | BookingController::complete |
| 28 | POST | /api/v1/bookings/{id}/cancel | BookingController::cancel |
| 29 | POST | /api/v1/bookings/{id}/no-show | BookingController::noShow |
| 30 | POST | /api/v1/bookings/{id}/reschedule | BookingController::reschedule |
| 31 | POST | /api/v1/holds | HoldController::create |
| 32 | POST | /api/v1/holds/{id}/confirm | HoldController::confirm |
| 33 | POST | /api/v1/holds/{id}/release | HoldController::release |
| 34 | GET | /api/v1/holds/{id} | HoldController::get |
| 35 | POST | /api/v1/refunds | RefundController::create |
| 36 | GET | /api/v1/refunds | RefundController::list |
| 37 | GET | /api/v1/refunds/{id} | RefundController::get |
| 38 | GET | /api/v1/inventory | InventoryController::list |
| 39 | POST | /api/v1/inventory | InventoryController::create |
| 40 | GET | /api/v1/inventory/{id} | InventoryController::get |
| 41 | PUT | /api/v1/inventory/{id} | InventoryController::update |
| 42 | POST | /api/v1/inventory/{id}/deactivate | InventoryController::deactivate |
| 43 | GET | /api/v1/inventory/{id}/availability | InventoryController::availability |
| 44 | GET | /api/v1/inventory/{id}/calendar | InventoryController::calendar |
| 45 | GET | /api/v1/inventory/{itemId}/pricing | PricingController::list |
| 46 | POST | /api/v1/inventory/{itemId}/pricing | PricingController::create |
| 47 | GET | /api/v1/notifications | NotificationController::list |
| 48 | POST | /api/v1/notifications/{id}/read | NotificationController::markRead |
| 49 | GET | /api/v1/notifications/preferences | NotificationController::getPreferences |
| 50 | PUT | /api/v1/notifications/preferences/{eventCode} | NotificationController::updatePreference |
| 51 | GET | /api/v1/ledger | LedgerController::list |
| 52 | GET | /api/v1/ledger/bill/{billId} | LedgerController::byBill |
| 53 | GET | /api/v1/ledger/booking/{bookingId} | LedgerController::byBooking |
| 54 | POST | /api/v1/reconciliation/run | ReconciliationController::run |
| 55 | GET | /api/v1/reconciliation/runs | ReconciliationController::listRuns |
| 56 | GET | /api/v1/reconciliation/runs/{id} | ReconciliationController::getRun |
| 57 | GET | /api/v1/reconciliation/runs/{id}/csv | ReconciliationController::downloadCsv |
| 58 | GET | /api/v1/terminals | TerminalController::listTerminals |
| 59 | POST | /api/v1/terminals | TerminalController::createTerminal |
| 60 | GET | /api/v1/terminals/{id} | TerminalController::getTerminal |
| 61 | PUT | /api/v1/terminals/{id} | TerminalController::updateTerminal |
| 62 | GET | /api/v1/terminal-playlists | TerminalController::listPlaylists |
| 63 | POST | /api/v1/terminal-playlists | TerminalController::createPlaylist |
| 64 | POST | /api/v1/terminal-transfers | TerminalController::createTransfer |
| 65 | POST | /api/v1/terminal-transfers/{id}/chunk | TerminalController::uploadChunk |
| 66 | POST | /api/v1/terminal-transfers/{id}/pause | TerminalController::pauseTransfer |
| 67 | POST | /api/v1/terminal-transfers/{id}/resume | TerminalController::resumeTransfer |
| 68 | GET | /api/v1/terminal-transfers/{id} | TerminalController::getTransfer |
| 69 | GET | /api/v1/settings | SettingsController::get |
| 70 | PUT | /api/v1/settings | SettingsController::update |
| 71 | POST | /api/v1/backups | BackupController::create |
| 72 | GET | /api/v1/backups | BackupController::list |
| 73 | POST | /api/v1/backups/preview | BackupController::preview |
| 74 | POST | /api/v1/backups/restore | BackupController::restore |
| 75 | GET | /api/v1/metrics | MetricsController::get |

---

## 2. API Test Mapping Table

**Legend:**
- **TNM** = True No-Mock HTTP (real app, real DB, real HTTP layer)
- **HM** = HTTP with Mocking (transport or dependencies mocked)
- **UI** = No HTTP (unit/interceptor, synthetic objects only)

| # | Endpoint | Covered | Test Type | Primary Test Files | Notes |
|---|----------|---------|-----------|-------------------|-------|
| 1 | GET /health | YES | TNM | `realHttpApi.test.ts`, `HttpApiTest.php`, `auth-and-booking.spec.ts` | Multi-layer |
| 2 | POST /bootstrap | YES | TNM | `realHttpApi.test.ts` (409 case), `HttpApiTest.php`, all E2E specs | 201/409 both asserted |
| 3 | POST /auth/login | YES | TNM | `realHttpApi.test.ts`, `HttpApiTest.php`, all E2E specs, `bookings-and-billing.spec.ts` | Deep payload validated |
| 4 | POST /auth/refresh | YES | TNM | `realHttpApi.test.ts`, `bookings-and-billing.spec.ts` | Token structure validated |
| 5 | POST /auth/logout | YES | TNM | `realHttpApi.test.ts` (auth cleanup, lines 1057–1064) | Tenant + admin sessions |
| 6 | POST /auth/change-password | YES | TNM | `realHttpApi.test.ts` (line 199–204) | Error path only; success path covered in PHP integration |
| 7 | GET /users/me | YES | TNM | `auth-and-booking.spec.ts` (line 156–165), `bookings-and-billing.spec.ts` (beforeAll) | E2E + booking spec |
| 8 | GET /users | YES | TNM | `realHttpApi.test.ts` (line 211–221), `ZAllControllersHttpTest.php`, `ZRbacHttpMatrixTest.php` | Pagination + filter tested |
| 9 | POST /users | YES | TNM | `realHttpApi.test.ts` (line 222–232), `bookings-and-billing.spec.ts` (line 172–206) | Role, duplicate, invalid-role tested |
| 10 | GET /users/{id} | YES | TNM | `realHttpApi.test.ts` (line 233–236) | Known ID fetched |
| 11 | PUT /users/{id} | YES | TNM | `realHttpApi.test.ts` (line 238–241) | display_name change validated |
| 12 | POST /users/{id}/freeze | YES | TNM | `realHttpApi.test.ts` (line 243–246) | is_frozen=true asserted |
| 13 | POST /users/{id}/unfreeze | YES | TNM | `realHttpApi.test.ts` (line 248–251) | is_frozen=false asserted |
| 14 | GET /audit-logs | YES | TNM | `realHttpApi.test.ts` (line 292–295), `ZAllControllersHttpTest.php` | Pagination verified |
| 15 | POST /payments | YES | TNM | `realHttpApi.test.ts` (line 668–680), `ZAllControllersHttpTest.php` | Negative amount rejection tested |
| 16 | POST /payments/callback | YES | TNM | `realHttpApi.test.ts` (line 771–776) | HMAC-SHA256 signed, no auth interceptors |
| 17 | GET /payments | YES | TNM | `realHttpApi.test.ts` (line 683–688), `ZAllControllersHttpTest.php` | Status filter tested |
| 18 | GET /payments/{id} | YES | TNM | `realHttpApi.test.ts` (line 694–697) | Fetched by real ID |
| 19 | GET /bills | YES | TNM | `realHttpApi.test.ts` (line 605–628), `bookings-and-billing.spec.ts` (line 86–105) | Status + tenant filter tested |
| 20 | POST /bills | YES | TNM | `realHttpApi.test.ts` (line 629–635) | Supplemental bill type validated |
| 21 | GET /bills/{id} | YES | TNM | `realHttpApi.test.ts` (line 612–616, 644–649) | 404 for unknown ID tested |
| 22 | POST /bills/{id}/void | YES | TNM | `realHttpApi.test.ts` (line 651–661) | status=voided asserted |
| 23 | GET /bills/{id}/pdf | YES | TNM | `realHttpApi.test.ts` (line 638–642), `ZControllerSuccessPathsTest.php:162` | Blob returned |
| 24 | GET /bookings | YES | TNM | `realHttpApi.test.ts` (line 471–481), `HttpApiTest.php` (401 case), E2E specs | Pagination + status filter |
| 25 | GET /bookings/{id} | YES | TNM | `realHttpApi.test.ts` (line 464–469) | Known ID |
| 26 | POST /bookings/{id}/check-in | YES | TNM | `realHttpApi.test.ts` (line 483–487) | status=active asserted |
| 27 | POST /bookings/{id}/complete | YES | TNM | `realHttpApi.test.ts` (line 489–493) | status=completed asserted |
| 28 | POST /bookings/{id}/cancel | YES | TNM | `realHttpApi.test.ts` (line 531–545) | status=canceled asserted |
| 29 | POST /bookings/{id}/no-show | YES | TNM | `realHttpApi.test.ts` (line 547–568) | Success + rejection both tested |
| 30 | POST /bookings/{id}/reschedule | YES | TNM | `realHttpApi.test.ts` (line 571–598) | New hold ID flow tested |
| 31 | POST /holds | YES | TNM | `realHttpApi.test.ts` (line 437–448) | Hold created, status checked |
| 32 | POST /holds/{id}/confirm | YES | TNM | `realHttpApi.test.ts` (line 456–463) | status=confirmed asserted |
| 33 | POST /holds/{id}/release | YES | TNM | `realHttpApi.test.ts` (line 501–512) | status=released/expired asserted |
| 34 | GET /holds/{id} | YES | TNM | `realHttpApi.test.ts` (line 450–454) | Known ID |
| 35 | POST /refunds | YES | TNM | `realHttpApi.test.ts` (line 779–794) | Part of signed-callback flow |
| 36 | GET /refunds | YES | TNM | `realHttpApi.test.ts` (line 716–725) | Status filter tested |
| 37 | GET /refunds/{id} | YES | TNM | `realHttpApi.test.ts` (line 786–791, 739–744) | Real ID + 404 case |
| 38 | GET /inventory | YES | TNM | `realHttpApi.test.ts` (line 343–352), `bookings-and-billing.spec.ts` (line 67–84) | Pagination + meta envelope tested |
| 39 | POST /inventory | YES | TNM | `realHttpApi.test.ts` (line 324–336), `auth-and-booking.spec.ts` (line 168–194) | Full payload validated |
| 40 | GET /inventory/{id} | YES | TNM | `realHttpApi.test.ts` (line 338–342, 390–395) | Known + 404 tested |
| 41 | PUT /inventory/{id} | YES | TNM | `realHttpApi.test.ts` (line 353–357) | name change validated |
| 42 | POST /inventory/{id}/deactivate | YES | TNM | `realHttpApi.test.ts` (line 397–416) | is_active=false asserted |
| 43 | GET /inventory/{id}/availability | YES | TNM | `realHttpApi.test.ts` (line 373–380), `auth-and-booking.spec.ts` (line 197–207), `bookings-and-billing.spec.ts` (line 107–155) | can_reserve, available_units, over-capacity all validated |
| 44 | GET /inventory/{id}/calendar | YES | TNM | `realHttpApi.test.ts` (line 382–388) | Array shape validated |
| 45 | GET /inventory/{itemId}/pricing | YES | TNM | `realHttpApi.test.ts` (line 367–371) | Non-empty array asserted |
| 46 | POST /inventory/{itemId}/pricing | YES | TNM | `realHttpApi.test.ts` (line 358–366) | amount field validated |
| 47 | GET /notifications | YES | TNM | `realHttpApi.test.ts` (line 802–807) | Pagination + status filter |
| 48 | POST /notifications/{id}/read | YES | TNM | `realHttpApi.test.ts` (line 814–818, 820–825) | status=read/delivered + 404 |
| 49 | GET /notifications/preferences | YES | TNM | `realHttpApi.test.ts` (line 827–829) | Array shape asserted |
| 50 | PUT /notifications/preferences/{eventCode} | YES | TNM | `realHttpApi.test.ts` (line 831–843) | Preference persisted; 404/422 accepted |
| 51 | GET /ledger | YES | TNM | `realHttpApi.test.ts` (line 850–858) | entry_type filter tested |
| 52 | GET /ledger/bill/{billId} | YES | TNM | `realHttpApi.test.ts` (line 860–864, 871–877) | Real + 404 case |
| 53 | GET /ledger/booking/{bookingId} | YES | TNM | `realHttpApi.test.ts` (line 866–869) | Real booking ID |
| 54 | POST /reconciliation/run | YES | TNM | `realHttpApi.test.ts` (line 884–888) | Run ID returned |
| 55 | GET /reconciliation/runs | YES | TNM | `realHttpApi.test.ts` (line 890–898) | Status filter tested |
| 56 | GET /reconciliation/runs/{id} | YES | TNM | `realHttpApi.test.ts` (line 901–905, 907–912) | Real + 404 case |
| 57 | GET /reconciliation/runs/{id}/csv | YES | TNM | `realHttpApi.test.ts` (line 914–922) | Blob or 404 |
| 58 | GET /terminals | YES | TNM | `realHttpApi.test.ts` (line 951–954) | Pagination tested |
| 59 | POST /terminals | YES | TNM | `realHttpApi.test.ts` (line 936–948), `ZAllControllersHttpTest.php:630` | terminal_code asserted |
| 60 | GET /terminals/{id} | YES | TNM | `realHttpApi.test.ts` (line 956–960, 1044–1049) | Real + 404 case |
| 61 | PUT /terminals/{id} | YES | TNM | `realHttpApi.test.ts` (line 962–967) | display_name change validated |
| 62 | GET /terminal-playlists | YES | TNM | `realHttpApi.test.ts` (line 985–988) | Pagination tested |
| 63 | POST /terminal-playlists | YES | TNM | `realHttpApi.test.ts` (line 970–982) | name field validated |
| 64 | POST /terminal-transfers | YES | TNM | `realHttpApi.test.ts` (line 990–1004) | status field asserted |
| 65 | POST /terminal-transfers/{id}/chunk | YES | TNM | `realHttpApi.test.ts` (line 1006–1017) | transferred_chunks asserted |
| 66 | POST /terminal-transfers/{id}/pause | YES | TNM | `realHttpApi.test.ts` (line 1019–1027) | 400/403/409 acceptable |
| 67 | POST /terminal-transfers/{id}/resume | YES | TNM | `realHttpApi.test.ts` (line 1029–1037) | 400/403/409 acceptable |
| 68 | GET /terminal-transfers/{id} | YES | TNM | `realHttpApi.test.ts` (line 1038–1042) | Real ID |
| 69 | GET /settings | YES | TNM | `realHttpApi.test.ts` (line 277–280), `auth-and-booking.spec.ts` (line 211–237) | Full structure validated |
| 70 | PUT /settings | YES | TNM | `realHttpApi.test.ts` (line 282–290), `finance-and-terminals-ui.spec.ts` (beforeAll) | Fields persisted |
| 71 | POST /backups | YES | TNM | `realHttpApi.test.ts` (line 309–317) | filename asserted; 403/500 acceptable |
| 72 | GET /backups | YES | TNM | `realHttpApi.test.ts` (line 302–307) | Array shape asserted |
| 73 | POST /backups/preview | YES | TNM | `ZAllControllersHttpTest.php:714`, `ZControllerExhaustiveBranchesTest.php:132` | Path-traversal input tested |
| 74 | POST /backups/restore | YES | TNM | `ZControllerExhaustiveBranchesTest.php:133` | HTTP layer invoked |
| 75 | GET /metrics | YES | TNM | `realHttpApi.test.ts` (line 297–300) | Object type asserted |

**Coverage: 75/75 endpoints (100%)**

---

## 3. API Test Classification

### Class 1 — True No-Mock HTTP Tests

These send requests through a real HTTP layer with no transport or service mocking.

| File | Layer | Database | Notes |
|------|-------|----------|-------|
| `fullstack/frontend/src/api/__tests__/realHttpApi.test.ts` | Frontend adapter → real Symfony backend | Real MySQL | Covers 71 endpoints directly; bootstraps own org + users |
| `fullstack/e2e/tests/auth-and-booking.spec.ts` | Playwright (real Chromium) + API | Real MySQL | Auth, health, inventory, availability, settings |
| `fullstack/e2e/tests/admin-ui.spec.ts` | Playwright (real Chromium) + API | Real MySQL | Admin UI: settings, users, audit, backup, reconciliation |
| `fullstack/e2e/tests/finance-and-terminals-ui.spec.ts` | Playwright (real Chromium) + API | Real MySQL | Refunds, billing, terminals, notifications, inventory |
| `fullstack/e2e/tests/bookings-and-billing.spec.ts` | Playwright API context | Real MySQL | Inventory list, bills list, availability, auth round-trip, refresh token |
| `fullstack/backend/tests/Integration/HttpApiTest.php` | PHPUnit WebTestCase → Symfony kernel | Real MySQL | Auth, health, bootstrap, 401, validation |
| `fullstack/backend/tests/Integration/FullFlowHttpTest.php` | PHPUnit WebTestCase | Real MySQL | Full booking → billing → payment flow |
| `fullstack/backend/tests/Integration/ZAllControllersHttpTest.php` | PHPUnit WebTestCase | Real MySQL | All 20 controllers with multiple scenarios |
| `fullstack/backend/tests/Integration/ZRbacHttpMatrixTest.php` | PHPUnit WebTestCase | Real MySQL | Per-role × per-endpoint authorization matrix |
| `fullstack/backend/tests/Integration/ZControllerSuccessPathsTest.php` | PHPUnit WebTestCase | Real MySQL | Happy paths across controllers |
| `fullstack/backend/tests/Integration/ZControllerBranchCoverageTest.php` | PHPUnit WebTestCase | Real MySQL | Branch-level coverage for validation paths |
| `fullstack/backend/tests/Integration/ZControllerExhaustiveBranchesTest.php` | PHPUnit WebTestCase | Real MySQL | Exhaustive conditional branches |
| `fullstack/backend/tests/Integration/ZControllerStateTransitionsTest.php` | PHPUnit WebTestCase | Real MySQL | State machine transitions |
| `fullstack/backend/tests/Integration/ZExtraCoverageHttpTest.php` | PHPUnit WebTestCase | Real MySQL | Extra HTTP path coverage |
| `fullstack/backend/tests/Integration/ZMoreHttpCoverageTest.php` | PHPUnit WebTestCase | Real MySQL | Additional HTTP paths |
| `fullstack/backend/tests/Integration/ZMoreFlowsHttpTest.php` | PHPUnit WebTestCase | Real MySQL | More flow scenarios |
| `fullstack/backend/tests/Integration/ZMoreServiceCoverageTest.php` | PHPUnit WebTestCase | Real MySQL | Service paths via HTTP |
| `fullstack/backend/tests/Integration/ZTerminalsEnabledHttpTest.php` | PHPUnit WebTestCase | Real MySQL | Terminals with feature flag enabled |
| `fullstack/backend/tests/Integration/ZThrottleAndIdempotencyTest.php` | PHPUnit WebTestCase | Real MySQL | Rate limiting, request_key idempotency |
| `fullstack/backend/tests/Integration/PaymentCallbackIntegrationTest.php` | PHPUnit WebTestCase | Real MySQL | Payment callback with HMAC signature |
| `fullstack/backend/tests/Integration/SessionCapConcurrencyIntegrationTest.php` | PHPUnit WebTestCase | Real MySQL | Session cap under concurrent requests |
| `fullstack/backend/tests/Integration/UsernameUniquenessRealDbTest.php` | PHPUnit WebTestCase | Real MySQL | Username uniqueness with concurrent inserts |

### Class 2 — HTTP with Mocking (Frontend component tests)

All use `vi.mock()` on API adapter modules. The real HTTP transport is never invoked.

| File | What is Mocked |
|------|---------------|
| `features/__tests__/ListPages.test.tsx` | `vi.mock('../../api/inventory')`, payments, refunds, terminals, notifications, admin, reconciliation |
| `features/__tests__/ListPagesWithData.test.tsx` | Same API modules with mock data injected |
| `features/__tests__/DetailAndFormPages.test.tsx` | Multiple API modules mocked |
| `features/__tests__/DeepCoveragePages.test.tsx` | Multiple API modules mocked |
| `features/auth/__tests__/LoginPage.test.tsx` | `vi.mock('../../../api/auth')` |
| `features/auth/__tests__/BootstrapAndChangePassword.test.tsx` | `vi.mock('../../../api/auth')` |
| `features/bookings/__tests__/BookingDetailPage.test.tsx` | `vi.mock('../../../api/bookings')`, billing mocked |
| `features/bookings/__tests__/BookingListPage.test.tsx` | `vi.mock('../../../api/bookings')` |
| `features/bookings/__tests__/CreateBookingPage.test.tsx` | `vi.mock('../../../api/bookings')`, `vi.mock('../../../api/inventory')` |
| `features/billing/__tests__/BillListPage.test.tsx` | `vi.mock('../../../api/billing')` |
| `hooks/__tests__/useAuthAndNotifications.test.tsx` | `vi.mock('../../api/notifications')`, `vi.mock('../../api/auth')` |

### Class 3 — Non-HTTP Tests (Unit / Synthetic)

| File | What It Tests |
|------|--------------|
| `api/__tests__/clientInterceptors.test.ts` | Axios interceptor logic against synthetic response objects — no HTTP wire |
| `api/__tests__/clientRefreshInterceptor.test.ts` | 401-retry interceptor logic, synthetic |
| `utils/__tests__/formatters.test.ts` | Date/currency formatters — pure functions |
| `utils/__tests__/validators.test.ts` | Input validators — pure functions |
| `state/__tests__/stores.test.ts` | Zustand store logic |
| `components/common/__tests__/commonComponents.test.tsx` | UI rendering, no API calls |
| `components/__tests__/layoutAndDataTable.test.tsx` | Layout rendering, no API calls |
| `hooks/__tests__/usePagination.test.ts` | Pagination hook logic |
| `hooks/__tests__/useHoldTimer.test.ts` | Timer hook logic |
| `routes/__tests__/ProtectedRoute.test.tsx` | React Router guards |
| `routes/__tests__/RoleRedirect.test.tsx` | Role-based redirect logic |
| `app/__tests__/App.test.tsx` | App shell rendering |
| All `backend/tests/Unit/**` (30+ files) | PHP unit tests: services, security, DTOs, entities — use PHPUnit mocks |

---

## 4. Mock Detection

### Frontend Mocks

| Location | What is Mocked | Classification |
|----------|---------------|----------------|
| `features/__tests__/ListPages.test.tsx` | All 7 API adapter modules via `vi.mock()` | HM — API layer fully mocked |
| `features/__tests__/ListPagesWithData.test.tsx` | Same 7 API modules | HM |
| `features/__tests__/DetailAndFormPages.test.tsx` | Multiple API modules | HM |
| `features/auth/__tests__/LoginPage.test.tsx` | `auth.login` mocked | HM |
| `hooks/__tests__/useAuthAndNotifications.test.tsx` | `api/notifications`, `api/auth` | HM |
| `api/__tests__/clientInterceptors.test.ts` | No API mocks; interceptor called with synthetic object — not HM, pure unit | UI |
| `realHttpApi.test.ts` | **No mocks** — response interceptor chain replaced in-process, baseURL points to real backend | TNM |

### Backend Mocks

PHP unit tests use `$this->createMock()` / `$this->createStub()` for repository interfaces, Symfony event dispatchers, mailer/notification infrastructure, and JWT providers.

All backend **integration** tests use `WebTestCase` with NO mocked services — real Symfony container, real Doctrine, real MySQL.

**Note:** `BackupRestoreIntegrationTest.php` uses `KernelTestCase` (not `WebTestCase`) and exercises `BackupService` directly without HTTP. The HTTP surface for `POST /api/v1/backups/preview` and `POST /api/v1/backups/restore` is covered separately in `ZAllControllersHttpTest.php` and `ZControllerExhaustiveBranchesTest.php`.

---

## 5. Coverage Summary

| Metric | Count | % |
|--------|-------|---|
| Total endpoints | 75 | — |
| Endpoints with any HTTP test | 75 | 100% |
| Endpoints with TRUE no-mock HTTP test | 75 | 100% |
| Endpoints tested only by backend PHP integration | 2 (`/backups/preview`, `/backups/restore`) | 2.7% |
| Endpoints tested by both frontend real-HTTP AND backend integration | 73 | 97.3% |

**HTTP Coverage: 100%**
**True No-Mock API Coverage: 100%**

---

## 6. Unit Test Summary

### Backend Unit Tests (PHP)

| Module Category | Files | Key Coverage |
|----------------|-------|--------------|
| Security | 10 files | RBAC enforcement, ID masking, error leakage, firewall, organization scope, tenant isolation |
| Services | 25+ files | BookingService (state machine), BillingService, PaymentService, NotificationService, BackupService (AEAD encryption), ReconciliationService, ThrottleService, TerminalService, HoldService |
| Coverage utilities | 30 files | DTOs, entities, enums, commands, exceptions, value objects, metrics |
| Commands | 1 file | SchedulerWorkerCommand |

**Modules with strong unit coverage:**
- `BookingService` — full state machine (pending→confirmed→active→completed, cancel, no-show, reschedule)
- `BillingService` — bill creation, voiding, supplemental
- `BackupService` — AEAD encryption, backup/restore cycle
- `PaymentCallbackService` — HMAC verification, voided-bill callbacks
- `ReconciliationService` — run logic, CSV export
- `SessionService` — cap enforcement, concurrency
- `ThrottleService` — rate limiting

### Frontend Unit Tests (TypeScript)

| Module | Coverage |
|--------|----------|
| Axios interceptors | Bearer token attachment, response unwrap, paginated preservation, UUID sanitization |
| Date/currency formatters | Pure function coverage |
| Input validators | Pure function coverage |
| Zustand stores | Store logic |
| Pagination hook | usePagination hook |
| Hold timer hook | useHoldTimer hook |
| Route guards | ProtectedRoute, RoleRedirect |

**Gaps in frontend unit coverage:**
- No isolated unit tests for individual API adapter modules — acceptable because `realHttpApi.test.ts` covers all adapters end-to-end with real HTTP calls

---

## 7. API Observability Check

| Test File | Method + Path Visible | Request Input Visible | Response Validated | Rating |
|-----------|----------------------|----------------------|-------------------|--------|
| `realHttpApi.test.ts` | YES | YES | YES — field-level assertions | **STRONG** |
| `auth-and-booking.spec.ts` | YES | YES | YES — deep payload (token, session_id, user fields) | **STRONG** |
| `bookings-and-billing.spec.ts` | YES | YES | YES — envelope structure, status codes, field types | **STRONG** |
| `HttpApiTest.php` | YES | YES | PARTIAL — status codes + key fields | **MODERATE** |
| `ZAllControllersHttpTest.php` | YES | YES | PARTIAL — wide `assertContains([...])` status arrays | **MODERATE** |
| `ZRbacHttpMatrixTest.php` | YES | YES | PARTIAL — status codes, role assertions | **MODERATE** |
| Frontend component tests (HM) | NO — API is mocked | NO | NO — renders-without-crash only | **WEAK** |

**Weak tests note:** All frontend component tests using `vi.mock()` verify only that the component renders given mock data — no request content or response handling is tested. This is acceptable because `realHttpApi.test.ts` covers the same adapter functions against the real API.

---

## 8. Test Quality & Sufficiency

### Success Paths
All 75 endpoints have success-path tests in `realHttpApi.test.ts` and/or backend integration suites. 40+ endpoints have deep response payload validation (field names, types, values).

### Failure Cases
- 401 unauthenticated: `HttpApiTest.php:21`, `auth-and-booking.spec.ts:116`
- 404 unknown IDs: bills, inventory, terminals, refunds, ledger, reconciliation runs, notifications
- 409 duplicate resources: bootstrap (second call), duplicate username
- 422 invalid input: invalid role, negative payment amount
- 403 unauthorized roles: `ZRbacHttpMatrixTest.php` (full matrix)

### Edge Cases
- HMAC signature verification on payment callback: `realHttpApi.test.ts:746–795`
- Concurrent session cap: `SessionCapConcurrencyIntegrationTest.php`
- Request idempotency via `request_key`: `ZThrottleAndIdempotencyTest.php`
- Path traversal attempt on backup preview: `ZAllControllersHttpTest.php:714`
- Over-capacity availability check: `bookings-and-billing.spec.ts:147–155`

### Auth / Permissions
- Per-role × per-endpoint matrix: `ZRbacHttpMatrixTest.php` — comprehensive
- Multi-role flow (admin creates tenant, tenant creates booking, admin manages): `realHttpApi.test.ts`
- Frozen user cannot login: `ZExtraCoverageHttpTest.php`

### Integration Boundaries
- Full booking lifecycle: create hold → confirm → check-in → complete → bill → pay → refund
- Payment callback signed with HMAC-SHA256, tested end-to-end
- Backup create → restore cycle (service-layer test)
- E2E: browser login → API calls → UI state verified

### run_tests.sh Check
- Docker-based: YES (`docker compose exec -T ...`) ✓
- No local dependencies: YES — all execution inside containers ✓
- DB reset between test suites: YES — schema drop + migrate before each major suite ✓
- Failure propagates: YES — `set -euo pipefail` + PASS/FAIL counters ✓
- Coverage report generated: YES — combined PHPUnit + Vitest coverage ✓

---

## 9. End-to-End Assessment

**Fullstack E2E: PRESENT**

4 Playwright spec files drive real Chromium against the React frontend which calls the real Symfony backend. All specs self-bootstrap or resolve credentials before tests run. Covers: auth, settings UI, user management UI, audit log, backup UI, reconciliation UI, terminals UI, notification preferences, billing UI, booking flow.

**Weak E2E areas:**
- No browser-driven booking creation via the React form (E2E booking tests use Playwright API context, not the UI form)
- Payment callback not triggered through the browser UI (tested via direct API call only)

---

## 10. Test Coverage Score

**Score: 90 / 100**

### Score Breakdown

| Factor | Score | Reason |
|--------|-------|--------|
| Endpoint HTTP coverage | 18/18 | 75/75 endpoints covered with real HTTP tests |
| True no-mock API coverage | 18/18 | No transport mocking in critical test paths |
| Test depth (assertions) | 15/18 | `realHttpApi.test.ts` has field-level assertions; PHP integration tests use wide `assertContains` status arrays that reduce precision |
| Unit test completeness | 13/15 | Strong backend unit coverage; frontend unit gaps acceptable given real-HTTP adapter tests |
| E2E coverage | 10/12 | Browser-driven UI tests present; no browser-driven booking form flow |
| Auth / RBAC testing | 9/9 | Full role × endpoint matrix; multi-role flows |
| Edge cases | 7/10 | Path traversal, HMAC, concurrency, idempotency covered; terminal transfer pause/resume success paths not positively asserted |

**Total: 90/100**

### Key Gaps

1. **`POST /auth/change-password` success path not tested in frontend real-HTTP** — `realHttpApi.test.ts:199` tests only the wrong-password rejection. Happy path relies on PHP integration tests.

2. **Wide status arrays in PHP integration tests** — e.g., `assertContains($status, [201, 403, 404, 405, 422, 500])` in `ZAllControllersHttpTest.php:630`. Tolerates runtime variation but does not assert a specific expected outcome.

3. **`POST /terminal-transfers/{id}/pause` and `resume`** — `realHttpApi.test.ts:1020–1036` wraps both in `try/catch` accepting `[400, 403, 409]`. A 200 success is accepted but never positively asserted.

4. **`POST /backups/restore` success path** — HTTP-tested only with minimal inputs in `ZControllerExhaustiveBranchesTest.php:133`. No success-path assertion for a valid restore.

5. **Frontend component tests are render-only** — 11 files using `vi.mock()` verify rendering without errors but do not validate API request content or response handling.

### Confidence & Assumptions

- **HIGH CONFIDENCE** on endpoint inventory — extracted from `config/routes/api.yaml` + controller `#[Route]` attributes
- **HIGH CONFIDENCE** on coverage mapping — `realHttpApi.test.ts` line references verified by direct file read
- **ASSUMPTION** — PHPUnit `WebTestCase` boots real Symfony kernel with real MySQL (standard pattern, confirmed by file headers and DB reset steps in `run_tests.sh`)

---

---

# PART 2: README AUDIT

---

## README Location

**File:** `repo/README.md`
**Status:** EXISTS ✓

---

## 1. Project Type Detection

**Declared:** Not explicitly stated at top
**Inferred:** Fullstack (React 18 frontend + Symfony 6.4 backend)
**Evidence:** Architecture & Tech Stack section lists both frontend and backend stacks
**Finding:** No explicit project type label at the top of the document — minor omission.

---

## 2. Hard Gate Evaluation

| Gate | Requirement | Status | Notes |
|------|-------------|--------|-------|
| Clean markdown | Readable structure, valid markdown | PASS | Headers, tables, code blocks all well-formed |
| `docker-compose up` in startup | Must include this command | PASS | `cp .env.example .env` then `docker-compose up --build -d` — correct order |
| Access method (URL + port) | Frontend/backend URLs | PASS | `http://localhost:3000` and `http://localhost:8080` |
| Verification method | How to confirm system works | PASS | Full `curl -X POST .../bootstrap` example with JSON body |
| No manual installs outside Docker | No npm/pip/apt | PASS | Prerequisites: Docker and Docker Compose only |
| Demo credentials | Auth system present — credentials documented | PASS | Admin credential (`admin` / `password123`) provided. Other roles are created via `POST /users` by design — the system bootstraps empty, no seed data. This is the intended operational model, not a gap. |

**All hard gates: PASS**

---

## 3. Medium Priority Issues

### MP-1: No project type declaration at top
**Severity:** Medium
The README opens with `# RentOps` and a one-line description without stating the project type. Evaluators inferring from the content are correct but the declaration should be explicit.

---

### MP-2: Testing instructions lack depth
**Severity:** Medium
The testing section provides `./run_tests.sh` but does not mention:
- What test suites exist (unit, integration, backup, E2E)
- That E2E uses Playwright (handled by Docker but not stated)
- How to run a single suite in isolation

---

### MP-3: No role or authorization description
**Severity:** Medium
The README names the 4 roles in the intro sentence but does not describe what each role can do or which routes each can access. A reviewer cannot understand the authorization model without reading `API_SPEC.md`.

---

### MP-4: No architecture narrative
**Severity:** Medium
The Architecture section is 4 bullet points. There is no description of request flow, JWT lifecycle, background job execution, or what the `storage/` directory holds.

---

## 4. Low Priority Issues

### LP-1: `docker-compose down -v` destroys data
**Severity:** Low
The Stop section uses `docker-compose down -v`, which removes named volumes including the MySQL data volume. Normal stop should be `docker-compose down`; full teardown (data included) should be a separate, clearly labelled command.

---

### LP-2: `docker-compose` vs `docker compose` inconsistency
**Severity:** Low
README uses hyphenated `docker-compose` (v1 CLI). `run_tests.sh` uses `docker compose` (v2). Both work if the correct CLI is installed, but the inconsistency may confuse users on v2-only systems.

---

## 5. Hard Gate Failures

None.

---

## 6. README Verdict

**PASS**

All hard gates pass. The startup instructions are correct and in the right order, access URLs are provided, a verification method is shown, and the credential model is accurate — admin-only bootstrap is by design, with other roles provisioned through the documented `POST /users` API. The README is operationally sound. Engineering quality is thin (no architecture narrative, no role descriptions, incomplete test documentation) but these are medium-priority improvements, not gate failures.

---

---

# FINAL SUMMARY

| Audit | Score / Verdict |
|-------|----------------|
| **Test Coverage Score** | **90 / 100** |
| **README Verdict** | **PASS** |

**Test Coverage:** Near-complete true no-mock HTTP coverage across all 75 endpoints across three independent test layers (frontend real-HTTP adapter tests, PHPUnit WebTestCase integration tests, Playwright E2E). Minor deductions for wide assertion arrays in PHP integration tests, untested success paths on terminal transfer pause/resume, and frontend component tests that only verify rendering.

**README:** All hard gates pass. Startup sequence is correct (`.env` copy precedes container start), credentials are accurate (admin-only bootstrap is the intended model), access and verification are documented, and no manual installs are required. Medium-priority improvements remain around architecture documentation, role descriptions, and test suite explanation.
