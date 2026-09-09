<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Enum;

enum SyncQueueStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case PermanentlyFailed = 'permanently_failed';

    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }

    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::PermanentlyFailed;
    }
}
