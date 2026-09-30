<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

/** Viewer účetních skupin majetku (Nastavení → Majetek). */
class AccountingGroupsViewer extends AssetsViewerBase
{
    /** Sloupec účtu → alias JOINu. */
    private const ACCOUNT_ALIASES = [
        'account_asset'        => 'aa',
        'account_acquisition'  => 'ac',
        'account_accumulated'  => 'ad',
        'account_depreciation' => 'ae',
        'account_disposal'     => 'af',
    ];

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT g.`id`, g.`code`, g.`name`, g.`note`, g.`sort_order`, g.`docState`, g.`docStateMain`'
            . $this->accountSelects()
            . ' FROM `' . $this->table . '` g'
            . $this->accountJoins();

        [$conditions, $params] = $this->viewGroupCondition($filters, 'g');

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(['code', 'name'], $search);
            $conditions[] = str_replace('`code`', 'g.`code`', str_replace('`name`', 'g.`name`', $searchSql));
            $params = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY g.`docStateMain` ASC, g.`sort_order` ASC, g.`code` ASC, g.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? 10);
        $row = [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['name'] ?? ''),
            'i1' => (string) ($rowData['code'] ?? ''),
        ];

        $t2 = [];
        foreach (self::ACCOUNT_ALIASES as $column => $alias) {
            $number = (string) ($rowData[$column . '_number'] ?? '');
            if ($number !== '') {
                $t2[] = ['text' => $number, 'class' => $column === 'account_asset' ? 'primary' : 'muted'];
            }
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
            'SELECT g.*' . $this->accountSelects() . ' FROM `' . $this->table . '` g' . $this->accountJoins()
            . ' WHERE g.`id` = %i',
            $recordId,
        );
        if ($record === null) {
            return ['tabs' => []];
        }

        $identity = [];
        $this->addItem($identity, $this->text('label.code', 'Code'), $record['code'] ?? null);
        $this->addItem($identity, $this->text('label.name', 'Name'), $record['name'] ?? null);
        $this->addItem($identity, $this->text('label.note', 'Note'), $record['note'] ?? null);

        $labels = [
            'account_asset'        => $this->text('label.accountAsset', 'Asset account'),
            'account_acquisition'  => $this->text('label.accountAcquisition', 'Acquisition account'),
            'account_accumulated'  => $this->text('label.accountAccumulated', 'Accumulated depreciation account'),
            'account_depreciation' => $this->text('label.accountDepreciation', 'Depreciation account'),
            'account_disposal'     => $this->text('label.accountDisposal', 'Disposal account'),
        ];
        $accounts = [];
        foreach (self::ACCOUNT_ALIASES as $column => $alias) {
            $number = (string) ($record[$column . '_number'] ?? '');
            $name   = (string) ($record[$column . '_name'] ?? '');
            $this->addItem($accounts, $labels[$column], trim($number . ' ' . $name));
        }

        return $this->overviewDetail([
            ['title' => $this->text('group.identity', 'Identity'), 'items' => $identity],
            ['title' => $this->text('group.accounts', 'Accounts'), 'items' => $accounts],
        ]);
    }

    private function accountSelects(): string
    {
        $parts = [];
        foreach (self::ACCOUNT_ALIASES as $column => $alias) {
            $parts[] = $alias . '.`number` AS `' . $column . '_number`';
            $parts[] = $alias . '.`name` AS `' . $column . '_name`';
        }
        return ', ' . implode(', ', $parts);
    }

    private function accountJoins(): string
    {
        $sql = '';
        foreach (self::ACCOUNT_ALIASES as $column => $alias) {
            $sql .= ' LEFT JOIN `economy_accounting_accounts` ' . $alias . ' ON ' . $alias . '.`id` = g.`' . $column . '`';
        }
        return $sql;
    }
}
