<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

/** Viewer typů majetku (Nastavení → Majetek). */
class AssetTypesViewer extends AssetsViewerBase
{
    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT t.`id`, t.`name`, t.`short_name`, t.`default_category`, t.`note`, t.`sort_order`,'
            . ' t.`docState`, t.`docStateMain`,'
            . ' tg.`name` AS `group_name`, g.`code` AS `acc_group_code`'
            . ' FROM `' . $this->table . '` t'
            . ' LEFT JOIN `economy_assets_type_groups` tg ON tg.`id` = t.`type_group`'
            . ' LEFT JOIN `economy_assets_accounting_groups` g ON g.`id` = t.`default_accounting_group`';

        [$conditions, $params] = $this->viewGroupCondition($filters, 't');

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(['name', 'short_name'], $search);
            $conditions[] = str_replace('`name`', 't.`name`', str_replace('`short_name`', 't.`short_name`', $searchSql));
            $params = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY t.`docStateMain` ASC, tg.`sort_order` ASC, tg.`name` ASC, t.`sort_order` ASC, t.`name` ASC, t.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $categories = new AssetCategories($this->config);
        $docState   = (int) ($rowData['docState'] ?? 10);

        $row = [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['name'] ?? ''),
            'i1' => !empty($rowData['short_name']) ? (string) $rowData['short_name'] : null,
        ];

        $t2 = [];
        if (!empty($rowData['group_name'])) {
            $t2[] = ['text' => (string) $rowData['group_name'], 'class' => 'muted'];
        }
        $category = (string) ($rowData['default_category'] ?? '');
        if ($category !== '') {
            $t2[] = ['text' => $categories->label($category), 'class' => 'primary'];
        }
        if (!empty($rowData['acc_group_code'])) {
            $t2[] = ['text' => (string) $rowData['acc_group_code']];
        }
        $badge = $this->stateBadge($docState);
        if ($badge !== null) {
            $t2[] = $badge;
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        if (!empty($rowData['note'])) {
            $row['t3'] = (string) $rowData['note'];
        }
        $row['stateStyle'] = $this->stateStyleOf($docState);

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow(
            'SELECT t.*, tg.`name` AS `group_name`, g.`code` AS `acc_group_code`, g.`name` AS `acc_group_name`'
            . ' FROM `' . $this->table . '` t'
            . ' LEFT JOIN `economy_assets_type_groups` tg ON tg.`id` = t.`type_group`'
            . ' LEFT JOIN `economy_assets_accounting_groups` g ON g.`id` = t.`default_accounting_group`'
            . ' WHERE t.`id` = %i',
            $recordId,
        );
        if ($record === null) {
            return ['tabs' => []];
        }
        $cs = $this->cs();
        $categories = new AssetCategories($this->config);

        $identity = [];
        $this->addItem($identity, $cs ? 'Název' : 'Name', $record['name'] ?? null);
        $this->addItem($identity, $cs ? 'Zkrácený název' : 'Short name', $record['short_name'] ?? null);
        $this->addItem($identity, $cs ? 'Skupina typů' : 'Type group', $record['group_name'] ?? null);
        $this->addItem($identity, $cs ? 'Poznámka' : 'Note', $record['note'] ?? null);

        $defaults = [];
        $category = (string) ($record['default_category'] ?? '');
        $this->addItem($defaults, $cs ? 'Výchozí druh' : 'Default category', $category !== '' ? $categories->label($category) : null);
        $group = trim(((string) ($record['acc_group_code'] ?? '')) . ' — ' . ((string) ($record['acc_group_name'] ?? '')), ' —');
        $this->addItem($defaults, $cs ? 'Výchozí účetní skupina' : 'Default accounting group', $group);

        return $this->overviewDetail([
            ['title' => $cs ? 'Identifikace' : 'Identity', 'items' => $identity],
            ['title' => $cs ? 'Výchozí hodnoty' : 'Defaults', 'items' => $defaults],
        ]);
    }
}
