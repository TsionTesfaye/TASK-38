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
echo "--- Running Backend Integration Tests ---"
if docker compose exec -T backend php vendor/bin/phpunit --testsuite=integration --colors=always; then
    echo "Integration tests: PASSED"
    PASS=$((PASS + 1))
else
    echo "Integration tests: FAILED"
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
