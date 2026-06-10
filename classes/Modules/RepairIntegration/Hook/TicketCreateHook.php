<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Hook;

use Xentral\Components\Database\Database;
use Xentral\Modules\RepairIntegration\Service\RepairConfigService;
use Xentral\Modules\RepairIntegration\Service\RepairTicketEnricher;

final class TicketCreateHook
{
    public function __construct(
        private readonly Database $db,
        private readonly RepairTicketEnricher $enricher,
        private readonly RepairConfigService $configService,
    ) {}

    /**
     * Called after a new ticket is created by the IMAP import cronjob.
     * Checks if the ticket has a repair subject tag and enriches it.
     */
    public function onTicketCreated(int $ticketId): bool
    {
        if (!$this->configService->isAutoEnrichEnabled()) {
            return false;
        }

        $ticket = $this->db->fetchRow(
            'SELECT `id`, `betreff` FROM `ticket` WHERE `id` = :id',
            ['id' => $ticketId]
        );
        if (!$ticket) {
            return false;
        }

        // Get the first message body for JSON data extraction
        $message = $this->db->fetchRow(
            'SELECT `text` FROM `ticket_nachricht`
             WHERE `ticket` = (SELECT `schluessel` FROM `ticket` WHERE `id` = :id)
             ORDER BY `zeit` ASC LIMIT 1',
            ['id' => $ticketId]
        );
        $messageBody = $message ? (string)$message['text'] : '';

        return $this->enricher->enrichTicket($ticketId, $ticket['betreff'], $messageBody);
    }
}
