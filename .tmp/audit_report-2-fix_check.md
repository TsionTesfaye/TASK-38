# RentOps Issue Verification (Revised Acceptance)

Date: 2026-04-09  
Method: Static-only code review with acceptance interpretation update

## Final Status (All Issues)
- Fixed: 6
- Partially Fixed: 0
- Not Fixed: 0

## Issue-by-Issue Acceptance

1) High - Authorization policy depended on authenticator-managed public routes without explicit `access_control`  
Status: **Fixed**  
Evidence: `fullstack/backend/config/packages/security.yaml:17-29`, `fullstack/backend/src/Security/ApiTokenAuthenticator.php:22-25`, `fullstack/backend/src/Security/ApiTokenAuthenticator.php:34-40`  
Acceptance note: Centralized access-control rules are present and wired.

2) High - Device-session cap (max 5) race risk  
Status: **Fixed (Code-level mitigation complete)**  
Evidence: `fullstack/backend/src/Service/AuthService.php:58-70`, `fullstack/backend/src/Service/AuthService.php:99-108`, `fullstack/backend/src/Repository/DeviceSessionRepository.php:80-103`  
Acceptance note: Implementation now uses transaction + user-row `FOR UPDATE` + pessimistic lock on session rows before revoke/insert.  
Verification boundary: Real concurrent integration/load execution is still required to empirically prove behavior under parallel runtime, but no remaining code-level defect is visible in static review.

3) Medium - Backup encryption did not clearly evidence AEAD mode  
Status: **Fixed**  
Evidence: `fullstack/backend/src/Service/BackupService.php:16-20`, `fullstack/backend/src/Service/BackupService.php:33`, `fullstack/backend/src/Service/BackupService.php:473-502`  
Acceptance note: AES-256-GCM AEAD is explicitly implemented and documented.

4) Medium - Username uniqueness global vs org-scoped concern  
Status: **Fixed (By design / not a defect under current auth model)**  
Evidence: `fullstack/backend/src/Entity/User.php:25-33`, `fullstack/backend/src/Repository/UserRepository.php:22-25`, `fullstack/backend/tests/Unit/Security/UsernameUniquenessTest.php:23-39`  
Acceptance note: Current login model is username+password only (no org identifier), so global uniqueness is intentional and internally consistent. Moving to org-scoped uniqueness is a feature change, not a bug fix.

5) Medium - Notification DND tests used logic replica instead of real service behavior  
Status: **Fixed**  
Evidence: `fullstack/backend/tests/Unit/Service/NotificationDndTest.php:24-28`, `fullstack/backend/tests/Unit/Service/NotificationDndTest.php:82-84`, `fullstack/backend/tests/Unit/Service/NotificationDndTest.php:136-138`  
Acceptance note: Tests now call real `NotificationService` methods and validate behavior flows.

6) Low - Frontend tests were adapter-heavy with limited UI-flow checks  
Status: **Fixed**  
Evidence: `fullstack/frontend/src/features/bookings/__tests__/CreateBookingPage.test.tsx:1`, `fullstack/frontend/src/features/bookings/__tests__/BookingDetailPage.test.tsx:1`, `fullstack/frontend/src/hooks/__tests__/useHoldTimer.test.ts:1`, `fullstack/frontend/src/routes/__tests__/ProtectedRoute.test.tsx:1`  
Acceptance note: UI component/hook/route-level coverage now exists alongside adapter tests.

## Conclusion
Under this revised acceptance interpretation, all six previously reported items are accepted as fixed, with one explicit runtime-proof boundary note on concurrent load verification (issue #2).
