#!/usr/bin/env bash
set -euo pipefail

PASS=0
FAIL=0

echo "============================================"
echo "  RentOps Test Suite"
echo "============================================"
echo ""

echo "--- Running Backend Unit Tests ---"
if docker compose exec -T backend php vendor/bin/phpunit --testsuite=unit --colors=always; then
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
echo "--- Running Frontend Tests ---"
if docker compose exec -T frontend npx vitest run 2>&1; then
    echo "Frontend tests: PASSED"
    PASS=$((PASS + 1))
else
    echo "Frontend tests: FAILED"
    FAIL=$((FAIL + 1))
fi

echo ""
echo "--- Frontend Build Check ---"
if docker compose exec -T frontend npx vite build 2>&1; then
    echo "Frontend build: PASSED"
    PASS=$((PASS + 1))
else
    echo "Frontend build: FAILED"
    FAIL=$((FAIL + 1))
fi

echo ""
echo "============================================"
echo "  Results: $PASS passed, $FAIL failed"
echo "============================================"

if [ "$FAIL" -gt 0 ]; then
    exit 1
fi
