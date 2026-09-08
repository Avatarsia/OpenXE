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

    public function testNormalizeCustomerQuoteAmountAcceptsNumbersAndStrings(): void
    {
        self::assertSame('149.90', RepairApiController::normalizeCustomerQuoteAmount(149.9));
        self::assertSame('149.90', RepairApiController::normalizeCustomerQuoteAmount('149.90'));
        self::assertSame('0.00', RepairApiController::normalizeCustomerQuoteAmount(0));
        self::assertSame('1234.00', RepairApiController::normalizeCustomerQuoteAmount('1234'));
    }

    public function testNormalizeCustomerQuoteAmountAcceptsGermanNotation(): void
    {
        self::assertSame('1234.56', RepairApiController::normalizeCustomerQuoteAmount('1.234,56'));
        // Deutsche Tausender-Notation ohne Nachkommastellen.
        self::assertSame('1234.00', RepairApiController::normalizeCustomerQuoteAmount('1.234'));
    }

    public function testNormalizeCustomerQuoteAmountReturnsNullForInvalidValues(): void
    {
        self::assertNull(RepairApiController::normalizeCustomerQuoteAmount(null));
        self::assertNull(RepairApiController::normalizeCustomerQuoteAmount(''));
        self::assertNull(RepairApiController::normalizeCustomerQuoteAmount('   '));
        self::assertNull(RepairApiController::normalizeCustomerQuoteAmount('abc'));
        self::assertNull(RepairApiController::normalizeCustomerQuoteAmount(['149.90']));
        self::assertNull(RepairApiController::normalizeCustomerQuoteAmount(true));
    }

    public function testNormalizeCustomerQuoteAmountRejectsNegativeAndOverflow(): void
    {
        self::assertNull(RepairApiController::normalizeCustomerQuoteAmount('-1'));
        // Zielfeld decimal(10,2): max 99999999.99.
        self::assertNull(RepairApiController::normalizeCustomerQuoteAmount('100000000'));
        self::assertSame('99999999.99', RepairApiController::normalizeCustomerQuoteAmount('99999999.99'));
    }

    public function testAttachmentMarkerUsesMediaIdWhenPresent(): void
    {
        self::assertSame(
            'WP-REPAIR-MEDIA-ID-123',
            RepairApiController::attachmentMarker('https://example.com/uploads/a.jpg', 123)
        );
        // Ohne URL, aber mit ID: der ID-Marker braucht die URL nicht.
        self::assertSame('WP-REPAIR-MEDIA-ID-1', RepairApiController::attachmentMarker(null, 1));
    }

    public function testAttachmentMarkerFallsBackToLegacyUrlHash(): void
    {
        $url = 'https://example.com/uploads/a.jpg';
        self::assertSame(
            'WP-REPAIR-MEDIA-' . sha1($url),
            RepairApiController::attachmentMarker($url, null)
        );
    }

    public function testAttachmentMarkerTreatsInvalidIdsAsMissing(): void
    {
        $url = 'https://example.com/uploads/a.jpg';
        $legacy = 'WP-REPAIR-MEDIA-' . sha1($url);
        self::assertSame($legacy, RepairApiController::attachmentMarker($url, 0));
        self::assertSame($legacy, RepairApiController::attachmentMarker($url, -5));
    }

    public function testNormalizeMediaIdAcceptsPositiveIntsAndDigitStrings(): void
    {
        self::assertSame(123, RepairApiController::normalizeMediaId(123));
        self::assertSame(123, RepairApiController::normalizeMediaId('123'));
    }

    public function testNormalizeMediaIdReturnsNullForInvalidValues(): void
    {
        self::assertNull(RepairApiController::normalizeMediaId(null));
        self::assertNull(RepairApiController::normalizeMediaId(0));
        self::assertNull(RepairApiController::normalizeMediaId(-5));
        self::assertNull(RepairApiController::normalizeMediaId('0'));
        self::assertNull(RepairApiController::normalizeMediaId('abc'));
        self::assertNull(RepairApiController::normalizeMediaId('12a'));
        self::assertNull(RepairApiController::normalizeMediaId(12.5));
        self::assertNull(RepairApiController::normalizeMediaId(true));
        self::assertNull(RepairApiController::normalizeMediaId([123]));
    }
}
