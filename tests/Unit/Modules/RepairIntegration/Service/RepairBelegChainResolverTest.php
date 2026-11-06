<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\RepairIntegration\Service;

use PHPUnit\Framework\TestCase;
use Xentral\Components\Database\Adapter\AdapterInterface;
use Xentral\Components\Database\Database;
use Xentral\Components\Database\SqlQuery\QueryFactory;
use Xentral\Modules\RepairIntegration\Gateway\RepairBelegGateway;
use Xentral\Modules\RepairIntegration\Service\RepairBelegChainResolver;
use Xentral\Modules\RepairIntegration\Service\RepairBelegLinkRenderer;

final class RepairBelegChainResolverTest extends TestCase
{
    /**
     * DB-Shape wie MySQL liefert: ids/Fremdschluessel als String, DECIMAL als String.
     */
    private const TABLES = [
        'angebot' => [
            1 => ['id' => '1', 'belegnr' => 'AN-1001', 'status' => 'versendet', 'datum' => '2026-11-01', 'adresse' => '7', 'kopievon' => '0', 'betrag' => '119.0000'],
            2 => ['id' => '2', 'belegnr' => 'AN-1001-1', 'status' => 'freigegeben', 'datum' => '2026-11-02', 'adresse' => '7', 'kopievon' => '1', 'betrag' => '238.0000'],
            3 => ['id' => '3', 'belegnr' => 'AN-1001-2', 'status' => 'beauftragt', 'datum' => '2026-11-03', 'adresse' => '7', 'kopievon' => '1', 'betrag' => '357.0000'],
            4 => ['id' => '4', 'belegnr' => '', 'status' => 'angelegt', 'datum' => '2026-11-04', 'adresse' => '7', 'kopievon' => '0', 'betrag' => '0.0000'],
        ],
        'auftrag' => [
            10 => ['id' => '10', 'belegnr' => 'AB-2001', 'status' => 'abgeschlossen', 'datum' => '2026-11-05', 'adresse' => '7', 'angebotid' => '3', 'betrag' => '357.00'],
            11 => ['id' => '11', 'belegnr' => 'AB-2002', 'status' => 'freigegeben', 'datum' => '2026-11-05', 'adresse' => '7', 'angebotid' => '0', 'betrag' => '10.00'],
        ],
        'lieferschein' => [
            20 => ['id' => '20', 'belegnr' => 'LS-3001', 'status' => 'versendet', 'datum' => '2026-11-06', 'adresse' => '7', 'auftragid' => '10', 'betrag' => null],
        ],
        'rechnung' => [
            30 => ['id' => '30', 'belegnr' => 'RE-4001', 'status' => 'versendet', 'datum' => '2026-11-06', 'adresse' => '7', 'auftragid' => '10', 'lieferschein' => '20', 'betrag' => '357.00'],
            31 => ['id' => '31', 'belegnr' => 'RE-4002', 'status' => 'versendet', 'datum' => '2026-11-06', 'adresse' => '7', 'auftragid' => '0', 'lieferschein' => '0', 'betrag' => '5.00'],
        ],
        'gutschrift' => [
            40 => ['id' => '40', 'belegnr' => 'GS-5001', 'status' => 'freigegeben', 'datum' => '2026-11-07', 'adresse' => '7', 'rechnungid' => '30', 'betrag' => '-357.00'],
        ],
    ];

    private function adapter(array $links = []): AdapterInterface
    {
        $tables = self::TABLES;
        $tables['adresse'] = [7 => ['id' => '7', 'name' => 'Kunde GmbH', 'kundennummer' => '10007']];
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('fetchRow')->willReturnCallback(static function (string $sql, array $values = []) use ($tables) {
            if (preg_match('/FROM `(\w+)` WHERE `id` = :id/', $sql, $m) === 1) {
                return $tables[$m[1]][(int)$values['id']] ?? [];
            }
            return [];
        });
        $adapter->method('fetchCol')->willReturnCallback(static function (string $sql, array $values = []) use ($tables) {
            if (preg_match('/FROM `(\w+)` WHERE `(\w+)` = :pid/', $sql, $m) !== 1) {
                return [];
            }
            $ids = [];
            foreach ($tables[$m[1]] ?? [] as $row) {
                if ((int)($row[$m[2]] ?? 0) === (int)$values['pid']) {
                    $ids[] = $row['id'];
                }
            }
            return $ids;
        });
        $adapter->method('fetchAll')->willReturnCallback(static function (string $sql) use ($links) {
            if (strpos($sql, 'FROM `repair_ticket_beleg`') !== false) {
                return $links;
            }
            if (strpos($sql, 'UNION ALL') !== false) {
                $rows = [];
                foreach (self::TABLES as $typ => $tableRows) {
                    foreach ($tableRows as $row) {
                        $rows[] = ['typ' => $typ] + $row;
                    }
                }
                return $rows;
            }
            return [];
        });
        return $adapter;
    }

