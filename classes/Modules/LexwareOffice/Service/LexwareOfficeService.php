<?php

declare(strict_types=1);

namespace Xentral\Modules\LexwareOffice\Service;

use erpAPI;
use Xentral\Components\Database\Database;
use Xentral\Components\Logger\Logger;
use Xentral\Modules\LexwareOffice\Bootstrap;
use Xentral\Modules\LexwareOffice\Exception\LexwareOfficeException;

final class LexwareOfficeService
{
    /**
     * Lexware contacts-search requires at least 3 characters for name/email filters.
     */
    private const LEXWARE_MIN_SEARCH_LENGTH = 3;

    private LexwareOfficePayloadMapper $mapper;

    public function __construct(
        private Database $db,
        private LexwareOfficeConfigService $config,
        private LexwareOfficeApiClient $client,
        private Logger $logger,
        private ?erpAPI $erp = null,
        ?LexwareOfficePayloadMapper $mapper = null
    ) {
        $this->mapper = $mapper ?? new LexwareOfficePayloadMapper($this->erp, $this->logger);
    }

    public function hasApiKey(): bool
    {
        return $this->config->hasApiKey();
    }

    public function saveApiKey(string $apiKey): void
    {
        // Lazy-Migration: beim ersten Speichern eines API-Keys stellen wir sicher,
        // dass die Idempotenz-Spalten (lexware_contact_id, lexware_invoice_id,
        // lexware_uploaded_at) existieren. ensureSchema() ist idempotent via
        // SHOW COLUMNS-Check, also unbedenklich bei wiederholten Saves.
        Bootstrap::ensureSchema($this->db);
        $this->config->saveApiKey($apiKey);
    }

    public function deleteApiKey(): void
    {
        $this->config->deleteApiKey();
    }

    /**
     * @param int $invoiceId
     *
     * @return array
     */
    public function pushInvoice(int $invoiceId): array
    {
        $apiKey = $this->config->getApiKey();
        if (empty($apiKey)) {
            throw new LexwareOfficeException('Es ist kein Lexware Office API-Schlüssel hinterlegt.');
        }

        $invoice = $this->fetchInvoice($invoiceId);
        if (empty($invoice)) {
            throw new LexwareOfficeException('Rechnung wurde nicht gefunden.');
        }

        // NOTE: Zwischen diesem Pre-Check und persistInvoiceMapping() existiert ein
        // kleines Race-Window. Akzeptiert fuer den manuellen UI-Trigger (Klick auf
        // "An Lexware Office senden"). Bei automatisiertem Bulk-Upload muesste hier
        // ein Row-Lock oder eine separate Status-Spalte mit UPDATE ... WHERE
        // lexware_invoice_id IS NULL ergaenzt werden.
        if (!empty($invoice['lexware_invoice_id'])) {
            throw new LexwareOfficeException(
                sprintf(
                    'Rechnung wurde bereits an Lexware Office uebertragen (Beleg-ID: %s, am %s).',
                    $invoice['lexware_invoice_id'],
                    $invoice['lexware_uploaded_at'] ?? 'unbekannt'
                )
            );
        }

        $positions = $this->fetchPositions($invoiceId);
        if (empty($positions)) {
            throw new LexwareOfficeException('Die Rechnung enthält keine Positionen.');
        }

        $contactId = $this->resolveContact($apiKey, $invoice);
        $payload = $this->mapper->mapInvoicePayload($invoice, $positions, $contactId);

        // D1: Deterministischer Idempotency-Key aus OpenXE-Invoice-ID + Belegnr.
        // Falls persistInvoiceMapping() nach erfolgreichem createInvoice() fehlschlaegt
        // und der User den Upload erneut ausloest, dedupliziert Lexware serverseitig
        // auf diesem Key (best effort — Header ist in der public Doku nicht
        // explizit bestaetigt, aber ein sicherer No-Op falls ignoriert).
        $idempotencyKey = sprintf('openxe-rechnung-%d-%s', $invoiceId, $invoice['belegnr'] ?? '');
        try {
            $invoiceResponse = $this->client->createInvoice($apiKey, $payload, true, $idempotencyKey);
        } catch (LexwareOfficeException $e) {
            // D2: Self-Heal bei toter persistierter contactId. Wenn wir eine
            // persistierte adresse.lexware_contact_id hatten und Lexware sie
            // mit 400/404 ablehnt, wurde der Kontakt vermutlich in Lexware
            // geloescht. Stale ID nullen, neu aufloesen, einmalig erneut versuchen.
            $persistedContactId = trim((string)($invoice['adresse_lexware_contact_id'] ?? ''));
            $status = $e->getCode();
            if ($persistedContactId !== '' && in_array($status, [400, 404], true)) {
                $this->logger->warning(
                    'Lexware Office: persistierte Contact-ID abgelehnt, Self-Heal-Versuch',
                    [
                        'invoice_id' => $invoiceId,
                        'stale_contact_id' => $persistedContactId,
                        'status' => $status,
                        'error' => $e->getMessage(),
                    ]
                );
                if (!empty($invoice['adresse'])) {
                    try {
                        $this->db->perform(
                            'UPDATE `adresse` SET `lexware_contact_id` = NULL WHERE `id` = :id',
                            ['id' => (int)$invoice['adresse']]
                        );
                    } catch (\Throwable $dbError) {
                        // Nicht blockierend, aber loggen. Der Retry laeuft trotzdem,
                        // da wir unten das In-Memory-Invoice-Array zuruecksetzen.
                        $this->logger->warning(
                            'Lexware Office: Konnte stale contact-ID nicht loeschen',
                            [
                                'adresse_id' => $invoice['adresse'],
                                'error' => $dbError->getMessage(),
                            ]
                        );
                    }
                }
                // In-Memory Invoice-Array aktualisieren, damit resolveContact()
                // Stufe 0 (persistierte ID) ueberspringt.
                $invoice['adresse_lexware_contact_id'] = '';
                $contactId = $this->resolveContact($apiKey, $invoice);
                $payload = $this->mapper->mapInvoicePayload($invoice, $positions, $contactId);
                $invoiceResponse = $this->client->createInvoice($apiKey, $payload, true, $idempotencyKey);
            } else {
                throw $e;
            }
        }

        $lexwareInvoiceId = $this->assertInvoiceCreated($invoiceResponse);

        // Mapping persistieren fuer Idempotenz und Dedup
        $this->persistInvoiceMapping($invoiceId, $lexwareInvoiceId);
        if (!empty($invoice['adresse']) && empty($invoice['adresse_lexware_contact_id'])) {
            $this->persistContactMapping((int)$invoice['adresse'], $contactId);
        }

        // B1/D5: notice enthaelt nur IDs, damit im Produktions-Logfile keine
        // Kunden-PII (Name, Adresse, Email, Telefon, Rechnungsposten) landen.
        // Troubleshooting-Details liegen im debug-Kanal und koennen temporaer
        // aktiviert werden, wenn noetig.
        $this->logger->notice(
            'Rechnung an Lexware Office gesendet',
            [
                'invoice_id' => $invoiceId,
                'lexware_invoice_id' => $lexwareInvoiceId,
                'contact_id' => $contactId,
            ]
        );
        $this->logger->debug(
            'Lexware Office payload details',
            [
                'invoice_id' => $invoiceId,
                'lexware_invoice_id' => $lexwareInvoiceId,
                'lexware_response' => $invoiceResponse,
                'lexware_payload' => $payload,
            ]
        );

        return [
            'invoiceId' => $lexwareInvoiceId,
            'contactId' => $contactId,
            'response' => $invoiceResponse,
        ];
    }

