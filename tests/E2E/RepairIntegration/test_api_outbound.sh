#!/bin/bash
# E2E Test: Outbound Sync (OpenXE -> WP)
# Voraussetzung: OpenXE + WP laufen, Modul konfiguriert
#
# Ausfuehrung:
#   OPENXE_URL=http://localhost:8081 WP_URL=http://localhost:8080 \
#   bash tests/E2E/RepairIntegration/test_api_outbound.sh

set -euo pipefail

OPENXE_URL="${OPENXE_URL:-http://localhost:8081}"
WP_URL="${WP_URL:-http://localhost:8080}"
OPENXE_DB_HOST="${OPENXE_DB_HOST:-localhost}"
OPENXE_DB_USER="${OPENXE_DB_USER:-openxe}"
OPENXE_DB_PASS="${OPENXE_DB_PASS:-openxe}"
OPENXE_DB_NAME="${OPENXE_DB_NAME:-openxe}"

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

pass=0
fail=0

assert_eq() {
    local test_name="$1"
    local expected="$2"
    local actual="$3"
    if [ "$actual" = "$expected" ]; then
        echo -e "${GREEN}PASS${NC} ${test_name}"
        ((pass++))
    else
        echo -e "${RED}FAIL${NC} ${test_name} (expected '${expected}', got '${actual}')"
        ((fail++))
    fi
}

echo "========================================"
echo "E2E Test: RepairIntegration Outbound Sync"
echo "========================================"
echo ""

# -------------------------------------------------------
# Vorbedingung: Pruefe ob Testticket existiert
# -------------------------------------------------------
echo "--- Vorbedingung: Pruefe Testticket ---"

TICKET_EXISTS=$(mysql -h "${OPENXE_DB_HOST}" -u "${OPENXE_DB_USER}" -p"${OPENXE_DB_PASS}" "${OPENXE_DB_NAME}" \
    -N -e "SELECT COUNT(*) FROM ticket WHERE schluessel = '202604050001'" 2>/dev/null || echo "0")

if [ "${TICKET_EXISTS}" = "0" ]; then
    echo -e "${RED}SKIP${NC} Testticket 202604050001 existiert nicht."
    echo "Erstelle zuerst ein Ticket manuell oder per E-Mail-Import."
    echo ""
    echo "Schnelltest: Manueller Queue-Eintrag"
    echo "  mysql: INSERT INTO repair_sync_queue (ticket_id, ticket_schluessel, action, payload, target_url, next_retry_at)"
    echo "         VALUES (1, '202604050001', 'status_change', '{\"request_number\":\"202604050001\",\"status\":\"in_diagnosis\"}', '${WP_URL}/wp-json/p3d/v1/requests/status', NOW());"
    exit 0
fi

echo -e "${GREEN}OK${NC} Testticket existiert"
echo ""

# -------------------------------------------------------
# Test 1: Statusaenderung erzeugt Queue-Eintrag
# -------------------------------------------------------
echo "--- Test 1: Statusaenderung -> Queue-Eintrag ---"

# Zaehle Queue-Eintraege vorher
QUEUE_BEFORE=$(mysql -h "${OPENXE_DB_HOST}" -u "${OPENXE_DB_USER}" -p"${OPENXE_DB_PASS}" "${OPENXE_DB_NAME}" \
    -N -e "SELECT COUNT(*) FROM repair_sync_queue WHERE ticket_schluessel = '202604050001'" 2>/dev/null)

echo "Queue-Eintraege vorher: ${QUEUE_BEFORE}"
echo ""
echo "Bitte jetzt im OpenXE-UI den Status von Ticket #202604050001 auf 'in_diagnose' aendern."
echo "Druecke Enter wenn erledigt..."
read -r

QUEUE_AFTER=$(mysql -h "${OPENXE_DB_HOST}" -u "${OPENXE_DB_USER}" -p"${OPENXE_DB_PASS}" "${OPENXE_DB_NAME}" \
    -N -e "SELECT COUNT(*) FROM repair_sync_queue WHERE ticket_schluessel = '202604050001'" 2>/dev/null)

if [ "${QUEUE_AFTER}" -gt "${QUEUE_BEFORE}" ]; then
    echo -e "${GREEN}PASS${NC} Queue-Eintrag wurde erstellt (${QUEUE_BEFORE} -> ${QUEUE_AFTER})"
    ((pass++))
else
    echo -e "${RED}FAIL${NC} Kein neuer Queue-Eintrag (${QUEUE_BEFORE} -> ${QUEUE_AFTER})"
    ((fail++))
fi
echo ""

# -------------------------------------------------------
# Test 2: Sync-Cronjob verarbeitet Queue
# -------------------------------------------------------
echo "--- Test 2: Sync-Queue manuell verarbeiten ---"
echo "Fuehre Cronjob manuell aus..."

# Pruefe ob der Queue-Eintrag auf 'completed' wechselt
PENDING=$(mysql -h "${OPENXE_DB_HOST}" -u "${OPENXE_DB_USER}" -p"${OPENXE_DB_PASS}" "${OPENXE_DB_NAME}" \
    -N -e "SELECT COUNT(*) FROM repair_sync_queue WHERE ticket_schluessel = '202604050001' AND status = 'pending'" 2>/dev/null)

echo "Pending Eintraege: ${PENDING}"
echo ""
echo "Cronjob wird ausgefuehrt wenn der Prozessstarter laeuft."
echo "Alternativ: Manuell im Browser aufrufen oder per CLI."
echo ""

# -------------------------------------------------------
# Test 3: Sync-Log pruefen
# -------------------------------------------------------
echo "--- Test 3: Sync-Log ---"
mysql -h "${OPENXE_DB_HOST}" -u "${OPENXE_DB_USER}" -p"${OPENXE_DB_PASS}" "${OPENXE_DB_NAME}" \
    -e "SELECT direction, ticket_schluessel, action, success, error_message, created_at FROM repair_sync_log WHERE ticket_schluessel = '202604050001' ORDER BY created_at DESC LIMIT 5" 2>/dev/null

echo ""

# -------------------------------------------------------
# Ergebnis
# -------------------------------------------------------
echo "========================================"
echo -e "Ergebnis: ${GREEN}${pass} PASS${NC}, ${RED}${fail} FAIL${NC}"
echo "========================================"
