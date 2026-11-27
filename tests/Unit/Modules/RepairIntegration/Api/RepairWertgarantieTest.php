<?php
declare(strict_types=1);
namespace Tests\Unit\Modules\RepairIntegration\Api;
use PHPUnit\Framework\TestCase;
use Xentral\Modules\RepairIntegration\Api\RepairApiController;
use Xentral\Modules\RepairIntegration\Service\RepairWertgarantie;
final class RepairWertgarantieTest extends TestCase
{
    private function block(): array
    {
        return ['contract_number'=>'66326566','status'=>'verified','status_label'=>'Verifiziert','checked_at'=>'2026-11-06T14:37:06+01:00','live'=>true];
    }
    private function map(array $service): array
    {
        $class=new \ReflectionClass(RepairApiController::class);
        return $class->getMethod('mapInboundData')->invoke($class->newInstanceWithoutConstructor(),['service_details'=>$service]);
    }
    public function testBlockIsMappedAndUnknownStatusIsRetained(): void
    {
        $block=$this->block();$block['status']='future_status';$block['status_label']='Neuer Status';
        self::assertSame($block,json_decode($this->map(['wertgarantie'=>$block])['wertgarantie'],true));
    }
    public function testNullClearsAndMissingKeyKeepsExistingData(): void
    {
        self::assertArrayHasKey('wertgarantie',$this->map(['wertgarantie'=>null]));
        self::assertNull($this->map(['wertgarantie'=>null])['wertgarantie']);
        self::assertArrayNotHasKey('wertgarantie',$this->map([]));
    }
    public function testInvalidBlocksAreIgnoredWithoutException(): void
    {
        foreach (['contract_number'=>['abc',str_repeat('1',65),123], 'status'=>['BAD',str_repeat('a',33)],
            'status_label'=>[str_repeat('a',256),[]], 'checked_at'=>['nonsense','2026-02-30T00:00:00Z'], 'live'=>['true',1]] as $field=>$values) {
            foreach ($values as $value) {
                $block=$this->block();$block[$field]=$value;
                self::assertArrayNotHasKey('wertgarantie',$this->map(['wertgarantie'=>$block]));
            }
        }
    }
    public function testBadgeEscapesLabelAndOnlyRecognizesTwoStatuses(): void
    {
        foreach (['verified','verified_pending','active_external','future_status'] as $status) {
            $block=$this->block();$block['status']=$status;$block['status_label']='<script>alert(1)</script>';
            $html=RepairWertgarantie::render(json_encode($block));
            self::assertStringContainsString('Wertgarantie-Reparatur',$html);
            self::assertStringContainsString('66326566',$html);
            self::assertStringNotContainsString('<script>',$html);
            self::assertSame(in_array($status,['verified','verified_pending'],true),strpos($html,'repair-wg-recognized')!==false);
        }
    }
}
