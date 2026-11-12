<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\RepairIntegration\Enum;

use PHPUnit\Framework\TestCase;
use Xentral\Modules\RepairIntegration\Enum\SyncQueueStatus;

class SyncQueueStatusTest extends TestCase
{
    public function testOnlyFailedIsRetryable(): void
    {
        self::assertTrue(SyncQueueStatus::Failed->isRetryable());
        self::assertFalse(SyncQueueStatus::Pending->isRetryable());
        self::assertFalse(SyncQueueStatus::Processing->isRetryable());
        self::assertFalse(SyncQueueStatus::Completed->isRetryable());
        self::assertFalse(SyncQueueStatus::PermanentlyFailed->isRetryable());
    }

    public function testFinalStates(): void
    {
        self::assertTrue(SyncQueueStatus::Completed->isFinal());
        self::assertTrue(SyncQueueStatus::PermanentlyFailed->isFinal());
        self::assertFalse(SyncQueueStatus::Pending->isFinal());
        self::assertFalse(SyncQueueStatus::Processing->isFinal());
        self::assertFalse(SyncQueueStatus::Failed->isFinal());
    }

    public function testAllCasesHaveStringValue(): void
    {
        foreach (SyncQueueStatus::cases() as $status) {
            self::assertNotEmpty($status->value);
            self::assertSame($status, SyncQueueStatus::from($status->value));
        }
    }
}
