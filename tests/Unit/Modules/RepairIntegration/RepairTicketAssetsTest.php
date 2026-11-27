<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\RepairIntegration;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../phpwf/plugins/class.modulescriptcache.php';
require_once __DIR__ . '/../../../../www/pages/ticket.php';
require_once __DIR__ . '/../../../../www/pages/ticket_custom.php';

final class RepairTicketAssetsTest extends TestCase
{
    public function testNativeTicketRegistersRepairAssetsBeforeCoreIncludesBaseClass(): void
    {
        $cacheDir = sys_get_temp_dir() . '/repair-assets-' . bin2hex(random_bytes(8));
        mkdir($cacheDir);
        try {
            $cache = new RepairAssetCache($cacheDir);
            $app = new class($cache) {
                public function __construct(public \ModuleScriptCache $ModuleScriptCache) {}
                public function ActionHandlerInit(...$args): void {}
                public function ActionHandler(...$args): void {}
                public function DefaultActionHandler(...$args): void {}
                public function ActionHandlerListen(...$args): void {}
            };
            new \TicketCustom($app);
            $cache->IncludeModule('Ticket');
            $javascript = $cache->GetJavascriptHtmlTags();
            $stylesheet = $cache->GetStylesheetHtmlTags();
            self::assertStringContainsString('<script ', $javascript);
            self::assertStringContainsString('<link ', $stylesheet);
            self::assertCount(2, glob($cacheDir . '/*'));
        } finally {
            foreach (glob($cacheDir . '/*') as $file) unlink($file);
            rmdir($cacheDir);
        }
    }
}

final class RepairAssetCache extends \ModuleScriptCache
{
    public function __construct(string $cacheDir)
    {
        $this->baseDir = dirname(__DIR__, 4);
        $this->absoluteCacheDir = $cacheDir;
        $this->relativeCacheDir = './cache';
    }
}
