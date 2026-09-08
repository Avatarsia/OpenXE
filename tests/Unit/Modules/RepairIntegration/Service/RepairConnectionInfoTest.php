<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\RepairIntegration\Service;

use PHPUnit\Framework\TestCase;
use Xentral\Modules\RepairIntegration\Service\RepairConnectionInfo;

class RepairConnectionInfoTest extends TestCase
{
    public function testHttpsWhenHttpsOn(): void
    {
        $url = RepairConnectionInfo::endpointUrl(['HTTPS' => 'on', 'HTTP_HOST' => 'erp.example.com']);
        self::assertSame('https://erp.example.com/repairapi/index.php/repair-status', $url);
    }

    public function testHttpWhenHttpsOff(): void
    {
        $url = RepairConnectionInfo::endpointUrl(['HTTPS' => 'off', 'HTTP_HOST' => '192.168.0.150']);
        self::assertSame('http://192.168.0.150/repairapi/index.php/repair-status', $url);
    }

    public function testHttpWhenHttpsIsZero(): void
    {
        // Manche Setups setzen HTTPS='0' statt 'off' - darf nicht als sicher gelten.
        $url = RepairConnectionInfo::endpointUrl(['HTTPS' => '0', 'HTTP_HOST' => '192.168.0.150']);
        self::assertSame('http://192.168.0.150/repairapi/index.php/repair-status', $url);
    }

    public function testHttpWhenHttpsMissing(): void
    {
        $url = RepairConnectionInfo::endpointUrl(['HTTP_HOST' => '192.168.0.150']);
        self::assertSame('http://192.168.0.150/repairapi/index.php/repair-status', $url);
    }

    public function testHttpsWhenForwardedProtoIsHttps(): void
    {
        // Reverse Proxy terminiert TLS - HTTPS ist dann gar nicht gesetzt.
        $url = RepairConnectionInfo::endpointUrl([
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_HOST' => 'erp.example.com',
        ]);
        self::assertSame('https://erp.example.com/repairapi/index.php/repair-status', $url);
    }

    public function testHttpsWhenForwardedSslIsOn(): void
    {
        $url = RepairConnectionInfo::endpointUrl([
            'HTTP_X_FORWARDED_SSL' => 'on',
            'HTTP_HOST' => 'erp.example.com',
        ]);
        self::assertSame('https://erp.example.com/repairapi/index.php/repair-status', $url);
    }

    public function testHostWithPortIsKept(): void
    {
        $url = RepairConnectionInfo::endpointUrl(['HTTPS' => '', 'HTTP_HOST' => 'localhost:8081']);
        self::assertSame('http://localhost:8081/repairapi/index.php/repair-status', $url);
    }

    public function testFallbackHostWhenMissing(): void
    {
        $url = RepairConnectionInfo::endpointUrl([]);
        self::assertSame('http://localhost/repairapi/index.php/repair-status', $url);
    }

    public function testSubDirectoryInstallIsPrefixed(): void
    {
        $url = RepairConnectionInfo::endpointUrl([
            'HTTPS' => 'on',
            'HTTP_HOST' => 'erp.example.com',
            'SCRIPT_NAME' => '/OpenXE/index.php',
        ]);
        self::assertSame('https://erp.example.com/OpenXE/repairapi/index.php/repair-status', $url);
    }

    public function testRootInstallIsNotPrefixed(): void
    {
        $url = RepairConnectionInfo::endpointUrl([
            'HTTP_HOST' => 'erp.example.com',
            'SCRIPT_NAME' => '/index.php',
        ]);
        self::assertSame('http://erp.example.com/repairapi/index.php/repair-status', $url);
    }
}
