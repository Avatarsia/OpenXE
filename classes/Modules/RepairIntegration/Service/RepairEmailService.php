<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Components\Database\Database;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairStatusConfigGateway;

final class RepairEmailService
{
    public function __construct(
        private readonly Database $db,
        private readonly RepairStatusConfigGateway $statusConfigGateway,
        private readonly RepairDetailsGateway $detailsGateway,
    ) {}

    /**
     * Sends status notification to customer if configured for this status.
     * Returns true if email was sent, false if skipped.
     *
     * Note: Actual mail sending requires $app->erp->MailSend() which is
     * a legacy dependency. This method prepares all data and will be
     * called from the Hook layer which has access to $app.
     */
    public function shouldSendNotification(string $newStatus): bool
    {
        return $this->statusConfigGateway->shouldNotifyCustomer($newStatus);
    }

    public function prepareNotificationData(int $ticketId, string $newStatus): ?array
    {
        $config = $this->statusConfigGateway->getBySlug($newStatus);
        if ($config === null || (int)$config['notify_customer'] !== 1) {
            return null;
        }

        $ticket = $this->db->fetchRow(
            'SELECT * FROM `ticket` WHERE `id` = :id',
            ['id' => $ticketId]
        );
        if (!$ticket) {
            return null;
        }

        $details = $this->detailsGateway->getByTicketId($ticketId);

        return [
            'to' => $ticket['mailadresse'],
            'subject' => sprintf('Status-Update: Ticket #%s', $ticket['schluessel']),
            'ticket' => $ticket,
            'details' => $details,
            'status_label' => $config['label_de'],
            'template' => $config['email_template'] ?? 'status_change',
        ];
    }
}
