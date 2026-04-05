<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Hook;

use Xentral\Components\Database\Database;
use Xentral\Modules\RepairIntegration\Service\RepairSyncService;
use Xentral\Modules\RepairIntegration\Service\RepairEmailService;

final class TicketStatusChangeHook
{
    public function __construct(
        private readonly Database $db,
        private readonly RepairSyncService $syncService,
        private readonly RepairEmailService $emailService,
    ) {}

    /**
     * Called from ticket_edit_after hook via Page-Controller.
     * Compares old/new status and queues sync + notification if changed.
     */
    public function onTicketEditAfter(int $ticketId, string $oldStatus): void
    {
        $currentStatus = $this->db->fetchValue(
            'SELECT `status` FROM `ticket` WHERE `id` = :id',
            ['id' => $ticketId]
        );

        if ($currentStatus === false || (string)$currentStatus === $oldStatus) {
            return;
        }

        // Queue WP sync
        $this->syncService->checkAndQueueStatusChange($ticketId);

        // Prepare email notification (actual sending done by caller with $app)
        // The caller checks shouldSendNotification() and uses prepareNotificationData()
    }
}
