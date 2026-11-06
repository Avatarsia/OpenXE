<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Components\Database\Database;

/**
 * Leitet aus direkt verknuepften Belegen die komplette Belegkette ab.
 *
 * Grundlage sind ausschliesslich die Beziehungsspalten des Cores
 * (Kind -> Eltern), traversiert in beide Richtungen:
 * - angebot.kopievon        -> Hauptangebot (neue Version)
 * - auftrag.angebotid       -> Angebot
 * - lieferschein.auftragid  -> Auftrag
 * - rechnung.auftragid      -> Auftrag
 * - rechnung.lieferschein   -> Lieferschein
 * - gutschrift.rechnungid   -> Rechnung
 *
 * Die Rueckverweise (angebot.auftragid, auftrag.rechnungid, ...) werden
 * bewusst nicht genutzt: bei Sammelrechnungen wuerden sonst fremde Auftraege
 * desselben Kunden in die Kette gezogen.
 *
 * Nichts davon wird gespeichert - die Kette wird bei jeder Anzeige neu
 * berechnet und bleibt damit nach Loeschen/Umhaengen automatisch korrekt.
 */
final class RepairBelegChainResolver
{
    public const CHAIN_TYPES = ['angebot', 'auftrag', 'lieferschein', 'rechnung', 'gutschrift'];

    public const LABELS = [
        'angebot' => 'Angebot',
        'auftrag' => 'Auftrag',
        'lieferschein' => 'Lieferschein',
        'rechnung' => 'Rechnung',
        'gutschrift' => 'Gutschrift',
        'verbindlichkeit' => 'Verbindlichkeit',
    ];

    /** Bruttobetrag je Belegtabelle (rechnung/gutschrift haben "soll"). */
    public const AMOUNT_COLUMNS = [
        'angebot' => 'gesamtsumme',
        'auftrag' => 'gesamtsumme',
        'rechnung' => 'soll',
        'gutschrift' => 'soll',
    ];

    /** Kanten: [Kind-Typ, Spalte im Kind, Eltern-Typ]. */
    private const EDGES = [
        ['angebot', 'kopievon', 'angebot'],
        ['auftrag', 'angebotid', 'angebot'],
        ['lieferschein', 'auftragid', 'auftrag'],
        ['rechnung', 'auftragid', 'auftrag'],
        ['rechnung', 'lieferschein', 'lieferschein'],
        ['gutschrift', 'rechnungid', 'rechnung'],
    ];

    private const MAX_NODES = 200;
    private const MAX_CHILDREN = 50;

    public function __construct(
        private readonly Database $db,
    ) {}

    public static function label(string $typ): string
    {
        return self::LABELS[$typ] ?? ucfirst($typ);
    }

    public static function number(array $node): string
    {
        $nr = trim((string)($node['belegnr'] ?? ''));
        return $nr !== '' ? $nr : 'Entwurf #' . (int)($node['id'] ?? 0);
    }

    public static function key(string $typ, int $id): string
    {
        return $typ . ':' . $id;
    }

    public static function isChainType(string $typ): bool
    {
        return in_array($typ, self::CHAIN_TYPES, true);
    }

    /**
     * @param array<int, array{typ: string, id: int}> $seeds
     *
     * @return array<int, array<string, mixed>> Knoten in Anzeigereihenfolge,
     *         je Knoten: typ, id, key, belegnr, status, datum, betrag, adresse,
     *         depth, origin_text (Herkunft oder '')
     */
    public function resolve(array $seeds): array
    {
        $nodes = [];
        $queue = [];
        foreach ($seeds as $seed) {
            $typ = (string)$seed['typ'];
            $id = (int)$seed['id'];
            if ($id > 0 && self::isChainType($typ)) {
                $queue[] = [$typ, $id];
            }
        }

        while ($queue !== [] && count($nodes) < self::MAX_NODES) {
            [$typ, $id] = array_shift($queue);
            $key = self::key($typ, $id);
            if (isset($nodes[$key])) {
                continue;
            }
            $row = $this->loadRow($typ, $id);
            if ($row === null) {
                continue;
            }
            $nodes[$key] = $row;

            foreach (self::EDGES as [$childTyp, $column, $parentTyp]) {
                if ($childTyp === $typ && (int)$row[$column] > 0) {
                    $queue[] = [$parentTyp, (int)$row[$column]];
                }
                if ($parentTyp === $typ) {
                    foreach ($this->childIds($childTyp, $column, $id) as $childId) {
                        $queue[] = [$childTyp, $childId];
                    }
                }
            }
        }

        return $this->order($nodes);
    }

    /**
     * Laedt einen einzelnen Beleg (fuer direkt verknuepfte Belege, deren
     * Typ nicht Teil der Kette ist, oder fuer Pruefungen). Null wenn
     * der Beleg nicht (mehr) existiert.
     */
    public function loadBeleg(string $typ, int $id): ?array
    {
        if (!self::isChainType($typ) || $id <= 0) {
            return null;
        }
        return $this->loadRow($typ, $id);
    }

