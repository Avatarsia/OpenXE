<?php

/**
 * Lexware Office — Payload-Dump-Verification.
 *
 * Standalone-Script (kein PHPUnit), das die oeffentlichen Mapping-Methoden
 * des LexwareOfficePayloadMapper direkt aufruft und die resultierenden
 * Payloads dumpt. Dient der manuellen Verifikation dass alle Spec-Compliance-
 * Fixes zusammen ein valides Lexware-Voucher-Payload erzeugen.
 *
 * Aufruf:
 *     cd openxe/
 *     php tests/lexware_payload_dump.php
 *
 * Prueft die Voucher-Payloads gegen die bekannten Lexware-Pflichten (POST /v1/vouchers):
 *  - type salesinvoice/salescreditnote, voucherStatus 'open', taxType 'gross'
 *  - contactId top-level (NICHT address.contactId)
 *  - voucherDate/dueDate im yyyy-MM-dd-Format (KEIN RFC3339/T/Zeitzone)
 *  - voucherItems[] mit GENAU amount/taxAmount/taxRatePercent/categoryId
 *  - totalGrossAmount/totalTaxAmount = Summe der Item-Werte, alle >= 0
 *  - KEINE Invoice-Felder (lineItems/version/currency/title/address)
 *  - company vs person mutually exclusive (Contact-Payload)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Xentral\Modules\LexwareOffice\Service\LexwareOfficePayloadMapper;

$mapper = new LexwareOfficePayloadMapper();

// Default-Erloeskategorie wie vom ConfigService geliefert (neutraler Default).
$defaultCategoryId = '8f8664a1-fd86-11e1-a21f-0800200c9a66';

/** @var list<array{0: string, 1: array}> $failures */
$failures = [];

function expect(bool $condition, string $description, array &$failures): void
{
    if ($condition) {
        echo "  [OK]  $description\n";
    } else {
        echo "  [FAIL] $description\n";
        $failures[] = [$description, []];
    }
}

// ----------------------------------------------------------------------
// Fixtures
// ----------------------------------------------------------------------

$invoiceFirma = [
    'id' => 101,
    'datum' => '2026-04-08',
    'belegnr' => 'RE-2026-0042',
    'name' => 'Musterfirma GmbH',
    'adresszusatz' => 'Gebaeude B',
    'strasse' => 'Beispielstrasse 1',
    'plz' => '12345',
    'ort' => 'Musterstadt',
    'land' => 'DE',
    'email' => 'info@musterfirma.de',
    'telefon' => '+49 30 12345',
    'freitext' => 'Vielen Dank fuer Ihren Auftrag.',
    'waehrung' => 'EUR',
    'zahlungszieltage' => 14,
    'zahlungszieltageskonto' => 7,
    'zahlungszielskonto' => 2.0,
    'steuersatz_normal' => 19,
    'lookupCustomerNumber' => '10042',
    'ansprechpartner' => 'Max Mustermann',
    'adresse_typ' => 'firma',
    'adresse_name' => 'Musterfirma GmbH',
    'adresse_vorname' => '',
    'adresse_ansprechpartner' => 'Max Mustermann',
    'adresse_email' => 'info@musterfirma.de',
    'adresse_telefon' => '+49 30 12345',
    'adresse_lexware_contact_id' => '',
];

$invoicePrivat = array_merge($invoiceFirma, [
    'id' => 102,
    'belegnr' => 'RE-2026-0043',
    'name' => 'Mueller',
    'ansprechpartner' => '',
    'adresse_typ' => 'herr',
    'adresse_name' => 'Mueller',
    'adresse_vorname' => 'Klaus',
    'adresse_ansprechpartner' => '',
    'email' => 'klaus.mueller@example.de',
    'adresse_email' => 'klaus.mueller@example.de',
]);

