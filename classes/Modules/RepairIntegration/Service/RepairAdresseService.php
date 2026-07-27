<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Components\Database\Database;

/**
 * Verknuepft ein Reparatur-Ticket mit einer Adresse: Match ueber die
 * E-Mail-Adresse, sonst Neuanlage aus dem WP-Kundenblock.
 *
 * Bewusst ohne $app->erp, damit der Service auch im Standalone-Kontext des
 * API-Entry-Points (www/repairapi/index.php) laeuft, wo nur die
 * Database-Komponente zur Verfuegung steht.
 */
final class RepairAdresseService
{
    /**
     * Alle NOT-NULL-Spalten von `adresse` OHNE DEFAULT laut
     * database/struktur.sql, vorbelegt mit Leerwerten — plus die drei
     * NULL-faehigen Stammdatenfelder, die aus dem Payload befuellt werden.
     *
     * Live laeuft MySQL mit STRICT_TRANS_TABLES: jede ausgelassene Spalte
     * dieser Liste bricht den INSERT mit "Field ... doesn't have a default
     * value" ab. Lokal (ohne Strict-Mode) faellt das nicht auf.
     *
     * @var array<string, string|int>
     */
    private const ADRESSE_DEFAULTS = [
        'typ' => '',
        'marketingsperre' => '',
        'trackingsperre' => 0,
        'rechnungsadresse' => 0,
        'name' => '',
        'abteilung' => '',
        'unterabteilung' => '',
        'ansprechpartner' => '',
        'land' => '',
        'strasse' => '',
        'ort' => '',
        'plz' => '',
        'email' => '',
        'ust_befreit' => 0,
        'passwort_gesendet' => 0,
        'sonstiges' => '',
        'adresszusatz' => '',
        'kundenfreigabe' => 0,
        'steuer' => '',
        'kundennummer' => '',
        'lieferantennummer' => '',
        'mitarbeiternummer' => '',
        'bank' => '',
        'inhaber' => '',
        'waehrung' => '',
        'paypal' => '',
        'paypalinhaber' => '',
        'paypalwaehrung' => '',
        'projekt' => 0,
        'partner' => 0,
        'zahlungsweise' => '',
        'zahlungszieltage' => '',
        'zahlungszieltageskonto' => '',
        'zahlungszielskonto' => '',
        'versandart' => '',
        'kundennummerlieferant' => '',
        'zahlungsweiselieferant' => '',
        'zahlungszieltagelieferant' => '',
        'zahlungszieltageskontolieferant' => '',
        'zahlungszielskontolieferant' => '',
        'versandartlieferant' => '',
        'geloescht' => 0,
        'firma' => 1,
        'sachkonto' => '',
        'infoauftragserfassung' => '',
        'mandatsreferenz' => '',
        'glaeubigeridentnr' => '',
        'nachname' => '',
        'angebot_cc' => '',
        'auftrag_cc' => '',
        'rechnung_cc' => '',
        'gutschrift_cc' => '',
        'lieferschein_cc' => '',
        'bestellung_cc' => '',
        'angebot_fax_cc' => '',
        'auftrag_fax_cc' => '',
        'rechnung_fax_cc' => '',
        'gutschrift_fax_cc' => '',
        'lieferschein_fax_cc' => '',
        'bestellung_fax_cc' => '',
        'abpermail' => '',
        'kassierernummer' => '',
        'mandatsreferenzart' => '',
        'mandatsreferenzwdhart' => '',
        'kundennummer_buchhaltung' => '',
        'lieferantennummer_buchhaltung' => '',
        'zahlungsweiseabo' => '',
        'bundesland' => '',
        'umsatzsteuer_lieferant' => '',
        'art' => '',
        'angebot_email' => '',
        'auftrag_email' => '',
        'rechnungs_email' => '',
        'gutschrift_email' => '',
        'lieferschein_email' => '',
        'bestellung_email' => '',
        'hinweistextlieferant' => '',
        'hinweis_einfuegen' => '',
        'gln' => '',
        'rechnung_gln' => '',
        'lieferbedingung' => '',
        'zollinformationen' => '',
        'bundesstaat' => '',
        'rechnung_bundesstaat' => '',
        // NULL-faehig, wird aus dem Payload gefuellt
        'telefon' => '',
        'ustid' => '',
        'vorname' => '',
    ]; // @php83: add type array

