#!/bin/bash
#
# End-to-end test: starts Docker, seeds the DB, tests the API and CLI.
#
# Usage:
#   ./build/scripts/test-e2e.sh
#
# Prerequisites:
#   - Docker & Docker Compose
#   - Go 1.22+ (to build the CLI)
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$PROJECT_DIR"

PASS=0
FAIL=0
TESTS=()

pass() { ((PASS++)); TESTS+=("PASS: $1"); echo "  ✓ $1"; }
fail() { ((FAIL++)); TESTS+=("FAIL: $1 — $2"); echo "  ✗ $1: $2"; }

check_status() {
    local desc="$1" expected="$2" actual="$3" body="$4"
    if [ "$actual" -eq "$expected" ]; then
        pass "$desc (HTTP $actual)"
    else
        fail "$desc" "expected $expected, got $actual — $body"
    fi
}

# ─── 1. Start containers ────────────────────────────────────────────────
echo "=== Starting Docker containers ==="
docker compose down -v 2>/dev/null || true
docker compose up -d --build
echo "Waiting for MySQL to be healthy..."
for i in $(seq 1 60); do
    if docker compose exec -T db mysqladmin ping -h localhost -u root -prootpass &>/dev/null; then
        break
    fi
    sleep 2
done
echo "MySQL is ready."

# ─── 2. Seed the database ───────────────────────────────────────────────
echo ""
echo "=== Seeding database ==="
SEED_OUTPUT=$(docker compose exec -T app php /var/www/html/build/scripts/docker-seed.php 2>&1)
echo "$SEED_OUTPUT"

API_KEY=$(echo "$SEED_OUTPUT" | grep "^API Key:" | awk '{print $3}')
if [ -z "$API_KEY" ]; then
    echo "ERROR: Could not extract API key from seed output"
    docker compose logs
    exit 1
fi
echo "Using API key: ${API_KEY:0:20}..."

BASE_URL="http://localhost:8080/api/v3"

# ─── 3. Test API endpoints ──────────────────────────────────────────────
echo ""
echo "=== Testing API endpoints ==="

# Health (no auth)
STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" "$BASE_URL/system/health")
BODY=$(cat /tmp/e2e-body)
check_status "GET /system/health" 200 "$STATUS" "$BODY"

# API root (authenticated)
STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" -H "Authorization: Bearer $API_KEY" "$BASE_URL/")
BODY=$(cat /tmp/e2e-body)
check_status "GET / (API root)" 200 "$STATUS" "$BODY"

# Versions (no auth)
STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" "http://localhost:8080/api/versions")
BODY=$(cat /tmp/e2e-body)
check_status "GET /versions" 200 "$STATUS" "$BODY"

# Capabilities
STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" -H "Authorization: Bearer $API_KEY" "$BASE_URL/capabilities")
BODY=$(cat /tmp/e2e-body)
check_status "GET /capabilities" 200 "$STATUS" "$BODY"

# ── CRUD: campaigns ─────────────────────────────────────────────────────
STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" -H "Authorization: Bearer $API_KEY" "$BASE_URL/campaigns")
BODY=$(cat /tmp/e2e-body)
check_status "GET /campaigns (list)" 200 "$STATUS" "$BODY"

STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" \
    -X POST -H "Authorization: Bearer $API_KEY" -H "Content-Type: application/json" \
    -d '{"aff_campaign_name":"E2E Test Campaign","aff_campaign_url":"https://example.com/offer"}' \
    "$BASE_URL/campaigns")
BODY=$(cat /tmp/e2e-body)
check_status "POST /campaigns (create)" 201 "$STATUS" "$BODY"
CAMPAIGN_ID=$(echo "$BODY" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['aff_campaign_id'])" 2>/dev/null || echo "")

if [ -n "$CAMPAIGN_ID" ]; then
    STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" -H "Authorization: Bearer $API_KEY" "$BASE_URL/campaigns/$CAMPAIGN_ID")
    BODY=$(cat /tmp/e2e-body)
    check_status "GET /campaigns/$CAMPAIGN_ID" 200 "$STATUS" "$BODY"

    STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" \
        -X PUT -H "Authorization: Bearer $API_KEY" -H "Content-Type: application/json" \
        -d '{"aff_campaign_name":"E2E Updated"}' \
        "$BASE_URL/campaigns/$CAMPAIGN_ID")
    BODY=$(cat /tmp/e2e-body)
    check_status "PUT /campaigns/$CAMPAIGN_ID (update)" 200 "$STATUS" "$BODY"

    STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" \
        -X DELETE -H "Authorization: Bearer $API_KEY" "$BASE_URL/campaigns/$CAMPAIGN_ID")
    check_status "DELETE /campaigns/$CAMPAIGN_ID" 204 "$STATUS" ""