$positions = [
    [
        'bezeichnung' => 'Beratung',
        'beschreibung' => 'Technische Beratung 4h',
        'menge' => 4.0,
        'einheit' => 'Stunde',
        'preis' => 120.00,
        'steuersatz' => 19,
        'rabatt' => 0.0,
        'waehrung' => 'EUR',
        'nummer' => 'BER-001',
    ],
    [
        'bezeichnung' => 'Hardware',
        'beschreibung' => '',
        'menge' => 1.0,
        'einheit' => 'Stueck',
        'preis' => 399.00,
        'steuersatz' => 19,
        'rabatt' => 10.0,
        'waehrung' => 'EUR',
        'nummer' => 'HW-001',
    ],
];

// Steuerfreier Fall: Beleg explizit ust_befreit = 3 (vatfree).
$invoiceVatFree = array_merge($invoiceFirma, [
    'id' => 103,
    'belegnr' => 'RE-2026-0044',
    'ust_befreit' => 3,
]);

// ----------------------------------------------------------------------
// Case 1: Firmen-Voucher (net / 19%)
// ----------------------------------------------------------------------

echo "=== Case 1: Firmen-Voucher (salesinvoice, net/19%) ===\n\n";

$voucherPayload = $mapper->mapVoucherPayload($invoiceFirma, $positions, 'test-contact-uuid-firma', $defaultCategoryId);
$contactPayload = $mapper->mapContactPayload($invoiceFirma);

echo "Voucher Payload:\n";
echo json_encode($voucherPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "Contact Payload:\n";
echo json_encode($contactPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "Assertions (Firma):\n";
expect(($voucherPayload['type'] ?? null) === 'salesinvoice', 'type = salesinvoice', $failures);
expect(($voucherPayload['voucherStatus'] ?? null) === 'open', 'voucherStatus = open', $failures);
expect(($voucherPayload['taxType'] ?? null) === 'gross', 'taxType = gross', $failures);
expect(($voucherPayload['useCollectiveContact'] ?? null) === false, 'useCollectiveContact = false', $failures);
expect(($voucherPayload['contactId'] ?? null) === 'test-contact-uuid-firma', 'contactId top-level korrekt durchgereicht', $failures);
expect(!array_key_exists('address', $voucherPayload), 'KEIN address-Key (Voucher nutzt top-level contactId)', $failures);
expect(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($voucherPayload['voucherDate'] ?? '')) === 1, 'voucherDate yyyy-MM-dd (kein T/Zeitzone)', $failures);
expect(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($voucherPayload['dueDate'] ?? '')) === 1, 'dueDate yyyy-MM-dd', $failures);
expect(($voucherPayload['dueDate'] ?? null) === '2026-04-22', 'dueDate = voucherDate + 14 Tage (2026-04-22)', $failures);
expect(($voucherPayload['voucherNumber'] ?? null) === 'RE-2026-0042', 'voucherNumber gesetzt aus belegnr', $failures);
expect(($voucherPayload['remark'] ?? null) === 'Vielen Dank fuer Ihren Auftrag.', 'remark aus freitext', $failures);

// Verbotene Invoice-Felder duerfen NICHT im Voucher-Payload auftauchen.
foreach (['version', 'currency', 'title', 'lineItems', 'paymentConditions', 'shippingConditions', 'totalPrice', 'taxConditions'] as $forbidden) {
    expect(!array_key_exists($forbidden, $voucherPayload), "KEIN '$forbidden'-Key im Voucher-Payload", $failures);
}

