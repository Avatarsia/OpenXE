<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\RepairIntegration\Service;

use PHPUnit\Framework\TestCase;
use Xentral\Modules\RepairIntegration\Service\RepairStatusExchangeService;

final class RepairStatusExchangeServiceTest extends TestCase
{
    private function event(string $origin = 'wp', string $time = '2026-10-20T10:00:00.000001Z'): array
    {
        return ['request_number' => '202610200001', 'status' => 'in_repair',
            'event_id' => '00112233-4455-4677-8899-aabbccddeeff', 'origin' => $origin,
            'changed_at_utc' => $time, 'revision' => 0];
    }

    public function testOrderingUsesMicrosecondsBeforeOriginAndIgnoresRevision(): void
    {
        $older = $this->event('openxe');
        $older['revision'] = 999;
        self::assertGreaterThan(0, RepairStatusExchangeService::compareEvents(
            $this->event('wp', '2026-10-20T10:00:00.000002Z'), $older));
        self::assertGreaterThan(0, RepairStatusExchangeService::compareEvents($this->event('openxe'), $this->event()));
    }

    public function testUuidBreaksExactTimeAndOriginTie(): void
    {
        $second = $this->event();
        $second['event_id'] = '10112233-4455-4677-8899-aabbccddeeff';
        self::assertGreaterThan(0, RepairStatusExchangeService::compareEvents($second, $this->event()));
        self::assertSame(0, RepairStatusExchangeService::compareEvents($second, $second));
    }

    public function testEventValidationRejectsNonCanonicalAndImpossibleUtcTime(): void
    {
        foreach (['2026-02-30T10:00:00.000001Z', '2026-10-20T10:00:00Z', 'now'] as $time) {
            try {
                RepairStatusExchangeService::validateEvent($this->event('wp', $time));
                self::fail('Invalid UTC timestamp accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('INVALID_STATUS_EVENT', $e->getMessage());
            }
        }
    }

    public function testCanonicalEventRetainsStableIdentity(): void
    {
        self::assertSame($this->event(), RepairStatusExchangeService::validateEvent($this->event()));
    }

    public function testJsonPropertyOrderDoesNotChangeCanonicalIdentity(): void
    {
        self::assertSame($this->event(), RepairStatusExchangeService::validateEvent(array_reverse($this->event(), true)));
    }
}
