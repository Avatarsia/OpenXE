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

    public function testHttpWhenHttpsMissing(): void
    {
        $url = RepairConnectionInfo::endpointUrl(['HTTP_HOST' => '192.168.0.150']);
        self::assertSame('http://192.168.0.150/repairapi/index.php/repair-status', $url);
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
}
