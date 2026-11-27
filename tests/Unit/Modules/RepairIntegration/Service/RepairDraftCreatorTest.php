<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\RepairIntegration\Service;

use PHPUnit\Framework\TestCase;
use Xentral\Modules\RepairIntegration\Service\RepairDraftCreator;

final class RepairDraftCreatorTest extends TestCase
{
    private function prepared(string $type = 'angebot'): array
    {
        return ['ticket_id' => 42, 'ticket_schluessel' => 'T42', 'adresse_id' => 3, 'beleg_typ' => $type,
            'betreff' => "Reparatur O'Brien", 'position' => ['article_id' => 17, 'name' => "Service O'Brien",
                'description' => "Kundenfehler <script>\nZweite Zeile", 'net_amount' => null, 'gross_amount' => null]];
    }

    private function app(): \stdClass
    {
        $app = new \stdClass();
        $app->DB = new DraftLegacyBoundary();
        $app->erp = new DraftErpBoundary($app->DB);
        return $app;
    }

    public function testNativeOfferPositionIsCreatedAndLinkedOnOneConnection(): void
    {
        $app = $this->app();
        self::assertSame(13, RepairDraftCreator::create($app, $this->prepared(), "User O'Brien"));
        self::assertSame(['CreateAngebot', 'LoadAngebotStandardwerte', 'AddPositionManuell', 'AngebotNeuberechnen'], $app->erp->calls);
        self::assertStringContainsString('repair_ticket_beleg', implode("\n", $app->DB->queries));
        self::assertSame('START TRANSACTION', $app->DB->queries[0]);
        self::assertSame('COMMIT', end($app->DB->queries));
        self::assertSame(1, $app->erp->positionArguments[3]);
        self::assertSame(17, $app->erp->positionArguments[2]);
    }

    public function testDefaultPriceIsNativeArticlePriceNotTicketTotal(): void
    {
        $app = $this->app();
        RepairDraftCreator::create($app, $this->prepared(), 'User');
        self::assertSame('23.50', $app->DB->price);
        self::assertStringNotContainsString('SET preis', implode("\n", $app->DB->queries));
    }

    public function testCustomNetPriceIsAppliedOnlyToNewPosition(): void
    {
        $app = $this->app();
        $prepared = $this->prepared(); $prepared['position']['net_amount'] = '0.00';
        RepairDraftCreator::create($app, $prepared, 'User');
        self::assertSame('0.00', $app->DB->price);
        self::assertStringContainsString('id = 500', implode("\n", $app->DB->queries));
    }

    public function testDescriptionIsHtmlEscapedAndSqlEscapedExactlyOnce(): void
    {
        $app = $this->app();
        RepairDraftCreator::create($app, $this->prepared(), 'User');
        self::assertSame("Service O\\'Brien", $app->erp->positionArguments[4]);
        self::assertSame("Kundenfehler &lt;script&gt;<br>Zweite Zeile", $app->erp->positionArguments[5]);
    }

    public function testFeeIsConvertedWithRealDocumentTaxRate(): void
    {
        $app = $this->app(); $app->erp->rate = 7;
        $prepared = $this->prepared('rechnung'); $prepared['position']['gross_amount'] = '49.00';
        RepairDraftCreator::create($app, $prepared, 'User');
        self::assertSame('45.794393', $app->DB->price);
    }

    public function testTaxExemptDocumentKeepsGrossFeeAsNetPrice(): void
    {
        $app = $this->app(); $app->erp->withTax = false;
        $prepared = $this->prepared('rechnung'); $prepared['position']['gross_amount'] = '49.00';
        RepairDraftCreator::create($app, $prepared, 'User');
        self::assertSame('49.000000', $app->DB->price);
    }

    public function testMissingPositionRollsBackWholeDraft(): void
    {
        $app = $this->app(); $app->erp->positionId = 0;
        try {
            RepairDraftCreator::create($app, $this->prepared(), 'User');
            self::fail('Expected creation failure');
        } catch (\RuntimeException $e) {
            self::assertSame('ROLLBACK', end($app->DB->queries));
            self::assertNotContains('COMMIT', $app->DB->queries);
        }
    }

    public function testFailedLinkRollsBackDocumentAndPosition(): void
    {
        $app = $this->app(); $app->DB->failLink = true;
        try {
            RepairDraftCreator::create($app, $this->prepared(), 'User');
            self::fail('Expected link failure');
        } catch (\RuntimeException $e) {
            self::assertSame('ROLLBACK', end($app->DB->queries));
            self::assertNotContains('COMMIT', $app->DB->queries);
        }
    }

    public function testGrossFeeInForeignCurrencyIsRejected(): void
    {
        $app = $this->app(); $app->DB->currency = 'USD';
        $prepared = $this->prepared('rechnung'); $prepared['position']['gross_amount'] = '49.00';
        $this->expectException(\RuntimeException::class);
        RepairDraftCreator::create($app, $prepared, 'User');
    }
}

final class DraftLegacyBoundary
{
    public array $queries = [];
    public string $price = '23.50';
    public string $currency = 'EUR';
    public bool $failLink = false;
    public function Query($sql): bool
    {
        $this->queries[] = $sql;
        if (preg_match("/SET preis = '([^']+)'/", $sql, $match)) $this->price = $match[1];
        return !($this->failLink && strpos($sql, 'INSERT INTO repair_ticket_beleg') !== false);
    }
    public function SelectRow($sql): array
    {
        if (strpos($sql, '_position') !== false) return ['id' => 500, 'artikel' => 17, 'beleg_id' => 13, 'preis' => $this->price];
        return ['id' => 13, 'waehrung' => $this->currency];
    }
    public function real_escape_string($value): string { return str_replace(["\\", "'", "\n"], ["\\\\", "\\'", "\\n"], $value); }
}

final class DraftErpBoundary
{
    public array $calls = [];
    public array $positionArguments = [];
    public int $positionId = 500;
    public float $rate = 19;
    public bool $withTax = true;
    public function __construct(private DraftLegacyBoundary $db) {}
    public function __call($name, $args)
    {
        if (str_starts_with($name, 'Create')) { $this->calls[] = $name; return 13; }
        if (str_starts_with($name, 'Load') || str_ends_with($name, 'Neuberechnen')) { $this->calls[] = $name; return null; }
        if (str_ends_with($name, 'MitUmsatzeuer')) return $this->withTax;
        throw new \RuntimeException('Unexpected ERP call: ' . $name);
    }
    public function AddPositionManuell(...$args): int { $this->calls[] = __FUNCTION__; $this->positionArguments = $args; return $this->positionId; }
    public function GetSteuersatzBelegpos(...$args): float { return $this->rate; }
}
