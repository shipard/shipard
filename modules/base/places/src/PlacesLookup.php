<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Places;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/**
 * Lookup míst (#169 D12): hledá v názvu, zkráceném názvu a kódu; nabízí
 * místa v Konceptu, V pořádku a V opravě. `primary` je název, `secondary`
 * cesta · druh (místo nejvyšší úrovně jen druh). Řadí podle cesty a názvu,
 * takže místa jedné budovy drží pohromadě.
 *
 * Filtry (formulář je posílá z přepočtu; kombinují se):
 *
 *  - `childType` — jen místa, jejichž typ smí být nadřazený danému typu
 *    ({@see PlaceTypes::canBeParent()})
 *  - `exclude` — vynechá místo a celý jeho podstrom (jinak by šel postavit
 *    cyklus)
 *  - `types` — jen místa vyjmenovaných typů (#174 D23): seznam klíčů typů
 *    jako pole, nebo řetězec oddělený čárkami (z query stringu projde jen
 *    skalár — {@see self::typesFilter()} ho skládá); prázdné = bez omezení
 *
 * Hledání dělá SQL, filtry a řazení PHP nad {@see PlaceTree} — typ
 * i podstrom jsou vlastnosti stromu, ne řádku. `resolve()` filtry nezná:
 * vybrané místo se zobrazí, i když jím neprojde.
 */
class PlacesLookup extends TableLookup
{
    public const FILTER_CHILD_TYPE = 'childType';
    public const FILTER_EXCLUDE = 'exclude';
    public const FILTER_TYPES = 'types';

    private ?PlaceTree $tree = null;

    public function getAllowedFilterKeys(): array
    {
        return [self::FILTER_CHILD_TYPE, self::FILTER_EXCLUDE, self::FILTER_TYPES];
    }

    /**
     * Hodnota filtru `types` pro deklaraci pole ve formuláři: lookup filtr
     * nese jen skaláry, seznam typů proto jde jako řetězec oddělený čárkami.
     * Prázdný seznam (`null` = libovolný typ) vrací `null` — filtr se
     * neposílá.
     *
     * @param list<string>|null $types
     */
    public static function typesFilter(?array $types): ?string
    {
        $types = self::parseTypes($types ?? []);
        return $types !== [] ? implode(',', $types) : null;
    }

    /**
     * Seznam typů z hodnoty filtru — pole, nebo řetězec oddělený čárkami.
     * Mezery kolem klíčů a prázdné položky se zahodí, opakování také.
     *
     * @return list<string>
     */
    private static function parseTypes(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $types = [];
        foreach ($value as $type) {
            $type = is_scalar($type) ? trim((string) $type) : '';
            if ($type !== '') {
                $types[$type] = true;
            }
        }
        return array_map(strval(...), array_keys($types));
    }

    public function search(string $q, array $filter, int $limit): array
    {
        if ($this->db === null) {
            return [];
        }
        $tree = $this->tree();
        $types = new PlaceTypes($this->config);

        $childType = trim((string) ($filter[self::FILTER_CHILD_TYPE] ?? ''));
        $excludeId = (int) ($filter[self::FILTER_EXCLUDE] ?? 0);
        $excluded = $excludeId > 0 ? array_flip($tree->subtreeIds($excludeId)) : [];
        $onlyTypes = array_flip(self::parseTypes($filter[self::FILTER_TYPES] ?? []));

        $found = [];
        foreach ($this->matchingIds(trim($q)) as $id) {
            $node = $tree->node($id);
            if ($node === null || isset($excluded[$id])) {
                continue;
            }
            if ($childType !== '' && !$types->canBeParent((string) ($node['kind_type'] ?? ''), $childType)) {
                continue;
            }
            if ($onlyTypes !== [] && !isset($onlyTypes[(string) ($node['kind_type'] ?? '')])) {
                continue;
            }
            $found[] = ['node' => $node, 'path' => $tree->pathText($id)];
        }

        usort($found, static fn (array $a, array $b): int
            => PlaceTree::compareText($a['path'], $b['path'])
                ?: PlaceTree::compareText((string) $a['node']['name'], (string) $b['node']['name'])
                ?: $a['node']['id'] <=> $b['node']['id']);

        return array_map(
            static fn (array $item): LookupItem => self::item(
                (int) $item['node']['id'],
                (string) $item['node']['name'],
                $item['path'],
                (string) ($item['node']['kind_name'] ?? ''),
            ),
            array_slice($found, 0, $limit),
        );
    }

    public function resolve(array $ids): array
    {
        if ($this->db === null || $ids === []) {
            return [];
        }
        $intIds = array_values(array_filter(array_map('intval', $ids), static fn (int $v): bool => $v > 0));
        if ($intIds === []) {
            return [];
        }
        // Přímo z tabulky, ne ze stromu: vybrané místo se musí zobrazit,
        // i když je mezitím v koši.
        $rows = $this->db->fetchAll(
            'SELECT p.`id`, p.`name`, k.`name` AS `kind_name`'
            . ' FROM `' . PlaceDocument::TABLE . '` p'
            . ' LEFT JOIN `' . PlaceKindDocument::TABLE . '` k ON k.`id` = p.`kind`'
            . ' WHERE p.`id` IN %in',
            $intIds,
        );
        $tree = $this->tree();
        return array_map(
            static fn (array $row): LookupItem => self::item(
                (int) $row['id'],
                (string) ($row['name'] ?? ''),
                $tree->pathText((int) $row['id']),
                (string) ($row['kind_name'] ?? ''),
            ),
            $rows,
        );
    }

    /** „cesta · druh“; bez cesty jen druh, bez obojího prázdný řetězec. */
    public static function describe(string $path, string $kindName): string
    {
        return implode(' · ', array_values(array_filter(
            [trim($path), trim($kindName)],
            static fn (string $part): bool => $part !== '',
        )));
    }

    private static function item(int $id, string $name, string $path, string $kindName): LookupItem
    {
        $secondary = self::describe($path, $kindName);
        return new LookupItem(
            id: $id,
            primary: $name !== '' ? $name : ('#' . $id),
            secondary: $secondary !== '' ? $secondary : null,
        );
    }

    /**
     * Id míst ve stavech, které lookup nabízí, vyhovujících hledání.
     *
     * @return list<int>
     */
    private function matchingIds(string $q): array
    {
        $sql = 'SELECT p.`id` FROM `' . PlaceDocument::TABLE . '` p WHERE p.`docState` IN %in';
        $args = [PlaceDocument::LIVE_STATES];
        if ($q !== '') {
            [$searchSql, $searchArgs] = SearchCondition::anyContains(['p.`name`', 'p.`short_name`', 'p.`code`'], $q);
            $sql .= ' AND ' . $searchSql;
            $args = array_merge($args, $searchArgs);
        }
        return array_map(static fn (array $row): int => (int) $row['id'], $this->db->fetchAll($sql, ...$args));
    }

    /** Strom míst, jednou za požadavek (přepsatelné v testech). */
    protected function tree(): PlaceTree
    {
        return $this->tree ??= new PlaceTree($this->db);
    }
}
