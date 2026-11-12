<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Viewer saldokont (economy_accbal_balances) — skupiny saldokonta
 * (Pohledávky, Závazky, …). Detail skupiny ukazuje properties + vnořenou
 * tabulku „Nastavení účtů" (řádky economy_accbal_balance_accounts).
 *
 * Vzor: CashDesksViewer (archivní docStates) + DocsHeadsViewer (composite
 * detail s tabulkou).
 */
class BalancesViewer extends TableViewer
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
        $sql = 'SELECT `id`, `code`, `name`, `short_name`, `sort_order`,'
            . ' `valid_from`, `valid_to`, `docState`, `docStateMain`'
            . ' FROM `' . $this->table . '`';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        foreach ($filters as $filter) {
            if (($filter['id'] ?? null) === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
            }
        }

        if ($viewGroup !== 'all') {
            [$vgSql, $vgParams] = $this->buildViewGroupFilter($this->docStatesCfgItem, $viewGroup);
            if ($vgSql !== '') {
                $conditions[] = $vgSql;
                $params = array_merge($params, $vgParams);
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(['code', 'name', 'short_name'], $search);
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY `docStateMain` ASC, `sort_order` ASC, `name` ASC, `id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $row = [
            'id' => (int) $rowData['id'],
            't1' => $rowData['name'] ?? '',
            'i1' => $rowData['code'] ?? null,
        ];

        $t2 = [];

        $docState = (int) ($rowData['docState'] ?? 10);
        $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
        $stateData = $cfg->getState($docState);
        $stateStyle = $stateData['stateStyle'] ?? 'concept';

        if ($docState !== 10) {
            $t2[] = [
                'text'  => $stateData['stateName'] ?? '',
                'class' => self::STATE_SPAN_CLASS[$stateStyle] ?? 'muted',
            ];
        }

        $row['t2'] = $t2 !== [] ? $t2 : null;
        $row['stateStyle'] = $stateStyle;

        return $row;
    }

    /** Přehled: identifikace skupiny a tabulka účtů saldokonta. */
    protected function detailTabs(array $record): array
    {
        $blocks = [
            $this->identityBlock($record),
            $this->accountsTable((int) $record['id']),
        ];

        return [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => ['type' => 'composite', 'blocks' => $blocks],
        ]];
    }

    /** Properties blok — Identifikace skupiny. */
    private function identityBlock(array $record): array
    {
        $items = [];
        $this->addItem($items, $this->columnLabel('code', 'Code'), $record['code'] ?? null);
        // Název je titulkem; zkrácený název jen když se od něj liší (#140 D26, §2).
        $shortName = trim((string) ($record['short_name'] ?? ''));
        if ($shortName !== trim((string) ($record['name'] ?? ''))) {
            $this->addItem($items, $this->columnLabel('short_name', 'Short name'), $shortName);
        }
        $this->addItem($items, $this->columnLabel('valid_from', 'Valid from'), $this->formatDate($record['valid_from'] ?? null));
        $this->addItem($items, $this->columnLabel('valid_to', 'Valid to'), $this->formatDate($record['valid_to'] ?? null));
        $this->addItem($items, $this->columnLabel('sort_order', 'Order'), (string) ($record['sort_order'] ?? 0));

        return [
            'type'   => 'properties',
            'groups' => [['title' => $this->sharedText('groupIdentity', 'Identity'), 'items' => $items]],
        ];
    }

    /** Vnořená tabulka účtů skupiny — ekvivalent „Nastavení účtů". */
    private function accountsTable(int $balanceId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT `account_number`, `acc_side`, `amounts_sign`, `bal_side`, `modify_sign`, `note`'
            . ' FROM `economy_accbal_balance_accounts`'
            . ' WHERE `balance` = %i AND `docState` != 90'
            . ' ORDER BY `sort_order` ASC, `id` ASC',
            $balanceId,
        );

        $accounts = 'economy_accbal_balance_accounts';
        $columns = [
            ['id' => 'account', 'label' => $this->detailText('economy.accbal.viewerDetailLabels', 'account', 'Account')],
            ['id' => 'side',    'label' => $this->columnLabel('acc_side', 'Side', $accounts)],
            ['id' => 'amounts', 'label' => $this->columnLabel('amounts_sign', 'Amounts', $accounts)],
            ['id' => 'role',    'label' => $this->columnLabel('bal_side', 'Role', $accounts)],
            ['id' => 'invert',  'label' => $this->detailText('economy.accbal.viewerDetailLabels', 'invertSign', '×−1'), 'align' => 'center'],
            ['id' => 'note',    'label' => $this->columnLabel('note', 'Note', $accounts)],
        ];

        $tableRows = [];
        foreach ($rows as $r) {
            $r = is_array($r) ? $r : $r->toArray();
            $tableRows[] = [
                'account' => (string) ($r['account_number'] ?? ''),
                'side'    => $this->enumLabel('economy.accbal.accSides', $r['acc_side'] ?? null) ?? '',
                'amounts' => $this->enumLabel('economy.accbal.amountsSigns', $r['amounts_sign'] ?? null) ?? '',
                'role'    => $this->enumLabel('economy.accbal.balSides', $r['bal_side'] ?? null) ?? '',
                'invert'  => (int) ($r['modify_sign'] ?? 0) === 1 ? '✓' : '',
                'note'    => (string) ($r['note'] ?? ''),
            ];
        }

        return ['type' => 'table', 'columns' => $columns, 'rows' => $tableRows];
    }

    /** Lokalizovaný název enumu z cfgItem ({key: {name}}). */
    private function enumLabel(string $cfgItemId, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $items = $this->config?->cfgItem($cfgItemId);
        if (!is_array($items)) {
            return null;
        }
        $entry = $items[(string) (int) $value] ?? null;
        return is_array($entry) ? ($entry['name'] ?? null) : null;
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
