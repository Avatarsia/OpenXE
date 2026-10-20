<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\RepairIntegration\Service;

use PHPUnit\Framework\TestCase;
use Xentral\Components\Database\Adapter\AdapterInterface;
use Xentral\Components\Database\Database;
use Xentral\Components\Database\SqlQuery\QueryFactory;
use Xentral\Modules\RepairIntegration\Gateway\RepairBelegGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;
use Xentral\Modules\RepairIntegration\Service\RepairBelegService;
use Xentral\Modules\RepairIntegration\Service\RepairConfigService;

final class RepairBelegPreparationTest extends TestCase
{
    private function service(array $details = [], array $config = [], bool $articleExists = true): RepairBelegService
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('fetchRow')->willReturnCallback(static function ($sql) use ($details, $articleExists) {
            if (strpos($sql, 'ticket_repair_details') !== false) {
                return array_merge(['service_type' => 'reparatur', 'model' => 'X1C', 'quote_amount' => '500.00'], $details);
            }
            if (strpos($sql, 'artikel') !== false) {
                return $articleExists ? ['id' => 17, 'nummer' => '100003', 'name_de' => "Reparatur O'Brien", 'geloescht' => 0] : [];
            }
            return ['id' => 42, 'schluessel' => 'T42', 'adresse' => 3, 'betreff' => 'Repair'];
        });
        $adapter->method('fetchValue')->willReturnCallback(static function ($sql, $params = []) use ($config) {
            return $config[$params['key'] ?? ''] ?? (strpos($params['key'] ?? '', 'position_template_') === 0 ? 'Geraet: {modell}' : false);
        });
        $db = new Database($adapter, new QueryFactory('mysql'));
        return new RepairBelegService($db, new RepairDetailsGateway($db), new RepairBelegGateway($db), new RepairConfigService($db));
    }

    public function testConfiguredArticleIsPreparedWithResolvedText(): void
    {
        $result = $this->service([], ['service_article_number' => '100003'])->prepareBelegCreation(42, 'angebot');
        self::assertSame(17, $result['position']['article_id']);
        self::assertSame("Reparatur O'Brien", $result['position']['name']);
        self::assertSame('Geraet: X1C', $result['position']['description']);
        self::assertNull($result['position']['net_amount']);
    }

    public function testOfferTotalIsNeverReusedAsRepairPositionPrice(): void
    {
        $result = $this->service(['quote_amount' => '500.00', 'position_net_amount' => '100.00'], ['service_article_number' => '100003'])->prepareBelegCreation(42, 'angebot');
        self::assertSame('100.00', $result['position']['net_amount']);
    }

    public function testMissingArticleConfigurationFailsBeforeCreatingAnyDocument(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Artikel');
        $this->service()->prepareBelegCreation(42, 'angebot');
    }

    public function testDeletedOrMissingArticleIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service([], ['service_article_number' => '100003'], false)->prepareBelegCreation(42, 'angebot');
    }

    public function testFeeOnlyUsesOriginalGrossFeeAndIgnoresRepairPrice(): void
    {
        $result = $this->service(['diagnosis_cost_snapshot' => '49.00', 'position_net_amount' => '100.00'], ['service_article_number' => '100003'])->prepareBelegCreation(42, 'rechnung', 'fee');
        self::assertSame('49.00', $result['position']['gross_amount']);
        self::assertNull($result['position']['net_amount']);
    }

    public function testMissingOriginalFeeNeverBecomesZero(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Formular');
        $this->service([], ['service_article_number' => '100003'])->prepareBelegCreation(42, 'rechnung', 'fee');
    }

    public function testFeeOnlyIsNotAllowedForMaintenance(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service(['service_type' => 'wartung', 'diagnosis_cost_snapshot' => '49.00'], ['service_article_number' => '100003'])->prepareBelegCreation(42, 'rechnung', 'fee');
    }
}
