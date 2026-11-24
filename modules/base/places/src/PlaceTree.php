<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Places;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Čtení stromu míst (#169 D6; docs/places.md). Cesta, podstrom ani pořadí
 * se nikam neukládají — všechno se počítá odsud, nad všemi místy mimo koš
 * načtenými jedním dotazem a zapamatovanými v instanci.
 *
 * Používá ho viewer, detail, lookup a formulář. Dokument (PlaceDocument)
 * si při validaci čte přímo — běží uvnitř ukládací transakce a potřebuje
 * jen pár řádků.
 *
 * Uzel: `{id, parent, name, short_name, code, order_pos, docState, kind,
 * kind_name, kind_type, kind_icon}`; `parent` je null u místa nejvyšší
 * úrovně.
 *
 * Průchody mají strop {@see MAX_DEPTH} a pamatují si navštívené uzly —
 * poškozená data (cyklus) vrátí zkrácený výsledek, ne nekonečnou smyčku.
 */
class PlaceTree
{
    public const MAX_DEPTH = 100;
    public const SEPARATOR = ' › ';

    private const PLACES_TABLE = 'base_places_places';
    private const KINDS_TABLE = 'base_places_kinds';
    private const STATE_DELETED = 90;

    /** @var array<int, array<string, mixed>>|null id → uzel */
    private ?array $nodes = null;

    /** @var array<int, list<int>>|null id rodiče (0 = nejvyšší úroveň) → seřazená id dětí */
    private ?array $childIds = null;

    private static ?\Collator $collator = null;

    public function __construct(protected readonly ?DataSourceConnection $db)
    {
    }

    /** @return array<string, mixed>|null uzel místa, null = neexistuje nebo je v koši */
    public function node(int $id): ?array
    {
        return $this->nodes()[$id] ?? null;
    }

    /**
     * Předci místa shora (kořen první), bez místa samotného.
     *
     * @return list<array<string, mixed>>
     */
    public function ancestors(int $id): array
    {
        $nodes = $this->nodes();
        $chain = [];
        $seen = [$id => true];
        $current = $nodes[$id] ?? null;
        $depth = 0;

        while ($current !== null && $depth++ < self::MAX_DEPTH) {
            $parentId = (int) ($current['parent'] ?? 0);
            if ($parentId <= 0 || isset($seen[$parentId]) || !isset($nodes[$parentId])) {
                break;
            }
            $seen[$parentId] = true;
            $current = $nodes[$parentId];
            $chain[] = $current;
        }

        return array_reverse($chain);
    }

    /**
     * Popisky předků shora — zkrácený název, bez něj název.
     *
     * @return list<string>
     */
    public function pathLabels(int $id): array
    {
        return array_map(static fn (array $node): string => self::labelOf($node), $this->ancestors($id));
    }

    /** Cesta k místu („Areál › A › 2. NP“); místo nejvyšší úrovně má prázdnou. */
    public function pathText(int $id): string
    {
        return implode(self::SEPARATOR, $this->pathLabels($id));
    }

    /**
     * Přímá podřízená místa v pořadí stromu: pořadí → kód → název.
     * `null` = místa nejvyšší úrovně.
     *
     * @return list<array<string, mixed>>
     */
    public function children(?int $id): array
    {
        $nodes = $this->nodes();
        $out = [];
        foreach ($this->childIds()[$id ?? 0] ?? [] as $childId) {
            $out[] = $nodes[$childId];
        }
        return $out;
    }

    /**
     * Místo a všichni jeho potomci.
     *
     * @return list<int>
     */
    public function subtreeIds(int $id): array
    {
        $childIds = $this->childIds();
        $seen = [$id => true];
        $level = [$id];
        $depth = 0;

        while ($level !== [] && $depth++ < self::MAX_DEPTH) {
            $next = [];
            foreach ($level as $current) {
                foreach ($childIds[$current] ?? [] as $childId) {
                    if (!isset($seen[$childId])) {
                        $seen[$childId] = true;
                        $next[] = $childId;
                    }
                }
            }
            $level = $next;
        }

        return array_keys($seen);
    }

    /** Typ místa (typ jeho druhu), null = místo neexistuje nebo druh chybí. */
    public function typeOf(int $id): ?string
    {
        $type = $this->node($id)['kind_type'] ?? null;
        return is_string($type) && $type !== '' ? $type : null;
    }

    /** Popisek uzlu do cesty a stromu: zkrácený název, bez něj název. */
    public static function labelOf(array $node): string
    {
        $short = trim((string) ($node['short_name'] ?? ''));
        return $short !== '' ? $short : (string) ($node['name'] ?? '');
    }

    /**
     * Porovnání textů pro řazení stromu: česky a s čísly podle hodnoty
     * („2“ před „10“).
     */
    public static function compareText(string $a, string $b): int
    {
        if (self::$collator === null) {
            self::$collator = new \Collator('cs');
            self::$collator->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);
        }
        $result = self::$collator->compare($a, $b);
        return is_int($result) ? $result : strcmp($a, $b);
    }

    /**
     * Všechna místa mimo koš s typem, názvem a ikonou druhu — jedním
     * dotazem (přepsatelné v testech).
     *
     * @return list<array<string, mixed>>
     */
    protected function loadAll(): array
    {
        if ($this->db === null) {
            return [];
        }
        return $this->db->fetchAll(
            'SELECT p.`id`, p.`parent`, p.`name`, p.`short_name`, p.`code`, p.`order_pos`, p.`docState`, p.`kind`,'
            . ' k.`name` AS `kind_name`, k.`type` AS `kind_type`, k.`icon` AS `kind_icon`'
            . ' FROM `' . self::PLACES_TABLE . '` p'
            . ' LEFT JOIN `' . self::KINDS_TABLE . '` k ON k.`id` = p.`kind`'
            . ' WHERE p.`docState` <> %i',
            self::STATE_DELETED,
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function nodes(): array
    {
        if ($this->nodes !== null) {
            return $this->nodes;
        }
        $nodes = [];
        foreach ($this->loadAll() as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $parent = (int) ($row['parent'] ?? 0);
            $row['id'] = $id;
            $row['parent'] = $parent > 0 ? $parent : null;
            $row['order_pos'] = (int) ($row['order_pos'] ?? 0);
            $row['docState'] = (int) ($row['docState'] ?? 0);
            $nodes[$id] = $row;
        }
        return $this->nodes = $nodes;
    }

    /** @return array<int, list<int>> */
    private function childIds(): array
    {
        if ($this->childIds !== null) {
            return $this->childIds;
        }
        $nodes = $this->nodes();
        $groups = [];
        foreach ($nodes as $id => $node) {
            // Rodič, který není ve stromu (koš, poškozená data), dělá
            // z místa pro čtení místo nejvyšší úrovně.
            $parent = $node['parent'] !== null && isset($nodes[$node['parent']]) ? $node['parent'] : 0;
            $groups[$parent][] = $id;
        }
        foreach ($groups as &$ids) {
            usort($ids, static function (int $a, int $b) use ($nodes): int {
                return $nodes[$a]['order_pos'] <=> $nodes[$b]['order_pos']
                    ?: self::compareText((string) ($nodes[$a]['code'] ?? ''), (string) ($nodes[$b]['code'] ?? ''))
                    ?: self::compareText((string) ($nodes[$a]['name'] ?? ''), (string) ($nodes[$b]['name'] ?? ''))
                    ?: $a <=> $b;
            });
        }
        unset($ids);
        return $this->childIds = $groups;
    }
}
