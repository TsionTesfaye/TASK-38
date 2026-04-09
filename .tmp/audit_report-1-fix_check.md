# RentOps Issue Revalidation (Static) - V3

Date: 2026-04-09
Scope: Static code/test inspection only (no runtime execution)

## Overall Result
- Fixed: 6
- Partially Fixed: 0
- Not Fixed: 0

## Issue-by-Issue Status

### 1) High - Device-session cap can remain above 5 active sessions
- Status: **Fixed**
- Evidence:
  - Auth flow now wraps session-cap enforcement and session creation in a transaction: `fullstack/backend/src/Service/AuthService.php:58-77`
  - Uses `revokeExcessByUserId(...)` before creating the new session: `fullstack/backend/src/Service/AuthService.php:64`
- Conclusion: The old “revoke-only-one” behavior is replaced by bulk/excess revocation with transactional safety.

### 2) High - Backup integration test used domain-invalid status/type values
- Status: **Fixed**
- Evidence:
  - Seed bill type now valid: `initial` at `fullstack/backend/tests/Integration/BackupRestoreIntegrationTest.php:153`
  - Seed payment status now valid: `succeeded` at `fullstack/backend/tests/Integration/BackupRestoreIntegrationTest.php:169`
  - Seed refund status now valid: `issued` at `fullstack/backend/tests/Integration/BackupRestoreIntegrationTest.php:187`
  - Enum sets include these values: `fullstack/backend/src/Enum/BillType.php:9-12`, `fullstack/backend/src/Enum/PaymentStatus.php:9-12`, `fullstack/backend/src/Enum/RefundStatus.php:9-10`
- Conclusion: Fixture data is aligned with domain enums.

### 3) Medium - Dual authentication layers increase drift risk
- Status: **Fixed**
- Evidence:
  - Source `Security` folder now contains `ApiTokenAuthenticator.php` but no `JwtAuthenticator.php`: `fullstack/backend/src/Security`
  - Firewall uses only `ApiTokenAuthenticator`: `fullstack/backend/config/packages/security.yaml:11-13`
  - Authenticator itself sets `authenticated_user` on success (single-path flow): `fullstack/backend/src/Security/ApiTokenAuthenticator.php:102-109`
- Conclusion: Dual-path auth drift risk has been removed in source.

### 4) Medium - Security-critical coverage uneven; logic-replica tests
- Status: **Fixed**
- Evidence:
  - Previous replica-style cap check is now real-service style (calls `authenticate(...)`, asserts repository interaction): `fullstack/backend/tests/Unit/Service/AuthSessionHardeningTest.php:260-306`
  - Integration callback coverage is now expanded beyond a single invalid-signature check:
    - Full callback integration section introduced: `fullstack/backend/tests/Integration/FullFlowHttpTest.php:353-366`
    - Invalid signature with DB non-mutation assertions: `fullstack/backend/tests/Integration/FullFlowHttpTest.php:428-460`
    - Amount mismatch test: `fullstack/backend/tests/Integration/FullFlowHttpTest.php:464-496`
    - Currency mismatch test: `fullstack/backend/tests/Integration/FullFlowHttpTest.php:500-520`
- Conclusion: The specific coverage concerns previously raised are now addressed.

### 5) Medium - Recurring billing scheduling split across two models
- Status: **Fixed**
- Evidence:
  - No `AppScheduleProvider` class present in source tree under `fullstack/backend/src`.
  - Central scheduler table lives in `SchedulerService`: `fullstack/backend/src/Service/SchedulerService.php:38-69`
  - Worker command delegates scheduling to that single service: `fullstack/backend/src/Command/SchedulerWorkerCommand.php:15-17`, `:40-53`
- Conclusion: The earlier split-model ambiguity is removed at source level.

### 6) Low - Frontend chunk-to-base64 conversion brittleness
- Status: **Fixed**
- Evidence:
  - Upload code now uses `FileReader` + `readAsDataURL` extraction instead of spread-based `String.fromCharCode(...Uint8Array)` conversion: `fullstack/frontend/src/features/terminals/TerminalListPage.tsx:133-140`
- Conclusion: The flagged conversion path has been replaced with a safer approach.

## Final Verdict
All six previously reported issues are fixed based on current static evidence.
