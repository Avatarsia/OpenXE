<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Exception;

final class ForbiddenException extends RepairIntegrationException
{
    public static function missingPermission(string $permission): self
    {
        return new self(sprintf('Missing permission: %s', $permission));
    }
}
