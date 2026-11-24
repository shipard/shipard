<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Places;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Viewer\Detail\DetailHeader;
use Shipard\Module\Base\Persons\Values\AddressFormat;

/**
 * Viewer míst (Nastavení → Místa, #169 D11, D16).
 *
 * Seznam je dočasně plochý (strom přinese task B): název, kód a ve druhém
 * řádku cesta · druh, ikona podle druhu. Cestu ani pořadí neukládáme —
 * počítá je {@see PlaceTree} jednou za požadavek.
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

    private ?PlaceTree $tree = null;

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT p.`id`, p.`name`, p.`short_name`, p.`code`, p.`docState`, p.`docStateMain`,'
            . ' k.`name` AS `kind_name`, k.`type` AS `kind_type`, k.`icon` AS `kind_icon`'
            . ' FROM `' . $this->table . '` p'
            . ' LEFT JOIN `' . PlaceKindDocument::TABLE . '` k ON k.`id` = p.`kind`';

        [$conditions, $params] = $this->viewGroupCondition($filters, 'p');

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = SearchCondition::anyContains(['p.`name`', 'p.`short_name`', 'p.`code`'], $search);
            $conditions[] = $searchSql;
            $params = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY p.`docStateMain` ASC, p.`name` ASC, p.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
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
