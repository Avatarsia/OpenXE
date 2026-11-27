#!/bin/bash
# E2E Test: Inbound API (WP -> OpenXE)
# Voraussetzung: OpenXE laeuft unter $OPENXE_URL, Shared Secret konfiguriert
#
# Ausfuehrung:
#   OPENXE_URL=http://localhost:8081 SHARED_SECRET=test123 bash tests/E2E/RepairIntegration/test_api_inbound.sh

set -euo pipefail

OPENXE_URL="${OPENXE_URL:-http://localhost:8081}"
SHARED_SECRET="${SHARED_SECRET:-test_shared_secret_change_me}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
FIXTURE_DIR="${SCRIPT_DIR}/fixtures"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

pass=0
fail=0

assert_http_code() {
    local test_name="$1"
    local expected="$2"
    local actual="$3"
    if [ "$actual" = "$expected" ]; then
        echo -e "${GREEN}PASS${NC} ${test_name} (HTTP ${actual})"
        ((pass++))
    else
        echo -e "${RED}FAIL${NC} ${test_name} (expected HTTP ${expected}, got ${actual})"
        ((fail++))
    fi
}

generate_signature() {
    local payload="$1"
    local timestamp="$2"
    echo -n "${timestamp}.${payload}" | openssl dgst -sha256 -hmac "${SHARED_SECRET}" | awk '{print $NF}'
}

echo "========================================"
echo "E2E Test: RepairIntegration Inbound API"
echo "OpenXE URL: ${OPENXE_URL}"
echo "========================================"
echo ""

# -------------------------------------------------------
# Test 1: Gueltige Anfrage mit korrekter HMAC-Signatur
# -------------------------------------------------------
echo "--- Test 1: Gueltiger Push mit HMAC ---"
PAYLOAD=$(cat "${FIXTURE_DIR}/inbound_api_payload.json")
TIMESTAMP=$(date +%s)
SIGNATURE=$(generate_signature "${PAYLOAD}" "${TIMESTAMP}")

HTTP_CODE=$(curl -s -o /tmp/e2e_response.json -w "%{http_code}" \
    -X POST "${OPENXE_URL}/index.php?module=repairapi&action=push_details" \
    -H "Content-Type: application/json" \
    -H "X-Signature: ${SIGNATURE}" \
    -H "X-Timestamp: ${TIMESTAMP}" \
    -d "${PAYLOAD}")

assert_http_code "Gueltiger Inbound-Push" "200" "${HTTP_CODE}"
echo "  Response: $(cat /tmp/e2e_response.json)"
echo ""

# -------------------------------------------------------
# Test 2: Falsche Signatur -> 401
# -------------------------------------------------------
echo "--- Test 2: Falsche Signatur ---"
HTTP_CODE=$(curl -s -o /tmp/e2e_response.json -w "%{http_code}" \
    -X POST "${OPENXE_URL}/index.php?module=repairapi&action=push_details" \
    -H "Content-Type: application/json" \
    -H "X-Signature: invalid_signature_here" \
    -H "X-Timestamp: ${TIMESTAMP}" \
    -d "${PAYLOAD}")

assert_http_code "Falsche Signatur wird abgelehnt" "401" "${HTTP_CODE}"
echo "  Response: $(cat /tmp/e2e_response.json)"
echo ""

# -------------------------------------------------------
# Test 3: Abgelaufener Timestamp -> 401
# -------------------------------------------------------
echo "--- Test 3: Abgelaufener Timestamp ---"
OLD_TIMESTAMP=$((TIMESTAMP - 600))
OLD_SIGNATURE=$(generate_signature "${PAYLOAD}" "${OLD_TIMESTAMP}")

HTTP_CODE=$(curl -s -o /tmp/e2e_response.json -w "%{http_code}" \
    -X POST "${OPENXE_URL}/index.php?module=repairapi&action=push_details" \
    -H "Content-Type: application/json" \
    -H "X-Signature: ${OLD_SIGNATURE}" \
    -H "X-Timestamp: ${OLD_TIMESTAMP}" \
    -d "${PAYLOAD}")

assert_http_code "Abgelaufener Timestamp abgelehnt" "401" "${HTTP_CODE}"
echo ""

# -------------------------------------------------------
# Test 4: Fehlende Pflichtfelder -> 400
# -------------------------------------------------------
echo "--- Test 4: Fehlende Pflichtfelder ---"
INCOMPLETE='{"status": "new"}'
TS4=$(date +%s)
SIG4=$(generate_signature "${INCOMPLETE}" "${TS4}")

HTTP_CODE=$(curl -s -o /tmp/e2e_response.json -w "%{http_code}" \
    -X POST "${OPENXE_URL}/index.php?module=repairapi&action=push_details" \
    -H "Content-Type: application/json" \
    -H "X-Signature: ${SIG4}" \
    -H "X-Timestamp: ${TS4}" \
    -d "${INCOMPLETE}")

assert_http_code "Fehlende Pflichtfelder -> 400" "400" "${HTTP_CODE}"
echo "  Response: $(cat /tmp/e2e_response.json)"
echo ""

# -------------------------------------------------------
# Test 5: Falscher Content-Type -> 400
# -------------------------------------------------------
echo "--- Test 5: Falscher Content-Type ---"
TS5=$(date +%s)
SIG5=$(generate_signature "${PAYLOAD}" "${TS5}")

HTTP_CODE=$(curl -s -o /tmp/e2e_response.json -w "%{http_code}" \
    -X POST "${OPENXE_URL}/index.php?module=repairapi&action=push_details" \
    -H "Content-Type: text/plain" \
    -H "X-Signature: ${SIG5}" \
    -H "X-Timestamp: ${TS5}" \
    -d "${PAYLOAD}")

assert_http_code "Falscher Content-Type abgelehnt" "400" "${HTTP_CODE}"
echo ""

# -------------------------------------------------------
# Test 6: GET statt POST -> 400
# -------------------------------------------------------
echo "--- Test 6: GET statt POST ---"
HTTP_CODE=$(curl -s -o /tmp/e2e_response.json -w "%{http_code}" \
    -X GET "${OPENXE_URL}/index.php?module=repairapi&action=push_details")

assert_http_code "GET wird abgelehnt" "400" "${HTTP_CODE}"
echo ""

# -------------------------------------------------------
# Ergebnis
# -------------------------------------------------------
echo "========================================"
echo -e "Ergebnis: ${GREEN}${pass} PASS${NC}, ${RED}${fail} FAIL${NC}"
echo "========================================"

if [ "${fail}" -gt 0 ]; then
    exit 1
fi
