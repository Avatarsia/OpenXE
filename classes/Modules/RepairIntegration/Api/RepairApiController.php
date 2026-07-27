<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Api;

use Xentral\Components\Database\Database;
use Xentral\Modules\RepairIntegration\Enum\ServiceType;
use Xentral\Modules\RepairIntegration\Exception\AuthenticationException;
use Xentral\Modules\RepairIntegration\Exception\ForbiddenException;
use Xentral\Modules\RepairIntegration\Exception\ValidationException;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairStatusConfigGateway;
use Xentral\Modules\RepairIntegration\Service\RepairAdresseService;
use Xentral\Modules\RepairIntegration\Service\RepairConfigService;

final class RepairApiController
{
    private const MAX_PAYLOAD_SIZE = 65536; // @php83: add type int
    private const MAX_REQUESTS_PER_MINUTE = 60; // @php83: add type int
    private const MAX_STATUS_LENGTH = 30; // @php83: add type int
    private const DEFAULT_STATUS = 'neu'; // @php83: add type string

    /** Zeitzone, in der das WP-Plugin `created_at` liefert. */
    private const PAYLOAD_TIMEZONE = 'Europe/Berlin'; // @php83: add type string
    /** Untere Plausibilitaetsgrenze fuer `created_at`. */
    private const CREATED_AT_MIN = '2020-01-01 00:00:00'; // @php83: add type string

    private const ATTACHMENT_TIMEOUT = 10; // @php83: add type int
    private const ATTACHMENT_MAX_BYTES = 26214400; // 25 MB @php83: add type int
    private const ATTACHMENT_MAX_COUNT = 20; // @php83: add type int
    private const ATTACHMENT_CREATOR = 'WP-API'; // @php83: add type string
    /** Praefix in `datei`.`nummer`, ueber das Re-Pushes ihre Importe wiederfinden. */
    private const ATTACHMENT_MARKER_PREFIX = 'WP-REPAIR-MEDIA-'; // @php83: add type string

