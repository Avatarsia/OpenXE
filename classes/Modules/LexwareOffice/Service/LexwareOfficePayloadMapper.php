<?php

declare(strict_types=1);

namespace Xentral\Modules\LexwareOffice\Service;

use DateTimeImmutable;
use DateTimeZone;
use erpAPI;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Xentral\Modules\LexwareOffice\Exception\LexwareOfficeException;

/**
 * Reine Mapping-Logik zwischen OpenXE-Datenstrukturen und Lexware-Office-Payloads.
 *
 * Keine DB-, HTTP- oder Config-Abhaengigkeiten — darum unit-testbar via
 * direkten Methoden-Calls (nicht via Reflection). LexwareOfficeService
 * delegiert die Payload-Erzeugung an diese Klasse.
 */
final class LexwareOfficePayloadMapper
{
    /**
     * Fallback-Steuersatz wenn weder Rechnung noch Position einen Wert liefern.
     * DE-Standard 19%. Lexware verlangt unitPrice.taxRatePercentage pro Position als Pflichtfeld.
     */
    private const DEFAULT_TAX_RATE = 19.0;

    private LoggerInterface $logger;

    public function __construct(
        private ?erpAPI $erp = null,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Baut den Contact-POST-Payload aus einer OpenXE-Rechnungs-Row
     * (inkl. per JOIN geholter adresse-Felder).
     *
     * @param array $invoice
     *
     * @return array
     */
    public function mapContactPayload(array $invoice): array
    {
        $email = $invoice['email'] ?? $invoice['adresse_email'] ?? '';
        $phone = $invoice['telefon'] ?? $invoice['adresse_telefon'] ?? '';
        $countryCode = $this->normalizeCountry($invoice['land'] ?? '');
        $address = array_filter([
            'name' => $invoice['name'] ?? '',
            'supplement' => $invoice['adresszusatz'] ?? '',
            'street' => $invoice['strasse'] ?? '',
            'zip' => $invoice['plz'] ?? '',
            'city' => $invoice['ort'] ?? '',
        ], static fn($value) => $value !== null && $value !== '');
        // countryCode ist Lexware-Pflichtfeld fuer freie Adressen - niemals filtern
        $address['countryCode'] = $countryCode;

        $payload = [
            'version' => 0,
            'roles' => [
                'customer' => new \stdClass(),
            ],
            'addresses' => [
                'billing' => [$address],
            ],
        ];

        $typ = strtolower(trim((string)($invoice['adresse_typ'] ?? '')));
        $ansprechpartner = trim((string)($invoice['adresse_ansprechpartner'] ?? $invoice['ansprechpartner'] ?? ''));

        if ($typ === 'herr' || $typ === 'frau') {
            $lastName = trim((string)($invoice['adresse_name'] ?? ''));
            if ($lastName === '') {
                $lastName = trim((string)($invoice['name'] ?? ''));
            }
            if ($lastName === '') {
                throw new LexwareOfficeException('Kontakt kann nicht angelegt werden: Nachname fehlt.');
            }
            $firstName = trim((string)($invoice['adresse_vorname'] ?? ''));
            $person = [
                'salutation' => $typ === 'herr' ? 'Herr' : 'Frau',
                'lastName' => $lastName,
            ];
            if ($firstName !== '') {
                $person['firstName'] = $firstName;
            }
            $payload['person'] = $person;
        } else {
            // D7: Bei $typ === 'firma' ist der company-Branch korrekt. Bei leerem/unbekanntem
            // $typ (z.B. weil r.adresse = 0 ist und der JOIN adresse_typ auf NULL setzt)
            // ist die Zuordnung zur company-Seite eine Annahme — wir loggen das als warning,
            // damit Privatkunden ohne verknuepfte Adresse im Ops-Log auffindbar sind.
            if ($typ !== 'firma') {
                $this->logger->warning(
                    'Lexware Office: Kontakt ohne adresse_typ wird als Firma angelegt (Fallback)',
                    [
                        'invoice_id' => $invoice['id'] ?? null,
                        'adresse_id' => $invoice['adresse'] ?? null,
                        'raw_typ' => $invoice['adresse_typ'] ?? null,
                    ]
                );
            }
            $company = [
                'name' => $invoice['name'] ?? '',
            ];
            if ($ansprechpartner !== '') {
                $company['contactPersons'] = [
                    ['lastName' => $ansprechpartner, 'primary' => true],
                ];
            }
            $payload['company'] = $company;
        }

        if (!empty($email)) {
            $payload['emailAddresses']['business'][] = $email;
        }
        if (!empty($phone)) {
            $payload['phoneNumbers']['business'][] = $phone;
        }

        return $payload;
    }

    /**
     * Baut den Invoice-POST-Payload.
     *
     * @param array  $invoice
     * @param array  $positions
     * @param string $contactId
     *
     * @return array
     */
    public function mapInvoicePayload(array $invoice, array $positions, string $contactId): array
    {
        $voucherDate = $invoice['datum'] ?? date('Y-m-d');
        // Lexware erwartet einen RFC3339-Timestamp mit Zeitzone; wir fixieren auf Europe/Berlin,
        // damit voucherDate auf UTC-Servern nicht auf den Vortag rutscht.
        $voucherDateTime = new DateTimeImmutable($voucherDate . 'T00:00:00', new DateTimeZone('Europe/Berlin'));
        $defaultCurrency = $this->normalizeCurrency($invoice['waehrung'] ?? 'EUR');
        $paymentTerm = (int)($invoice['zahlungszieltage'] ?? 0);
        $discountDays = (int)($invoice['zahlungszieltageskonto'] ?? 0);
        $discountPercent = (float)($invoice['zahlungszielskonto'] ?? 0);
        $title = !empty($invoice['belegnr']) ? sprintf('Rechnung %s', $invoice['belegnr']) : 'Rechnung';

        $payload = [
            // Lexware voucherDate: RFC3339 extended (mit Millisekunden & Zeitzone)
            'voucherDate' => $voucherDateTime->format(DATE_RFC3339_EXTENDED),
            'title' => $title,
            'remark' => $invoice['freitext'] ?? '',
            // Lexware-Spec: choose ONE approach - entweder contactId ODER freie Felder,
            // niemals beides parallel. Da resolveContact() immer eine contactId liefert,
            // senden wir ausschliesslich die contactId; die kanonische Adresse liegt am Contact.
            'address' => [
                'contactId' => $contactId,
            ],
            'lineItems' => $this->mapLineItems($positions, $invoice),
            'totalPrice' => [
                'currency' => $defaultCurrency,
            ],
            'taxConditions' => [
                'taxType' => $this->resolveTaxType($invoice, $positions),
            ],
            'shippingConditions' => [
                'shippingType' => 'delivery',
                'shippingDate' => $voucherDateTime->format(DATE_RFC3339_EXTENDED),
            ],
            'paymentConditions' => [
                'paymentTermDuration' => $paymentTerm,
            ],
        ];

        if ($discountDays > 0 && $discountPercent > 0) {
            $payload['paymentConditions']['paymentDiscountConditions'] = [
                'discountPercentage' => $discountPercent,
                'discountRange' => $discountDays,
            ];
            $payload['paymentConditions']['paymentTermLabel'] = sprintf(
                '%d Tage - %s%%, %d Tage netto',
                $discountDays,
                $this->formatNumber($discountPercent),
                $paymentTerm
            );
        }

        return $payload;
    }

    /**
     * Baut den CreditNote-POST-Payload fuer /v1/credit-notes.
     *
     * Lexware verwendet fuer credit-notes die exakt gleiche Payload-Struktur
     * wie /v1/invoices (lineItems, taxConditions, address.contactId,
     * voucherDate, paymentConditions, shippingConditions). Wir delegieren
     * darum an mapInvoicePayload() und tauschen lediglich den Title aus,
     * damit "Gutschrift {belegnr}" statt "Rechnung {belegnr}" am Voucher
     * steht.
     *
     * @param array  $creditNote      gutschrift-Row inkl. JOIN-Felder.
     * @param array  $positions       gutschrift_position-Rows.
     * @param string $contactId       Bereits aufgeloeste Lexware-Contact-ID.
     */
    public function mapCreditNotePayload(array $creditNote, array $positions, string $contactId): array
    {
        $payload = $this->mapInvoicePayload($creditNote, $positions, $contactId);
        $payload['title'] = !empty($creditNote['belegnr'])
            ? sprintf('Gutschrift %s', $creditNote['belegnr'])
            : 'Gutschrift';

        return $payload;
    }

    /**
     * Leitet den Lexware-taxType aus dem OpenXE-Belegkontext ab.
     *
     * Mapping (orientiert sich an OpenXE-erpAPI::AdresseUSTCheck und der
     * GetSteuersatz-Logik in class.erpapi.php):
     *
     *   - 'gross'                     -> Mandant ist Kleinunternehmer
     *                                    (firmendaten.kleinunternehmer = '1').
     *                                    Lexware verlangt fuer Kleinunternehmer
     *                                    eine Gross-Erfassung — andernfalls
     *                                    laufen die Belege im Buchhaltungs-
     *                                    abgleich auf.
     *   - 'vatfree'                   -> ust_befreit = 3 oder alle Positionen
     *                                    fuehren umsatzsteuer = 'befreit'.
     *   - 'intraCommunitySupply'      -> ust_befreit = 1, ODER (Heuristik)
     *                                    Empfaenger-Land in EU + Empfaenger-Land
     *                                    != Mandanten-Land + USt-IdNr. vorhanden.
     *   - 'thirdPartyCountryDelivery' -> ust_befreit = 2, ODER (Heuristik)
     *                                    Empfaenger-Land ausserhalb EU und
     *                                    != Mandanten-Land.
     *   - 'net' (Default)             -> Inland-B2B/B2C mit Standardsteuersatz.
     *
     * Annahmen:
     *   - "Drittlandservice" vs. "ThirdPartyCountryDelivery" wird nicht
     *     unterschieden — OpenXE hat keinen klaren Marker fuer
     *     Service-vs-Lieferung. Voreinstellung: thirdPartyCountryDelivery.
     *   - constructionService13b/externalService13b werden NICHT abgebildet,
     *     da OpenXE dafuer kein Standardfeld fuehrt; ein expliziter
     *     ust_befreit-Override durch das jeweilige Modul (z.B. Bauleistung)
     *     wird als 'vatfree' erfasst — fuer paragraph-13b-Buchungen waere
     *     eine Mandanten-spezifische Erweiterung noetig.
     *
     * @param array $invoice   Rechnungs-Row inkl. JOIN auf adresse.
     * @param array $positions Rechnungs-Positionen (rechnung_position).
     */
    public function resolveTaxType(array $invoice, array $positions): string
    {
        // Stufe 0: Kleinunternehmer auf Mandantenebene -> gross.
        // Wir benutzen erp->Firmendaten() (gecached) als kanonische Quelle.
        if ($this->isKleinunternehmer()) {
            return 'gross';
        }

        // Stufe 1: explizite Steuerbefreiung am Beleg.
        $ustBefreit = isset($invoice['ust_befreit']) ? (int)$invoice['ust_befreit'] : 0;
        if ($ustBefreit === 1) {
            return 'intraCommunitySupply';
        }
        if ($ustBefreit === 2) {
            return 'thirdPartyCountryDelivery';
        }
        if ($ustBefreit === 3) {
            return 'vatfree';
        }

        // Stufe 1.5: Land/USt-ID-Heuristik wenn ust_befreit explizit 0 ist.
        // Greift z.B. wenn der Beleg ueber einen anderen Kanal angelegt wurde
        // (Import, Custom-Modul) und ust_befreit nicht durch IstEU()/Steuerbefreit()
        // gesetzt wurde. Fuer normalen OpenXE-Workflow ist Stufe 1 bereits gesetzt.
        if ($ustBefreit === 0) {
            $heuristic = $this->resolveTaxTypeHeuristic($invoice);
            if ($heuristic !== null) {
                return $heuristic;
            }
        }

        // Stufe 2: alle Positionen sind als 'befreit' markiert.
        if ($this->allPositionsTaxFree($positions)) {
            return 'vatfree';
        }

        // Default: Netto.
        return 'net';
    }

    /**
     * Heuristik fuer taxType wenn ust_befreit nicht explizit gesetzt ist.
     * Liefert null wenn keine sichere Aussage moeglich ist (Inland, fehlendes
     * Land, fehlender Mandant, IstEU-Exception, EU-B2C ohne USt-IdNr.) — der
     * Aufrufer faellt dann auf den naechsten Schritt (Position-Check / net).
     */
    private function resolveTaxTypeHeuristic(array $invoice): ?string
    {
        if ($this->erp === null) {
            return null;
        }

        $land = trim((string)($invoice['land'] ?? ''));
        if ($land === '') {
            return null;
        }

        $mandatLand = $this->getMandantLand();
        if ($mandatLand === '' || strcasecmp($land, $mandatLand) === 0) {
            return null;
        }

        try {
            $isEu = (bool)$this->erp->IstEU($land);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Lexware Office: IstEU-Lookup fehlgeschlagen, Tax-Heuristik uebersprungen',
                ['error' => $e->getMessage(), 'land' => $land]
            );
            return null;
        }

        if ($isEu) {
            $ustid = trim((string)($invoice['ustid'] ?? ''));
            if ($ustid !== '') {
                return 'intraCommunitySupply';
            }
            // EU-B2C ohne USt-IdNr.: kein sicherer Marker fuer
            // intraCommunitySupply — Default-Pfad uebernimmt.
            return null;
        }

        return 'thirdPartyCountryDelivery';
    }

