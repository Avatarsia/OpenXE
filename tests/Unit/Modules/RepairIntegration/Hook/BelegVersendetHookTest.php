<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\RepairIntegration\Hook;

use PHPUnit\Framework\TestCase;
use Xentral\Modules\RepairIntegration\Hook\BelegVersendetHook;
use Xentral\Modules\RepairIntegration\Service\RepairSyncService;

class BelegVersendetHookTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function row(string $slug, string $category, int $sortOrder, int $terminal = 0): array
    {
        return ['slug' => $slug, 'category' => $category, 'sort_order' => $sortOrder, 'is_terminal' => $terminal];
    }

    public function testUnknownCurrentStatusAdvances(): void
    {
        self::assertTrue(BelegVersendetHook::shouldAdvance(null, self::row('kv_gesendet', 'repair', 110)));
    }

    public function testSameSlugDoesNotAdvance(): void
    {
        $kv = self::row('kv_gesendet', 'repair', 110);
        self::assertFalse(BelegVersendetHook::shouldAdvance($kv, $kv));
    }

    public function testTerminalStatusNeverAdvances(): void
    {
        $closed = self::row('abgeschlossen', 'general', 900, 1);
        self::assertFalse(BelegVersendetHook::shouldAdvance($closed, self::row('kv_gesendet', 'repair', 110)));
    }

    public function testGeneralStatusAdvancesIntoCategory(): void
    {
        $neu = self::row('neu', 'general', 10);
        self::assertTrue(BelegVersendetHook::shouldAdvance($neu, self::row('kv_gesendet', 'repair', 110)));
    }

    public function testForwardWithinCategoryAdvances(): void
    {
        $diagnose = self::row('in_diagnose', 'repair', 100);
        self::assertTrue(BelegVersendetHook::shouldAdvance($diagnose, self::row('kv_gesendet', 'repair', 110)));
    }

    public function testDeclinedQuoteCanBeApprovedByAuftrag(): void
    {
        $declined = self::row('kv_abgelehnt', 'repair', 115);
        self::assertTrue(BelegVersendetHook::shouldAdvance($declined, self::row('freigegeben', 'repair', 120)));
    }

    public function testNoRegressionFromLaterStatus(): void
    {
        $repariert = self::row('repariert', 'repair', 140);
        self::assertFalse(BelegVersendetHook::shouldAdvance($repariert, self::row('kv_gesendet', 'repair', 110)));
        self::assertFalse(BelegVersendetHook::shouldAdvance($repariert, self::row('freigegeben', 'repair', 120)));
    }

    public function testDifferentCategoryAdvances(): void
    {
        // Ticket steht in einer fremden Kategorie, Ziel folgt dem Service-Typ.
        $wartung = self::row('wartung_geplant', 'maintenance', 200);
        self::assertTrue(BelegVersendetHook::shouldAdvance($wartung, self::row('kv_gesendet', 'repair', 110)));
    }

    public function testBelegTypeMapping(): void
    {
        self::assertSame('quote_sent', BelegVersendetHook::BELEG_WP_STATUS['angebot']);
        self::assertSame('approved', BelegVersendetHook::BELEG_WP_STATUS['auftrag']);
        self::assertSame('repaired', BelegVersendetHook::BELEG_WP_STATUS['rechnung']);
        self::assertArrayNotHasKey('lieferschein', BelegVersendetHook::BELEG_WP_STATUS);
    }

    public function testFormatQuoteAmountFromDecimalString(): void
    {
        // DECIMAL(18,4) kommt vom Treiber als String.
        self::assertSame('149.90', RepairSyncService::formatQuoteAmount('149.9000'));
        self::assertSame('1234.57', RepairSyncService::formatQuoteAmount('1234.5650'));
    }

    public function testFormatQuoteAmountRejectsEmptyZeroAndGarbage(): void
    {
        self::assertNull(RepairSyncService::formatQuoteAmount(null));
        self::assertNull(RepairSyncService::formatQuoteAmount(''));
        self::assertNull(RepairSyncService::formatQuoteAmount('0.0000'));
        self::assertNull(RepairSyncService::formatQuoteAmount('-5'));
        self::assertNull(RepairSyncService::formatQuoteAmount('abc'));
    }
}
