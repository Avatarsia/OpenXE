<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\RepairIntegration;

use PHPUnit\Framework\TestCase;
use Xentral\Components\Database\Adapter\AdapterInterface;
use Xentral\Components\Database\Database;
use Xentral\Components\Database\SqlQuery\QueryFactory;
use Xentral\Components\Http\Session\SessionHandler;
use Xentral\Modules\RepairIntegration\Service\RepairConfigService;

require_once __DIR__ . '/../../../../www/pages/repairintegration.php';
require_once __DIR__ . '/../../../../www/pages/ticket.php';
require_once __DIR__ . '/../../../../www/pages/ticket_custom.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class RepairFormSessionTest extends TestCase
{
    private function app(): \stdClass
    {
        session_start();
        $_SESSION = ['existing_login' => 'keep'];
        session_write_close();
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('fetchValue')->willReturn(false);
        $db = new Database($adapter, new QueryFactory('mysql'));
        $services = ['Session' => SessionHandler::createSession(), 'Database' => $db, 'RepairConfigService' => new RepairConfigService($db)];
        $app = new \stdClass();
        $app->Container = new class($services) {
            public function __construct(private array $services) {}
            public function get($key) { return $this->services[$key]; }
        };
        $app->Tpl = new class {
            public array $values = [];
            public function Set($key, $value): void { $this->values[$key] = $value; }
        };
        $app->Secure = new class { public function GetPOST(...$args) { return ''; } };
        $app->YUI = new class { public function AutoComplete(...$args): void {} };
        return $app;
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    }

    public function testTicketFormTokenSurvivesTheNextRequest(): void
    {
        $app = $this->app();
        $page = new \TicketCustom($app, true);
        $method = new \ReflectionMethod($page, 'repair_render_service_fields');
        $method->setAccessible(true);
        $method->invoke($page, ['service_type' => 'reparatur'], 42);
        $nextRequest = SessionHandler::createSession();
        self::assertTrue($nextRequest->isCsrfTokenValid('repair_beleg', $app->Tpl->values['REPAIR_CSRF']));
        self::assertSame('keep', $_SESSION['existing_login']);
        self::assertStringContainsString('Steckverbindungen', $app->Tpl->values['REPAIR_PUBLIC_DIAGNOSIS']);
        self::assertStringContainsString('Testdruck', $app->Tpl->values['REPAIR_FINAL_CHECK']);
    }

    public function testSettingsFormTokenSurvivesTheNextRequest(): void
    {
        $app = $this->app();
        $page = new \Repairintegration($app, true);
        $method = new \ReflectionMethod($page, 'renderPositionSettings');
        $method->setAccessible(true);
        $method->invoke($page, $app->Container->get('RepairConfigService'));
        $nextRequest = SessionHandler::createSession();
        self::assertTrue($nextRequest->isCsrfTokenValid('repair_settings', $app->Tpl->values['REPAIR_SETTINGS_CSRF']));
        self::assertSame('keep', $_SESSION['existing_login']);
    }
}
