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
     *
     * Scheme-Erkennung folgt der Konvention aus www/lib/class.location.php:
     * nur HTTPS='on' bzw. die Reverse-Proxy-Header gelten als sicher.
     * Der Pfad wird um das Installationsverzeichnis ergaenzt, damit
     * Unterverzeichnis-Installationen die korrekte URL liefern.
     */
    public static function endpointUrl(array $server): string
    {
        $isSecure = ($server['HTTPS'] ?? '') === 'on'
            || ($server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || ($server['HTTP_X_FORWARDED_SSL'] ?? '') === 'on';
        $scheme = $isSecure ? 'https' : 'http';
        $host = (string)($server['HTTP_HOST'] ?? 'localhost');
        $base = rtrim(str_replace('/index.php', '', (string)($server['SCRIPT_NAME'] ?? '')), '/');
        return $scheme . '://' . $host . $base . self::ENDPOINT_PATH;
    }
}
