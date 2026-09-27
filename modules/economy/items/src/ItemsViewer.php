<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class ItemsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    private const STATE_SPAN_CLASS = [
        'concept'   => 'warning',
        'confirmed' => 'primary',
        'done'      => 'success',
        'edit'      => 'warning',
        'archive'   => 'muted',
        'trash'     => 'muted',
        'cancelled' => 'danger',
    ];

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT i.`id`, i.`code`, i.`name`, i.`description`, i.`item_kind`, i.`item_type`,'
            . ' i.`unit`, i.`sales_price_no_vat`, i.`docState`, i.`docStateMain`,'
            . ' k.`name` AS kind_name, u.`shortcut` AS unit_shortcut'
            . ' FROM `' . $this->table . '` i'
            . ' LEFT JOIN `economy_items_kinds` k ON k.`id` = i.`item_kind`'
            . ' LEFT JOIN `core_units` u ON u.`id` = i.`unit`';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        foreach ($filters as $filter) {
            if ($filter['id'] === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
            }
        }

        if ($viewGroup !== 'all' && $this->docStatesCfgItem !== null && $this->config !== null) {
            $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
            $states = $cfg->getViewGroupStates($viewGroup);
            if ($states !== []) {
                $placeholders = implode(', ', array_fill(0, count($states), '%i'));
                $conditions[] = 'i.`docState` IN (' . $placeholders . ')';
                $params = array_merge($params, $states);
            } elseif ($viewGroup !== 'active') {
                $conditions[] = '1=0';
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = SearchCondition::anyContains([
                'i.`name`', 'i.`code`', 'i.`description`',
            ], $search);
            $conditions[] = $searchSql;
            $params = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY i.`docStateMain` ASC, i.`name` ASC, i.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? 10);
        $stateStyle = $this->resolveStateStyle($docState);

        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => (string) ($rowData['name'] ?? ''),
            'i1'         => $rowData['code'] ?? null,
            'stateStyle' => $stateStyle,
        ];

        $t2 = [];
        $itemTypeLabel = $this->resolveItemTypeLabel((int) ($rowData['item_type'] ?? 3));
        if ($itemTypeLabel !== '') {
            $t2[] = ['text' => $itemTypeLabel, 'class' => 'muted'];
        }
        if (!empty($rowData['kind_name'])) {
            $t2[] = ['text' => (string) $rowData['kind_name']];
        }
        if ($docState !== 10) {
            $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
            $stateData = $cfg->getState($docState);
            $t2[] = [
                'text'  => $stateData['stateName'] ?? '',
                'class' => self::STATE_SPAN_CLASS[$stateStyle] ?? 'muted',
            ];
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        $price = $rowData['sales_price_no_vat'] ?? null;
        if ($price !== null && $price !== '') {
            $shortcut = (string) ($rowData['unit_shortcut'] ?? '');
            $formatted = number_format((float) $price, 2, ',', ' ') . ' Kč';
            if ($shortcut !== '') {
                $formatted .= ' / ' . $shortcut;
            }
            $row['t3'] = $formatted;
        } else {
            $row['t3'] = null;
        }

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow(
            'SELECT i.*, k.`name` AS kind_name, u.`name` AS unit_name, u.`shortcut` AS unit_shortcut'
            . ' FROM `' . $this->table . '` i'
            . ' LEFT JOIN `economy_items_kinds` k ON k.`id` = i.`item_kind`'
            . ' LEFT JOIN `core_units` u ON u.`id` = i.`unit`'
            . ' WHERE i.`id` = %i',
            $recordId,
        );

        if ($record === null) {
            return ['tabs' => []];
        }

        // Kód a název jsou v hlavičce detailu — skupina Identifikace tu už není.
        $classificationItems = [];
        $this->addItem($classificationItems, 'Druh', $record['kind_name'] ?? null);
        $this->addItem($classificationItems, 'Typ', $this->resolveItemTypeLabel((int) ($record['item_type'] ?? 3)));

        $pricingItems = [];
        $price = $record['sales_price_no_vat'] ?? null;
        if ($price !== null && $price !== '') {
            $this->addItem($pricingItems, 'Cena bez DPH', number_format((float) $price, 2, ',', ' ') . ' Kč');
        }
        $unitName = trim((string) ($record['unit_name'] ?? ''));
        $unitShort = trim((string) ($record['unit_shortcut'] ?? ''));
        $unitLabel = $unitShort !== '' && $unitName !== '' ? "{$unitName} ({$unitShort})" : ($unitName !== '' ? $unitName : $unitShort);
        $this->addItem($pricingItems, 'Jednotka', $unitLabel);

        $detailItems = [];
        $this->addItem($detailItems, 'Popis', $record['description'] ?? null);
        $this->addItem($detailItems, 'Platnost od', $this->formatDate($record['valid_from'] ?? null));
        $this->addItem($detailItems, 'Platnost do', $this->formatDate($record['valid_to'] ?? null));

        $groups = [];
        if ($classificationItems !== []) {
            $groups[] = ['title' => 'Klasifikace', 'items' => $classificationItems];
        }
        if ($pricingItems !== []) {
            $groups[] = ['title' => 'Cena', 'items' => $pricingItems];
        }
        if ($detailItems !== []) {
            $groups[] = ['title' => 'Detaily', 'items' => $detailItems];
        }

        $name = trim((string) ($record['name'] ?? ''));
        $code = trim((string) ($record['code'] ?? ''));

        $badges = [];
        $stateBadge = $this->buildStateBadge((int) ($record['docState'] ?? 10));
        if ($stateBadge !== null) {
            $badges[] = $stateBadge;
        }

        $blocks = [['type' => 'properties', 'groups' => $groups]];

        // Přílohy — velké náhledy / miniatury s přepínáním (attachment-grid)
        $attachments = $this->fetchAttachments((int) $record['id']);
        if ($attachments !== []) {
            $blocks[] = ['type' => 'heading', 'text' => 'Přílohy'];
            $blocks[] = ['type' => 'attachment-grid', 'attachments' => $attachments];
        }

        return [
            'title'    => $name !== '' ? $name : '(bez názvu)',
            'subtitle' => $code !== '' ? 'Kód ' . $code : null,
            'badges'   => $badges,
            // Stejný klíč jako viewers[].icon v module.jsonc.
            'icon'     => 'box',
            'tabs'     => [[
                'id'      => 'overview',
                'label'   => $this->defaultOverviewLabel(),
                'content' => count($blocks) === 1
                    ? $blocks[0]
                    : ['type' => 'composite', 'blocks' => $blocks],
            ]],
        ];
    }

    /**
     * Přílohy položky pro blok `attachment-grid` (AttachmentGrid s
     * přepínáním full/grid na frontendu). Velikost posíláme v bajtech,
     * formátuje frontend (formatFileSize v api/attachments.js).
     *
     * @return array<int, array{id: int, name: string, mime_type: string, file_size: int}>
     */
    private function fetchAttachments(int $recordId): array
    {
        // table_id 311 = economy_items (tables/economy_items.jsonc),
        // stejný vzor jako 303 v IncomingMessagesViewer
        $files = $this->db->fetchAll(
            'SELECT `id`, `name`, `file_name`, `file_size`, `mime_type`'
            . ' FROM `core_attachments_files`'
            . ' WHERE `table_id` = %i AND `record_id` = %i AND `is_deleted` = 0'
            . ' ORDER BY `att_order` ASC, `name` ASC',
            311,
            $recordId,
        );

        $out = [];
        foreach ($files as $f) {
            $out[] = [
                'id'        => (int) $f['id'],
                'name'      => (string) ($f['name'] ?? $f['file_name']),
                'mime_type' => (string) ($f['mime_type'] ?? ''),
                'file_size' => (int) ($f['file_size'] ?? 0),
            ];
        }

        return $out;
    }

    /** Badge stavu záznamu (label + stateStyle) z docState configu. */
    private function buildStateBadge(int $docState): ?array
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return null;
        }

        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        $stateData = $cfg->getState($docState);
        $label = (string) ($stateData['stateName'] ?? '');
        if ($label === '') {
            return null;
        }

        return [
            'label' => $label,
            'style' => (string) ($stateData['stateStyle'] ?? 'concept'),
        ];
    }

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
    }

    private function resolveItemTypeLabel(int $key): string
    {
        if ($this->config !== null) {
            $cfg = $this->config->cfgItem('economy.items.itemTypes');
            if (is_array($cfg) && isset($cfg[(string) $key]['name'])) {
                return (string) $cfg[(string) $key]['name'];
            }
        }
        return match ($key) {
            0 => 'Služba',
            1 => 'Zásoba',
            2 => 'Účetní položka',
            default => 'Ostatní',
        };
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('j. n. Y');
        }
        $ts = is_string($value) ? strtotime($value) : null;
        return $ts !== null && $ts !== false ? date('j. n. Y', $ts) : null;
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
