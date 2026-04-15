#!/usr/bin/env bash
# Docker-only test runner for RentOps.
# Builds containers if needed, resets the database, runs every test suite
# (unit + integration + coverage + E2E), and exits 0 on full success.
set -euo pipefail

PASS=0
FAIL=0

echo "============================================"
echo "  RentOps Test Suite"
echo "============================================"
echo ""

echo "--- Ensuring containers are built and running ---"
# Build all images (including E2E in profile)
docker compose --profile e2e build
# Start the main services (E2E is run on-demand)
docker compose up -d

echo ""
echo "--- Waiting for backend to be healthy ---"
for i in 1 2 3 4 5 6 7 8 9 10 11 12; do
    STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/api/v1/health 2>/dev/null || echo "000")
    if [ "$STATUS" = "200" ]; then
        echo "Backend ready."
        break
    fi
    echo "  Waiting ($i/12)..."
    sleep 5
done

echo ""
echo "--- Running Backend Unit Tests (with coverage) ---"
if docker compose exec -T -e XDEBUG_MODE=coverage backend php vendor/bin/phpunit --testsuite=unit --coverage-text --colors=always; then
    echo "Unit tests: PASSED"
    PASS=$((PASS + 1))
else
    echo "Unit tests: FAILED"
    FAIL=$((FAIL + 1))
fi

echo ""
echo "--- Resetting DB for integration tests ---"
docker compose exec -T backend php bin/console doctrine:schema:drop --force --full-database 2>/dev/null
docker compose exec -T backend php bin/console doctrine:migrations:migrate --no-interaction 2>/dev/null
docker compose exec -T backend php -r "
\$url = getenv('DATABASE_URL');
preg_match('#mysql://([^:]+):([^@]+)@([^:]+):(\d+)/([^?]+)#', \$url, \$m);
\$pdo = new PDO('mysql:host='.\$m[3].';port='.\$m[4].';dbname='.\$m[5], \$m[1], \$m[2]);
\$pdo->exec('ALTER TABLE audit_logs MODIFY COLUMN object_id VARCHAR(255) NOT NULL');
" 2>/dev/null
echo "DB reset complete."

echo ""
echo "--- Running Backend Integration Tests ---"
if docker compose exec -T backend php vendor/bin/phpunit --testsuite=integration --colors=always; then
    echo "Integration tests: PASSED"
    PASS=$((PASS + 1))
else
    echo "Integration tests: FAILED"
    FAIL=$((FAIL + 1))
fi

echo ""
echo "--- Running Backend Integration Tests (Backup/Restore) ---"
if docker compose exec -T backend php vendor/bin/phpunit --testsuite=integration-backup --colors=always; then
    echo "Backup integration tests: PASSED"
    PASS=$((PASS + 1))
else
    echo "Backup integration tests: FAILED"
    FAIL=$((FAIL + 1))
fi

echo ""
echo "--- Running Frontend Tests (with coverage) ---"
if docker compose exec -T frontend npx vitest run --coverage; then
    echo "Frontend tests: PASSED"
    PASS=$((PASS + 1))
else
    echo "Frontend tests: FAILED"
    FAIL=$((FAIL + 1))
fi

echo ""
echo "--- Frontend Build Check ---"
if docker compose exec -T frontend npx vite build; then
    echo "Frontend build: PASSED"
    PASS=$((PASS + 1))
else
    echo "Frontend build: FAILED"
    FAIL=$((FAIL + 1))
fi

echo ""
echo "--- Waiting for backend to be healthy before E2E ---"
for i in 1 2 3 4 5 6 7 8 9 10 11 12; do
    STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/api/v1/health 2>/dev/null || echo "000")
    if [ "$STATUS" = "200" ]; then
        echo "Backend ready for E2E."
        break
    fi
    echo "  Waiting ($i/12)..."
    sleep 5
done

echo ""
echo "--- Running E2E Tests (Playwright, browser-driven) ---"
if docker compose run --rm e2e; then
    echo "E2E tests: PASSED"
    PASS=$((PASS + 1))
else
    echo "E2E tests: FAILED"
    FAIL=$((FAIL + 1))
fi

echo ""
echo "============================================"
echo "  Results: $PASS passed, $FAIL failed"
echo "============================================"

if [ "$FAIL" -gt 0 ]; then
    exit 1
fi
exit 0
