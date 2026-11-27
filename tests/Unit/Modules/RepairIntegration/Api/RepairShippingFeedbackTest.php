<?php
declare(strict_types=1);
namespace Tests\Unit\Modules\RepairIntegration\Api;
use PHPUnit\Framework\TestCase;
use Xentral\Modules\RepairIntegration\Api\RepairApiController;
use Xentral\Modules\RepairIntegration\Service\RepairShippingFeedback;
final class RepairShippingFeedbackTest extends TestCase
{
    private function block(): array
    {
        return ['choice'=>'shipped','choice_label'=>'Bereits versendet','responded_at'=>'2026-11-09T12:58:56+01:00','tracking_number'=>'00340434123456789','carrier'=>'dhl','carrier_label'=>'DHL'];
    }
    private function map(array $service): array
    {
        $class=new \ReflectionClass(RepairApiController::class);
        return $class->getMethod('mapInboundData')->invoke($class->newInstanceWithoutConstructor(),['service_details'=>$service]);
    }
    public function testValidBlockAndTrackingBoundariesAreMapped(): void
    {
        foreach (['00340434123456789',str_repeat('A',64),'AB 123-XYZ',''] as $tracking) {
            foreach (['dhl','dpd','hermes','gls','ups','post','other'] as $carrier) {
                $block=$this->block();$block['tracking_number']=$tracking;$block['carrier']=$carrier;
                self::assertSame($block,json_decode($this->map(['shipping_feedback'=>$block])['shipping_feedback'],true));
            }
        }
    }
    public function testShippedWithoutOptionalTrackingAndCarrierIsMapped(): void
    {
        $block=$this->block();$block['tracking_number']=null;$block['carrier']=null;$block['carrier_label']=null;
        self::assertSame($block,json_decode($this->map(['shipping_feedback'=>$block])['shipping_feedback'],true));
        $block=$this->block();$block['tracking_number']=null;
        self::assertSame($block,json_decode($this->map(['shipping_feedback'=>$block])['shipping_feedback'],true));
        $block=$this->block();$block['carrier']=null;$block['carrier_label']=null;
        self::assertSame($block,json_decode($this->map(['shipping_feedback'=>$block])['shipping_feedback'],true));
        $block=$this->block();$block['carrier']=null;
        self::assertArrayNotHasKey('shipping_feedback',$this->map(['shipping_feedback'=>$block]));
    }
    public function testOtherChoicesHaveNullShippingFieldsAndNullableDate(): void
    {
        foreach (['later','cancelled'] as $choice) {
            $block=$this->block();$block['choice']=$choice;$block['choice_label']=$choice === 'later' ? 'Versendet später' : 'Versendet nicht mehr';$block['responded_at']=null;
            foreach (['tracking_number','carrier','carrier_label'] as $field) $block[$field]=null;
            self::assertSame($block,json_decode($this->map(['shipping_feedback'=>$block])['shipping_feedback'],true));
        }
    }
    public function testRfc3339FractionsAndUnknownFieldsAreNormalized(): void
    {
        foreach (['2026-11-09T12:58:56.123456Z','2026-11-09T12:58:56+01:00'] as $date) {
            $block=$this->block();$block['responded_at']=$date;$expected=$block;$block['extra']='discard';
            self::assertSame($expected,json_decode($this->map(['shipping_feedback'=>$block])['shipping_feedback'],true));
        }
    }
    public function testNullClearsAndMissingKeyKeepsExistingData(): void
    {
        self::assertArrayHasKey('shipping_feedback',$this->map(['shipping_feedback'=>null]));
        self::assertNull($this->map(['shipping_feedback'=>null])['shipping_feedback']);
        self::assertArrayNotHasKey('shipping_feedback',$this->map([]));
    }
    public function testInvalidBlocksAreIgnoredWithoutException(): void
    {
        foreach (['choice'=>['unknown',null,1], 'choice_label'=>[str_repeat('a',256),[],"\xff"],
            'tracking_number'=>[str_repeat('A',65),'abc','AB_123',"ABC\n",123],
            'carrier'=>['fedex',[]], 'carrier_label'=>[str_repeat('a',256),[],"\xff",null],
            'responded_at'=>['nonsense','tomorrow','2026-02-30T00:00:00Z','2026-11-09',123,'']] as $field=>$values) {
            foreach ($values as $value) {
                $block=$this->block();$block[$field]=$value;
                self::assertArrayNotHasKey('shipping_feedback',$this->map(['shipping_feedback'=>$block]));
            }
        }
        foreach (array_keys($this->block()) as $field) {
            $block=$this->block();unset($block[$field]);
            self::assertArrayNotHasKey('shipping_feedback',$this->map(['shipping_feedback'=>$block]));
        }
        foreach (['invalid',42,[]] as $value) self::assertArrayNotHasKey('shipping_feedback',$this->map(['shipping_feedback'=>$value]));
        $block=$this->block();$block['choice']='later';
        self::assertArrayNotHasKey('shipping_feedback',$this->map(['shipping_feedback'=>$block]));
    }
    public function testRenderEscapesAllDisplayedFieldsAndHidesTrackingForOtherChoices(): void
    {
        $block=$this->block();
        foreach (['choice_label','responded_at','carrier_label','tracking_number'] as $field) $block[$field]='<script>"&';
        $html=RepairShippingFeedback::render(json_encode($block));
        self::assertStringNotContainsString('<script>',$html);
        self::assertSame(4,substr_count($html,'&lt;script&gt;&quot;&amp;'));
        $block['choice']='later';$html=RepairShippingFeedback::render(json_encode($block));
        self::assertStringNotContainsString('Sendungsnummer',$html);
        self::assertStringNotContainsString('Versanddienstleister',$html);
        self::assertSame('',RepairShippingFeedback::render(null));
    }
}