// voucherItems: GENAU vier Keys pro Item, Feldname taxRatePercent.
expect(isset($voucherPayload['voucherItems']) && is_array($voucherPayload['voucherItems']), 'voucherItems vorhanden', $failures);
$allowedItemKeys = ['amount', 'taxAmount', 'taxRatePercent', 'categoryId'];
foreach ($voucherPayload['voucherItems'] as $idx => $item) {
    $keys = array_keys($item);
    sort($keys);
    $expected = $allowedItemKeys;
    sort($expected);
    expect($keys === $expected, "voucherItems[$idx] hat GENAU amount,taxAmount,taxRatePercent,categoryId", $failures);
    expect(array_key_exists('taxRatePercent', $item), "voucherItems[$idx] Feld heisst taxRatePercent (nicht ...age)", $failures);
    expect(!array_key_exists('taxRatePercentage', $item), "voucherItems[$idx] hat KEIN taxRatePercentage", $failures);
    expect(!array_key_exists('currency', $item), "voucherItems[$idx] hat KEIN currency", $failures);
    expect(!array_key_exists('name', $item), "voucherItems[$idx] hat KEIN name", $failures);
    expect(($item['categoryId'] ?? null) === $defaultCategoryId, "voucherItems[$idx].categoryId = defaultCategoryId (net)", $failures);
    expect(($item['amount'] ?? -1) >= 0, "voucherItems[$idx].amount >= 0", $failures);
    expect(($item['taxAmount'] ?? -1) >= 0, "voucherItems[$idx].taxAmount >= 0", $failures);
}
// net 19%: Beratung 4*120=480, Hardware 1*399*0.9=359.1 -> netSum 839.1
// amount = round(839.1 * 1.19, 2) = 998.53; taxAmount = round(998.53 - 998.53/1.19, 2) = 159.43
expect(count($voucherPayload['voucherItems']) === 1, 'voucherItems: eine Gruppe (alle 19%)', $failures);
$item0 = $voucherPayload['voucherItems'][0];
expect((float)$item0['taxRatePercent'] === 19.0, 'voucherItems[0].taxRatePercent = 19.0', $failures);

// Totals = Summe der gerundeten Item-Werte.
$sumGross = array_sum(array_column($voucherPayload['voucherItems'], 'amount'));
$sumTax = array_sum(array_column($voucherPayload['voucherItems'], 'taxAmount'));
expect(abs(($voucherPayload['totalGrossAmount'] ?? 0) - round($sumGross, 2)) < 0.0001, 'totalGrossAmount = array_sum(amounts)', $failures);
expect(abs(($voucherPayload['totalTaxAmount'] ?? 0) - round($sumTax, 2)) < 0.0001, 'totalTaxAmount = array_sum(taxAmounts)', $failures);
expect(($voucherPayload['totalGrossAmount'] ?? -1) >= 0, 'totalGrossAmount >= 0', $failures);
expect(($voucherPayload['totalTaxAmount'] ?? -1) >= 0, 'totalTaxAmount >= 0', $failures);

// Contact-Payload (Firma).
expect(isset($contactPayload['company']), 'Contact: company gesetzt (typ=firma)', $failures);
expect(!isset($contactPayload['person']), 'Contact: person NICHT gesetzt (typ=firma)', $failures);
expect(($contactPayload['company']['name'] ?? null) === 'Musterfirma GmbH', 'Contact: company.name korrekt', $failures);
expect(isset($contactPayload['company']['contactPersons'][0]['lastName']), 'Contact: company.contactPersons[0].lastName gesetzt', $failures);
expect(($contactPayload['version'] ?? null) === 0, 'Contact: version = 0', $failures);
expect(isset($contactPayload['roles']['customer']), 'Contact: roles.customer gesetzt', $failures);
expect(is_object($contactPayload['roles']['customer']), 'Contact: roles.customer ist stdClass', $failures);
expect(isset($contactPayload['addresses']['billing'][0]), 'Contact: addresses.billing[0] gesetzt', $failures);

// ----------------------------------------------------------------------
// Case 2: Steuerfreier Voucher (vatfree -> rate 0)
// ----------------------------------------------------------------------

echo "\n=== Case 2: Steuerfreier Voucher (vatfree) ===\n\n";

$voucherVatFree = $mapper->mapVoucherPayload($invoiceVatFree, $positions, 'test-contact-uuid-vatfree', $defaultCategoryId);