    private function resolver(array $links = []): RepairBelegChainResolver
    {
        return new RepairBelegChainResolver(new Database($this->adapter($links), new QueryFactory('mysql')));
    }

    private static function summary(array $nodes): array
    {
        return array_map(
            static fn(array $n): string => str_repeat('  ', $n['depth']) . RepairBelegChainResolver::number($n)
                . ($n['origin_text'] !== '' ? ' (' . $n['origin_text'] . ')' : ''),
            $nodes
        );
    }

    public function testOfferVersionsAndFollowUpDocumentsAreDerivedFromOriginalOffer(): void
    {
        $nodes = $this->resolver()->resolve([['typ' => 'angebot', 'id' => 1]]);

        self::assertSame([
            'AN-1001',
            '  AN-1001-1 (Version von AN-1001)',
            '  AN-1001-2 (Version von AN-1001)',
            'AB-2001 (aus Angebot AN-1001-2)',
            '  LS-3001 (aus Auftrag AB-2001)',
            '    RE-4001 (aus Lieferschein LS-3001)',
            '      GS-5001 (aus Rechnung RE-4001)',
        ], self::summary($nodes));
    }

    public function testChainIsFoundBackwardsFromInvoice(): void
    {
        $keys = array_column($this->resolver()->resolve([['typ' => 'rechnung', 'id' => 30]]), 'key');
        sort($keys);

        self::assertSame(
            ['angebot:1', 'angebot:2', 'angebot:3', 'auftrag:10', 'gutschrift:40', 'lieferschein:20', 'rechnung:30'],
            $keys
        );
    }

    public function testNewVersionAloneResolvesSiblingsAndOriginal(): void
    {
        $keys = array_column($this->resolver()->resolve([['typ' => 'angebot', 'id' => 2]]), 'key');

        self::assertContains('angebot:1', $keys);
        self::assertContains('angebot:3', $keys);
        self::assertNotContains('auftrag:11', $keys);
        self::assertNotContains('angebot:4', $keys);
    }

    public function testUnrelatedOrMissingDocumentsStayAlone(): void
    {
        self::assertSame(['rechnung:31'], array_column($this->resolver()->resolve([['typ' => 'rechnung', 'id' => 31]]), 'key'));
        self::assertSame([], $this->resolver()->resolve([['typ' => 'rechnung', 'id' => 999], ['typ' => 'verbindlichkeit', 'id' => 1]]));
    }

    public function testDraftWithoutNumberIsShownAsEntwurf(): void
    {
        $nodes = $this->resolver()->resolve([['typ' => 'angebot', 'id' => 4]]);
        self::assertSame('Entwurf #4', RepairBelegChainResolver::number($nodes[0]));
    }

    public function testTicketPanelMarksDirectLinkAndOffersOnlyDocumentsOutsideChain(): void
    {
        $links = [[
            'id' => '5', 'ticket_id' => '42', 'ticket_schluessel' => '202611060001', 'beleg_typ' => 'angebot',
            'beleg_id' => '1', 'beleg_nr' => 'AN-1001', 'created_at' => '2026-11-01 10:00:00',
        ]];
        $db = new Database($this->adapter($links), new QueryFactory('mysql'));
        $renderer = new RepairBelegLinkRenderer(new RepairBelegGateway($db), new RepairBelegChainResolver($db));

        [$nodes, $direct] = $renderer->resolveForTicket(42);
        $rows = $renderer->ticketChainRows(42, $nodes, $direct, true);
        $candidates = $renderer->ticketCandidates(42, 7, '', $nodes, $direct, true);

        self::assertStringContainsString('Version von AN-1001', $rows);
        self::assertStringContainsString('name="repair_unlink" value="5"', $rows);
        self::assertSame(1, substr_count($rows, 'repair_unlink'), 'nur direkte Links sind entfernbar');
        self::assertStringContainsString('value="rechnung:31"', $candidates);
        self::assertStringContainsString('value="auftrag:11"', $candidates);
        self::assertStringNotContainsString('value="angebot:2"', $candidates);
        self::assertStringNotContainsString('value="rechnung:30"', $candidates);
    }
}
