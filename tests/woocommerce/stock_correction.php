<?php
// SPDX-License-Identifier: AGPL-3.0-only

use Xentral\Components\WooCommerce\StockCorrection;
use Xentral\Components\WooCommerce\StockCorrectionPartialFailure;

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Xentral\\Components\\')) {
        $path = $root . '/classes/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

final class StockFixtureDatabase
{
    public array $article = ['id' => '9', 'projekt' => '4', 'lagerartikel' => '1', 'chargenverwaltung' => '0',
        'mindesthaltbarkeitsdatum' => '0', 'seriennummern' => 'keine', 'lager_platz' => ''];
    public array $places = [
        1 => ['id' => '1', 'kurzbezeichnung' => 'A-01', 'lager' => 'Haupt', 'sperrlager' => '0', 'geloescht' => '0'],
        2 => ['id' => '2', 'kurzbezeichnung' => 'B-01', 'lager' => 'Haupt', 'sperrlager' => '0', 'geloescht' => '0'],
        3 => ['id' => '3', 'kurzbezeichnung' => 'C-01', 'lager' => 'Neben', 'sperrlager' => '0', 'geloescht' => '0'],
    ];
    /** @var list<array{artikel:int, lager_platz:int, menge:string}> */
    public array $stock = [];
    public int $stockQueries = 0;

    public function fetchAll(string $sql, array $params = []): array
    {
        if (!str_contains($sql, 'FROM lager_platz_inhalt')) {
            throw new LogicException($sql);
        }
        $this->stockQueries++;
        preg_match('/lpi\.artikel IN \(([0-9,]+)\)/', $sql, $ids);
        $ids = array_map('intval', explode(',', $ids[1]));
        $sums = [];
        foreach ($this->stock as $row) {
            $place = $this->places[$row['lager_platz']] ?? null;
            if (!in_array($row['artikel'], $ids, true) || $place === null || $place['sperrlager'] === '1') {
                continue;
            }
            $key = $row['artikel'] . ':' . $row['lager_platz'];
            $sums[$key] = ($sums[$key] ?? 0.0) + (float) $row['menge'];
        }
        $rows = [];
        foreach ($sums as $key => $sum) {
            [$article, $placeId] = array_map('intval', explode(':', $key));
            if (abs($sum) < 0.00005) {
                continue;
            }
            $place = $this->places[$placeId];
            $rows[] = ['artikel' => (string) $article, 'id' => $place['id'], 'kurzbezeichnung' => $place['kurzbezeichnung'],
                'lager' => $place['lager'], 'menge' => number_format($sum, 4, '.', '')];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['kurzbezeichnung'], $b['kurzbezeichnung']));
        return $rows;
    }

    public function fetchRow(string $sql, array $params = []): array|false
    {
        if (str_contains($sql, 'FROM artikel ')) {
            return (int) $this->article['id'] === $params['article'] ? $this->article : false;
        }
        if (str_contains($sql, 'FROM lager_platz ')) {
            $place = $this->places[$params['id']] ?? null;
            if ($place === null || $place['sperrlager'] === '1' || $place['geloescht'] === '1') {
                return false;
            }
            return ['id' => $place['id'], 'kurzbezeichnung' => $place['kurzbezeichnung']];
        }
        throw new LogicException($sql);
    }
}

final class StockFixtureErp
{
    public array $bookings = [];
    public ?int $failAt = null;
    public string $failure = 'Auslagern fehlgeschlagen! (Bestand zu gering)';

    public function __construct(private StockFixtureDatabase $db) {}

    public function LagerEinlagern($artikel, $menge, $regal, $projekt, $grund = '')
    {
        $this->book('ein', $artikel, $regal, $menge, $projekt, $grund, $menge);
    }

    public function LagerAuslagernRegal($artikel, $regal, $menge, $projekt, $grund)
    {
        $this->book('aus', $artikel, $regal, $menge, $projekt, $grund, -$menge);
    }

    private function book(string $type, $artikel, $regal, $menge, $projekt, $grund, $delta): void
    {
        if ($this->failAt === count($this->bookings) + 1) {
            throw new Exception($this->failure);
        }
        $this->bookings[] = [$type, $artikel, $regal, $menge, $projekt, $grund];
        $this->db->stock[] = ['artikel' => $artikel, 'lager_platz' => $regal, 'menge' => (string) $delta];
    }
}

