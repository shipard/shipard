<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Places;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Viewer\Detail\DetailHeader;
use Shipard\Module\Base\Persons\Values\AddressFormat;

/**
 * Viewer míst (Nastavení → Místa, #169 D8–D12, D20–D25).
 *
 * Výchozí layout je **strom** (docs/viewer-tree.md): sloupce Název, Kód
 * a Druh, sourozenci v pořadí {@see PlaceTree::compareNodes()}, předci
 * mimo filtr jako kontext. Řádek i detail nesou akci **Přidat podřízené**
 * — formulář s předvyplněným nadřazeným místem a navrženým druhem
 * ({@see childPreset()}, D23).
 *
 * Druhý layout je plochý seznam (D24): název, kód a ve druhém řádku cesta
 * · druh, ikona podle druhu. Cestu ani pořadí neukládáme — počítá je
 * {@see PlaceTree} jednou za požadavek.
 *
 * Detail: titulek název, podtitul cesta · druh. Přehled hlavičku neopakuje
 * (#140 D26) — druh ani cesta ve vlastnostech nejsou; nese kód, zkrácený
 * název (jen když se liší od názvu), pořadí, adresu (jen typ s adresou),
 * poznámku a proklikávací tabulku podřízených míst.
 */
class PlacesViewer extends PlacesViewerBase
{
    private const VIEWER_ID = 'base.places.places';
    private const STATE_ARCHIVED = 70;
    private const ACTION_ADD_CHILD = 'addChild';

    private ?PlaceTree $tree = null;

    /** @var list<array<string, mixed>>|null živé druhy, jednou za požadavek */
    private ?array $liveKinds = null;

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        [$where, $params] = $this->filterWhere($search, $filters);
        $sql = $this->baseSelect() . $where . ' ORDER BY p.`docStateMain` ASC, p.`name` ASC, p.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    // --- strom (docs/viewer-tree.md) ---

    public function getDefaultLayout(): string
    {
        return 'tree';
    }

    public function getTreeOptions(): ?array
    {
        return [
            'parentColumn' => 'parent',
            'columns'      => [
                ['id' => 'name', 'label' => $this->columnLabel('name', 'Name'), 'grow' => true],
                ['id' => 'code', 'label' => $this->columnLabel('code', 'Code'), 'width' => 128],
                ['id' => 'kind', 'label' => $this->columnLabel('kind', 'Kind'), 'width' => 224],
            ],
        ];
    }

    /**
     * Vybraná místa stromu: stejný filtr stavů a hledání jako seznam, bez
     * stránkování a bez řazení — sourozence řadí compareTreeRows(). Řádek
     * nad strop je detekce „příliš mnoho“ (D25).
     */
    public function selectTreeRows(?string $search, array $filters): array
    {
        [$where, $params] = $this->filterWhere($search, $filters);
        $maxNodes = (int) ($this->getTreeOptions()['maxNodes'] ?? self::TREE_MAX_NODES);

        return $this->db->fetchAll($this->baseSelect() . $where . ' LIMIT ' . ($maxNodes + 1), ...$params);
    }

