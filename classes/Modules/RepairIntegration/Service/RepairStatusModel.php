<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Components\Database\Database;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;

final class RepairStatusModel
{
    public const LEGACY = [
        'in_diagnosis' => 'in_progress', 'approved' => 'in_progress', 'in_repair' => 'in_progress',
        'quote_declined' => 'completed', 'repaired' => 'completed', 'returned' => 'completed',
    ];

    public static function normalize(string $status): string
    {
        return self::LEGACY[$status] ?? $status;
    }

    public static function nativeDecision(string $status): ?string
    {
        if (in_array($status, ['freigegeben', 're_freigabe', 'ind_freigabe'], true)) return 'approved';
        if (in_array($status, ['kv_abgelehnt', 're_abgelehnt', 'ind_abgelehnt'], true)) return 'declined';
        return null;
    }

    public static function map(array $source, ?string $legacyStatus = null): array
    {
        if (!array_key_exists('quote_decision', $source)) {
            return match ($legacyStatus) {
                'approved' => ['quote_decision' => 'approved'],
                'quote_declined' => ['quote_decision' => 'declined'],
                default => [],
            };
        }
        if (!in_array($source['quote_decision'], [null, 'approved', 'declined'], true)) {
            error_log('RepairIntegration: invalid quote_decision ignored');
            return [];
        }
        return ['quote_decision' => $source['quote_decision']];
    }

    public static function apply(Database $db, int $ticketId, array $source, ?string $legacyStatus = null): void
    {
        $mapped = self::map($source, $legacyStatus);
        if ($mapped === []) return;
        $gateway = new RepairDetailsGateway($db);
        $details = $gateway->getByTicketId($ticketId);
        if ($details !== null) $gateway->update((int)$details['id'], $mapped);
    }

    public static function render(?string $decision): string
    {
        return match ($decision) {
            'approved' => '<p><strong>KVA-Entscheidung:</strong> Freigegeben</p>',
            'declined' => '<p><strong>KVA-Entscheidung:</strong> Abgelehnt</p>',
            default => '',
        };
    }
}