    public function __construct(
        private readonly Database $db,
    ) {}

    /**
     * Ermittelt (oder erzeugt) die Adresse zum Kundenblock einer WP-Anfrage
     * und haengt sie an das Ticket, sofern dort noch keine Adresse steht.
     *
     * @param array<string, mixed> $customerData Der `customer`-Block aus dem WP-Payload
     * @return int Adress-ID, 0 wenn keine Adresse ermittelbar war
     */
    public function ensureAdresseForTicket(int $ticketId, array $customerData): int
    {
        // Fallback fuer Aufrufer ohne WP-Payload (z.B. der "Kundenkonto
        // anlegen"-Button im Ticket): Kundendaten aus dem Ticket selbst und
        // den Repair-Details rekonstruieren. Strassendaten liegen dort nicht
        // vor — die Adresse wird dann ohne Anschrift angelegt.
        if ($customerData === [] && $ticketId > 0) {
            $customerData = $this->customerDataFromTicket($ticketId);
        }

        $email = self::str($customerData['email'] ?? null, 255);

        $adresseId = 0;
        if ($email !== '') {
            $found = $this->db->fetchValue(
                'SELECT `id` FROM `adresse` WHERE `email` = :mail AND `geloescht` = 0 ORDER BY `id` LIMIT 1',
                ['mail' => $email]
            );
            if ($found !== false && $found !== null) {
                $adresseId = (int)$found;
            }
        }

        if ($adresseId === 0) {
            $adresseId = $this->createAdresse($customerData, $email);
        }

        if ($adresseId > 0 && $ticketId > 0) {
            $this->db->perform(
                'UPDATE `ticket` SET `adresse` = :aid
                 WHERE `id` = :tid AND (`adresse` IS NULL OR `adresse` = 0)',
                ['aid' => $adresseId, 'tid' => $ticketId]
            );
        }

        return $adresseId;
    }

