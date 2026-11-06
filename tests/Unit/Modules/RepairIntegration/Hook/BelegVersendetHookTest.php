<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\RepairIntegration\Hook;

use PHPUnit\Framework\TestCase;
use Xentral\Components\Database\Adapter\AdapterInterface;
use Xentral\Components\Database\Database;
use Xentral\Components\Database\SqlQuery\QueryFactory;
use Xentral\Modules\RepairIntegration\Gateway\RepairBelegGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairStatusConfigGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairSyncQueueGateway;
use Xentral\Modules\RepairIntegration\Hook\BelegVersendetHook;
use Xentral\Modules\RepairIntegration\Service\RepairConfigService;
use Xentral\Modules\RepairIntegration\Service\RepairSyncService;

class BelegVersendetHookTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function row(string $slug, string $category, int $sortOrder, int $terminal = 0): array
    {
        return ['slug' => $slug, 'category' => $category, 'sort_order' => $sortOrder, 'is_terminal' => $terminal];
    }

    public function testUnknownCurrentStatusAdvances(): void
    {
        self::assertTrue(BelegVersendetHook::shouldAdvance(null, self::row('kv_gesendet', 'repair', 110)));
    }

    public function testSameSlugDoesNotAdvance(): void
    {
        $kv = self::row('kv_gesendet', 'repair', 110);
        self::assertFalse(BelegVersendetHook::shouldAdvance($kv, $kv));
    }

    public function testTerminalStatusNeverAdvances(): void
    {
        $closed = self::row('abgeschlossen', 'general', 900, 1);
        self::assertFalse(BelegVersendetHook::shouldAdvance($closed, self::row('kv_gesendet', 'repair', 110)));
    }

    public function testGeneralStatusAdvancesIntoCategory(): void
    {
        $neu = self::row('neu', 'general', 10);
        self::assertTrue(BelegVersendetHook::shouldAdvance($neu, self::row('kv_gesendet', 'repair', 110)));
    }

    public function testForwardWithinCategoryAdvances(): void
    {
        $diagnose = self::row('in_diagnose', 'repair', 100);
        self::assertTrue(BelegVersendetHook::shouldAdvance($diagnose, self::row('kv_gesendet', 'repair', 110)));
    }

    public function testDeclinedQuoteCanBeApprovedByAuftrag(): void
    {
        $declined = self::row('kv_abgelehnt', 'repair', 115);
        self::assertTrue(BelegVersendetHook::shouldAdvance($declined, self::row('freigegeben', 'repair', 120)));
    }

    public function testNoRegressionFromLaterStatus(): void
    {
        $repariert = self::row('repariert', 'repair', 140);
        self::assertFalse(BelegVersendetHook::shouldAdvance($repariert, self::row('kv_gesendet', 'repair', 110)));
        self::assertFalse(BelegVersendetHook::shouldAdvance($repariert, self::row('freigegeben', 'repair', 120)));
    }

    public function testDifferentCategoryAdvances(): void
    {
        // Ticket steht in einer fremden Kategorie, Ziel folgt dem Service-Typ.
        $wartung = self::row('wartung_geplant', 'maintenance', 200);
        self::assertTrue(BelegVersendetHook::shouldAdvance($wartung, self::row('kv_gesendet', 'repair', 110)));
    }

    public function testBelegTypeMapping(): void
    {
        self::assertSame('quote_sent', BelegVersendetHook::BELEG_WP_STATUS['angebot']);
        self::assertSame('approved', BelegVersendetHook::BELEG_WP_STATUS['auftrag']);
        self::assertSame('repaired', BelegVersendetHook::BELEG_WP_STATUS['rechnung']);
        self::assertArrayNotHasKey('lieferschein', BelegVersendetHook::BELEG_WP_STATUS);
    }

    public function testFormatQuoteAmountFromDecimalString(): void
    {
        // DECIMAL(18,4) kommt vom Treiber als String.
        self::assertSame('149.90', RepairSyncService::formatQuoteAmount('149.9000'));
        self::assertSame('1234.57', RepairSyncService::formatQuoteAmount('1234.5650'));
    }

    public function testFormatQuoteAmountRejectsEmptyZeroAndGarbage(): void
    {
        self::assertNull(RepairSyncService::formatQuoteAmount(null));
        self::assertNull(RepairSyncService::formatQuoteAmount(''));
        self::assertNull(RepairSyncService::formatQuoteAmount('0.0000'));
        self::assertNull(RepairSyncService::formatQuoteAmount('-5'));
        self::assertNull(RepairSyncService::formatQuoteAmount('abc'));
    }

    /**
     * DB-Shape wie MySQL liefert: ids/Fremdschluessel als String, DECIMAL als String.
     * Angebot 1 = Hauptangebot, Angebot 2 = per "neue Version" entstanden.
     */
    private const BELEGE = [
        'angebot' => [
            1 => ['id' => '1', 'belegnr' => 'AN-1001', 'status' => 'versendet', 'datum' => '2026-11-01', 'adresse' => '7', 'kopievon' => '0', 'gesamtsumme' => '119.0000'],
            2 => ['id' => '2', 'belegnr' => 'AN-1001-1', 'status' => 'freigegeben', 'datum' => '2026-11-02', 'adresse' => '7', 'kopievon' => '1', 'gesamtsumme' => '238.0000'],
        ],
    ];

    /** @var array<int, array{0: string, 1: array}> */
    private array $performed = [];

    /** @var string[] */
    private array $fetchAllSql = [];

    /**
     * @param array<int, array<string, mixed>> $links repair_ticket_beleg-Zeilen
     */
    private function hook(array $links): BelegVersendetHook
    {
        $belege = self::BELEGE;
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('fetchRow')->willReturnCallback(static function (string $sql, array $values = []) use ($belege) {
            if (preg_match('/SELECT `belegnr`, `(\w+)` AS `summe` FROM `(\w+)` WHERE `id` = :id/', $sql, $m) === 1) {
                $row = $belege[$m[2]][(int)$values['id']] ?? null;
                return $row === null ? [] : ['belegnr' => $row['belegnr'], 'summe' => $row[$m[1]]];
            }
            if (preg_match('/FROM `(angebot|auftrag|lieferschein|rechnung|gutschrift)` WHERE `id` = :id/', $sql, $m) === 1) {
                $row = $belege[$m[1]][(int)$values['id']] ?? null;
                return $row === null ? [] : $row + ['betrag' => $row['gesamtsumme'] ?? null];
            }
            if (strpos($sql, 'FROM `ticket_repair_details`') !== false) {
                return ['id' => '50', 'ticket_id' => (string)$values['ticket_id'], 'service_type' => 'reparatur', 'quote_amount' => null];
            }
            if (strpos($sql, '`wp_status_mapping` = :wp') !== false) {
                return ['slug' => 'kv_gesendet', 'category' => 'repair', 'sort_order' => '110', 'is_terminal' => '0'];
            }
            if (strpos($sql, 'FROM `ticket_status_config` WHERE `slug` = :slug') !== false) {
                return ['slug' => 'neu', 'category' => 'general', 'sort_order' => '10', 'is_terminal' => '0'];
            }
            return [];
        });
        $adapter->method('fetchCol')->willReturnCallback(static function (string $sql, array $values = []) use ($belege) {
            if (preg_match('/FROM `(\w+)` WHERE `(\w+)` = :pid/', $sql, $m) !== 1) {
                return [];
            }
            $ids = [];
            foreach ($belege[$m[1]] ?? [] as $row) {
                if ((int)($row[$m[2]] ?? 0) === (int)$values['pid']) {
                    $ids[] = $row['id'];
                }
            }
            return $ids;
        });
        $adapter->method('fetchAll')->willReturnCallback(function (string $sql, array $values = []) use ($links) {
            $this->fetchAllSql[] = $sql;
            if (strpos($sql, 'repair_ticket_beleg') === false) {
                return [];
            }
            $wanted = [];
            foreach ($values as $key => $value) {
                if (preg_match('/^typ(\d*)$/', (string)$key, $m) === 1) {
                    $wanted[] = $value . ':' . (int)$values['bid' . $m[1]];
                }
            }
            return array_values(array_filter(
                $links,
                static fn(array $l): bool => in_array($l['beleg_typ'] . ':' . (int)$l['beleg_id'], $wanted, true)
            ));
        });
        $adapter->method('fetchValue')->willReturnCallback(static function (string $sql) {
            if (strpos($sql, 'GET_LOCK') !== false) {
                return '1';
            }
            if (strpos($sql, 'FROM `ticket`') !== false) {
                return 'neu';
            }
            return false;
        });
        $adapter->method('perform')->willReturnCallback(function (string $sql, array $values = []) {
            $this->performed[] = [$sql, $values];
            return 1;
        });

        $db = new Database($adapter, new QueryFactory('mysql'));
        $statusConfig = new RepairStatusConfigGateway($db);
        $details = new RepairDetailsGateway($db);
        $sync = new RepairSyncService($db, new RepairSyncQueueGateway($db), $statusConfig, $details, new RepairConfigService($db));

        return new BelegVersendetHook($db, new RepairBelegGateway($db), $details, $statusConfig, $sync);
    }

    /** @return array<string, mixed> */
    private static function link(string $id, string $ticketId, string $typ, string $belegId, string $belegNr): array
    {
        return ['id' => $id, 'ticket_id' => $ticketId, 'beleg_typ' => $typ, 'beleg_id' => $belegId, 'beleg_nr' => $belegNr, 'created_at' => '2026-11-01 10:00:00'];
    }

    /** @return array<int, array<string, mixed>> */
    private function performedOn(string $table): array
    {
        return array_values(array_map(
            static fn(array $p): array => $p[1],
            array_filter($this->performed, static fn(array $p): bool => strpos($p[0], 'UPDATE `' . $table . '`') !== false)
        ));
    }

    public function testVersionWithoutOwnLinkUpdatesTicketOfOriginalOffer(): void
    {
        $results = $this->hook([
            self::link('900', '5', 'angebot', '1', 'AN-1001'),
        ])->onBelegVersendet('angebot', 2);

        self::assertSame([[
            'ticket_id' => 5,
            'old_status' => 'neu',
            'new_status' => 'kv_gesendet',
            'amount' => '238.00',
        ]], $results);
        self::assertSame([['amount' => '238.00', 'ticket_id' => 5]], $this->performedOn('ticket_repair_details'));
        self::assertSame([['status' => 'kv_gesendet', 'id' => 5]], $this->performedOn('ticket'));
    }

    public function testChainLinksAreProcessedOncePerTicket(): void
    {
        $results = $this->hook([
            self::link('900', '5', 'angebot', '1', 'AN-1001'),
            self::link('901', '5', 'angebot', '1', 'AN-1001'),
        ])->onBelegVersendet('angebot', 2);

        self::assertSame([5], array_column($results, 'ticket_id'));
    }

    public function testDirectLinkTakesPrecedenceOverChain(): void
    {
        $results = $this->hook([
            self::link('900', '5', 'angebot', '1', 'AN-1001'),
            self::link('901', '6', 'angebot', '2', ''),
        ])->onBelegVersendet('angebot', 2);

        self::assertSame([6], array_column($results, 'ticket_id'));
        self::assertSame([], array_filter($this->fetchAllSql, static fn(string $sql): bool => strpos($sql, 'rtb.') !== false));
        // Direkter Link: Belegnummer wird wie bisher nachgezogen.
        self::assertSame([['bnr' => 'AN-1001-1', 'id' => 901]], $this->performedOn('repair_ticket_beleg'));
    }

    public function testDerivedLinkKeepsItsBelegNr(): void
    {
        $this->hook([
            self::link('900', '5', 'angebot', '1', 'AN-1001'),
        ])->onBelegVersendet('angebot', 2);

        self::assertSame([], $this->performedOn('repair_ticket_beleg'));
    }
}
