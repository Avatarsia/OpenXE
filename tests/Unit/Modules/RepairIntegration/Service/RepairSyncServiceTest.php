<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\RepairIntegration\Service;

use PHPUnit\Framework\TestCase;
use Xentral\Modules\RepairIntegration\Service\RepairSyncService;

final class RepairSyncServiceTest extends TestCase
{
    public function testInfersRequestNumberFromUniqueTwelveDigitTicketKey(): void
    {
        self::assertSame('202609080001', RepairSyncService::inferWpRequestNumber('202609080001', 1));
    }

    public function testDoesNotInferRequestNumberFromAmbiguousOrInvalidTicketKey(): void
    {
        self::assertNull(RepairSyncService::inferWpRequestNumber('202609080001', 2));
        self::assertNull(RepairSyncService::inferWpRequestNumber('4711', 1));
        self::assertNull(RepairSyncService::inferWpRequestNumber('20260908ABCD', 1));
    }
}
