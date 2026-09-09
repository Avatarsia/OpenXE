<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Components\Database\Database;
use Xentral\Modules\RepairIntegration\Exception\RepairIntegrationException;
use Xentral\Modules\RepairIntegration\Gateway\RepairBelegGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;

final class RepairBelegService
{
    private const ALLOWED_BELEG_TYPES = ['angebot', 'auftrag', 'rechnung']; // @php83: add type array

    public function __construct(
        private readonly Database $db,
        private readonly RepairDetailsGateway $detailsGateway,
        private readonly RepairBelegGateway $belegGateway,
    ) {}

    /**
     * MCP-compatible: returns structured array.
     * Note: Actual Beleg creation requires $app->erp->CreateAngebot() etc.
     * This method is called from the controller which has $app access.
     */
    public function prepareBelegCreation(int $ticketId, string $belegTyp): array
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
        ];
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
        foreach ($links as $link) {
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