    private function extractInvoiceId(array $invoiceResponse): ?string
    {
        foreach ([$invoiceResponse['id'] ?? null, $invoiceResponse['voucherId'] ?? null] as $id) {
            if ($id !== null && $id !== '') {
                return (string)$id;
            }
        }

        return null;
    }

    /**
     * Extrahiert die Lexware-Beleg-ID aus der Create-Response.
     *
     * Wirft eine sprechende Exception wenn die Response keine ID liefert,
     * inklusive gegebenenfalls enthaltener Lexware-Error-Details.
     */
    private function assertInvoiceCreated(array $invoiceResponse): string
    {
        $lexwareInvoiceId = $this->extractInvoiceId($invoiceResponse);
        if ($lexwareInvoiceId === null || $lexwareInvoiceId === '') {
            $message = $invoiceResponse['message'] ?? $invoiceResponse['error'] ?? '';
            if ($message === '' && !empty($invoiceResponse)) {
                $message = json_encode($invoiceResponse, JSON_UNESCAPED_UNICODE) ?: '';
            }
            throw new LexwareOfficeException(
                sprintf(
                    'Rechnung wurde nicht in Lexware Office angelegt. %s',
                    $message !== '' ? $message : 'Keine Beleg-ID erhalten.'
                )
            );
        }

        return $lexwareInvoiceId;
    }

