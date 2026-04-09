# RentOps Billing & Booking Platform

Fully offline, role-based billing and booking platform for rentable assets.

## Prerequisites

- Docker & Docker Compose
- (Optional for local frontend dev) Node.js 18 LTS

## Quick Start

```bash
cd fullstack
docker compose up
```

That's it. On first startup, the containers automatically:

1. **mysql** — starts MySQL 8.0, creates the `rentops` database
2. **backend** — installs PHP dependencies, waits for MySQL, runs migrations, starts API on port 8080
3. **scheduler** — starts the background job runner (hold expiry, billing, notifications)
4. **frontend** — installs Node dependencies, starts Vite dev server on port 3000

### First-Run Bootstrap

After `docker compose up`, the database is empty. Open `http://localhost:3000/bootstrap` or call:

```bash
curl -X POST http://localhost:8080/api/v1/bootstrap \
  -H "Content-Type: application/json" \
  -d '{
    "organization_name": "My Company",
    "organization_code": "MYCO",
    "admin_username": "admin",
    "admin_password": "secure_password_123",
    "admin_display_name": "System Admin"
  }'
```

This creates the first organization and administrator. The endpoint is disabled after the first admin exists.

## Ports

| Service  | URL                        | Port |
|----------|----------------------------|------|
| Frontend | http://localhost:3000       | 3000 |
| Backend  | http://localhost:8080       | 8080 |
| MySQL    | mysql://localhost:3306      | 3306 |

## API Specification

See **[API_SPEC.md](API_SPEC.md)** for the complete REST API documentation covering all 65+ endpoints, request/response shapes, authentication, RBAC, error codes, and pagination.

## Running Tests

### Via Docker (recommended)

```bash
# All tests (backend unit + integration + frontend)
./run_tests.sh

# Or individually:
docker compose exec backend php vendor/bin/phpunit --testsuite=unit
docker compose exec backend php vendor/bin/phpunit --testsuite=integration
docker compose exec frontend npx vitest run
```

### Local frontend tests (no Docker needed)

```bash
cd frontend
npm install
npm test
```

Requires Node.js 18 LTS. The frontend enforces `engines.node: >=18.0.0 <19.0.0`.

### Test Suites

| Suite | Location | What it covers |
|-------|----------|----------------|
| **Backend Unit** | `backend/tests/Unit/Service/` | Service logic, billing, payments, RBAC, backups, throttling, scheduling, enum validation |
| **Backend Unit** | `backend/tests/Unit/Security/` | Auth firewall, RBAC matrix, error leakage, tenant isolation, access control coverage, username uniqueness |
| **Backend Unit** | `backend/tests/Unit/Command/` | Scheduler worker command |
| **Backend Integration** | `backend/tests/Integration/` | Full HTTP lifecycle, backup/restore, session cap, username uniqueness (real DB) |
| **Frontend** | `frontend/src/**/__tests__/` | API adapters, hold timer, booking detail, cancellation/no-show UI, role-based route protection |

## Runtime Requirements

| Component | Version |
|-----------|---------|
| Docker    | 20+     |
| Node.js   | 18 LTS (18.20.x) — frontend container uses `node:18.20-alpine` |
| PHP       | 8.2 — backend container uses `php:8.2-cli` |
| MySQL     | 8.0 — `mysql:8.0` Docker image |

## Environment Variables

| Variable               | Default                                    | Description                       |
|------------------------|--------------------------------------------|-----------------------------------|
| APP_ENV                | dev                                        | Symfony environment               |
| APP_SECRET             | change_me_in_production                    | Symfony app secret                |
| DATABASE_URL           | mysql://rentops:rentops_secret@mysql:3306/rentops | MySQL connection string    |
| JWT_SECRET             | local_jwt_secret_key_change_in_production  | JWT signing secret                |
| JWT_ACCESS_TOKEN_TTL   | 900                                        | Access token lifetime (seconds)   |
| JWT_REFRESH_TOKEN_TTL  | 1209600                                    | Refresh token lifetime (seconds)  |
| PAYMENT_SHARED_SECRET  | local_payment_shared_secret                | Payment signature shared secret   |
| BACKUP_ENCRYPTION_KEY  | local_backup_encryption_key_32ch           | AES-256-GCM backup encryption key |

## Architecture

- **Frontend**: React 18 + TypeScript + Vite (Zustand state, React Router, TanStack Query)
- **Backend**: Symfony 6.4 REST API (Doctrine ORM, JWT auth, RBAC)
- **Database**: MySQL 8.0 (InnoDB, foreign keys, pessimistic locking)
- **Auth**: Stateless JWT — access tokens (15 min) + refresh tokens (14 days)
- **Encryption**: AES-256-GCM for backup encryption
- **Background Jobs**: Symfony console scheduler (hold expiry, recurring billing, no-show evaluation, notifications, reconciliation)

## Storage Directories

| Directory                | Purpose                         |
|--------------------------|---------------------------------|
| `storage/pdfs/`          | Generated PDF receipts/invoices |
| `storage/exports/`       | CSV exports                     |
| `storage/backups/`       | Encrypted database backups      |
| `storage/logs/`          | Application logs                |
| `storage/terminal_assets/` | Terminal package files         |
| `storage/terminal_chunks/` | Terminal upload chunks         |

## Identity Model

Usernames are **globally unique** (not scoped per organization). This is a deliberate design decision because the login endpoint accepts only `username` + `password` with no organization identifier. See `User.php` and `UsernameUniquenessTest.php` for full documentation.
