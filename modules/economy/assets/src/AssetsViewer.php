<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Database\SearchCondition;

/**
 * Viewer karet majetku (tasks/assets-phase1.md → UI).
 *
 * Spodní taby (TableViewer::getBottomTabs): Vše / per druh z cfgItem
 * (id = klíč druhu, Přidat předvyplní druh) / Cizí (is_foreign). Filtry
 * pravého panelu: Typ, Účetní skupina. Fulltext: inventární číslo, název,
 * zkrácený název. ViewGroups ze stavů — V archívu = vyřazené.
 */
class AssetsViewer extends AssetsViewerBase
{
    public const TAB_ALL = 'all';
    public const TAB_FOREIGN = 'foreign';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT a.`id`, a.`asset_number`, a.`name`, a.`short_name`, a.`category`,'
            . ' a.`is_foreign`, a.`acquired_date`, a.`disposed_date`, a.`price`,'
            . ' a.`docState`, a.`docStateMain`,'
            . ' t.`name` AS `type_name`, g.`code` AS `group_code`, p.`full_name` AS `owner_name`'
            . ' FROM `' . $this->table . '` a'
            . ' LEFT JOIN `economy_assets_types` t ON t.`id` = a.`asset_type`'
            . ' LEFT JOIN `economy_assets_accounting_groups` g ON g.`id` = a.`accounting_group`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = a.`owner`';

        [$conditions, $params] = $this->viewGroupCondition($filters, 'a');

        $bottomTab = self::TAB_ALL;
        foreach ($filters as $filter) {
            $id    = $filter['id'] ?? null;
            $value = $filter['value'] ?? null;
            if ($id === 'bottomTab') {
                $bottomTab = (string) $value;
            } elseif ($id === 'asset_type' && ctype_digit((string) $value) && (int) $value > 0) {
                $conditions[] = 'a.`asset_type` = %i';
                $params[] = (int) $value;
            } elseif ($id === 'accounting_group' && ctype_digit((string) $value) && (int) $value > 0) {
                $conditions[] = 'a.`accounting_group` = %i';
                $params[] = (int) $value;
            }
        }