function stockSetup(array $stock = []): array
{
    $db = new StockFixtureDatabase();
    foreach ($stock as [$article, $place, $menge]) {
        $db->stock[] = ['artikel' => $article, 'lager_platz' => $place, 'menge' => $menge];
    }
    $erp = new StockFixtureErp($db);
    return [new StockCorrection($db, $erp), $db, $erp];
}

function expectDomainError(string $message, callable $call): void
{
    try {
        $call();
    } catch (StockCorrectionPartialFailure $error) {
        throw new RuntimeException('Unexpected partial failure: ' . $error->getMessage());
    } catch (DomainException $error) {
        expect($message, $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected DomainException: ' . $message);
}

function count_(int $place, string $expected, string $target): array
{
    return ['place' => $place, 'expected' => $expected, 'target' => $target];
}

$tests = [
    'places_sums_per_article_excluding_blocked_places' => function (): void {
        [$stock, $db] = stockSetup([[9, 1, '5.0000'], [9, 1, '2.0000'], [9, 2, '4.0000'], [10, 3, '1.0000']]);
        $db->places[2]['sperrlager'] = '1';
        $places = $stock->places([9, 10]);
        expect([['id' => 1, 'name' => 'A-01', 'lager' => 'Haupt', 'menge' => '7.0000']], $places[9]);
        expect('7.0000', StockCorrection::total($places[9]));
        expect('1.0000', StockCorrection::total($places[10]));
        expect('0.0000', StockCorrection::total([]));
        expect(1, $db->stockQueries);
        expect([], $stock->places([]));
        expect(1, $db->stockQueries);
    },
    'format_trims_zeros_and_uses_comma' => function (): void {
        expect('12,5', StockCorrection::format('12.5000'));
        expect('3', StockCorrection::format('3.0000'));
        expect('-2', StockCorrection::format('-2.0000'));
        expect('0', StockCorrection::format('0'));
        expect('1234,0625', StockCorrection::format('1234.0625'));
    },
    'tracked_detection' => function (): void {
        $plain = ['chargenverwaltung' => '0', 'mindesthaltbarkeitsdatum' => '0', 'seriennummern' => 'keine'];
        expect(false, StockCorrection::isTracked($plain));
        expect(false, StockCorrection::isTracked(['seriennummern' => ''] + $plain));
        expect(true, StockCorrection::isTracked(['chargenverwaltung' => '1'] + $plain));
        expect(true, StockCorrection::isTracked(['chargenverwaltung' => '2'] + $plain));
        expect(true, StockCorrection::isTracked(['mindesthaltbarkeitsdatum' => '1'] + $plain));
        expect(true, StockCorrection::isTracked(['seriennummern' => 'eigene'] + $plain));
    },
    'single_place_writeoff_books_auslagern_with_reason' => function (): void {
        [$stock, , $erp] = stockSetup([[9, 1, '7.0000']]);
        $result = $stock->correct(9, [count_(1, '7.0000', '5')]);
        expect([['aus', 9, 1, 2.0, 4, StockCorrection::REASON]], $erp->bookings);
        expect('Inventur-Korrektur (WooCommerce-Uebersicht)', StockCorrection::REASON);
        expect('5.0000', StockCorrection::total($result['places']));
    },
    'comma_target_is_accepted' => function (): void {
        [$stock, , $erp] = stockSetup([[9, 1, '3.0000']]);
        $stock->correct(9, [count_(1, '3.0000', '2,5')]);
        expect([['aus', 9, 1, 0.5, 4, StockCorrection::REASON]], $erp->bookings);
    },
    'negative_place_to_zero_books_einlagern' => function (): void {
        [$stock, , $erp] = stockSetup([[9, 1, '-2.0000']]);
        $result = $stock->correct(9, [count_(1, '-2.0000', '0')]);
        expect([['ein', 9, 1, 2.0, 4, StockCorrection::REASON]], $erp->bookings);
        expect([], $result['places']);
    },
    'no_places_uses_standard_storage' => function (): void {
        [$stock, $db, $erp] = stockSetup();
        $db->article['lager_platz'] = '3';
        $result = $stock->correct(9, [count_(0, '0', '4')]);
        expect([['ein', 9, 3, 4.0, 4, StockCorrection::REASON]], $erp->bookings);
        expect('4.0000', StockCorrection::total($result['places']));
    },
    'missing_or_blocked_standard_storage_is_rejected' => function (): void {
        [$stock, $db, $erp] = stockSetup();
        expectDomainError('Kein gueltiger Standard-Lagerplatz am Artikel hinterlegt.',
            fn () => $stock->correct(9, [count_(0, '0', '4')]));
        $db->article['lager_platz'] = '3';
        $db->places[3]['sperrlager'] = '1';
        expectDomainError('Kein gueltiger Standard-Lagerplatz am Artikel hinterlegt.',
            fn () => $stock->correct(9, [count_(0, '0', '4')]));
        expect([], $erp->bookings);
    },
    'stale_expected_aborts_before_any_booking' => function (): void {
        [$stock, , $erp] = stockSetup([[9, 1, '5.0000'], [9, 2, '3.0000']]);
        expectDomainError('Bestand wurde zwischenzeitlich geaendert. Seite neu laden.',
            fn () => $stock->correct(9, [count_(1, '5.0000', '4'), count_(2, '2.0000', '1')]));
        expect([], $erp->bookings);
    },
    'unknown_place_and_place_zero_with_places_are_rejected' => function (): void {
        [$stock, $db, $erp] = stockSetup([[9, 1, '5.0000']]);
        $db->article['lager_platz'] = '3';
        expectDomainError('Lagerplatz ist nicht (mehr) belegt. Seite neu laden.',
            fn () => $stock->correct(9, [count_(99, '0', '1')]));
        expectDomainError('Bestand wurde zwischenzeitlich geaendert. Seite neu laden.',
            fn () => $stock->correct(9, [count_(0, '0', '1')]));
        expect([], $erp->bookings);
    },
    'invalid_targets_are_rejected' => function (): void {
        [$stock, , $erp] = stockSetup([[9, 1, '5.0000']]);
        foreach (['-1', 'abc', '1.23456', '', '1e3', '1,2,3'] as $target) {
            expectDomainError('Bitte eine Menge >= 0 mit hoechstens 4 Nachkommastellen eingeben.',
                fn () => $stock->correct(9, [count_(1, '5.0000', $target)]));
        }
        expectDomainError('Ungueltige Eingabe.', fn () => $stock->correct(9, []));
        expect([], $erp->bookings);
    },
    'tracked_or_non_stock_article_is_rejected' => function (): void {
        [$stock, $db, $erp] = stockSetup([[9, 1, '5.0000']]);
        $db->article['lagerartikel'] = '0';
        expectDomainError('Artikel wird nicht lagergefuehrt.', fn () => $stock->correct(9, [count_(1, '5.0000', '4')]));
        $db->article['lagerartikel'] = '1';
        $db->article['seriennummern'] = 'eigene';
        expectDomainError('Chargen-, MHD- oder Seriennummernartikel bitte im Lager-Modul korrigieren.',
            fn () => $stock->correct(9, [count_(1, '5.0000', '4')]));
        expectDomainError('Artikel nicht vorhanden.', fn () => $stock->correct(8, [count_(1, '5.0000', '4')]));
        expect([], $erp->bookings);
    },
    'unchanged_counts_book_nothing' => function (): void {
        [$stock, , $erp] = stockSetup([[9, 1, '5.0000']]);
        $result = $stock->correct(9, [count_(1, '5.0000', '5')]);
        expect([], $erp->bookings);
        expect('5.0000', StockCorrection::total($result['places']));
    },
    'partial_failure_reports_booked_places' => function (): void {
        [$stock, , $erp] = stockSetup([[9, 1, '5.0000'], [9, 2, '3.0000']]);
        $erp->failAt = 2;
        try {
            $stock->correct(9, [count_(1, '5.0000', '4'), count_(2, '3.0000', '1')]);
            throw new RuntimeException('Expected partial failure.');
        } catch (StockCorrectionPartialFailure $error) {
            expect('Teilweise gebucht (A-01). Fehler bei B-01: Auslagern fehlgeschlagen! (Bestand zu gering). Seite neu laden.',
                $error->getMessage());
        }
        expect([['aus', 9, 1, 1.0, 4, StockCorrection::REASON]], $erp->bookings);
    },
    'first_booking_failure_is_plain_domain_error' => function (): void {
        [$stock, , $erp] = stockSetup([[9, 1, '5.0000']]);
        $erp->failAt = 1;
        expectDomainError('Auslagern fehlgeschlagen! (Bestand zu gering)',
            fn () => $stock->correct(9, [count_(1, '5.0000', '4')]));
        expect([], $erp->bookings);
    },
];

function expect(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS $name\n";
    } catch (Throwable $error) {
        echo "FAIL $name: {$error->getMessage()}\n";
        $failed++;
    }
}
echo 'Stock correction tests: ' . (count($tests) - $failed) . '/' . count($tests) . " passed\n";
exit($failed === 0 ? 0 : 1);