    /** Předci jako kontext (D21) — v jakémkoli stavu, filtr se na ně nevztahuje. */
    public function selectTreeRowsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        return $this->db->fetchAll($this->baseSelect() . ' WHERE p.`id` IN %in', $ids);
    }

    public function compareTreeRows(array $a, array $b): int
    {
        return PlaceTree::compareNodes($a, $b);
    }

    public function renderTreeRow(array $rowData): array
    {
        $id = (int) $rowData['id'];
        $docState = (int) ($rowData['docState'] ?? 10);
        $type = (string) ($rowData['kind_type'] ?? '');

        $name = [['text' => (string) ($rowData['name'] ?? '')]];
        $badge = $this->rowStateTag($docState);
        if ($badge !== null) {
            $name[] = $badge;
        }

        $row = [
            'id'    => $id,
            'cells' => [
                'name' => $name,
                'code' => !empty($rowData['code']) ? (string) $rowData['code'] : null,
                'kind' => !empty($rowData['kind_name']) ? (string) $rowData['kind_name'] : null,
            ],
            'stateStyle' => $this->stateStyleOf($docState),
        ];

        $icon = $this->kindIcon($rowData['kind_icon'] ?? null, $type);
        if ($icon !== null) {
            $row['icon'] = $icon;
        }
        $action = $this->addChildAction($id, $type, $docState);
        if ($action !== null) {
            $row['rowAction'] = $action;
        }

        return $row;
    }

    /**
     * Akce Přidat podřízené (D10) pro řádek stromu i detail; null, když pod
     * místo nic nesmí (typ nejnižší úrovně) nebo místo není v živém stavu
     * — pod archivované ani smazané místo se nové založit nedá.
     *
     * @return array<string, mixed>|null
     */
    private function addChildAction(int $id, string $type, int $docState): ?array
    {
        if (!in_array($docState, PlaceDocument::LIVE_STATES, true)) {
            return null;
        }
        $preset = $this->childPreset($id, $type);
        if ($preset === null) {
            return null;
        }
        return [
            'id'      => self::ACTION_ADD_CHILD,
            'label'   => $this->text('action.addChild', 'Add child place'),
            'icon'    => 'add',
            'variant' => 'secondary',
            'kind'    => 'open_form',
            'target'  => ['table' => $this->table, 'preset' => $preset],
        ];
    }

    /**
     * Předvyplnění formuláře podřízeného místa (D23): nadřazené místo
     * a navržený druh. Null = pod místo tohoto typu nic nesmí.
     *
     * Druh: druh posledního podřízeného místa mimo koš (další kancelář na
     * patře je nejspíš zase kancelář), pokud je živý. Jinak výchozí
     * podřízený typ místa a z něj seedový druh, bez něj první živý druh
     * typu podle názvu. Bez vhodného druhu se předvyplní jen nadřazené
     * místo.
     *
     * @return array{parent: int, kind?: int}|null
     */
    protected function childPreset(int $id, string $type): ?array
    {
        if (!$this->types()->canHaveChildren($type)) {
            return null;
        }

        $preset = ['parent' => $id];
        $kind = $this->siblingKind($id) ?? $this->defaultChildKind($type);
        if ($kind !== null) {
            $preset['kind'] = $kind;
        }
        return $preset;
    }

    /** Druh posledního podřízeného místa (podle pořadí stromu), když je živý. */
    private function siblingKind(int $id): ?int
    {
        $children = $this->tree()->children($id);
        if ($children === []) {
            return null;
        }
        $kindId = (int) ($children[count($children) - 1]['kind'] ?? 0);
        foreach ($this->liveKinds() as $kind) {
            if ((int) $kind['id'] === $kindId) {
                return $kindId;
            }
        }
        return null;
    }

    /** Seedový druh výchozího podřízeného typu, bez něj první živý druh typu podle názvu. */
    private function defaultChildKind(string $type): ?int
    {
        $childType = $this->types()->defaultChildType($type);
        if ($childType === null) {
            return null;
        }
        $first = null;
        foreach ($this->liveKinds() as $kind) {
            if ((string) ($kind['type'] ?? '') !== $childType) {
                continue;
            }
            if ((string) ($kind['system_code'] ?? '') === $childType) {
                return (int) $kind['id'];
            }
            $first ??= (int) $kind['id'];
        }
        return $first;
    }

    /** @return list<array<string, mixed>> */
    private function liveKinds(): array
    {
        return $this->liveKinds ??= $this->loadLiveKinds();
    }

    /**
     * Živé druhy míst podle názvu — jedním dotazem (přepsatelné v testech).
     *
     * @return list<array<string, mixed>> `{id, type, system_code}`
     */
    protected function loadLiveKinds(): array
    {
        return $this->db->fetchAll(
            'SELECT `id`, `type`, `system_code` FROM `' . PlaceKindDocument::TABLE . '`'
            . ' WHERE `docState` IN %in ORDER BY `name` ASC, `id` ASC',
            PlaceKindDocument::LIVE_STATES,
        );
    }

    /** Společný SELECT seznamu i stromu: místo s názvem, typem a ikonou druhu. */
    private function baseSelect(): string
    {
        return 'SELECT p.`id`, p.`parent`, p.`name`, p.`short_name`, p.`code`, p.`order_pos`, p.`kind`,'
            . ' p.`docState`, p.`docStateMain`,'
            . ' k.`name` AS `kind_name`, k.`type` AS `kind_type`, k.`icon` AS `kind_icon`'
            . ' FROM `' . $this->table . '` p'
            . ' LEFT JOIN `' . PlaceKindDocument::TABLE . '` k ON k.`id` = p.`kind`';
    }

    /**
     * Filtr stavů a hledání v názvu, zkráceném názvu a kódu.
     *
     * @param list<array{id: string, value: mixed}> $filters
     * @return array{0: string, 1: list<mixed>} [' WHERE …' nebo '', params]
     */
    private function filterWhere(?string $search, array $filters): array
    {
        [$conditions, $params] = $this->viewGroupCondition($filters, 'p');

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = SearchCondition::anyContains(['p.`name`', 'p.`short_name`', 'p.`code`'], $search);
            $conditions[] = $searchSql;
            $params = array_merge($params, $searchParams);
        }

        return [$conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '', $params];
    }

    public function renderRow(array $rowData): array
    {
        $id = (int) $rowData['id'];
        $docState = (int) ($rowData['docState'] ?? 10);
        $row = [
            'id' => $id,
            't1' => (string) ($rowData['name'] ?? ''),
            'i1' => !empty($rowData['code']) ? (string) $rowData['code'] : null,
        ];

        $t2 = [];
        $where = PlacesLookup::describe($this->tree()->pathText($id), (string) ($rowData['kind_name'] ?? ''));
        if ($where !== '') {
            $t2[] = ['text' => $where];
        }
        $badge = $this->rowStateTag($docState);
        if ($badge !== null) {
            $t2[] = $badge;
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        $icon = $this->kindIcon($rowData['kind_icon'] ?? null, $rowData['kind_type'] ?? null);
        if ($icon !== null) {
            $row['icon'] = $icon;
        }
        $row['stateStyle'] = $this->stateStyleOf($docState);

        return $row;
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT p.*, k.`name` AS `kind_name`, k.`type` AS `kind_type`, k.`icon` AS `kind_icon`'
            . ' FROM `' . $this->table . '` p'
            . ' LEFT JOIN `' . PlaceKindDocument::TABLE . '` k ON k.`id` = p.`kind`'
            . ' WHERE p.`id` = %i',
            $recordId,
        );
    }

    protected function detailHeader(array $record): DetailHeader
    {
        $id = (int) ($record['id'] ?? 0);
        $name = trim((string) ($record['name'] ?? ''));
        $subtitle = PlacesLookup::describe($this->tree()->pathText($id), (string) ($record['kind_name'] ?? ''));

        return new DetailHeader(
            $name !== '' ? $name : '#' . $id,
            $subtitle !== '' ? $subtitle : null,
            $this->kindIcon($record['kind_icon'] ?? null, $record['kind_type'] ?? null),
        );
    }

    protected function detailTabs(array $record): array
    {
        $id = (int) ($record['id'] ?? 0);

        $identity = [];
        $this->addItem($identity, $this->columnLabel('code', 'Code'), $record['code'] ?? null);
        $shortName = trim((string) ($record['short_name'] ?? ''));
        if ($shortName !== trim((string) ($record['name'] ?? ''))) {
            $this->addItem($identity, $this->columnLabel('short_name', 'Short name'), $shortName);
        }
        $this->addItem($identity, $this->columnLabel('order_pos', 'Order'), $record['order_pos'] ?? null);

        $note = [];
        $this->addItem($note, $this->columnLabel('note', 'Note'), $record['note'] ?? null);

        $overview = $this->propertiesTab([
            ['title' => $this->sharedText('groupIdentity', 'Identity'), 'items' => $identity],
            ['title' => $this->text('group.address', 'Address'), 'items' => $this->addressItems($record)],
            ['title' => $this->columnLabel('note', 'Note'), 'items' => $note, 'wide' => true],
        ]);

        $children = $this->childrenTable($id);
        if ($children !== null) {
            $overview['content'] = [
                'type'   => 'composite',
                'blocks' => [
                    $overview['content'],
                    ['type' => 'heading', 'text' => $this->text('heading.children', 'Child places ({count})', ['count' => count($children['rows'])])],
                    $children,
                ],
            ];
        }

        return [$overview];
    }

    /**
     * Akce detailu: Přidat podřízené — tatáž akce jako „+“ na řádku stromu.
     */
    protected function detailActions(array $record): array
    {
        $action = $this->addChildAction(
            (int) ($record['id'] ?? 0),
            (string) ($record['kind_type'] ?? ''),
            (int) ($record['docState'] ?? 0),
        );
        return $action !== null ? [$action] : [];
    }

    /**
     * Adresa místa (D13): jen u typu s adresou a jen vyplněná — řádek
     * adresy ve sdíleném tvaru, země a souřadnice.
     *
     * @param array<string, mixed> $record
     * @return list<array{label: string, value: string}>
     */
    private function addressItems(array $record): array
    {
        $type = (string) ($record['kind_type'] ?? '');
        if ($type === '' || !$this->types()->hasAddress($type)) {
            return [];
        }

        $items = [];
        $this->addItem(
            $items,
            $this->text('label.address', 'Address'),
            AddressFormat::line($record, $this->dsConfig?->getCountry()),
        );
        $country = (string) ($record['country'] ?? '');
        $this->addItem(
            $items,
            $this->columnLabel('country', 'Country'),
            $country !== '' ? $this->cfgLabel('world.base.countries', $country) : null,
        );
        $latitude = trim((string) ($record['latitude'] ?? ''));
        $longitude = trim((string) ($record['longitude'] ?? ''));
        $this->addItem(
            $items,
            $this->text('label.gps', 'GPS'),
            $latitude !== '' && $longitude !== '' ? $latitude . ', ' . $longitude : null,
        );
        return $items;
    }

    /**
     * Tabulka přímých podřízených míst v pořadí stromu; řádek otevírá
     * detail podřízeného místa (open_detail na tentýž viewer). Null bez
     * podřízených — sekce se nekreslí.
     *
     * @return array<string, mixed>|null blok `table`
     */
    private function childrenTable(int $id): ?array
    {
        $children = $this->tree()->children($id);
        if ($children === []) {
            return null;
        }

        $rows = [];
        foreach ($children as $child) {
            $row = [
                'name'    => (string) ($child['name'] ?? ''),
                'code'    => (string) ($child['code'] ?? ''),
                'kind'    => (string) ($child['kind_name'] ?? ''),
                '_action' => [
                    'kind'   => 'open_detail',
                    'target' => [
                        'viewerId' => $this->definition?->id ?? self::VIEWER_ID,
                        'recordId' => (int) $child['id'],
                    ],
                ],
            ];
            if ((int) ($child['docState'] ?? 0) === self::STATE_ARCHIVED) {
                $row['_class'] = 'muted';
            }
            $rows[] = $row;
        }

        return [
            'type'    => 'table',
            'columns' => [
                ['id' => 'name', 'label' => $this->columnLabel('name', 'Name'), 'link' => true],
                ['id' => 'code', 'label' => $this->columnLabel('code', 'Code')],
                ['id' => 'kind', 'label' => $this->columnLabel('kind', 'Kind')],
            ],
            'rows' => $rows,
        ];
    }

    /** Strom míst, jednou za požadavek (přepsatelné v testech). */
    protected function tree(): PlaceTree
    {
        return $this->tree ??= new PlaceTree($this->db);
    }
}