    /** @var list<string> Erlaubte MIME-Typen fuer `media_urls`. */
    private const MEDIA_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'video/mp4',
        'video/quicktime',
    ]; // @php83: add type array

    /** @var list<string> Zusaetzlich erlaubte MIME-Typen fuer `document_url`. */
    private const DOCUMENT_MIME_TYPES = ['text/html']; // @php83: add type array

    /** @var array<string, string> Dateiendung je MIME-Typ, wenn die URL keine liefert. */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'text/html' => 'html',
    ]; // @php83: add type array

    public function __construct(
        private readonly Database $db,
        private readonly RepairApiAuth $auth,
        private readonly RepairConfigService $configService,
        private readonly RepairDetailsGateway $detailsGateway,
        private readonly RepairStatusConfigGateway $statusConfigGateway,
        // Optional, damit der Standalone-Entry-Point (www/repairapi/index.php)
        // den Controller weiterhin mit fuenf Argumenten bauen kann.
        private readonly ?RepairAdresseService $adresseService = null,
    ) {}

    public function handlePushDetails(): void
    {
        try {
            $this->validateMethod('POST');
            $this->validateContentType();
            $this->checkRateLimit();

            $rawBody = $this->readBody();
            $this->authenticate($rawBody);

            $data = json_decode($rawBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ValidationException('INVALID_JSON');
            }
            $this->validatePushDetailsSchema($data);

            $this->processPushDetails($data);
            $this->respond(200, ['success' => true]);
        } catch (AuthenticationException $e) {
            $this->logInbound(null, $rawBody ?? '', false, $e->getMessage());
            $this->respond(401, ['success' => false, 'error' => $e->getMessage()]);
        } catch (ValidationException $e) {
            $this->respond(400, ['success' => false, 'error' => $e->getMessage()]);
        } catch (ForbiddenException $e) {
            $this->respond(403, ['success' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log(
                'RepairIntegration push failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine()
            );
            $this->respond(500, ['success' => false, 'error' => 'INTERNAL_ERROR']);
        }
    }

    /**
     * Reiner Verbindungstest fuer das WordPress-Plugin.
     *
     * Prueft ausschliesslich Erreichbarkeit und Authentifizierung. Es werden
     * bewusst weder Tickets angelegt noch Reparaturdaten geschrieben, damit
     * der Test beliebig oft ausgefuehrt werden kann.
     */
    public function handlePing(): void
    {
        try {
            $this->validateMethod('POST');
            $this->checkRateLimit();

            $rawBody = $this->readBody();
            $this->authenticate($rawBody);

            $this->logInbound(null, $rawBody, true, '', 'ping');
            $this->respond(200, ['success' => true, 'pong' => true]);
        } catch (AuthenticationException $e) {
            $this->logInbound(null, $rawBody ?? '', false, $e->getMessage(), 'ping');
            $this->respond(401, ['success' => false, 'error' => $e->getMessage()]);
        } catch (ValidationException $e) {
            $this->respond(400, ['success' => false, 'error' => $e->getMessage()]);
        } catch (ForbiddenException $e) {
            $this->respond(403, ['success' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log(
                'RepairIntegration ping failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine()
            );
            $this->respond(500, ['success' => false, 'error' => 'INTERNAL_ERROR']);
        }
    }

    private function validateMethod(string $expected): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $expected) {
            throw new ValidationException('METHOD_NOT_ALLOWED');
        }
    }

    private function validateContentType(): void
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') === false) {
            throw new ValidationException('UNSUPPORTED_MEDIA_TYPE');
        }
    }

    private function readBody(): string
    {
        $body = file_get_contents('php://input');
        if ($body === false || strlen($body) > self::MAX_PAYLOAD_SIZE) {
            throw new ValidationException('PAYLOAD_TOO_LARGE');
        }
        return $body;
    }

    private function authenticate(string $rawBody): void
    {
        $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
        $timestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
        $secret = $this->configService->getInboundSharedSecret();

        // Support both HMAC-SHA256 (preferred) and Bearer token (WP plugin compat)
        if ($signature !== '' && $timestamp !== '') {
            // HMAC authentication
            $this->auth->validateRequest($rawBody, $signature, $timestamp, $secret);
        } else {
            // Bearer token fallback
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (strpos($authHeader, 'Bearer ') !== 0) {
                throw new AuthenticationException('MISSING_AUTH');
            }
            $token = substr($authHeader, 7);
            if (!hash_equals($secret, $token)) {
                throw new AuthenticationException('INVALID_BEARER_TOKEN');
            }
        }
    }

    private function checkRateLimit(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = md5($ip);
        $window = date('YmdHi');

        $count = (int)$this->db->fetchValue(
            'SELECT `request_count` FROM `repair_api_ratelimit`
             WHERE `identifier_hash` = :key AND `window_key` = :window',
            ['key' => $key, 'window' => $window]
        );

        if ($count >= self::MAX_REQUESTS_PER_MINUTE) {
            throw new ForbiddenException('RATE_LIMIT_EXCEEDED');
        }

        $this->db->perform(
            'INSERT INTO `repair_api_ratelimit` (`identifier_hash`, `window_key`, `request_count`)
             VALUES (:key, :window, 1)
             ON DUPLICATE KEY UPDATE `request_count` = `request_count` + 1',
            ['key' => $key, 'window' => $window]
        );
    }

    private function validatePushDetailsSchema(array $data): void
    {
        $required = ['request_number', 'service_type'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }

        $validServiceTypes = ['reparatur', 'wartung', 'reverse_engineering', 'individualisierung'];
        if (!in_array($data['service_type'], $validServiceTypes, true)) {
            throw new ValidationException('Invalid service_type');
        }

        if (strlen($data['request_number']) > 20) {
            throw new ValidationException('request_number too long');
        }

        // `status` wird hier bewusst NICHT validiert. Das Feld ist optional und
        // rein beratend: normalizeWpStatus() liefert fuer Nicht-Strings, leere,
        // ueberlange oder anders geformte Werte null, die Ticket-Anlage faellt
        // dann auf DEFAULT_STATUS zurueck. Ein kaputtes Beiwerk-Feld darf die
        // Anlage des Tickets nicht mit 400 scheitern lassen.
    }

    private function processPushDetails(array $data): void
    {
        $ticketSchluessel = $data['request_number'];
        $createdAt = self::normalizeCreatedAt($data['created_at'] ?? null);

        // Find existing ticket by schluessel
        $ticket = $this->db->fetchRow(
            'SELECT `id`, `schluessel`, `quelle`, `adresse` FROM `ticket` WHERE `schluessel` = :key',
            ['key' => $ticketSchluessel]
        );

        $isNewTicket = false;
        if (!$ticket) {
            // No ticket found — create one from the WP payload.
            // Der WP-Status wird nur hier ausgewertet: nach der Anlage besitzt
            // OpenXE den Workflow, bestehende Tickets werden nie ueberschrieben.
            $isNewTicket = true;
            $wpStatus = self::normalizeWpStatus($data['status'] ?? null);
            $mappedStatus = $this->resolveOpenXeStatus($wpStatus, (string)$data['service_type']);
            $ticket = $this->createTicketFromPayload($data, $mappedStatus ?? self::DEFAULT_STATUS, $createdAt);
            $this->createInitialTicketMessage($data, $ticket['schluessel'], $createdAt);

            $note = 'TICKET_CREATED';
            if ($wpStatus !== null && $mappedStatus === null) {
                $note .= sprintf(
                    ' (WP-Status "%s" ohne Mapping, Fallback "%s")',
                    $wpStatus,
                    self::DEFAULT_STATUS
                );
            }
            $this->logInbound($ticketSchluessel, (string)json_encode($data), true, $note);
        } elseif ($createdAt !== null && (string)($ticket['quelle'] ?? '') === 'api') {
            // Bestandskorrektur per Re-Push: Tickets, die diese Schnittstelle
            // selbst angelegt hat, duerfen ihre Erstellzeit nachtraeglich vom
            // WP-Zeitstempel bekommen. Manuell angelegte Tickets bleiben unberuehrt.
            $this->db->perform(
                'UPDATE `ticket` SET `zeit` = :zeit WHERE `id` = :id',
                ['zeit' => $createdAt, 'id' => (int)$ticket['id']]
            );
            // Auch die urspruengliche API-Nachricht mitkorrigieren, sonst zeigt
            // die Ticket-Detailansicht weiterhin den Push-Zeitpunkt.
            $this->db->perform(
                'UPDATE `ticket_nachricht` SET `zeit` = :zeit
                 WHERE `medium` = \'api\' AND `ticket` = :schluessel
                 ORDER BY `id` ASC LIMIT 1',
                ['zeit' => $createdAt, 'schluessel' => (string)$ticket['schluessel']]
            );
        }

        $existing = $this->detailsGateway->getByTicketId((int)$ticket['id']);
        if ($existing !== null) {
            // Update existing details
            $this->detailsGateway->update((int)$existing['id'], $this->mapInboundData($data));
        } else {
            // Create new details
            $details = array_merge(
                ['ticket_id' => (int)$ticket['id'], 'ticket_schluessel' => $ticket['schluessel']],
                $this->mapInboundData($data)
            );
            $this->detailsGateway->create($details);
        }

        // Adresse: bei Neuanlage immer, bei Bestandstickets nur solange dort
        // noch keine Adresse haengt (manuelle Zuordnung hat Vorrang).
        if ($isNewTicket || (int)($ticket['adresse'] ?? 0) === 0) {
            $this->linkCustomerAdresse((int)$ticket['id'], $data);
        }

        $this->importAttachments($data, (string)$ticket['schluessel'], $createdAt);

        $this->logInbound($ticketSchluessel, (string)json_encode($data), true);
    }

    /**
     * Normalisiert den optionalen WP-Status aus dem Payload.
     *
     * Reine Funktion ohne DB-Zugriff (bewusst statisch, damit sie ohne
     * Container getestet werden kann). Liefert null, wenn der Wert nicht als
     * Status-Slug verwertbar ist — der Aufrufer faellt dann auf DEFAULT_STATUS
     * zurueck, ein unbekannter Wert ist kein Request-Fehler.
     *
     * @param mixed $raw Rohwert aus dem JSON-Payload
     */
    public static function normalizeWpStatus(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $value = strtolower(trim($raw));
        if ($value === '' || strlen($value) > self::MAX_STATUS_LENGTH) {
            return null;
        }
        if (preg_match('/^[a-z0-9_]+$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * Normalisiert das optionale Top-Level-Feld `created_at` aus dem Payload.
     *
     * Erwartet 'Y-m-d H:i:s' in Europe/Berlin, so wie es das WP-Plugin sendet.
     * Reine Funktion ohne DB-Zugriff (bewusst statisch, damit sie ohne
     * Container getestet werden kann). Liefert null, wenn der Wert fehlt,
     * nicht parsebar oder unplausibel ist — der Aufrufer faellt dann auf NOW()
     * zurueck bzw. laesst den Bestand unveraendert.
     *
     * @param mixed $raw Rohwert aus dem JSON-Payload
     */
    public static function normalizeCreatedAt(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        $timezone = new \DateTimeZone(self::PAYLOAD_TIMEZONE);
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $timezone);
        if ($date === false) {
            return null;
        }

        // getLastErrors() liefert ab PHP 8.2 false statt eines Null-Arrays;
        // beide Faelle werden hier abgedeckt.
        $errors = \DateTimeImmutable::getLastErrors();
        if (is_array($errors)
            && ((int)($errors['warning_count'] ?? 0) > 0 || (int)($errors['error_count'] ?? 0) > 0)
        ) {
            return null;
        }

        $min = new \DateTimeImmutable(self::CREATED_AT_MIN, $timezone);
        $max = (new \DateTimeImmutable('now', $timezone))->modify('+1 day');
        if ($date < $min || $date > $max) {
            return null;
        }

        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Loest einen WP-Status-Slug ueber die Kategorie des Service-Typs in einen
     * OpenXE-Status auf. Null = kein Mapping vorhanden.
     */
    private function resolveOpenXeStatus(?string $wpStatus, string $serviceType): ?string
    {
        if ($wpStatus === null) {
            return null;
        }

        $type = ServiceType::tryFrom($serviceType);
        if ($type === null) {
            return null;
        }

        $row = $this->statusConfigGateway->getByWpMapping($wpStatus, $type->statusCategory());
        if ($row === null || empty($row['slug'])) {
            return null;
        }

        return (string)$row['slug'];
    }

    /**
     * Creates a new ticket from the WP API push payload.
     *
     * @param array<string, mixed> $data Validated payload from WP
     * @param string $status OpenXE-Status-Slug (aufgeloest aus dem WP-Status)
     * @param string|null $createdAt Geprueftes `created_at`, null = NOW()
     * @return array{id: int, schluessel: string} The created ticket row
     */
    private function createTicketFromPayload(
        array $data,
        string $status = self::DEFAULT_STATUS,
        ?string $createdAt = null,
    ): array {
        $ticketSchluessel = $data['request_number'];
        $serviceType = $data['service_type'];
        $manufacturer = $data['device']['manufacturer'] ?? '';
        $model = $data['device']['model'] ?? '';
        $betreff = $this->buildSubjectLine($serviceType, $ticketSchluessel, $manufacturer, $model);

        $customerName = $data['customer']['name'] ?? '';
        $customerEmail = $data['customer']['email'] ?? '';
        $verfasser = $customerName !== '' && $customerEmail !== ''
            ? "{$customerName} <{$customerEmail}>"
            : ($customerName !== '' ? $customerName : $customerEmail);

        $companyName = (string)($data['customer']['company'] ?? '');
        $notiz = "Automatisch erstellt via WP API Push ({$serviceType})";
        if ($companyName !== '') {
            $notiz .= " | Firma: {$companyName}";
        }

        $this->db->perform(
            "INSERT INTO `ticket` (
                `schluessel`, `zeit`, `projekt`, `quelle`, `status`, `kunde`,
                `mailadresse`, `prio`, `betreff`, `firma`, `notiz`,
                `bearbeiter`, `adresse`, `warteschlange`, `zugewiesen`,
                `inbearbeitung`, `inbearbeitung_user`, `kommentar`, `tags`
            ) VALUES (
                :schluessel, IFNULL(:zeit, NOW()), 0, :quelle, :status, :kunde,
                :mailadresse, :prio, :betreff, :firma, :notiz,
                '', 0, '', 0,
                0, '', '', ''
            )",
            [
                'schluessel' => $ticketSchluessel,
                'zeit' => $createdAt,
                'quelle' => 'api',
                'status' => $status,
                'kunde' => $verfasser,
                'mailadresse' => $customerEmail,
                'prio' => 3,
                'betreff' => $betreff,
                'firma' => 1,
                'notiz' => $notiz,
            ]
        );
        $ticketId = (int)$this->db->lastInsertId();

        return ['id' => $ticketId, 'schluessel' => $ticketSchluessel];
    }

    /**
     * Builds a subject line with the appropriate service-type tag prefix.
     */
    private function buildSubjectLine(
        string $serviceType,
        string $requestNumber,
        string $manufacturer,
        string $model,
    ): string {
        $tagMap = [
            'reparatur' => '[REP] Reparaturanfrage',
            'wartung' => '[WRT] Wartungsanfrage',
            'reverse_engineering' => '[REV] RE-Anfrage',
            'individualisierung' => '[IND] Individualisierung',
        ];

        $prefix = $tagMap[$serviceType] ?? "[{$serviceType}]";
        $devicePart = trim("{$manufacturer} {$model}");
        $subject = "{$prefix} Ticket #{$requestNumber}";

        if ($devicePart !== '') {
            $subject .= " - {$devicePart}";
        }

        return $subject;
    }

    /**
     * Creates the first ticket_nachricht entry with the issue description.
     *
     * @param array<string, mixed> $data Validated payload from WP
     * @param string $ticketSchluessel The ticket schluessel (NOT ticket.id)
     * @param string|null $createdAt Geprueftes `created_at`, null = NOW()
     */
    private function createInitialTicketMessage(
        array $data,
        string $ticketSchluessel,
        ?string $createdAt = null,
    ): void {
        $customerName = $data['customer']['name'] ?? '';
        $customerEmail = $data['customer']['email'] ?? '';
        $verfasser = $customerName !== '' && $customerEmail !== ''
            ? "{$customerName} <{$customerEmail}>"
            : ($customerName !== '' ? $customerName : $customerEmail);

        $issueDescription = $data['service_details']['issue_description'] ?? '';
        if ($issueDescription === '') {
            $issueDescription = '(Keine Fehlerbeschreibung uebermittelt)';
        }

        $serviceType = $data['service_type'];
        $manufacturer = $data['device']['manufacturer'] ?? '';
        $model = $data['device']['model'] ?? '';
        $betreff = $this->buildSubjectLine($serviceType, $ticketSchluessel, $manufacturer, $model);

        $this->db->perform(
            "INSERT INTO `ticket_nachricht` (
                `ticket`, `zeit`, `text`, `betreff`, `medium`,
                `verfasser`, `mail`, `status`,
                `bearbeiter`, `textausgang`, `bemerkung`, `versendet`,
                `mail_cc`, `verfasser_replyto`, `mail_replyto`
            ) VALUES (
                :ticket, IFNULL(:zeit, NOW()), :text, :betreff, :medium,
                :verfasser, :mail, :status,
                '', '', '', '',
                '', '', ''
            )",
            [
                'ticket' => $ticketSchluessel,
                'zeit' => $createdAt,
                'text' => $issueDescription,
                'betreff' => $betreff,
                'medium' => 'api',
                'verfasser' => $verfasser,
                'mail' => $customerEmail,
                'status' => 'neu',
            ]
        );

        // Update ticket message count
        $this->db->perform(
            "UPDATE `ticket` AS t
             INNER JOIN (
                 SELECT COUNT(`id`) AS co, `ticket`
                 FROM `ticket_nachricht`
                 GROUP BY `ticket`
             ) AS tn ON t.`schluessel` = tn.`ticket`
             SET t.`nachrichten_anz` = tn.co
             WHERE t.`schluessel` = :schluessel",
            ['schluessel' => $ticketSchluessel]
        );
    }

    /**
     * Haengt die Kundenadresse an das Ticket. Fehler werden protokolliert,
     * duerfen den Push aber nicht scheitern lassen — das Ticket samt Details
     * ist wichtiger als die Adressverknuepfung.
     *
     * @param array<string, mixed> $data Validated payload from WP
     */
    private function linkCustomerAdresse(int $ticketId, array $data): void
    {
        $customer = $data['customer'] ?? null;
        if (!is_array($customer) || $customer === []) {
            return;
        }

        try {
            $service = $this->adresseService ?? new RepairAdresseService($this->db);
            $service->ensureAdresseForTicket($ticketId, $customer);
        } catch (\Throwable $e) {
            error_log(
                'RepairIntegration: Adressverknuepfung fuer Ticket ' . $ticketId . ' fehlgeschlagen: '
                . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine()
            );
        }
    }

    /**
     * Laedt `media_urls` und `document_url` herunter und haengt sie als
     * OpenXE-Dateien an die erste Nachricht des Tickets.
     *
     * Idempotent: die Quell-URL wird als Marker in `datei`.`nummer` abgelegt,
     * ein Re-Push erzeugt darum keine Duplikate. Alle Fehler werden nur
     * protokolliert — der Push bleibt in jedem Fall erfolgreich.
     *
     * @param array<string, mixed> $data Validated payload from WP
     * @param string|null $createdAt Geprueftes `created_at`, null = heute
     */
    private function importAttachments(array $data, string $ticketSchluessel, ?string $createdAt): void
    {
        try {
            $jobs = [];

            if (isset($data['media_urls']) && is_array($data['media_urls'])) {
                foreach ($data['media_urls'] as $entry) {
                    // Das Plugin sendet je nach Version blanke URLs oder Objekte mit `url`.
                    $url = is_array($entry) ? ($entry['url'] ?? null) : $entry;
                    if (is_string($url) && trim($url) !== '') {
                        $jobs[] = ['url' => trim($url), 'types' => self::MEDIA_MIME_TYPES];
                    }
                }
            }

            if (isset($data['document_url']) && is_string($data['document_url'])
                && trim($data['document_url']) !== ''
            ) {
                $jobs[] = [
                    'url' => trim($data['document_url']),
                    'types' => array_merge(self::MEDIA_MIME_TYPES, self::DOCUMENT_MIME_TYPES),
                ];
            }

            if ($jobs === []) {
                return;
            }

            // Obergrenze gegen ausufernde Payloads: jeder Download blockiert
            // den Request synchron (bis 10s Timeout pro URL).
            if (count($jobs) > self::ATTACHMENT_MAX_COUNT) {
                error_log(sprintf(
                    'RepairIntegration: %d Anhaenge im Payload, importiere nur die ersten %d',
                    count($jobs),
                    self::ATTACHMENT_MAX_COUNT
                ));
                $jobs = array_slice($jobs, 0, self::ATTACHMENT_MAX_COUNT);
            }

            // Anhaenge haengen in OpenXE an der Nachricht, nicht am Ticket
            // (vgl. www/pages/ticket.php: AddDateiStichwort(..., 'Ticket', <nachricht.id>)).
            $nachrichtId = (int)$this->db->fetchValue(
                'SELECT MIN(`id`) FROM `ticket_nachricht` WHERE `ticket` = :key',
                ['key' => $ticketSchluessel]
            );
            if ($nachrichtId <= 0) {
                error_log(
                    'RepairIntegration: keine Ticket-Nachricht zu ' . $ticketSchluessel
                    . ' gefunden, Anhaenge uebersprungen'
                );
                return;
            }

            foreach ($jobs as $job) {
                try {
                    $this->importSingleAttachment(
                        $job['url'],
                        $job['types'],
                        $ticketSchluessel,
                        $nachrichtId,
                        $createdAt
                    );
                } catch (\Throwable $e) {
                    error_log(
                        'RepairIntegration: Anhang ' . $job['url'] . ' fuer ' . $ticketSchluessel
                        . ' fehlgeschlagen: ' . get_class($e) . ': ' . $e->getMessage()
                        . ' @ ' . $e->getFile() . ':' . $e->getLine()
                    );
                }
            }
        } catch (\Throwable $e) {
            error_log(
                'RepairIntegration: Anhang-Import fuer ' . $ticketSchluessel . ' fehlgeschlagen: '
                . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine()
            );
        }
    }

    /**
     * @param list<string> $allowedTypes Erlaubte MIME-Typen
     */
    private function importSingleAttachment(
        string $url,
        array $allowedTypes,
        string $ticketSchluessel,
        int $nachrichtId,
        ?string $createdAt,
    ): void {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            error_log('RepairIntegration: Anhang-URL mit unzulaessigem Schema uebersprungen: ' . $url);
            return;
        }

        $marker = self::ATTACHMENT_MARKER_PREFIX . sha1($url);
        $alreadyImported = $this->db->fetchValue(
            'SELECT `d`.`id`
               FROM `datei` AS `d`
               INNER JOIN `datei_stichwoerter` AS `ds` ON `ds`.`datei` = `d`.`id`
              WHERE `d`.`nummer` = :marker
                AND `ds`.`objekt` = :objekt
                AND `ds`.`parameter` = :parameter
              LIMIT 1',
            ['marker' => $marker, 'objekt' => 'Ticket', 'parameter' => (string)$nachrichtId]
        );
        if ($alreadyImported !== false && $alreadyImported !== null) {
            return;
        }

        $file = $this->downloadAttachment($url, $allowedTypes);
        if ($file === null) {
            return;
        }

        $this->storeTicketAttachment(
            $file['content'],
            $file['filename'],
            $marker,
            $url,
            $ticketSchluessel,
            $nachrichtId,
            $createdAt
        );
    }

    /**
     * Laedt eine Datei mit Timeout, Groessenlimit und MIME-Pruefung.
     *
     * @param list<string> $allowedTypes Erlaubte MIME-Typen
     * @return array{content: string, mime: string, filename: string}|null
     */
    private function downloadAttachment(string $url, array $allowedTypes): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: OpenXE-RepairIntegration',
                'timeout' => self::ATTACHMENT_TIMEOUT,
                'follow_location' => 1,
                'max_redirects' => 3,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        // Ein Byte ueber dem Limit lesen, um Ueberlaeufe sicher zu erkennen,
        // ohne die ganze Datei in den Speicher zu ziehen.
        error_clear_last();
        $content = @file_get_contents($url, false, $context, 0, self::ATTACHMENT_MAX_BYTES + 1);
        $headers = $http_response_header ?? [];

        if ($content === false) {
            $lastError = error_get_last();
            error_log(
                'RepairIntegration: Download fehlgeschlagen (' . $url . '): '
                . ($lastError['message'] ?? 'unbekannter Fehler')
            );
            return null;
        }

        $httpCode = self::parseHttpCode($headers);
        if ($httpCode !== 0 && ($httpCode < 200 || $httpCode >= 300)) {
            error_log('RepairIntegration: Download lieferte HTTP ' . $httpCode . ' (' . $url . ')');
            return null;
        }

        if ($content === '') {
            error_log('RepairIntegration: Download lieferte leere Datei (' . $url . ')');
            return null;
        }

        if (strlen($content) > self::ATTACHMENT_MAX_BYTES) {
            error_log(
                'RepairIntegration: Download ueberschreitet ' . self::ATTACHMENT_MAX_BYTES
                . ' Byte (' . $url . ')'
            );
            return null;
        }

        $mime = self::parseContentType($headers);
        if (!in_array($mime, $allowedTypes, true)) {
            error_log('RepairIntegration: MIME-Typ "' . $mime . '" nicht erlaubt (' . $url . ')');
            return null;
        }

        return [
            'content' => $content,
            'mime' => $mime,
            'filename' => self::buildAttachmentFileName($url, $mime),
        ];
    }

    /**
     * @param list<string> $headers Roh-Header aus $http_response_header
     */
    private static function parseHttpCode(array $headers): int
    {
        $code = 0;
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/[\d.]+ (\d{3})/', (string)$header, $matches) === 1) {
                // Bei Redirects steht der finale Status hinten.
                $code = (int)$matches[1];
            }
        }
        return $code;
    }

    /**
     * @param list<string> $headers Roh-Header aus $http_response_header
     */
    private static function parseContentType(array $headers): string
    {
        $mime = '';
        foreach ($headers as $header) {
            if (preg_match('/^Content-Type:\s*([^;\r\n]+)/i', (string)$header, $matches) === 1) {
                $mime = strtolower(trim($matches[1]));
            }
        }

        // Manche Webserver liefern die nicht registrierte Variante image/jpg.
        if ($mime === 'image/jpg') {
            $mime = 'image/jpeg';
        }

        return $mime;
    }

    private static function buildAttachmentFileName(string $url, string $mime): string
    {
        $name = rawurldecode(basename((string)parse_url($url, PHP_URL_PATH)));
        $name = (string)preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        $name = ltrim($name, '.');
        if (strlen($name) > 200) {
            // Von hinten kuerzen, damit die Endung erhalten bleibt.
            $name = substr($name, -200);
        }
        if ($name === '') {
            $name = 'anhang';
        }
        if (pathinfo($name, PATHINFO_EXTENSION) === '') {
            $name .= '.' . (self::MIME_EXTENSIONS[$mime] ?? 'bin');
        }

        return $name;
    }

    /**
     * Legt Datei, Version, physische Ablage und Stichwort an — die Minimalform
     * von erpAPI::CreateDatei() + AddDateiStichwort() ohne $app->erp.
     */
    private function storeTicketAttachment(
        string $content,
        string $fileName,
        string $marker,
        string $sourceUrl,
        string $ticketSchluessel,
        int $nachrichtId,
        ?string $createdAt,
    ): void {
        $beschreibung = sprintf(
            "Automatisch importiert aus der WP-Reparaturanfrage %s.\nQuelle: %s",
            $ticketSchluessel,
            $sourceUrl
        );

        $this->db->perform(
            "INSERT INTO `datei` (`titel`, `beschreibung`, `nummer`, `geloescht`, `firma`)
             VALUES (:titel, :beschreibung, :nummer, 0, :firma)",
            [
                'titel' => $fileName,
                'beschreibung' => $beschreibung,
                'nummer' => $marker,
                'firma' => 1,
            ]
        );
        $fileId = (int)$this->db->lastInsertId();
        if ($fileId <= 0) {
            throw new \RuntimeException('INSERT INTO datei lieferte keine ID');
        }

        $this->db->perform(
            "INSERT INTO `datei_version`
                (`datei`, `ersteller`, `datum`, `version`, `dateiname`, `bemerkung`, `size`)
             VALUES (:datei, :ersteller, IFNULL(:datum, CURDATE()), 1, :dateiname, :bemerkung, :size)",
            [
                'datei' => $fileId,
                'ersteller' => self::ATTACHMENT_CREATOR,
                // Spalte ist vom Typ DATE — nur den Datumsteil uebergeben.
                'datum' => $createdAt !== null ? substr($createdAt, 0, 10) : null,
                'dateiname' => $fileName,
                'bemerkung' => 'Initiale Version',
                'size' => (string)strlen($content),
            ]
        );
        $versionId = (int)$this->db->lastInsertId();

        $targetDir = $versionId > 0 ? $this->createDmsPath($versionId) : null;
        $written = $targetDir !== null
            && @file_put_contents($targetDir . '/' . $versionId, $content) !== false;

        if (!$written) {
            // Ohne physische Datei waeren die Zeilen Karteileichen im DMS.
            $this->db->perform('DELETE FROM `datei_version` WHERE `id` = :id', ['id' => $versionId]);
            $this->db->perform('DELETE FROM `datei` WHERE `id` = :id', ['id' => $fileId]);
            throw new \RuntimeException('DMS-Ablage fehlgeschlagen fuer datei ' . $fileId);
        }

        $sort = 1 + (int)$this->db->fetchValue(
            'SELECT MAX(`sort`) FROM `datei_stichwoerter`
              WHERE `objekt` = :objekt AND `parameter` = :parameter',
            ['objekt' => 'Ticket', 'parameter' => (string)$nachrichtId]
        );

        $this->db->perform(
            "INSERT INTO `datei_stichwoerter`
                (`datei`, `subjekt`, `objekt`, `parameter`, `sort`, `parameter2`, `objekt2`)
             VALUES (:datei, :subjekt, :objekt, :parameter, :sort, 0, '')",
            [
                'datei' => $fileId,
                'subjekt' => 'Anhang',
                'objekt' => 'Ticket',
                'parameter' => (string)$nachrichtId,
                'sort' => $sort,
            ]
        );
    }

    /**
     * Baut den DMS-Unterordner zu einer datei_version-ID auf
     * (Namenskonvention aus erpAPI::CreateDMSPath: /d<2 Zeichen>/d<2 Zeichen>/...).
     */
    private function createDmsPath(int $versionId): ?string
    {
        $path = $this->getDmsRoot();
        if ($path === null) {
            return null;
        }

        if (!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path)) {
            error_log('RepairIntegration: DMS-Wurzel nicht anlegbar: ' . $path);
            return null;
        }

        foreach (str_split((string)$versionId, 2) as $chunk) {
            $path .= '/d' . $chunk;
            if (!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path)) {
                error_log('RepairIntegration: DMS-Verzeichnis nicht anlegbar: ' . $path);
                return null;
            }
        }

        return $path;
    }

    /**
     * Ermittelt <userdata>/dms/<dbname> ohne $app->Conf.
     */
    private function getDmsRoot(): ?string
    {
        $userdata = '';
        $dbName = '';

        // Config liegt in conf/main.conf.php und ist ueber xentral_autoloader.php
        // erreichbar — im Standalone-Entry-Point wie im Web-Kontext.
        if (class_exists('Config')) {
            try {
                $conf = new \Config();
                if (!empty($conf->WFuserdata)) {
                    $userdata = rtrim((string)$conf->WFuserdata, '/\\');
                }
                if (!empty($conf->WFdbname)) {
                    $dbName = (string)$conf->WFdbname;
                }
            } catch (\Throwable $e) {
                error_log('RepairIntegration: Config nicht lesbar: ' . $e->getMessage());
            }
        }

        if ($userdata === '') {
            // classes/Modules/RepairIntegration/Api -> Projektwurzel
            $userdata = dirname(__DIR__, 4) . '/userdata';
        }
        if ($dbName === '') {
            $dbName = (string)$this->db->fetchValue('SELECT DATABASE()');
        }
        if ($dbName === '') {
            error_log('RepairIntegration: DMS-Pfad nicht ermittelbar (kein Datenbankname)');
            return null;
        }

        return $userdata . '/dms/' . $dbName;
    }

    private function mapInboundData(array $data): array
    {
        $mapped = [
            'wp_request_number' => $data['request_number'] ?? null,
            'service_type' => $data['service_type'] ?? null,
            // Feldnamen-Kompatibilitaet: das WP-Plugin sendet je nach Version
            // `service_delivery_type` oder das kuerzere `service_delivery`.
            'service_delivery_type' => $data['service_delivery_type'] ?? $data['service_delivery'] ?? 'einsendung',
        ];

        if (isset($data['customer']) && is_array($data['customer'])) {
            $c = $data['customer'];
            $mapped['customer_type'] = isset($c['company']) && $c['company'] !== null ? 'business' : 'private';
            $mapped['company_name'] = $c['company'] ?? null;
            $mapped['vat_id'] = $c['vat_id'] ?? null;
        }

        if (isset($data['device']) && is_array($data['device'])) {
            $d = $data['device'];
            $mapped['manufacturer'] = $d['manufacturer'] ?? null;
            $mapped['model'] = $d['model'] ?? null;
            // Feldnamen-Kompatibilitaet: `serial_number` (aktuell) bzw. `serial` (aeltere Plugin-Version).
            $mapped['serial_number'] = $d['serial_number'] ?? $d['serial'] ?? null;
            $mapped['mods_present'] = !empty($d['mods_present']) ? 1 : 0;
            $mapped['mods_text'] = $d['mods_text'] ?? null;
        }

        if (isset($data['service_details']) && is_array($data['service_details'])) {
            $s = $data['service_details'];
            $fields = [
                'issue_category', 'issue_description', 'warranty_status',
                'cost_limit', 'wartung_paket', 'wartung_notes',
                'has_original_part', 'has_templates', 're_tolerance', 're_output_format',
                'has_3d_file', 'material_preference', 'color_preference',
                'functional_requirements', 'travel_distance_km', 'travel_fee',
            ];
            foreach ($fields as $field) {
                if (isset($s[$field])) {
                    $mapped[$field] = (string)$s[$field];
                }
            }
            $mapped['is_express'] = !empty($s['is_express']) ? 1 : 0;
            if (isset($s['express_price'])) {
                $mapped['express_price'] = (string)$s['express_price'];
            }
        }

        return array_filter($mapped, static fn($v): bool => $v !== null);
    }

    private function logInbound(
        ?string $ticketSchluessel,
        string $payload,
        bool $success,
        string $error = '',
        string $action = 'push_details',
    ): void {
        $this->db->perform(
            "INSERT INTO `repair_sync_log`
             (`direction`, `ticket_schluessel`, `action`, `payload_sent`, `success`, `error_message`, `ip_address`)
             VALUES ('inbound', :key, :action, :payload, :success, :error, :ip)",
            [
                'key' => $ticketSchluessel,
                'action' => $action,
                'payload' => substr($payload, 0, 65000),
                'success' => $success ? 1 : 0,
                'error' => $error !== '' ? $error : null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    }

    private function respond(int $httpCode, array $body): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode($body);
    }
}
