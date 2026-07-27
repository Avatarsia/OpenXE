<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\RepairIntegration\Api;

use PHPUnit\Framework\TestCase;
use Xentral\Modules\RepairIntegration\Api\RepairApiController;

class RepairApiControllerTest extends TestCase
{
    public function testNormalizeWpStatusAcceptsKnownSlugs(): void
    {
        $slugs = [
            'new', 'in_diagnosis', 'quote_sent', 'quote_declined', 'approved',
            'in_repair', 'repaired', 'returned', 'closed',
        ];
        foreach ($slugs as $slug) {
            self::assertSame($slug, RepairApiController::normalizeWpStatus($slug));
        }
    }

    public function testNormalizeWpStatusTrimsAndLowercases(): void
    {
        self::assertSame('in_repair', RepairApiController::normalizeWpStatus('  IN_REPAIR '));
        self::assertSame('closed', RepairApiController::normalizeWpStatus("Closed\n"));
    }

    public function testNormalizeWpStatusReturnsNullForNonStrings(): void
    {
        self::assertNull(RepairApiController::normalizeWpStatus(null));
        self::assertNull(RepairApiController::normalizeWpStatus(42));
        self::assertNull(RepairApiController::normalizeWpStatus(['new']));
        self::assertNull(RepairApiController::normalizeWpStatus(true));
    }

    public function testNormalizeWpStatusReturnsNullForEmptyValue(): void
    {
        self::assertNull(RepairApiController::normalizeWpStatus(''));
        self::assertNull(RepairApiController::normalizeWpStatus('   '));
    }

    public function testNormalizeWpStatusRejectsUnexpectedCharacters(): void
    {
        self::assertNull(RepairApiController::normalizeWpStatus('in repair'));
        self::assertNull(RepairApiController::normalizeWpStatus('in-repair'));
        self::assertNull(RepairApiController::normalizeWpStatus("new'; DROP TABLE ticket--"));
    }

    public function testNormalizeWpStatusRejectsOverlongValue(): void
    {
        self::assertNull(RepairApiController::normalizeWpStatus(str_repeat('a', 31)));
        self::assertSame(str_repeat('a', 30), RepairApiController::normalizeWpStatus(str_repeat('a', 30)));
    }

    public function testUnknownButWellFormedStatusIsKeptForMappingLookup(): void
    {
        // Unbekannte Slugs sind kein Fehler: sie werden normalisiert und erst
        // beim Gateway-Lookup verworfen (Fallback auf 'neu').
        self::assertSame('some_future_status', RepairApiController::normalizeWpStatus('some_future_status'));
    }
}
