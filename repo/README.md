# RentOps

**Project Type:** fullstack

Role-based billing & booking platform for rentable assets. Supports multi-role auth (administrator, property_manager, tenant, finance_clerk), inventory + pricing, holds + bookings, bills + payments + refunds, notifications with DND, encrypted backups, terminals, audit logs, and reconciliation.

## Architecture & Tech Stack

* Frontend: React 18 + TypeScript + Vite + Zustand + React Router + TanStack Query
* Backend: Symfony 6.4 (PHP 8.2) REST API + Doctrine ORM + JWT auth
* Database: MySQL 8.0 (InnoDB, pessimistic locking)
* Containerization: Docker & Docker Compose

## Project Structure

```text
.
├── fullstack/
│   ├── backend/        # Symfony 6.4 API (PHP 8.2)
│   ├── frontend/       # React 18 + Vite (Node 18 LTS)
│   ├── e2e/            # Playwright browser-driven E2E tests
│   ├── storage/        # Runtime data (PDFs, backups, logs)
│   └── API_SPEC.md     # Full REST API specification
├── .env.example
├── docker-compose.yml
├── run_tests.sh
└── README.md
```

## Prerequisites

* Docker
* Docker Compose

## Running the Application

```bash
cp .env.example .env
docker-compose up --build -d
```

On first startup the containers automatically:

1. Run database migrations
2. Seed all demo accounts for every role (no manual bootstrap step needed)
3. Start a background scheduler for hold expiry, recurring billing, no-show evaluation, notifications, and reconciliation

Go straight to **http://localhost:3000** and log in with the credentials below.

## Demo Credentials

All accounts are seeded automatically on first startup.

| Role               | Username  | Password     | Access summary                                      |
| ------------------ | --------- | ------------ | --------------------------------------------------- |
| `administrator`    | `admin`   | `password123` | Full access — users, inventory, bookings, billing, settings, audit logs, backups |
| `property_manager` | `manager` | `password123` | Inventory, holds, bookings, notifications           |
| `tenant`           | `tenant`  | `password123` | Own bookings, own bills, payments                   |
| `finance_clerk`    | `clerk`   | `password123` | Bills, payments, refunds, ledger, reconciliation    |

## Access

* Frontend: http://localhost:3000
* Backend API: http://localhost:8080
* API docs: [fullstack/API_SPEC.md](fullstack/API_SPEC.md)

## Verification Checklist

Run these after `docker-compose up --build -d` to confirm the stack is healthy.

### 1 — Backend health

```bash
curl -s http://localhost:8080/api/v1/health
```

Expected response (`HTTP 200`):

```json
{"data":{"status":"ok"}}
```

### 2 — Admin login

```bash
curl -s -X POST http://localhost:8080/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"password123","device_label":"verify","client_device_id":"verify-1"}'
```

Expected response (`HTTP 200`) contains `access_token`, `refresh_token`, `session_id`, and `user.role = "administrator"`.

### 3 — Tenant login

```bash
curl -s -X POST http://localhost:8080/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"tenant","password":"password123","device_label":"verify","client_device_id":"verify-2"}'
```

Expected response (`HTTP 200`) contains `user.role = "tenant"`.

### 4 — Frontend loads

Open http://localhost:3000 in a browser. The login page should render. Log in as `admin`/`password123` — the dashboard should appear.

## Stop

```bash
docker-compose down -v
```

## Testing

```bash
chmod +x run_tests.sh
./run_tests.sh
```

All tests run inside Docker — backend unit + integration (PHPUnit on real MySQL), frontend (Vitest + React Testing Library on Node 18), E2E (Playwright, browser-driven), and a production build check. Exits 0 on full success, non-zero on any failure.

### Expected output per layer

| Layer | Runner | Pass indicator |
|-------|--------|----------------|
| Backend unit | PHPUnit | `OK (N tests, N assertions)` |
| Backend integration | PHPUnit | `OK (N tests, N assertions)` |
| Frontend | Vitest | `N tests passed` |
| E2E | Playwright | `N passed` |
| Build | Vite | `dist/` created, exit 0 |