fi

# ── Aff networks ─────────────────────────────────────────────────────────
STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" -H "Authorization: Bearer $API_KEY" "$BASE_URL/aff-networks")
check_status "GET /aff-networks (list)" 200 "$STATUS" "$(cat /tmp/e2e-body)"

# ── PPC networks ─────────────────────────────────────────────────────────
STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" -H "Authorization: Bearer $API_KEY" "$BASE_URL/ppc-networks")
check_status "GET /ppc-networks (list)" 200 "$STATUS" "$(cat /tmp/e2e-body)"

# ── Trackers ─────────────────────────────────────────────────────────────
STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" -H "Authorization: Bearer $API_KEY" "$BASE_URL/trackers")
check_status "GET /trackers (list)" 200 "$STATUS" "$(cat /tmp/e2e-body)"

# ── Landing pages ────────────────────────────────────────────────────────
STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" -H "Authorization: Bearer $API_KEY" "$BASE_URL/landing-pages")
check_status "GET /landing-pages (list)" 200 "$STATUS" "$(cat /tmp/e2e-body)"

# ── Text ads ─────────────────────────────────────────────────────────────
STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" -H "Authorization: Bearer $API_KEY" "$BASE_URL/text-ads")
check_status "GET /text-ads (list)" 200 "$STATUS" "$(cat /tmp/e2e-body)"

# ── Clicks ───────────────────────────────────────────────────────────────
STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" -H "Authorization: Bearer $API_KEY" "$BASE_URL/clicks")
check_status "GET /clicks (list)" 200 "$STATUS" "$(cat /tmp/e2e-body)"

# ── Conversions ──────────────────────────────────────────────────────────
STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" -H "Authorization: Bearer $API_KEY" "$BASE_URL/conversions")
check_status "GET /conversions (list)" 200 "$STATUS" "$(cat /tmp/e2e-body)"

# ── Reports ──────────────────────────────────────────────────────────────
for report in summary breakdown timeseries daypart weekpart; do
    STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" -H "Authorization: Bearer $API_KEY" "$BASE_URL/reports/$report")
    check_status "GET /reports/$report" 200 "$STATUS" "$(cat /tmp/e2e-body)"
done

# ── Rotators ─────────────────────────────────────────────────────────────
STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" \
    -X POST -H "Authorization: Bearer $API_KEY" -H "Content-Type: application/json" \
    -d '{"name":"E2E Rotator"}' \
    "$BASE_URL/rotators")
BODY=$(cat /tmp/e2e-body)
check_status "POST /rotators (create)" 201 "$STATUS" "$BODY"
ROTATOR_ID=$(echo "$BODY" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])" 2>/dev/null || echo "")

if [ -n "$ROTATOR_ID" ]; then
    STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" -H "Authorization: Bearer $API_KEY" "$BASE_URL/rotators/$ROTATOR_ID/rules")
    check_status "GET /rotators/$ROTATOR_ID/rules (rule-list)" 200 "$STATUS" "$(cat /tmp/e2e-body)"

    STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" \
        -X DELETE -H "Authorization: Bearer $API_KEY" "$BASE_URL/rotators/$ROTATOR_ID")
    check_status "DELETE /rotators/$ROTATOR_ID" 204 "$STATUS" ""
fi

# ── System (admin) ───────────────────────────────────────────────────────
for endpoint in version db-stats cron errors dataengine metrics; do
    STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" -H "Authorization: Bearer $API_KEY" "$BASE_URL/system/$endpoint")
    check_status "GET /system/$endpoint" 200 "$STATUS" "$(cat /tmp/e2e-body)"
done

# ── Users ────────────────────────────────────────────────────────────────
STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" -H "Authorization: Bearer $API_KEY" "$BASE_URL/users")
check_status "GET /users (list)" 200 "$STATUS" "$(cat /tmp/e2e-body)"

# ── Attribution ──────────────────────────────────────────────────────────
STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" -H "Authorization: Bearer $API_KEY" "$BASE_URL/attribution/models")
check_status "GET /attribution/models (list)" 200 "$STATUS" "$(cat /tmp/e2e-body)"

