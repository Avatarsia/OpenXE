<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\RepairIntegration\Service;

use PHPUnit\Framework\TestCase;
use Xentral\Modules\RepairIntegration\Service\RepairStatusModel;
use Xentral\Modules\RepairIntegration\Api\RepairApiController;

final class RepairStatusModelTest extends TestCase
{
    public function testAllLegacyKeysNormalizeWithoutLosingDecision(): void
    {
        foreach (RepairStatusModel::LEGACY as $old => $new) {
            self::assertSame($new, RepairApiController::normalizeWpStatus($old));
            self::assertSame($new, RepairStatusModel::normalize($old));
        }
        self::assertSame(['quote_decision'=>'approved'], RepairStatusModel::map([], 'approved'));
        self::assertSame(['quote_decision'=>'declined'], RepairStatusModel::map([], 'quote_declined'));
        self::assertSame([], RepairStatusModel::map([], 'in_repair'));
    }

    public function testDecisionMissingNullExplicitAndInvalid(): void
    {
        self::assertSame([], RepairStatusModel::map([]));
        foreach ([null, 'approved', 'declined'] as $decision) {
            self::assertSame(['quote_decision'=>$decision], RepairStatusModel::map(['quote_decision'=>$decision], 'approved'));
        }
        foreach (['invalid', '', [], true, 1] as $decision) {
            self::assertSame([], RepairStatusModel::map(['quote_decision'=>$decision], 'approved'));
        }
        self::assertStringContainsString('Freigegeben', RepairStatusModel::render('approved'));
        self::assertStringContainsString('Abgelehnt', RepairStatusModel::render('declined'));
        self::assertSame('', RepairStatusModel::render('<script>'));
    }

    public function testNativeDecisionsAreOnlyEmittedForDecisionStatuses(): void
    {
        foreach (['freigegeben','re_freigabe','ind_freigabe'] as $slug) self::assertSame('approved', RepairStatusModel::nativeDecision($slug));
        foreach (['kv_abgelehnt','re_abgelehnt','ind_abgelehnt'] as $slug) self::assertSame('declined', RepairStatusModel::nativeDecision($slug));
        foreach (['in_reparatur','repariert','neu','eingegangen','storniert'] as $slug) self::assertNull(RepairStatusModel::nativeDecision($slug));
    }
}
