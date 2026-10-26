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
        $cs = $this->language === 'cs';

        $blocks = [
            $this->identityBlock($record, $cs),
            $this->accountsTable((int) $record['id'], $cs),
        ];

        return [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => ['type' => 'composite', 'blocks' => $blocks],
        ]];
    }

    /** Properties blok — Identifikace skupiny. */
    private function identityBlock(array $record, bool $cs): array
    {
        $items = [];
        $this->addItem($items, $cs ? 'Kód' : 'Code', $record['code'] ?? null);
        $this->addItem($items, $cs ? 'Název' : 'Name', $record['name'] ?? null);
        $this->addItem($items, $cs ? 'Zkrácený název' : 'Short name', $record['short_name'] ?? null);
        $this->addItem($items, $cs ? 'Platnost od' : 'Valid from', $this->formatDate($record['valid_from'] ?? null));
        $this->addItem($items, $cs ? 'Platnost do' : 'Valid to', $this->formatDate($record['valid_to'] ?? null));
        $this->addItem($items, $cs ? 'Pořadí' : 'Order', (string) ($record['sort_order'] ?? 0));

        return [
            'type'   => 'properties',
            'groups' => [['title' => $cs ? 'Identifikace' : 'Identity', 'items' => $items]],
        ];
    }

    /** Vnořená tabulka účtů skupiny — ekvivalent „Nastavení účtů". */
    private function accountsTable(int $balanceId, bool $cs): array
    {
        $rows = $this->db->fetchAll(
            'SELECT `account_number`, `acc_side`, `amounts_sign`, `bal_side`, `modify_sign`, `note`'
            . ' FROM `economy_accbal_balance_accounts`'
            . ' WHERE `balance` = %i AND `docState` != 90'
            . ' ORDER BY `sort_order` ASC, `id` ASC',
            $balanceId,
        );

        $columns = [
            ['id' => 'account', 'label' => $cs ? 'Účet' : 'Account'],
            ['id' => 'side',    'label' => $cs ? 'Strana' : 'Side'],
            ['id' => 'amounts', 'label' => $cs ? 'Částky' : 'Amounts'],
            ['id' => 'role',    'label' => $cs ? 'Předpis/Úhrada' : 'Role'],
            ['id' => 'invert',  'label' => '×−1', 'align' => 'center'],
            ['id' => 'note',    'label' => $cs ? 'Poznámka' : 'Note'],
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

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return (string) $value;
    }
}
