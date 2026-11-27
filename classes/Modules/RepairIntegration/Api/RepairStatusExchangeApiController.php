<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Api;

use Xentral\Modules\RepairIntegration\Service\RepairStatusExchangeService;

/** Authentication, method and body limits are enforced by the shared exchange dispatcher. */
final class RepairStatusExchangeApiController
{
    public function __construct(private readonly RepairStatusExchangeService $exchange) {}

    public function handle(array $event): array
    {
        return $this->exchange->receive($event);
    }
}
