<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Exception;

final class SyncFailedException extends RepairIntegrationException
{
    public function __construct(
        string $message,
        public readonly int $httpCode = 0,
        public readonly string $responseBody = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
