#!/bin/bash
# E2E Test: DB-Migration pruefen
# Voraussetzung: MariaDB laeuft, DB 'openxe' existiert
#
# Ausfuehrung:
#   OPENXE_DB_HOST=localhost OPENXE_DB_USER=openxe OPENXE_DB_PASS=openxe \
#   bash tests/E2E/RepairIntegration/test_db_migration.sh

set -euo pipefail

OPENXE_DB_HOST="${OPENXE_DB_HOST:-localhost}"
OPENXE_DB_USER="${OPENXE_DB_USER:-openxe}"
OPENXE_DB_PASS="${OPENXE_DB_PASS:-openxe}"
OPENXE_DB_NAME="${OPENXE_DB_NAME:-openxe}"

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

pass=0
fail=0

MYSQL_CMD="mysql -h ${OPENXE_DB_HOST} -u ${OPENXE_DB_USER} -p${OPENXE_DB_PASS} ${OPENXE_DB_NAME} -N"

assert_table_exists() {
    local table="$1"
    local count
    count=$($MYSQL_CMD -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${OPENXE_DB_NAME}' AND table_name = '${table}'" 2>/dev/null)
    if [ "$count" = "1" ]; then
        echo -e "${GREEN}PASS${NC} Tabelle '${table}' existiert"
        ((pass++))
    else
        echo -e "${RED}FAIL${NC} Tabelle '${table}' fehlt"
        ((fail++))
    fi
}

assert_min_rows() {
    local table="$1"
    local min_rows="$2"
    local count
    count=$($MYSQL_CMD -e "SELECT COUNT(*) FROM ${table}" 2>/dev/null)
    if [ "$count" -ge "$min_rows" ]; then
        echo -e "${GREEN}PASS${NC} '${table}' hat ${count} Zeilen (min. ${min_rows})"
        ((pass++))
    else
        echo -e "${RED}FAIL${NC} '${table}' hat ${count} Zeilen (erwartet min. ${min_rows})"
        ((fail++))
    fi
}

assert_column_exists() {
    local table="$1"
    local column="$2"
    local count
    count=$($MYSQL_CMD -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '${OPENXE_DB_NAME}' AND table_name = '${table}' AND column_name = '${column}'" 2>/dev/null)
    if [ "$count" = "1" ]; then
        echo -e "${GREEN}PASS${NC} '${table}.${column}' existiert"
        ((pass++))
    else
        echo -e "${RED}FAIL${NC} '${table}.${column}' fehlt"
        ((fail++))
    fi
}

echo "========================================"
echo "E2E Test: DB-Migration Verifikation"
echo "Host: ${OPENXE_DB_HOST}, DB: ${OPENXE_DB_NAME}"
echo "========================================"
echo ""

# -------------------------------------------------------
# Test 1: Alle 6 Tabellen existieren
# -------------------------------------------------------
echo "--- Tabellen ---"
assert_table_exists "ticket_status_config"
assert_table_exists "ticket_repair_details"
assert_table_exists "repair_sync_queue"
assert_table_exists "repair_sync_log"
assert_table_exists "repair_api_ratelimit"
assert_table_exists "repair_ticket_beleg"
echo ""

# -------------------------------------------------------
# Test 2: Status-Seed-Daten
# -------------------------------------------------------
echo "--- Seed-Daten ---"
assert_min_rows "ticket_status_config" 27
echo ""

# -------------------------------------------------------
# Test 3: Kritische Spalten in ticket_repair_details
# -------------------------------------------------------
echo "--- Spalten ticket_repair_details ---"
assert_column_exists "ticket_repair_details" "ticket_id"
assert_column_exists "ticket_repair_details" "service_type"
assert_column_exists "ticket_repair_details" "manufacturer"
assert_column_exists "ticket_repair_details" "serial_number"
assert_column_exists "ticket_repair_details" "diagnosis_result"
assert_column_exists "ticket_repair_details" "anonymized_at"
echo ""

# -------------------------------------------------------
# Test 4: Status-Kategorien vorhanden
# -------------------------------------------------------
echo "--- Status-Kategorien ---"
for cat in general repair maintenance reverse_engineering individualization; do
    count=$($MYSQL_CMD -e "SELECT COUNT(*) FROM ticket_status_config WHERE category = '${cat}'" 2>/dev/null)
    if [ "$count" -gt "0" ]; then
        echo -e "${GREEN}PASS${NC} Kategorie '${cat}' hat ${count} Eintraege"
        ((pass++))
    else
        echo -e "${RED}FAIL${NC} Kategorie '${cat}' hat keine Eintraege"
        ((fail++))
    fi
done
echo ""

# -------------------------------------------------------
# Test 5: WP-Status-Mappings vorhanden
# -------------------------------------------------------
echo "--- WP-Mappings ---"
WP_MAPPED=$($MYSQL_CMD -e "SELECT COUNT(*) FROM ticket_status_config WHERE wp_status_mapping IS NOT NULL AND wp_status_mapping != ''" 2>/dev/null)
if [ "$WP_MAPPED" -ge "10" ]; then
    echo -e "${GREEN}PASS${NC} ${WP_MAPPED} Status haben WP-Mapping"
    ((pass++))
else
    echo -e "${RED}FAIL${NC} Nur ${WP_MAPPED} Status mit WP-Mapping (erwartet min. 10)"
    ((fail++))
fi
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
