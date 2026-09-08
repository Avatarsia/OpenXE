<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Exception;

final class InvalidStatusTransitionException extends RepairIntegrationException
{
    public static function create(string $from, string $to): self
    {
        return new self(sprintf(
            'Invalid status transition from "%s" to "%s"',
            $from,
            $to,
        ));
    }
}