    private function getMandantLand(): string
    {
        if ($this->erp === null) {
            return '';
        }
        try {
            $value = $this->erp->Firmendaten('land');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Lexware Office: Firmendaten-Abruf "land" fehlgeschlagen',
                ['error' => $e->getMessage()]
            );
            return '';
        }

        return trim((string)$value);
    }

private function isKleinunternehmer(): bool
    {
        if ($this->erp === null) {
            return false;
        }
        try {
            $value = $this->erp->Firmendaten('kleinunternehmer');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Lexware Office: Firmendaten-Abruf "kleinunternehmer" fehlgeschlagen',
                ['error' => $e->getMessage()]
            );
            return false;
        }

        return (string)$value === '1' || $value === 1 || $value === true;
    }

    private function allPositionsTaxFree(array $positions): bool
    {
        if (empty($positions)) {
            return false;
        }
        foreach ($positions as $position) {
            $marker = strtolower(trim((string)($position['umsatzsteuer'] ?? '')));
            if ($marker !== 'befreit') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array $positions
     * @param array $invoice
     *
     * @return array
     */
    private function mapLineItems(array $positions, array $invoice): array
    {
        $items = [];
        $defaultCurrency = $this->normalizeCurrency($invoice['waehrung'] ?? 'EUR');
        $defaultTax = (float)($invoice['steuersatz_normal'] ?? self::DEFAULT_TAX_RATE);

        foreach ($positions as $position) {
            $tax = $position['steuersatz'] ?? $defaultTax;
            $currency = $this->normalizeCurrency($position['waehrung'] ?? $defaultCurrency);
            $unitPrice = [
                'currency' => $currency,
                'netAmount' => (float)$position['preis'],
                'taxRatePercentage' => (float)$tax,
            ];
            $items[] = array_filter([
                'type' => 'custom',
                'name' => $position['bezeichnung'] ?? $position['nummer'] ?? 'Position',
                'description' => $position['beschreibung'] ?? '',
                'quantity' => (float)$position['menge'],
                'unitName' => $position['einheit'] ?: 'Stück',
                'unitPrice' => $unitPrice,
                'discountPercentage' => $this->getDiscount($position),
            ], static fn($value) => $value !== null && $value !== '');
        }

        return $items;
    }

    /**
     * @param array $position
     *
     * @return float|null
     */
    private function getDiscount(array $position): ?float
    {
        $discount = $position['rabatt'] ?? 0.0;
        if ((float)$discount <= 0.0) {
            return null;
        }

        return (float)$discount;
    }

    /**
     * @param string $country
     *
     * @return string
     */
    private function normalizeCountry(string $country): string
    {
        $country = trim($country);
        if (strlen($country) === 2) {
            return strtoupper($country);
        }

        if ($this->erp !== null) {
            $iso = $this->erp->FindISOCountry($country);
            if (!empty($iso) && $iso !== -1) {
                return strtoupper($iso);
            }
        }

        // Fallback: DE-Default, aber loggen damit falsche Laenderdaten in OpenXE auffindbar sind
        if ($country !== '') {
            $this->logger->warning(
                'Lexware Office: Konnte Land nicht aufloesen, Fallback DE',
                ['raw_country' => $country]
            );
        }

        return 'DE';
    }

    private function normalizeCurrency(?string $currency): string
    {
        $currency = trim((string)$currency);
        if ($currency === '') {
            return 'EUR';
        }

        return strtoupper($currency);
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
