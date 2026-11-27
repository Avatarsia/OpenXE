<?php
declare(strict_types=1);
namespace Tests\Unit\Modules\RepairIntegration\Service;
use PHPUnit\Framework\TestCase;
use Xentral\Modules\RepairIntegration\Service\RepairReconcileService;
final class RepairReconcileServiceTest extends TestCase
{
    public function testImagesAreComparedByContentWithinOneRequest():void
    {
        $a=['origin_id'=>'local','sha256'=>str_repeat('a',64)];
        $b=['origin_id'=>'remote','sha256'=>str_repeat('a',64)];
        $c=['origin_id'=>'missing','sha256'=>str_repeat('b',64)];
        $delta=RepairReconcileService::imageDelta([$a],[$b,$c]);
        self::assertSame([], $delta['to_remote']);
        self::assertSame([$c], $delta['to_local']);
    }
    public function testImageComparisonNeverTruncatesAtTwenty():void
    {
        $images=[];
        for($i=0;$i<27;$i++) $images[]=['origin_id'=>(string)$i,'sha256'=>hash('sha256',(string)$i)];
        self::assertCount(27,RepairReconcileService::imageDelta([],$images)['to_local']);
    }
    public function testSameOriginWithDifferentBytesIsAConflict():void
    {
        $this->expectException(\InvalidArgumentException::class);
        RepairReconcileService::imageDelta([['origin_id'=>'same','sha256'=>str_repeat('a',64)]],[['origin_id'=>'same','sha256'=>str_repeat('b',64)]]);
    }
}
