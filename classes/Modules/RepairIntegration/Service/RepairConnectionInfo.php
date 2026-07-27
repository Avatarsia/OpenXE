<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

/**
 * Leitet die Verbindungsdaten ab, die das WordPress-Plugin braucht.
 * Pure statische Helper-Klasse ohne Abhaengigkeiten (unit-testbar).
 */
final class RepairConnectionInfo
{
    public const ENDPOINT_PATH = '/repairapi/index.php/repair-status';

    /**
     * Baut die absolute Inbound-Endpoint-URL aus einem $_SERVER-artigen Array.
     */
    public static function endpointUrl(array $server): string
    {
        $https = (string)($server['HTTPS'] ?? '');
        $scheme = ($https !== '' && strtolower($https) !== 'off') ? 'https' : 'http';
        $host = (string)($server['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host . self::ENDPOINT_PATH;
    }
}