    /**
     * @param string $apiKey
     * @param array  $invoice
     *
     * @return string
     */
    private function resolveContact(string $apiKey, array $invoice): string
    {
        // Stufe 0: Bereits persistierte Lexware-Contact-ID wiederverwenden
        $persistedContactId = trim((string)($invoice['adresse_lexware_contact_id'] ?? ''));
        if ($persistedContactId !== '') {
            return $persistedContactId;
        }

        // Stufe 1: exakter Match ueber Kundennummer (nur wenn numerisch, Lexware erwartet integer)
        $customerNumber = trim((string)($invoice['lookupCustomerNumber'] ?? ''));
        if ($customerNumber !== '' && ctype_digit($customerNumber)) {
            $response = $this->client->searchContacts($apiKey, [
                'customer' => 'true',
                'number' => (int)$customerNumber,
            ]);
            $matchId = $this->extractFirstContactId($response);
            if ($matchId !== null) {
                return $matchId;
            }
        }

        // Stufe 2: Email-Substring (Lexware verlangt mindestens 3 Zeichen)
        $email = trim((string)($invoice['email'] ?? $invoice['adresse_email'] ?? ''));
        if (mb_strlen($email, 'UTF-8') >= self::LEXWARE_MIN_SEARCH_LENGTH) {
            $response = $this->client->searchContacts($apiKey, [
                'customer' => 'true',
                'email' => $email,
            ]);
            $matchId = $this->extractFirstContactId($response);
            if ($matchId !== null) {
                return $matchId;
            }
        }

        // Stufe 3: Name-Substring (Lexware verlangt mindestens 3 Zeichen)
        $name = trim((string)($invoice['name'] ?? ''));
        if (mb_strlen($name, 'UTF-8') >= self::LEXWARE_MIN_SEARCH_LENGTH) {
            $response = $this->client->searchContacts($apiKey, [
                'customer' => 'true',
                'name' => $name,
            ]);
            $matchId = $this->extractFirstContactId($response);
            if ($matchId !== null) {
                return $matchId;
            }
        }

        // Stufe 4: kein Treffer -> neu anlegen
        $contactPayload = $this->mapper->mapContactPayload($invoice);
        $created = $this->client->createContact($apiKey, $contactPayload);
        if (empty($created['id'])) {
            throw new LexwareOfficeException('Kontakt konnte in Lexware Office nicht angelegt werden.');
        }

        return $created['id'];
    }

    /**
     * @param array $response
     *
     * @return string|null
     */
    private function extractFirstContactId(array $response): ?string
    {
        $content = $response['content'] ?? $response['items'] ?? [];
        if (!empty($content) && is_array($content)) {
            $first = reset($content);
            if (is_array($first) && !empty($first['id'])) {
                return (string)$first['id'];
            }
        }

        return null;
    }

    /**
     * @param int $invoiceId
     *
     * @return array|null
     */
    private function fetchInvoice(int $invoiceId): ?array
    {
        return $this->db->fetchRow(
            'SELECT
                r.*,
                COALESCE(r.kundennummer, adr.kundennummer) AS lookupCustomerNumber,
                adr.email AS adresse_email,
                adr.telefon AS adresse_telefon,
                adr.typ AS adresse_typ,
                adr.name AS adresse_name,
                adr.vorname AS adresse_vorname,
                adr.ansprechpartner AS adresse_ansprechpartner,
                adr.lexware_contact_id AS adresse_lexware_contact_id
            FROM `rechnung` AS `r`
            LEFT JOIN `adresse` AS `adr` ON adr.id = r.adresse
            WHERE r.id = :id',
            ['id' => $invoiceId]
        );
    }

    /**
     * @param int $invoiceId
     *
     * @return array
     */
    private function fetchPositions(int $invoiceId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM `rechnung_position` WHERE `rechnung` = :id ORDER BY `sort`',
            ['id' => $invoiceId]
        );
    }

    /**
     * Persistiert die Lexware-Invoice-ID und den Upload-Zeitpunkt.
     *
     * Loggt bei Fehlern auf error-Level, weil ein fehlgeschlagener Persist
     * den Duplicate-Check beim naechsten Upload umgeht — die Rechnung liegt
     * dann in Lexware, aber OpenXE weiss nichts davon.
     */
    private function persistInvoiceMapping(int $invoiceId, string $lexwareInvoiceId): void
    {
        try {
            $this->db->perform(
                'UPDATE `rechnung` SET `lexware_invoice_id` = :lexware_id, `lexware_uploaded_at` = NOW() WHERE `id` = :id',
                ['lexware_id' => $lexwareInvoiceId, 'id' => $invoiceId]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Lexware Office: Mapping-Persist fuer Rechnung fehlgeschlagen',
                [
                    'invoice_id' => $invoiceId,
                    'lexware_invoice_id' => $lexwareInvoiceId,
                    'error' => $e->getMessage(),
                    'consequence' => 'duplicate_upload_possible',
                ]
            );
        }
    }

    /**
     * Persistiert die Lexware-Contact-ID auf der OpenXE-Adresse.
     *
     * Fehler werden als error geloggt: beim naechsten Upload wird sonst
     * erneut ein Lookup/Create versucht, was Duplikat-Kontakte in Lexware
     * produzieren kann.
     */
    private function persistContactMapping(int $adresseId, string $lexwareContactId): void
    {
        try {
            $this->db->perform(
                'UPDATE `adresse` SET `lexware_contact_id` = :lexware_id WHERE `id` = :id',
                ['lexware_id' => $lexwareContactId, 'id' => $adresseId]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Lexware Office: Mapping-Persist fuer Adresse fehlgeschlagen',
                [
                    'adresse_id' => $adresseId,
                    'lexware_contact_id' => $lexwareContactId,
                    'error' => $e->getMessage(),
                    'consequence' => 'duplicate_contact_possible',
                ]
            );
        }
    }
}