    /**
     * Rekonstruiert einen customer-Block aus ticket + ticket_repair_details.
     *
     * @return array<string, mixed>
     */
    private function customerDataFromTicket(int $ticketId): array
    {
        $ticket = $this->db->fetchRow(
            'SELECT `kunde`, `mailadresse` FROM `ticket` WHERE `id` = :id',
            ['id' => $ticketId]
        );
        if (!$ticket) {
            return [];
        }

        // ticket.kunde hat das Format "Name <mail>" — nur den Namensteil nehmen.
        $name = trim((string)($ticket['kunde'] ?? ''));
        $bracketPos = strpos($name, '<');
        if ($bracketPos !== false) {
            $name = trim(substr($name, 0, $bracketPos));
        }

        $details = $this->db->fetchRow(
            'SELECT `company_name`, `vat_id` FROM `ticket_repair_details` WHERE `ticket_id` = :id',
            ['id' => $ticketId]
        );

        $company = trim((string)($details['company_name'] ?? ''));

        return [
            'name' => $name,
            'email' => trim((string)($ticket['mailadresse'] ?? '')),
            'company' => $company !== '' ? $company : null,
            'vat_id' => $details['vat_id'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $customerData
     */
    private function createAdresse(array $customerData, string $email): int
    {
        $company = self::str($customerData['company'] ?? null, 255);
        $person = self::str($customerData['name'] ?? null, 255);

        // Ohne jede identifizierende Angabe waere die Adresse wertlos —
        // dann lieber kein Datensatz als eine leere Karteileiche.
        if ($email === '' && $company === '' && $person === '') {
            return 0;
        }

        $address = $this->resolveAddressParts($customerData);

        $values = self::ADRESSE_DEFAULTS;
        $values['typ'] = $company !== '' ? 'firma' : '';
        $values['name'] = $company !== '' ? $company : $person;
        $values['ansprechpartner'] = $company !== '' ? $person : '';
        $values['vorname'] = self::cut(self::firstName($person), 255);
        $values['nachname'] = self::cut(self::lastName($person), 128);
        $values['email'] = $email;
        $values['telefon'] = self::str($customerData['phone'] ?? null, 64);
        $values['ustid'] = self::str($customerData['vat_id'] ?? null, 64);
        $values['strasse'] = $address['strasse'];
        $values['plz'] = $address['plz'];
        $values['ort'] = $address['ort'];
        $values['land'] = $address['land'];

        $columns = array_keys($values);
        $sql = sprintf(
            'INSERT INTO `adresse` (`%s`) VALUES (%s)',
            implode('`, `', $columns),
            implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns))
        );

        try {
            $this->db->perform($sql, $values);
        } catch (\Throwable $e) {
            // Original-Exception sichtbar machen: unter STRICT_TRANS_TABLES
            // steht hier die fehlende Spalte drin.
            error_log(
                'RepairIntegration: INSERT INTO adresse fehlgeschlagen: '
                . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine()
            );
            throw $e;
        }

        return (int)$this->db->lastInsertId();
    }

    /**
     * Bevorzugt die strukturierten Felder des WP-Plugins, faellt sonst auf das
     * Parsen des Freitext-Feldes `address` zurueck.
     *
     * @param array<string, mixed> $customerData
     * @return array{strasse: string, plz: string, ort: string, land: string}
     */
    private function resolveAddressParts(array $customerData): array
    {
        $strasse = trim(
            self::str($customerData['street'] ?? null, 255)
            . ' '
            . self::str($customerData['house_number'] ?? null, 64)
        );
        $plz = self::str($customerData['postal_code'] ?? null, 64);
        $ort = self::str($customerData['city'] ?? null, 64);

        if ($strasse === '' || $plz === '' || $ort === '') {
            $parsed = self::parseAddressString(self::str($customerData['address'] ?? null, 1024));
            if ($strasse === '') {
                $strasse = $parsed['strasse'];
            }
            if ($plz === '') {
                $plz = $parsed['plz'];
            }
            if ($ort === '') {
                $ort = $parsed['ort'];
            }
        }

        return [
            'strasse' => self::cut($strasse, 255),
            'plz' => self::cut($plz, 64),
            'ort' => self::cut($ort, 64),
            'land' => self::normalizeCountry($customerData['country'] ?? null),
        ];
    }

    /**
     * Zerlegt den Fallback-String "Strasse Nr, PLZ Ort".
     *
     * @return array{strasse: string, plz: string, ort: string}
     */
    private static function parseAddressString(string $address): array
    {
        $result = ['strasse' => '', 'plz' => '', 'ort' => ''];

        $parts = array_values(array_filter(
            array_map('trim', explode(',', trim($address))),
            static fn(string $part): bool => $part !== ''
        ));
        if ($parts === []) {
            return $result;
        }

        $result['strasse'] = $parts[0];
        if (count($parts) === 1) {
            return $result;
        }

        $last = $parts[count($parts) - 1];
        if (preg_match('/^([A-Za-z]{0,2}[- ]?\d{4,6})\s+(.+)$/u', $last, $matches) === 1) {
            $result['plz'] = trim($matches[1]);
            $result['ort'] = trim($matches[2]);
        } else {
            $result['ort'] = $last;
        }

        return $result;
    }

    /**
     * @param mixed $raw Rohwert aus dem JSON-Payload
     */
    private static function normalizeCountry(mixed $raw): string
    {
        $land = self::str($raw, 64);
        if (preg_match('/^[A-Za-z]{2}$/', $land) === 1) {
            return strtoupper($land);
        }
        return $land;
    }

    private static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        if (!is_array($parts) || count($parts) < 2) {
            return '';
        }
        return implode(' ', array_slice($parts, 0, -1));
    }

    private static function lastName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        if (!is_array($parts) || $parts === []) {
            return '';
        }
        return (string)$parts[count($parts) - 1];
    }

    /**
     * @param mixed $raw Rohwert aus dem JSON-Payload
     */
    private static function str(mixed $raw, int $maxLength): string
    {
        if (is_string($raw)) {
            $value = $raw;
        } elseif (is_int($raw) || is_float($raw)) {
            $value = (string)$raw;
        } else {
            return '';
        }

        return self::cut(trim($value), $maxLength);
    }

    private static function cut(string $value, int $maxLength): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }
        return substr($value, 0, $maxLength);
    }
}
