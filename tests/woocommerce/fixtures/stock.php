<?php
// SPDX-License-Identifier: AGPL-3.0-only

/** Shared in-memory emulation of lager_platz / lager_platz_inhalt for StockCorrection tests. */
trait StockFixtureRows
{
    public array $places = [
        1 => ['id' => '1', 'kurzbezeichnung' => 'A-01', 'lager' => 'Haupt', 'sperrlager' => '0', 'geloescht' => '0'],
        2 => ['id' => '2', 'kurzbezeichnung' => 'B-01', 'lager' => 'Haupt', 'sperrlager' => '0', 'geloescht' => '0'],
        3 => ['id' => '3', 'kurzbezeichnung' => 'C-01', 'lager' => 'Neben', 'sperrlager' => '0', 'geloescht' => '0'],
    ];
    /** @var list<array{artikel:int, lager_platz:int, menge:string}> */
    public array $stock = [];
    public int $stockQueries = 0;

    public function addStock(int $article, int $place, string $menge): void
    {
        $this->stock[] = ['artikel' => $article, 'lager_platz' => $place, 'menge' => $menge];
    }

    private function stockRows(string $sql): array
    {
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
        // Same order as the real query: ORDER BY lp.kurzbezeichnung, lp.id
        usort($rows, static fn (array $a, array $b): int => [$a['kurzbezeichnung'], (int) $a['id']] <=> [$b['kurzbezeichnung'], (int) $b['id']]);
        return $rows;
    }

    private function stockPlace(array $params): array|false
    {
        $place = $this->places[$params['id']] ?? null;
        if ($place === null || $place['sperrlager'] === '1' || $place['geloescht'] === '1') {
            return false;
        }
        return ['id' => $place['id'], 'kurzbezeichnung' => $place['kurzbezeichnung']];
    }
}

/** Records erpAPI stock bookings and mirrors them into the fixture database. */
trait StockFixtureBookings
{
    public array $bookings = [];
    public ?int $failAt = null;
    public string $failure = 'Auslagern fehlgeschlagen! (Bestand zu gering)';
    /** Runs before each booking, e.g. to simulate a concurrent stock movement. */
    public ?Closure $beforeBook = null;

    public function LagerEinlagern($artikel, $menge, $regal, $projekt, $grund = '')
    {
        $this->book('ein', $artikel, $regal, $menge, $projekt, $grund, $menge);
    }

    public function LagerAuslagernRegal($artikel, $regal, $menge, $projekt, $grund)
    {
        if ($this->beforeBook !== null) {
            ($this->beforeBook)();
        }
        // Mirrors erpAPI::LagerAuslagernRegal: abort when the place holds less than requested.
        $bestand = 0.0;
        foreach ($this->stockDb()->stock as $row) {
            if ($row['artikel'] === $artikel && $row['lager_platz'] === $regal) {
                $bestand += (float) $row['menge'];
            }
        }
        if ($menge > $bestand || $bestand <= 0) {
            throw new Exception('Auslagern fehlgeschlagen! (Bestand zu gering)');
        }
        $this->book('aus', $artikel, $regal, $menge, $projekt, $grund, -$menge);
    }

    private function book(string $type, $artikel, $regal, $menge, $projekt, $grund, $delta): void
    {
        if ($this->failAt === count($this->bookings) + 1) {
            throw new Exception($this->failure);
        }
        $this->bookings[] = [$type, $artikel, $regal, $menge, $projekt, $grund];
        $this->stockDb()->addStock($artikel, $regal, (string) $delta);
    }
}
