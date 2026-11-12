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
     * DE-Standard 19%. Lexware verlangt voucherItems[].taxRatePercent pro Item als Pflichtfeld.
     */
    private const DEFAULT_TAX_RATE = 19.0;

    /**
     * Lexware-Voucher categoryIds (GUIDs aus der offiziellen Lexware-Office-Doku
     * zu POST /v1/vouchers). Jede steuerfreie Sonderkonstellation hat einen
     * fixen categoryId-Platzhalter, der die Buchungslogik in Lexware vorgibt.
     * Der Default (Erloeskategorie) ist konfigurierbar und kommt als Parameter
     * vom Service.
     */
    private const CATEGORY_KLEINUNTERNEHMER       = 'f5c7fee8-f184-4e7a-ab04-8f7e7ad6c207';
    private const CATEGORY_INTRA_COMMUNITY_SUPPLY = '9075a4e3-66de-4795-a016-3889feca0d20';
    private const CATEGORY_THIRD_PARTY_DELIVERY   = '93d24c20-ea84-424e-a731-5e1b78d1e6a9';

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
     * Baut den Voucher-POST-Payload fuer POST /v1/vouchers (type=salesinvoice).
     *
     * Lexware behandelt Belege als Voucher; nur dieser Endpoint liefert eine
     * Voucher-ID, an die anschliessend per /vouchers/{id}/files ein PDF gehaengt
     * werden kann. Der Body folgt strikt der Voucher-Spec (KEINE Invoice-Felder
     * wie lineItems/taxConditions/address/version/currency).
     *
     * @param array  $invoice            Rechnungs-Row inkl. JOIN auf adresse.
     * @param array  $positions          rechnung_position-Rows.
     * @param string $contactId          Bereits aufgeloeste Lexware-Contact-ID (top-level).
     * @param string $defaultCategoryId  Neutrale Default-Erloeskategorie (vom ConfigService).
     *
     * @return array
     */
    public function mapVoucherPayload(
        array $invoice,
        array $positions,
        string $contactId,
        string $defaultCategoryId
    ): array {
        $voucherDate = $invoice['datum'] ?? date('Y-m-d');
        // Europe/Berlin fixieren, damit voucherDate auf UTC-Servern nicht auf den
        // Vortag rutscht. Voucher erwartet yyyy-MM-dd (NICHT RFC3339).
        $voucherDateTime = new DateTimeImmutable($voucherDate . 'T00:00:00', new DateTimeZone('Europe/Berlin'));
        $paymentTerm = (int)($invoice['zahlungszieltage'] ?? 0);
        $dueDateTime = $voucherDateTime->modify(sprintf('+%d days', max(0, $paymentTerm)));

        // taxType einmal aufloesen; bestimmt categoryId UND ob alle Items auf
        // rate 0 gezwungen werden (steuerfreie Faelle -> HTTP 400 bei !=0).
        $taxType = $this->resolveTaxType($invoice, $positions);
        $categoryId = $this->resolveCategoryId($taxType, $defaultCategoryId);
        $forceZeroTax = $taxType !== 'net';

        $items = $this->buildVoucherItems($positions, $invoice, $categoryId, $forceZeroTax);

        // Totals = Summe der bereits gerundeten Item-Werte (by construction
        // konsistent, kein Re-Rounding -> kein Total-Mismatch -> kein 400).
        $totalGross = 0.0;
        $totalTax = 0.0;
        foreach ($items as $item) {
            $totalGross += $item['amount'];
            $totalTax += $item['taxAmount'];
        }
        // abs() auf Totals: No-Op fuer Rechnung; bei Gutschrift werden ggf.
        // negativ gespeicherte OpenXE-Werte positiv (type kodiert die Richtung).
        $totalGross = round(abs($totalGross), 2);
        $totalTax = round(abs($totalTax), 2);

        $payload = [
            'type' => 'salesinvoice',
            'voucherStatus' => 'open',
            'voucherDate' => $voucherDateTime->format('Y-m-d'),
            'dueDate' => $dueDateTime->format('Y-m-d'),
            'totalGrossAmount' => $totalGross,
            'totalTaxAmount' => $totalTax,
            'taxType' => 'gross',
            'useCollectiveContact' => false,
            'contactId' => $contactId,
            'voucherItems' => $items,
        ];

        // voucherNumber: nur setzen wenn belegnr vorhanden (sonst Feld weglassen).
        $belegnr = trim((string)($invoice['belegnr'] ?? ''));
        if ($belegnr !== '') {
            $payload['voucherNumber'] = $belegnr;
        }

        // remark: nur setzen wenn Freitext vorhanden.
        $remark = trim((string)($invoice['freitext'] ?? ''));
        if ($remark !== '') {
            $payload['remark'] = $remark;
        }

        return $payload;
    }

    /**
     * Baut den Voucher-Payload fuer eine Gutschrift (type=salescreditnote).
     *
     * Delegiert an mapVoucherPayload() und tauscht nur den type. Lexware verlangt
     * fuer salescreditnote POSITIVE Betraege — die Credit-Richtung kodiert der
     * type, nicht das Vorzeichen. abs() wird bereits in buildVoucherItems() und
     * bei den Totals angewandt.
     *
     * @param array  $creditNote         gutschrift-Row inkl. JOIN-Felder.
     * @param array  $positions          gutschrift_position-Rows.
     * @param string $contactId          Bereits aufgeloeste Lexware-Contact-ID.
     * @param string $defaultCategoryId  Neutrale Default-Erloeskategorie.
     *
     * @return array
     */
    public function mapVoucherCreditNotePayload(
        array $creditNote,
        array $positions,
        string $contactId,
        string $defaultCategoryId
    ): array {
        $payload = $this->mapVoucherPayload($creditNote, $positions, $contactId, $defaultCategoryId);
        $payload['type'] = 'salescreditnote';

        return $payload;
    }

    /**
     * Leitet die Voucher-categoryId aus dem aufgeloesten taxType ab.
     *
     * - 'net'/'vatfree'           -> konfigurierbare Default-Erloeskategorie
     * - 'gross' (Kleinunternehmer)-> fixe Kleinunternehmer-Kategorie
     * - 'intraCommunitySupply'    -> fixe innergemeinschaftliche-Lieferung-Kategorie
     * - 'thirdPartyCountryDelivery'-> fixe Drittland-Lieferung-Kategorie
     */
    private function resolveCategoryId(string $taxType, string $defaultCategoryId): string
    {
        switch ($taxType) {
            case 'gross':
                return self::CATEGORY_KLEINUNTERNEHMER;
            case 'intraCommunitySupply':
                return self::CATEGORY_INTRA_COMMUNITY_SUPPLY;
            case 'thirdPartyCountryDelivery':
                return self::CATEGORY_THIRD_PARTY_DELIVERY;
            case 'vatfree':
            case 'net':
            default:
                return $defaultCategoryId;
        }
    }

    /**
     * Baut die voucherItems gruppiert pro Steuersatz inkl. konsistenter Rundung.
     *
     * Pro Position: net = preis * menge * (1 - rabatt/100). Sentinel
     * steuersatz == -1 -> Default-Satz. Die net-Summen werden pro rate
     * gruppiert, dann pro Gruppe amount/taxAmount EINMAL gerundet — so sind
     * die Totals (Summe der Item-Werte) garantiert konsistent.
     *
     * @return list<array{amount: float, taxAmount: float, taxRatePercent: float, categoryId: string}>
     */
    private function buildVoucherItems(
        array $positions,
        array $invoice,
        string $categoryId,
        bool $forceZeroTax
    ): array {
        // Default-Satz aus firmendaten.steuersatz_normal; defensiv auf DE-Standard
        // zwingen wenn 0/leer (Mandantenwechsel, leeres Feld).
        $defaultTax = (float)($invoice['steuersatz_normal'] ?? self::DEFAULT_TAX_RATE);
        if ($defaultTax <= 0.0) {
            $defaultTax = self::DEFAULT_TAX_RATE;
        }

        // net-Summen pro Steuersatz gruppieren.
        $netByRate = [];
        foreach ($positions as $position) {
            // OpenXE-Sentinel: steuersatz = -1 bedeutet "keinen Satz erzwingen"
            // -> Default-Satz. Lexware lehnt negative Werte ab.
            $rawTax = $position['steuersatz'] ?? null;
            $rate = ($rawTax !== null && (float)$rawTax >= 0.0) ? (float)$rawTax : $defaultTax;
            // Steuerfreie taxTypes erzwingen rate 0 fuer alle Items (HTTP 400 sonst).
            if ($forceZeroTax) {
                $rate = 0.0;
            }

            $preis = (float)($position['preis'] ?? 0.0);
            $menge = (float)($position['menge'] ?? 0.0);
            $net = $preis * $menge;
            if (!is_finite($net)) {
                $net = 0.0;
            }
            $rabatt = (float)($position['rabatt'] ?? 0.0);
            if ($rabatt > 0.0) {
                $net *= (1 - $rabatt / 100);
            }

            $rateKey = (string)$rate;
            if (!isset($netByRate[$rateKey])) {
                $netByRate[$rateKey] = ['rate' => $rate, 'net' => 0.0];
            }
            $netByRate[$rateKey]['net'] += $net;
        }

        $items = [];
        foreach ($netByRate as $group) {
            $rate = $group['rate'];
            $netSum = $group['net'];
            // abs(): No-Op fuer Rechnung; bei Gutschrift werden negativ
            // gespeicherte OpenXE-Betraege positiv (type kodiert die Richtung).
            $amount = round(abs($netSum * (1 + $rate / 100)), 2);
            $taxAmount = $rate > 0.0
                ? round($amount - $amount / (1 + $rate / 100), 2)
                : 0.0;

            $items[] = [
                'amount' => $amount,
                'taxAmount' => $taxAmount,
                'taxRatePercent' => (float)$rate,
                'categoryId' => $categoryId,
            ];
        }

        return $items;
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
}
