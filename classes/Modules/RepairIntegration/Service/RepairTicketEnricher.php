<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Components\Database\Database;
use Xentral\Modules\RepairIntegration\Enum\ServiceType;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;

final class RepairTicketEnricher
{
    private const string REPAIR_DATA_PATTERN = '/<!--REPAIR_DATA_START\s*(\{.*?\})\s*REPAIR_DATA_END-->/s';
    private const string SUBJECT_TAG_PATTERN = '/\[(REP|WRT|REV|IND)\]/i';

    public function __construct(
        private readonly Database $db,
        private readonly RepairDetailsGateway $detailsGateway,
    ) {}

    public function parseSubjectTag(string $subject): ?ServiceType
    {
        if (preg_match(self::SUBJECT_TAG_PATTERN, $subject, $matches) === 1) {
            return ServiceType::fromSubjectTag('[' . strtoupper($matches[1]) . ']');
        }
        return null;
    }

    public function parseRepairDataFromBody(string $htmlBody): ?array
    {
        if (preg_match(self::REPAIR_DATA_PATTERN, $htmlBody, $matches) !== 1) {
            return null;
        }

        $data = json_decode($matches[1], true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * Enriches a ticket with repair details parsed from the email.
     * Returns true if enrichment was performed, false if skipped.
     */
    public function enrichTicket(int $ticketId, string $subject, string $messageBody): bool
    {
        // Skip if already enriched
        $existing = $this->detailsGateway->getByTicketId($ticketId);
        if ($existing !== null) {
            return false;
        }

        // Parse service type from subject tag
        $serviceType = $this->parseSubjectTag($subject);
        if ($serviceType === null) {
            return false;
        }

        // Get ticket data for schluessel
        $ticket = $this->db->fetchRow(
            'SELECT `id`, `schluessel` FROM `ticket` WHERE `id` = :id',
            ['id' => $ticketId]
        );
        if (!$ticket) {
            return false;
        }

        // Parse structured data from email body
        $repairData = $this->parseRepairDataFromBody($messageBody);

        // Build details array
        $details = [
            'ticket_id' => $ticketId,
            'ticket_schluessel' => $ticket['schluessel'],
            'service_type' => $serviceType->value,
        ];

        if ($repairData !== null) {
            $details = array_merge($details, $this->mapRepairDataToColumns($repairData));
        }

        $this->detailsGateway->create($details);
        return true;
    }

    private function mapRepairDataToColumns(array $data): array
    {
        $mapped = [];

        // Direct mappings
        $directFields = [
            'request_number' => 'wp_request_number',
            'service_type' => 'service_type',
            'service_delivery_type' => 'service_delivery_type',
        ];
        foreach ($directFields as $source => $target) {
            if (isset($data[$source]) && $data[$source] !== null) {
                $mapped[$target] = (string)$data[$source];
            }
        }

        // Customer data
        if (isset($data['customer']) && is_array($data['customer'])) {
            $customer = $data['customer'];
            if (isset($customer['company'])) {
                $mapped['company_name'] = (string)$customer['company'];
                $mapped['customer_type'] = 'business';
            } else {
                $mapped['customer_type'] = 'private';
            }
            if (isset($customer['vat_id'])) {
                $mapped['vat_id'] = (string)$customer['vat_id'];
            }
        }

        // Device data
        if (isset($data['device']) && is_array($data['device'])) {
            $device = $data['device'];
            $deviceFields = [
                'manufacturer' => 'manufacturer',
                'model' => 'model',
                'serial_number' => 'serial_number',
                'mods_present' => 'mods_present',
                'mods_text' => 'mods_text',
            ];
            foreach ($deviceFields as $source => $target) {
                if (isset($device[$source])) {
                    $mapped[$target] = $source === 'mods_present'
                        ? ($device[$source] ? 1 : 0)
                        : (string)$device[$source];
                }
            }
        }

        // Service details
        if (isset($data['service_details']) && is_array($data['service_details'])) {
            $details = $data['service_details'];
            $serviceFields = [
                'issue_category' => 'issue_category',
                'issue_description' => 'issue_description',
                'warranty_status' => 'warranty_status',
                'cost_limit' => 'cost_limit',
                'is_express' => 'is_express',
                'express_price' => 'express_price',
                'wartung_paket' => 'wartung_paket',
                'wartung_notes' => 'wartung_notes',
                'has_original_part' => 'has_original_part',
                'has_templates' => 'has_templates',
                're_tolerance' => 're_tolerance',
                're_output_format' => 're_output_format',
                'has_3d_file' => 'has_3d_file',
                'material_preference' => 'material_preference',
                'color_preference' => 'color_preference',
                'functional_requirements' => 'functional_requirements',
                'travel_distance_km' => 'travel_distance_km',
                'travel_fee' => 'travel_fee',
            ];
            foreach ($serviceFields as $source => $target) {
                if (isset($details[$source]) && $details[$source] !== null) {
                    $booleanFields = ['is_express', 'mods_present'];
                    if (in_array($source, $booleanFields, true)) {
                        $mapped[$target] = $details[$source] ? 1 : 0;
                    } else {
                        $mapped[$target] = (string)$details[$source];
                    }
                }
            }
        }

        return $mapped;
    }
}