    private function loadRow(string $typ, int $id): ?array
    {
        $columns = ['id', 'belegnr', 'status', 'datum', 'adresse'];
        foreach (self::EDGES as [$childTyp, $column]) {
            if ($childTyp === $typ) {
                $columns[] = $column;
            }
        }
        $amount = self::AMOUNT_COLUMNS[$typ] ?? null;
        $select = '`' . implode('`, `', $columns) . '`'
            . ($amount !== null ? ', `' . $amount . '` AS `betrag`' : ', NULL AS `betrag`');

        $row = $this->db->fetchRow(
            'SELECT ' . $select . ' FROM `' . $typ . '` WHERE `id` = :id LIMIT 1',
            ['id' => $id]
        );
        if (empty($row)) {
            return null;
        }
        $row['typ'] = $typ;
        $row['id'] = (int)$row['id'];
        $row['key'] = self::key($typ, (int)$row['id']);
        return $row;
    }

    /** @return int[] */
    private function childIds(string $childTyp, string $column, int $parentId): array
    {
        $rows = $this->db->fetchCol(
            'SELECT `id` FROM `' . $childTyp . '` WHERE `' . $column . '` = :pid ORDER BY `id` LIMIT ' . self::MAX_CHILDREN,
            ['pid' => $parentId]
        );
        return array_map('intval', $rows);
    }

    /**
     * Baut die Anzeigereihenfolge: Versionen unter dem Hauptangebot,
     * Lieferschein unter dem Auftrag, Rechnung unter Lieferschein bzw. Auftrag,
     * Gutschrift unter der Rechnung.
     * Auftraege stehen auf oberster Ebene mit Herkunftsangabe "aus Angebot X".
     */
    private function order(array $nodes): array
    {
        $parents = [];
        foreach ($nodes as $key => $node) {
            [$parentKey, $originKey, $originPrefix] = $this->parentOf($node, $nodes);
            $parents[$key] = $parentKey;
            $nodes[$key]['origin_text'] = $originKey !== null
                ? $originPrefix . self::number($nodes[$originKey])
                : '';
        }

        $children = [];
        $roots = [];
        foreach ($nodes as $key => $node) {
            if ($parents[$key] !== null) {
                $children[$parents[$key]][] = $key;
            } else {
                $roots[] = $key;
            }
        }

        $sort = static function (array &$keys) use ($nodes): void {
            usort($keys, static function (string $a, string $b) use ($nodes): int {
                $typeOrder = array_flip(self::CHAIN_TYPES);
                return [$typeOrder[$nodes[$a]['typ']], (string)$nodes[$a]['datum'], $nodes[$a]['id']]
                    <=> [$typeOrder[$nodes[$b]['typ']], (string)$nodes[$b]['datum'], $nodes[$b]['id']];
            });
        };

        $result = [];
        $walk = static function (string $key, int $depth) use (&$walk, &$result, &$children, $nodes, $sort): void {
            $node = $nodes[$key];
            $node['depth'] = $depth;
            $result[] = $node;
            if (!empty($children[$key])) {
                $sort($children[$key]);
                foreach ($children[$key] as $childKey) {
                    $walk($childKey, $depth + 1);
                }
            }
        };
        $sort($roots);
        foreach ($roots as $rootKey) {
            $walk($rootKey, 0);
        }

        return $result;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: string} [Anzeige-Eltern, Herkunft, Herkunfts-Praefix]
     */
    private function parentOf(array $node, array $nodes): array
    {
        $ref = static function (string $typ, $id) use ($nodes): ?string {
            $key = self::key($typ, (int)$id);
            return ((int)$id > 0 && isset($nodes[$key])) ? $key : null;
        };

        switch ($node['typ']) {
            case 'angebot':
                $p = $ref('angebot', $node['kopievon']);
                return [$p, $p, 'Version von '];
            case 'auftrag':
                return [null, $ref('angebot', $node['angebotid']), 'aus Angebot '];
            case 'lieferschein':
                $p = $ref('auftrag', $node['auftragid']);
                return [$p, $p, 'aus Auftrag '];
            case 'rechnung':
                // Auftrag -> Lieferschein -> Rechnung setzt beide Felder: der
                // Lieferschein ist die genauere Herkunft.
                $p = $ref('lieferschein', $node['lieferschein']);
                if ($p !== null) {
                    return [$p, $p, 'aus Lieferschein '];
                }
                $p = $ref('auftrag', $node['auftragid']);
                return [$p, $p, 'aus Auftrag '];
            case 'gutschrift':
                $p = $ref('rechnung', $node['rechnungid']);
                return [$p, $p, 'aus Rechnung '];
        }
        return [null, null, ''];
    }
}
