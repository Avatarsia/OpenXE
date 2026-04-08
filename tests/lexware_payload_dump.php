<?php

/**
 * Lexware Office — Payload-Dump-Verification.
 *
 * Standalone-Script (kein PHPUnit), das die oeffentlichen Mapping-Methoden
 * des LexwareOfficePayloadMapper direkt aufruft und die resultierenden
 * Payloads dumpt. Dient der manuellen Verifikation dass alle Spec-Compliance-
 * Fixes zusammen ein valides Lexware-Invoice-Payload erzeugen.
 *
 * Aufruf:
 *     cd openxe/
 *     php tests/lexware_payload_dump.php
 *
 * Prueft die Payloads gegen die bekannten Lexware-Pflichten:
 *  - address.contactId statt top-level contactId
 *  - KEIN voucherNumber / electronicDocumentProfile / useContactAddress
 *  - voucherDate im RFC3339-Extended-Format mit Europe/Berlin Offset
 *  - taxConditions.taxType, shippingConditions.shippingType, paymentConditions
 *  - lineItems[].unitPrice.taxRatePercentage je Position
 *  - company vs person mutually exclusive
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Xentral\Modules\LexwareOffice\Service\LexwareOfficePayloadMapper;

$mapper = new LexwareOfficePayloadMapper();

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

// ----------------------------------------------------------------------
// Case 1: Firmen-Rechnung
// ----------------------------------------------------------------------

echo "=== Case 1: Firmen-Rechnung ===\n\n";

$invoicePayload = $mapper->mapInvoicePayload($invoiceFirma, $positions, 'test-contact-uuid-firma');
$contactPayload = $mapper->mapContactPayload($invoiceFirma);

echo "Invoice Payload:\n";
echo json_encode($invoicePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "Contact Payload:\n";
echo json_encode($contactPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "Assertions (Firma):\n";
expect(!array_key_exists('contactId', $invoicePayload), 'contactId NICHT auf top-level', $failures);
expect(isset($invoicePayload['address']['contactId']), 'address.contactId gesetzt', $failures);
expect(($invoicePayload['address']['contactId'] ?? null) === 'test-contact-uuid-firma', 'address.contactId korrekt durchgereicht', $failures);
expect(count($invoicePayload['address']) === 1, 'address enthaelt nur contactId (Spec: choose one approach)', $failures);
expect(!array_key_exists('voucherNumber', $invoicePayload), 'voucherNumber NICHT im Payload (read-only)', $failures);
expect(!array_key_exists('electronicDocumentProfile', $invoicePayload), 'electronicDocumentProfile NICHT im Payload (read-only)', $failures);
expect(!array_key_exists('useContactAddress', $invoicePayload), 'useContactAddress NICHT im Payload (undokumentiert)', $failures);
expect(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/', $invoicePayload['voucherDate']) === 1, 'voucherDate im RFC3339-Extended-Format', $failures);
expect(str_contains($invoicePayload['voucherDate'], '+02:00') || str_contains($invoicePayload['voucherDate'], '+01:00'), 'voucherDate mit Europe/Berlin Offset', $failures);
expect(($invoicePayload['totalPrice'] ?? []) === ['currency' => 'EUR'], 'totalPrice enthaelt nur currency', $failures);
expect(($invoicePayload['taxConditions']['taxType'] ?? null) === 'net', 'taxConditions.taxType = net', $failures);
expect(($invoicePayload['shippingConditions']['shippingType'] ?? null) === 'delivery', 'shippingConditions.shippingType = delivery', $failures);
expect(isset($invoicePayload['shippingConditions']['shippingDate']), 'shippingConditions.shippingDate gesetzt (required fuer delivery)', $failures);
expect(($invoicePayload['paymentConditions']['paymentTermDuration'] ?? null) === 14, 'paymentConditions.paymentTermDuration = 14', $failures);
expect(isset($invoicePayload['paymentConditions']['paymentDiscountConditions']), 'paymentDiscountConditions gesetzt bei discountDays>0 AND discountPercent>0', $failures);
expect(count($invoicePayload['lineItems']) === 2, 'lineItems enthaelt 2 Positionen', $failures);
foreach ($invoicePayload['lineItems'] as $idx => $item) {
    expect(isset($item['unitPrice']['taxRatePercentage']), "lineItems[$idx].unitPrice.taxRatePercentage gesetzt", $failures);
    expect(isset($item['unitPrice']['netAmount']), "lineItems[$idx].unitPrice.netAmount gesetzt (taxType=net)", $failures);
    expect(($item['unitPrice']['currency'] ?? null) === 'EUR', "lineItems[$idx].unitPrice.currency = EUR", $failures);
    expect(($item['type'] ?? null) === 'custom', "lineItems[$idx].type = custom", $failures);
}

expect(isset($contactPayload['company']), 'Contact: company gesetzt (typ=firma)', $failures);
expect(!isset($contactPayload['person']), 'Contact: person NICHT gesetzt (typ=firma)', $failures);
expect(($contactPayload['company']['name'] ?? null) === 'Musterfirma GmbH', 'Contact: company.name korrekt', $failures);
expect(isset($contactPayload['company']['contactPersons'][0]['lastName']), 'Contact: company.contactPersons[0].lastName gesetzt (ansprechpartner non-empty)', $failures);
expect(($contactPayload['version'] ?? null) === 0, 'Contact: version = 0', $failures);
expect(isset($contactPayload['roles']['customer']), 'Contact: roles.customer gesetzt', $failures);
expect(is_object($contactPayload['roles']['customer']), 'Contact: roles.customer ist stdClass (empty object in JSON)', $failures);
expect(isset($contactPayload['addresses']['billing'][0]), 'Contact: addresses.billing[0] gesetzt (Array auch bei 1 Adresse)', $failures);

// ----------------------------------------------------------------------
// Case 2: Privat-Rechnung
// ----------------------------------------------------------------------

echo "\n=== Case 2: Privat-Rechnung ===\n\n";

$invoicePayloadPrivat = $mapper->mapInvoicePayload($invoicePrivat, $positions, 'test-contact-uuid-privat');
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
