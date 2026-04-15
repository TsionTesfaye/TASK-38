# RentOps

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

On first startup the containers automatically run database migrations and start a background scheduler for hold expiry, recurring billing, no-show evaluation, notifications, and reconciliation.

First-run bootstrap (one-time, creates the first organization + admin):

```bash
curl -X POST http://localhost:8080/api/v1/bootstrap \
  -H "Content-Type: application/json" \
  -d '{
    "organization_name": "My Company",
    "organization_code": "MYCO",
    "admin_username": "admin",
    "admin_password": "password123",
    "admin_display_name": "System Admin"
  }'
```

## Access

* Frontend: http://localhost:3000
* Backend: http://localhost:8080
* API docs: [fullstack/API_SPEC.md](fullstack/API_SPEC.md)

## Stop

```bash
docker-compose down -v
```

## Testing

```bash
chmod +x run_tests.sh
./run_tests.sh
```

All tests run inside Docker — backend unit + integration (PHPUnit on real MySQL), frontend (Vitest + React Testing Library on Node 18), and a production build check. Exits 0 on full success, non-zero on any failure.

## Seeded Credentials

No users are seeded by default — the system is empty until `/api/v1/bootstrap` is called (see "Running the Application" above). After bootstrap, the following test credentials are available:

| Role  | Username | Password      |
| ----- | -------- | ------------- |
| Admin | admin    | password123   |
| User  | _created via `POST /users` by admin_ | _admin-set_ |
| Guest | _public endpoints only_ (`/health`, `/bootstrap`, `/auth/login`, `/auth/refresh`, `/payments/callback`) | — |