# ── Bulk upsert ──────────────────────────────────────────────────────────
STATUS=$(curl -s -o /tmp/e2e-body -w "%{http_code}" \
    -X POST -H "Authorization: Bearer $API_KEY" -H "Content-Type: application/json" \
    -H "Idempotency-Key: e2e-test-$(date +%s)" \
    -d '{"rows":[{"aff_network_name":"Bulk Network 1"},{"aff_network_name":"Bulk Network 2"}]}' \
    "$BASE_URL/aff-networks/bulk-upsert")
check_status "POST /aff-networks/bulk-upsert" 200 "$STATUS" "$(cat /tmp/e2e-body)"

# ─── 4. Test CLI against live API ────────────────────────────────────────
echo ""
echo "=== Testing CLI against live API ==="

CLI="$PROJECT_DIR/go-cli/dist/p202"
if [ ! -f "$CLI" ]; then
    echo "Building CLI..."
    cd "$PROJECT_DIR/go-cli"
    go build -o dist/p202 .
    cd "$PROJECT_DIR"
fi

# Configure CLI profile
mkdir -p /tmp/e2e-p202-config
export P202_CONFIG_DIR=/tmp/e2e-p202-config
$CLI config set-url http://localhost:8080
$CLI config set-key "$API_KEY"

# Test CLI commands
for cmd in \
    "system health" \
    "system version" \
    "system db-stats" \
    "system metrics" \
    "campaign list" \
    "aff-network list" \
    "ppc-network list" \
    "tracker list" \
    "landing-page list" \
    "text-ad list" \
    "click list" \
    "conversion list" \
    "rotator list" \
    "user list" \
    "attribution list" \
    "report summary" \
    ; do
    OUTPUT=$($CLI $cmd 2>&1) && RC=$? || RC=$?
    if [ $RC -eq 0 ]; then
        pass "CLI: p202 $cmd"
    else
        fail "CLI: p202 $cmd" "exit=$RC output=${OUTPUT:0:100}"
    fi
done

# Test CLI CRUD workflow
echo ""
echo "--- CLI CRUD workflow ---"
CREATE_OUT=$($CLI aff-network create --aff_network_name "CLI Test Network" 2>&1) && RC=$? || RC=$?
if [ $RC -eq 0 ]; then
    pass "CLI: aff-network create"
    NET_ID=$(echo "$CREATE_OUT" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['aff_network_id'])" 2>/dev/null || echo "")
    if [ -n "$NET_ID" ]; then
        $CLI aff-network get "$NET_ID" &>/dev/null && pass "CLI: aff-network get $NET_ID" || fail "CLI: aff-network get $NET_ID" "failed"
        $CLI aff-network update "$NET_ID" --aff_network_name "CLI Updated" &>/dev/null && pass "CLI: aff-network update" || fail "CLI: aff-network update" "failed"
        $CLI aff-network delete "$NET_ID" --force &>/dev/null && pass "CLI: aff-network delete" || fail "CLI: aff-network delete" "failed"
    fi
else
    fail "CLI: aff-network create" "exit=$RC"
fi

# Test rotator rule-list (new command)
ROTATOR_OUT=$($CLI rotator create --name "CLI Test Rotator" 2>&1) && RC=$? || RC=$?
if [ $RC -eq 0 ]; then
    pass "CLI: rotator create"
    ROT_ID=$(echo "$ROTATOR_OUT" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])" 2>/dev/null || echo "")
    if [ -n "$ROT_ID" ]; then
        $CLI rotator rule-list "$ROT_ID" &>/dev/null && pass "CLI: rotator rule-list $ROT_ID" || fail "CLI: rotator rule-list" "failed"
        $CLI rotator delete "$ROT_ID" --force &>/dev/null && pass "CLI: rotator delete" || fail "CLI: rotator delete" "failed"
    fi
else
    fail "CLI: rotator create" "exit=$RC"
fi

# ─── 5. Summary ──────────────────────────────────────────────────────────
echo ""
echo "==============================="
echo "  E2E Test Results"
echo "==============================="
echo "  Passed: $PASS"
echo "  Failed: $FAIL"
echo ""
for t in "${TESTS[@]}"; do echo "  $t"; done
echo ""

# ─── 6. Cleanup ──────────────────────────────────────────────────────────
echo "Stopping containers..."
docker compose down -v
rm -rf /tmp/e2e-p202-config /tmp/e2e-body

if [ "$FAIL" -gt 0 ]; then
    echo "SOME TESTS FAILED"
    exit 1
fi
echo "ALL TESTS PASSED"
