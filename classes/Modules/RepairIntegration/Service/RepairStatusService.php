<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Components\Database\Database;
use Xentral\Modules\RepairIntegration\Enum\ServiceType;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairStatusConfigGateway;

final class RepairStatusService
{
    public function __construct(
        private readonly Database $db,
        private readonly RepairStatusConfigGateway $statusConfigGateway,
        private readonly RepairDetailsGateway $detailsGateway,
    ) {}

    public function getServiceTypeForTicket(int $ticketId): ?ServiceType
    {
        $details = $this->detailsGateway->getByTicketId($ticketId);
        if ($details === null) {
            return null;
        }
        return ServiceType::tryFrom($details['service_type']);
    }

    public function getStatusOptionsForTicket(int $ticketId): array
    {
        $serviceType = $this->getServiceTypeForTicket($ticketId);
        $category = $serviceType !== null ? $serviceType->statusCategory() : null;
        return $this->statusConfigGateway->getActiveStatuses($category);
    }

    public function renderStatusDropdown(string $currentStatus, ?string $serviceTypeCategory): string
    {
        $statuses = $this->statusConfigGateway->getActiveStatuses($serviceTypeCategory);

        $html = '<select name="status" id="status">';
        $currentCategory = '';
        $categoryLabels = [
            'general' => 'Allgemein',
            'repair' => 'Reparatur',
            'maintenance' => 'Wartung',
            'reverse_engineering' => 'Reverse Engineering',
            'individualization' => 'Individualisierung',
        ];

        foreach ($statuses as $status) {
            if ($status['category'] !== $currentCategory) {
                if ($currentCategory !== '') {
                    $html .= '</optgroup>';
                }
                $label = isset($categoryLabels[$status['category']])
                    ? $categoryLabels[$status['category']]
                    : $status['category'];
                $html .= '<optgroup label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">';
                $currentCategory = $status['category'];
            }
            $selected = ($status['slug'] === $currentStatus) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($status['slug'], ENT_QUOTES, 'UTF-8') . '"'
                   . $selected . '>'
                   . htmlspecialchars($status['label_de'], ENT_QUOTES, 'UTF-8')
                   . '</option>';
        }
        if ($currentCategory !== '') {
            $html .= '</optgroup>';
        }
        $html .= '</select>';

        return $html;
    }

    /**
     * MCP-compatible: returns structured array, no side effects beyond DB write.
     */
    public function changeStatus(int $ticketId, string $newStatus): array
    {
        $statusConfig = $this->statusConfigGateway->getBySlug($newStatus);
        if ($statusConfig === null) {
            throw new \Xentral\Modules\RepairIntegration\Exception\InvalidStatusTransitionException(
                sprintf('Unknown status: %s', $newStatus)
            );
        }

        $oldStatus = $this->db->fetchValue(
            'SELECT `status` FROM `ticket` WHERE `id` = :id',
            ['id' => $ticketId]
        );
        if ($oldStatus === false) {
            throw new \InvalidArgumentException(sprintf('Ticket %d not found', $ticketId));
        }

        $this->db->perform(
            'UPDATE `ticket` SET `status` = :status WHERE `id` = :id',
            ['status' => $newStatus, 'id' => $ticketId]
        );

        return [
            'success' => true,
            'ticket_id' => $ticketId,
            'old_status' => (string)$oldStatus,
            'new_status' => $newStatus,
            'wp_status_mapping' => $this->statusConfigGateway->getWpMapping($newStatus),
            'notify_customer' => $this->statusConfigGateway->shouldNotifyCustomer($newStatus),
        ];
    }

    public function getAvailableNextStatuses(string $currentStatus): array
    {
        $next = $this->statusConfigGateway->getNextStatus($currentStatus);
        if ($next === null) {
            return [];
        }
        $nextConfig = $this->statusConfigGateway->getBySlug($next);
        return $nextConfig !== null ? [$nextConfig] : [];
    }
}
