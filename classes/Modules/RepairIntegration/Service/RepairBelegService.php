<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Components\Database\Database;
use Xentral\Modules\RepairIntegration\Exception\RepairIntegrationException;
use Xentral\Modules\RepairIntegration\Gateway\RepairBelegGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;

final class RepairBelegService
{
    public const SERVICE_FIELDS = ['public_diagnosis', 'service_work', 'final_check', 'position_net_amount'];

    private const ALLOWED_BELEG_TYPES = ['angebot', 'auftrag', 'rechnung']; // @php83: add type array

    public function __construct(
        private readonly Database $db,
        private readonly RepairDetailsGateway $detailsGateway,
        private readonly RepairBelegGateway $belegGateway,
        private readonly RepairConfigService $config,
    ) {}

    /**
     * MCP-compatible: returns structured array.
     * Note: Actual Beleg creation requires $app->erp->CreateAngebot() etc.
     * This method is called from the controller which has $app access.
     */
    public function prepareBelegCreation(int $ticketId, string $belegTyp, string $mode = 'service'): array
    {
        if (!in_array($belegTyp, self::ALLOWED_BELEG_TYPES, true)) {
            throw new RepairIntegrationException(
                sprintf('Invalid Beleg type: %s', $belegTyp)
            );
        }

        $ticket = $this->db->fetchRow(
            'SELECT `id`, `schluessel`, `adresse`, `betreff` FROM `ticket` WHERE `id` = :id',
            ['id' => $ticketId]
        );
        if (!$ticket) {
            throw new RepairIntegrationException(
                sprintf('Ticket %d not found', $ticketId)
            );
        }

        $adresseId = (int)$ticket['adresse'];
        if ($adresseId === 0) {
            throw new RepairIntegrationException('Ticket hat keine verknuepfte Adresse');
        }

        $details = $this->detailsGateway->getByTicketId($ticketId);
        if ($details === null) {
            throw new RepairIntegrationException('Keine Reparaturdaten fuer dieses Ticket.');
        }
        if (!in_array($mode, ['service', 'fee'], true)
            || ($mode === 'fee' && ($belegTyp !== 'rechnung' || $details['service_type'] !== 'reparatur'))) {
            throw new RepairIntegrationException('Ungueltige Abrechnung. Nur KVA ist ausschliesslich als Rechnung fuer Reparaturen moeglich.');
        }
        $number = trim($this->config->get('service_article_number'));
        if ($number === '') {
            throw new RepairIntegrationException('Bitte in Reparaturen > Einstellungen > Artikel und Produkttexte einen Artikel hinterlegen.');
        }
        $article = $this->db->fetchRow(
            'SELECT id, nummer, name_de FROM artikel WHERE nummer = :number AND geloescht = 0 LIMIT 1',
            ['number' => $number]
        );
        if (!$article) {
            throw new RepairIntegrationException('Hinterlegter Artikel ist nicht vorhanden oder geloescht.');
        }
        $templateKey = $mode === 'fee' ? 'fee' : ($details['service_type'] ?? 'reparatur');
        if ($templateKey === 'reparatur' && $belegTyp === 'rechnung') {
            $templateKey = 'reparatur_rechnung';
        }
        $defaults = RepairPositionTemplate::defaults();
        if (!isset($defaults[$templateKey])) {
            throw new RepairIntegrationException('Unbekannter Service-Typ.');
        }
        $gross = $mode === 'fee' ? RepairPositionTemplate::normalizeAmount($details['diagnosis_cost_snapshot'] ?? null) : null;
        if ($mode === 'fee' && $gross === null) {
            throw new RepairIntegrationException('Urspruenglicher KVA-Preis aus dem Formular fehlt. Keine automatische Abrechnung moeglich.');
        }
        $position = [
            'article_id' => (int)$article['id'], 'name' => (string)$article['name_de'],
            'description' => RepairPositionTemplate::render(
                $this->config->get('position_template_' . $templateKey, $defaults[$templateKey]), $details, (string)$ticket['schluessel']
            ),
            'net_amount' => $mode === 'fee' ? null : RepairPositionTemplate::normalizeAmount($details['position_net_amount'] ?? null),
            'gross_amount' => $gross,
        ];

        $betreff = '';
        if ($details !== null) {
            $betreff = sprintf(
                '%s: %s %s (Ticket #%s)',
                ucfirst($details['service_type'] ?? ''),
                $details['manufacturer'] ?? '',
                $details['model'] ?? '',
                $ticket['schluessel'],
            );
        }

        return [
            'ticket_id' => $ticketId,
            'ticket_schluessel' => $ticket['schluessel'],
            'adresse_id' => $adresseId,
            'beleg_typ' => $belegTyp,
            'betreff' => $betreff,
            'details' => $details,
            'position' => $position,
        ];
    }

    public function saveServiceFields(int $ticketId, array $input): array
    {
        $values = [];
        foreach (self::SERVICE_FIELDS as $field) {
            if (!array_key_exists($field, $input)) continue;
            $value = $input[$field];
            if ($field === 'position_net_amount') {
                $values[$field] = RepairPositionTemplate::normalizeAmount($value);
            } else {
                if (!is_string($value) || strlen($value) > 12000) {
                    throw new RepairIntegrationException('Leistungsdaten muessen Text mit maximal 12000 Bytes pro Feld sein.');
                }
                $values[$field] = trim($value) === '' && $field === 'service_work' ? null : trim($value);
            }
        }
        $this->detailsGateway->updateServiceFields($ticketId, $values);
        return $values;
    }

    /**
     * Links a created Beleg to a ticket. Called after CreateAngebot/Auftrag/Rechnung.
     */
    public function linkBelegToTicket(
        int $ticketId,
        string $schluessel,
        string $belegTyp,
        int $belegId,
        ?string $belegNr,
        string $createdBy,
    ): array {
        $this->belegGateway->link(
            $ticketId,
            $schluessel,
            $belegTyp,
            $belegId,
            $belegNr,
            true,
            $createdBy,
        );

        return [
            'success' => true,
            'ticket_id' => $ticketId,
            'beleg_typ' => $belegTyp,
            'beleg_id' => $belegId,
            'beleg_nr' => $belegNr,
        ];
    }

    public function getBelegeForTicket(int $ticketId): array
    {
        return $this->belegGateway->getByTicketId($ticketId);
    }

    /**
     * Called from Hook on WeiterfuehrenAngebotZuAuftrag.
     * Copies ticket-beleg links from source beleg to new beleg.
     */
    public function onBelegWeiterfuehren(
        string $sourceBelegTyp,
        int $sourceBelegId,
        string $targetBelegTyp,
        int $targetBelegId,
        ?string $targetBelegNr,
        string $createdBy,
    ): void {
        $links = $this->belegGateway->getByBeleg($sourceBelegTyp, $sourceBelegId);
        $existing = $this->belegGateway->getByBeleg($targetBelegTyp, $targetBelegId);
        $linkedTickets = array_map(static fn(array $row): int => (int)$row['ticket_id'], $existing);
        foreach ($links as $link) {
            if (in_array((int)$link['ticket_id'], $linkedTickets, true)) continue;
            $this->belegGateway->link(
                (int)$link['ticket_id'],
                $link['ticket_schluessel'],
                $targetBelegTyp,
                $targetBelegId,
                $targetBelegNr,
                false,
                $createdBy,
            );
        }
    }
}
