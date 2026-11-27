<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use DomainException;
use Throwable;

/**
 * Reads storage-place stock and books inventory corrections through the legacy erpAPI.
 * Bookings use the legacy connection, so they never run inside a Database transaction.
 */
final class StockCorrection
{
    public const REASON = 'Inventur-Korrektur (WooCommerce-Uebersicht)';
    private const STALE = 'Bestand wurde zwischenzeitlich geaendert. Seite neu laden.';
    private const EPSILON = 0.00005;

    public function __construct(private object $db, private object $erp)
    {
    }

    /**
     * @return array<int, list<array{id:int, name:string, lager:string, menge:string}>>
     */
    public function places(array $articleIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $articleIds)));
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT lpi.artikel, lp.id, lp.kurzbezeichnung, COALESCE(l.bezeichnung, \'\') AS lager, SUM(lpi.menge) AS menge'
            . ' FROM lager_platz_inhalt AS lpi INNER JOIN lager_platz AS lp ON lp.id = lpi.lager_platz AND lp.sperrlager != 1'
            . ' LEFT JOIN lager AS l ON l.id = lp.lager'
            . ' WHERE lpi.artikel IN (' . implode(',', $ids) . ')'
            . ' GROUP BY lpi.artikel, lp.id, lp.kurzbezeichnung, l.bezeichnung HAVING SUM(lpi.menge) <> 0'
            . ' ORDER BY lp.kurzbezeichnung, lp.id'
        );
        $places = [];
        foreach ($rows as $row) {
            $places[(int) $row['artikel']][] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['kurzbezeichnung'],
                'lager' => (string) $row['lager'],
                'menge' => number_format((float) $row['menge'], 4, '.', ''),
            ];
        }
        return $places;
    }

    public static function total(array $places): string
    {
        $sum = 0.0;
        foreach ($places as $place) {
            $sum += (float) $place['menge'];
        }
        return number_format(round($sum, 4), 4, '.', '');
    }

    public static function format(string $menge): string
    {
        $formatted = rtrim(rtrim(number_format(round((float) $menge, 4), 4, ',', ''), '0'), ',');
        return $formatted === '-0' ? '0' : $formatted;
    }

    public static function isTracked(array $article): bool
    {
        $serials = trim((string) ($article['seriennummern'] ?? ''));
        return (int) ($article['chargenverwaltung'] ?? 0) > 0
            || (int) ($article['mindesthaltbarkeitsdatum'] ?? 0) === 1
            || ($serials !== '' && $serials !== 'keine');
    }

    /**
     * @param list<array{place:int, expected:string, target:string}> $counts
     * @return array{places: list<array{id:int, name:string, lager:string, menge:string}>}
     */
    public function correct(int $articleId, array $counts): array
    {
        $article = $this->db->fetchRow(
            'SELECT id, projekt, lagerartikel, chargenverwaltung, mindesthaltbarkeitsdatum, seriennummern, lager_platz'
            . ' FROM artikel WHERE id = :article AND geloescht = 0',
            ['article' => $articleId]
        );
        if (!$article) {
            throw new DomainException('Artikel nicht vorhanden.');
        }
        if ((int) $article['lagerartikel'] !== 1) {
            throw new DomainException('Artikel wird nicht lagergefuehrt.');
        }
        if (self::isTracked($article)) {
            throw new DomainException('Chargen-, MHD- oder Seriennummernartikel bitte im Lager-Modul korrigieren.');
        }
        if ($counts === []) {
            throw new DomainException('Ungueltige Eingabe.');
        }

        $current = [];
        foreach ($this->places([$articleId])[$articleId] ?? [] as $place) {
            $current[$place['id']] = $place;
        }
        $bookings = [];
        $seen = [];
        foreach ($counts as $count) {
            $placeId = (int) $count['place'];
            $expected = (string) $count['expected'];
            $target = trim((string) $count['target']);
            if (isset($seen[$placeId]) || !preg_match('/^-?\d+(?:\.\d+)?$/D', $expected)) {
                throw new DomainException('Ungueltige Eingabe.');
            }
            $seen[$placeId] = true;
            if (!preg_match('/^\d+(?:[.,]\d{1,4})?$/D', $target)) {
                throw new DomainException('Bitte eine Menge >= 0 mit hoechstens 4 Nachkommastellen eingeben.');
            }
            if ($placeId === 0) {
                if ($current !== [] || abs((float) $expected) >= self::EPSILON) {
                    throw new DomainException(self::STALE);
                }
                $place = $this->standardPlace($article);
                $stock = 0.0;
            } elseif (!isset($current[$placeId])) {
                throw new DomainException('Lagerplatz ist nicht (mehr) belegt. Seite neu laden.');
            } else {
                $place = $current[$placeId];
                $stock = (float) $place['menge'];
                if (abs(round($stock - (float) $expected, 4)) >= self::EPSILON) {
                    throw new DomainException(self::STALE);
                }
            }
            $diff = round((float) str_replace(',', '.', $target) - $stock, 4);
            if (abs($diff) >= self::EPSILON) {
                $bookings[] = ['place' => $place, 'diff' => $diff];
            }
        }

        $booked = [];
        $project = (int) $article['projekt'];
        foreach ($bookings as $booking) {
            $place = $booking['place'];
            try {
                if ($booking['diff'] > 0) {
                    $this->erp->LagerEinlagern($articleId, $booking['diff'], $place['id'], $project, self::REASON);
                } else {
                    $this->erp->LagerAuslagernRegal($articleId, $place['id'], -$booking['diff'], $project, self::REASON);
                }
            } catch (Throwable $error) {
                if ($booked === []) {
                    throw new DomainException($error->getMessage(), 0, $error);
                }
                throw new StockCorrectionPartialFailure('Teilweise gebucht (' . implode(', ', $booked) . '). Fehler bei '
                    . $place['name'] . ': ' . $error->getMessage() . '. Seite neu laden.', 0, $error);
            }
            $booked[] = $place['name'];
        }
        return ['places' => $this->places([$articleId])[$articleId] ?? []];
    }

    private function standardPlace(array $article): array
    {
        $id = (int) $article['lager_platz'];
        $place = $id > 0 ? $this->db->fetchRow(
            'SELECT id, kurzbezeichnung FROM lager_platz WHERE id = :id AND sperrlager != 1 AND geloescht != 1',
            ['id' => $id]
        ) : false;
        if (!$place) {
            throw new DomainException('Kein gueltiger Standard-Lagerplatz am Artikel hinterlegt.');
        }
        return ['id' => (int) $place['id'], 'name' => (string) $place['kurzbezeichnung']];
    }
}
