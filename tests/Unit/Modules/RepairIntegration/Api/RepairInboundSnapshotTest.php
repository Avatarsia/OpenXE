<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\RepairIntegration\Api;

use PHPUnit\Framework\TestCase;
use Xentral\Modules\RepairIntegration\Api\RepairApiController;

final class RepairInboundSnapshotTest extends TestCase
{
    private function map(array $data): array
    {
        $class = new \ReflectionClass(RepairApiController::class);
        $method = $class->getMethod('mapInboundData');
        $method->setAccessible(true);
        return $method->invoke($class->newInstanceWithoutConstructor(), $data);
    }

    public function testOriginalPricesAndMaintenanceNotesAreMapped(): void
    {
        $mapped = $this->map(['form_id' => 3, 'service_details' => ['diagnosis_cost_snapshot' => '49.00',
            'wartung_paket_price_snapshot' => '129.00', 'travel_fee_per_km_snapshot' => '0.50', 'wartung_notes' => "O'Brien: Y-Achse"]]);
        self::assertSame('49.00', $mapped['diagnosis_cost_snapshot']);
        self::assertSame('129.00', $mapped['wartung_paket_price_snapshot']);
        self::assertSame('0.50', $mapped['travel_fee_per_km_snapshot']);
        self::assertArrayNotHasKey('form_id', $mapped);
        self::assertSame("O'Brien: Y-Achse", $mapped['wartung_notes']);
    }

    public function testFormSlugsAreNotInterpretedAsNumericMetadata(): void
    {
        foreach (['default', '3d-reparatur', 3] as $formId) {
            self::assertArrayNotHasKey('form_id', $this->map(['form_id' => $formId]));
        }
    }

    public function testOldPayloadDoesNotClearSnapshots(): void
    {
        $mapped = $this->map(['service_details' => ['diagnosis_cost_snapshot' => null]]);
        self::assertArrayNotHasKey('diagnosis_cost_snapshot', $mapped);
        self::assertArrayNotHasKey('form_id', $mapped);
    }

    public function testInvalidSnapshotsAreIgnoredWhileZeroIsPreserved(): void
    {
        $mapped = $this->map(['service_details' => ['diagnosis_cost_snapshot' => 'abc', 'wartung_paket_price_snapshot' => 0]]);
        self::assertArrayNotHasKey('diagnosis_cost_snapshot', $mapped);
        self::assertSame('0.00', $mapped['wartung_paket_price_snapshot']);
    }

    public function testWordPressCannotOverwriteStaffTextOrPositionPrice(): void
    {
        $mapped = $this->map(['service_details' => ['public_diagnosis' => 'external', 'position_net_amount' => '1.00']]);
        self::assertArrayNotHasKey('public_diagnosis', $mapped);
        self::assertArrayNotHasKey('position_net_amount', $mapped);
    }
}