        if ($bottomTab === self::TAB_FOREIGN) {
            $conditions[] = 'a.`is_foreign` = 1';
        } elseif ($bottomTab !== self::TAB_ALL && isset($this->categories()->all()[$bottomTab])) {
            $conditions[] = 'a.`category` = %s';
            $params[] = $bottomTab;
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = SearchCondition::anyContains(
                ['a.`asset_number`', 'a.`name`', 'a.`short_name`'],
                $search,
            );
            $conditions[] = $searchSql;
            $params = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY a.`docStateMain` ASC, a.`asset_number` ASC, a.`name` ASC, a.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    /**
     * @return list<array{id: string, label: string, newRecordDefaults?: array<string, mixed>}>
     */
    public function getBottomTabs(): array
    {
        $tabs = [[
            'id'    => self::TAB_ALL,
            'label' => $this->cs() ? 'Vše' : 'All',
        ]];

        foreach ($this->categories()->all() as $key => $entry) {
            $tabs[] = [
                'id'                => (string) $key,
                'label'             => (string) ($entry['name'] ?? $key),
                'newRecordDefaults' => ['category' => (string) $key],
            ];
        }

        $tabs[] = [
            'id'                => self::TAB_FOREIGN,
            'label'             => $this->cs() ? 'Cizí' : 'Foreign',
            'newRecordDefaults' => ['is_foreign' => 1],
        ];

        return $tabs;
    }

    public function getFilters(): array
    {
        return [
            [
                'id'      => 'asset_type',
                'label'   => $this->cs() ? 'Typ' : 'Type',
                'type'    => 'select',
                'options' => $this->codebookOptions('economy_assets_types', '`name`'),
            ],
            [
                'id'      => 'accounting_group',
                'label'   => $this->cs() ? 'Účetní skupina' : 'Accounting group',
                'type'    => 'select',
                'options' => $this->codebookOptions('economy_assets_accounting_groups', "CONCAT(`code`, ' — ', `name`)"),
            ],
        ];
    }

    public function renderRow(array $rowData): array
    {
        $categories = $this->categories();
        $category   = (string) ($rowData['category'] ?? '');
        $docState   = (int) ($rowData['docState'] ?? 10);

        $row = [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['name'] ?? ''),
            'i1' => !empty($rowData['asset_number']) ? (string) $rowData['asset_number'] : null,
        ];

        $t2 = [];
        if (!empty($rowData['type_name'])) {
            $t2[] = ['text' => (string) $rowData['type_name']];
        }
        if ($category !== '') {
            $t2[] = [
                'text'  => $categories->label($category),
                'class' => $categories->isLongTerm($category) ? 'primary' : 'muted',
            ];
        }
        if (!empty($rowData['is_foreign'])) {
            $owner = trim((string) ($rowData['owner_name'] ?? ''));
            $t2[] = [
                'text'  => ($this->cs() ? 'Cizí' : 'Foreign') . ($owner !== '' ? ' · ' . $owner : ''),
                'class' => 'warning',
            ];
        }
        $badge = $this->stateBadge($docState);
        if ($badge !== null) {
            $t2[] = $badge;
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        $i2 = [];
        $acquired = $this->formatDate($rowData['acquired_date'] ?? null);
        if ($acquired !== null) {
            $i2[] = ['text' => $acquired, 'class' => 'muted'];
        }
        if ($category !== '' && !$categories->isLongTerm($category)) {
            $price = $this->formatAmount($rowData['price'] ?? null);
            if ($price !== null) {
                $i2[] = ['text' => $price, 'class' => 'amount'];
            }
        }
        $row['i2'] = $i2 !== [] ? $i2 : null;

        if (!empty($rowData['short_name'])) {
            $row['t3'] = (string) $rowData['short_name'];
        }

        $row['stateStyle'] = $this->stateStyleOf($docState);

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow(
            'SELECT a.*, t.`name` AS `type_name`, g.`code` AS `group_code`, g.`name` AS `group_name`,'
            . ' p.`full_name` AS `owner_name`'
            . ' FROM `' . $this->table . '` a'
            . ' LEFT JOIN `economy_assets_types` t ON t.`id` = a.`asset_type`'
            . ' LEFT JOIN `economy_assets_accounting_groups` g ON g.`id` = a.`accounting_group`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = a.`owner`'
            . ' WHERE a.`id` = %i',
            $recordId,
        );
        if ($record === null) {
            return ['tabs' => []];
        }

        $categories = $this->categories();
        $category   = (string) ($record['category'] ?? '');
        $longTerm   = $category !== '' && $categories->isLongTerm($category);
        $tracking   = $this->config?->cfgItem('economy.assets.trackingKinds') ?? [];
        $cs = $this->cs();

        $identity = [];
        $this->addItem($identity, $cs ? 'Inventární číslo' : 'Inventory number', $record['asset_number'] ?? null);
        $this->addItem($identity, $cs ? 'Název' : 'Name', $record['name'] ?? null);
        $this->addItem($identity, $cs ? 'Zkrácený název' : 'Short name', $record['short_name'] ?? null);

        $classification = [];
        $this->addItem($classification, $cs ? 'Typ' : 'Type', $record['type_name'] ?? null);
        $this->addItem($classification, $cs ? 'Druh' : 'Category', $category !== '' ? $categories->label($category) : null);
        $trackingKey = (string) ($record['tracking'] ?? '');
        $this->addItem(
            $classification,
            $cs ? 'Způsob sledování' : 'Tracking',
            $trackingKey !== '' ? (string) ($tracking[$trackingKey]['name'] ?? $trackingKey) : null,
        );
        $group = trim(((string) ($record['group_code'] ?? '')) . ' — ' . ((string) ($record['group_name'] ?? '')), ' —');
        $this->addItem($classification, $cs ? 'Účetní skupina' : 'Accounting group', $group);

        $ownership = [];
        $this->addItem($ownership, $cs ? 'Cizí majetek' : 'Foreign asset', $this->yesNo($record['is_foreign'] ?? 0));
        if (!empty($record['is_foreign'])) {
            $this->addItem($ownership, $cs ? 'Vlastník' : 'Owner', $record['owner_name'] ?? null);
        }

        $lifecycle = [];
        $this->addItem($lifecycle, $cs ? 'Datum pořízení' : 'Acquired', $this->formatDate($record['acquired_date'] ?? null));
        $this->addItem($lifecycle, $cs ? 'Datum vyřazení' : 'Disposed', $this->formatDate($record['disposed_date'] ?? null));
        if (!$longTerm) {
            $this->addItem($lifecycle, $cs ? 'Cena' : 'Price', $this->formatAmount($record['price'] ?? null));
        }

        $note = [];
        $this->addItem($note, $cs ? 'Poznámka' : 'Note', $record['note'] ?? null);

        return $this->overviewDetail([
            ['title' => $cs ? 'Identifikace' : 'Identity', 'items' => $identity],
            ['title' => $cs ? 'Zařazení' : 'Classification', 'items' => $classification],
            ['title' => $cs ? 'Vlastnictví' : 'Ownership', 'items' => $ownership],
            ['title' => $cs ? 'Pořízení a vyřazení' : 'Acquisition and disposal', 'items' => $lifecycle],
            ['title' => $cs ? 'Poznámka' : 'Note', 'items' => $note],
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    protected function categories(): AssetCategories
    {
        return new AssetCategories($this->config);
    }

    /**
     * Options filtru z živých záznamů číselníku (10/40/80).
     *
     * @return list<array{value: int, label: string}>
     */
    private function codebookOptions(string $table, string $labelExpr): array
    {
        $rows = $this->db->fetchAll(
            'SELECT `id`, ' . $labelExpr . ' AS `label` FROM `' . $table . '`'
            . ' WHERE `docState` IN (10, 40, 80) ORDER BY `sort_order` ASC, `label` ASC',
        );
        $options = [];
        foreach ($rows as $row) {
            $options[] = ['value' => (int) $row['id'], 'label' => (string) $row['label']];
        }
        return $options;
    }
}