echo "Voucher Payload (vatfree):\n";
echo json_encode($voucherVatFree, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "Assertions (vatfree):\n";
expect(($voucherVatFree['type'] ?? null) === 'salesinvoice', 'vatfree: type = salesinvoice', $failures);
expect(count($voucherVatFree['voucherItems']) === 1, 'vatfree: eine Item-Gruppe (alle rate 0)', $failures);
foreach ($voucherVatFree['voucherItems'] as $idx => $item) {
    expect((float)$item['taxRatePercent'] === 0.0, "vatfree: voucherItems[$idx].taxRatePercent = 0", $failures);
    expect((float)$item['taxAmount'] === 0.0, "vatfree: voucherItems[$idx].taxAmount = 0", $failures);
    expect(($item['categoryId'] ?? null) === $defaultCategoryId, "vatfree: voucherItems[$idx].categoryId = defaultCategoryId", $failures);
}
expect((float)($voucherVatFree['totalTaxAmount'] ?? -1) === 0.0, 'vatfree: totalTaxAmount = 0', $failures);
// netto 839.10, keine Steuer -> totalGross = 839.10
expect(abs(($voucherVatFree['totalGrossAmount'] ?? 0) - 839.10) < 0.0001, 'vatfree: totalGrossAmount = 839.10 (netto)', $failures);

// ----------------------------------------------------------------------
// Case 3: Gutschrift (salescreditnote)
// ----------------------------------------------------------------------

echo "\n=== Case 3: Gutschrift-Voucher (salescreditnote) ===\n\n";

$creditNotePayload = $mapper->mapVoucherCreditNotePayload($invoiceFirma, $positions, 'test-contact-uuid-cn', $defaultCategoryId);

echo "CreditNote Voucher Payload:\n";
echo json_encode($creditNotePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "Assertions (Gutschrift):\n";
expect(($creditNotePayload['type'] ?? null) === 'salescreditnote', 'Gutschrift: type = salescreditnote', $failures);
expect(($creditNotePayload['voucherStatus'] ?? null) === 'open', 'Gutschrift: voucherStatus = open', $failures);
expect(($creditNotePayload['contactId'] ?? null) === 'test-contact-uuid-cn', 'Gutschrift: contactId top-level', $failures);
expect(($creditNotePayload['totalGrossAmount'] ?? -1) >= 0, 'Gutschrift: totalGrossAmount >= 0 (positiv)', $failures);
expect(($creditNotePayload['totalTaxAmount'] ?? -1) >= 0, 'Gutschrift: totalTaxAmount >= 0 (positiv)', $failures);
foreach ($creditNotePayload['voucherItems'] as $idx => $item) {
    expect(($item['amount'] ?? -1) >= 0, "Gutschrift: voucherItems[$idx].amount >= 0", $failures);
    expect(($item['taxAmount'] ?? -1) >= 0, "Gutschrift: voucherItems[$idx].taxAmount >= 0", $failures);
}

// ----------------------------------------------------------------------
// Case 4: Privat-Contact (person vs company)
// ----------------------------------------------------------------------

echo "\n=== Case 4: Privat-Contact (typ=herr) ===\n\n";

$contactPayloadPrivat = $mapper->mapContactPayload($invoicePrivat);

echo "Contact Payload (Privat):\n";
echo json_encode($contactPayloadPrivat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "Assertions (Privat):\n";
expect(isset($contactPayloadPrivat['person']), 'Contact: person gesetzt (typ=herr)', $failures);
expect(!isset($contactPayloadPrivat['company']), 'Contact: company NICHT gesetzt (typ=herr)', $failures);
expect(($contactPayloadPrivat['person']['salutation'] ?? null) === 'Herr', 'Contact: person.salutation = Herr', $failures);
expect(($contactPayloadPrivat['person']['lastName'] ?? null) === 'Mueller', 'Contact: person.lastName aus adresse_name', $failures);
expect(($contactPayloadPrivat['person']['firstName'] ?? null) === 'Klaus', 'Contact: person.firstName aus adresse_vorname', $failures);

// ----------------------------------------------------------------------
// Summary
// ----------------------------------------------------------------------

echo "\n=== Summary ===\n";
if (empty($failures)) {
    echo "All assertions passed.\n";
    exit(0);
}

echo count($failures) . " assertion(s) failed:\n";
foreach ($failures as [$description]) {
    echo "  - $description\n";
}
exit(1);
