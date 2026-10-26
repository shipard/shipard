<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Viewer účtů saldokont (economy_accbal_balance_accounts) — řádek per účet
 * ve skupině. Vyhledání per číslo účtu / poznámka, sloupec se skupinou,
 * filtr na skupinu (z detailu skupiny lze odkázat na filtrovaný seznam).
 *
 * Vzor: CashDesksViewer.
 */
class BalanceAccountsViewer extends TableViewer
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
        $sql = 'SELECT a.`id`, a.`account_number`, a.`acc_side`, a.`amounts_sign`,'
            . ' a.`bal_side`, a.`modify_sign`, a.`note`, a.`sort_order`,'
            . ' a.`docState`, a.`docStateMain`, b.`name` AS balance_name'
            . ' FROM `' . $this->table . '` a'
            . ' LEFT JOIN `economy_accbal_balances` b ON b.`id` = a.`balance`';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        foreach ($filters as $filter) {
            $id = $filter['id'] ?? null;
            $value = $filter['value'] ?? null;
            if ($id === 'viewGroup') {
                $viewGroup = (string) $value;
            } elseif ($id === 'balance' && $value !== null && $value !== '') {
                $conditions[] = 'a.`balance` = %i';
                $params[] = (int) $value;
            }
        }

        if ($viewGroup !== 'all') {
            [$vgSql, $vgParams] = $this->buildViewGroupFilter($this->docStatesCfgItem, $viewGroup);
            if ($vgSql !== '') {
                // JOIN na balances: obě tabulky mají `docState`, helper emituje
                // holý sloupec → kvalifikovat na alias účtů.
                $conditions[] = str_replace('`docState`', 'a.`docState`', $vgSql);
                $params = array_merge($params, $vgParams);
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(
                ['a.account_number', 'a.note', 'b.name'],
                $search,
            );
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY a.`docStateMain` ASC, b.`name` ASC, a.`sort_order` ASC, a.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $row = [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['account_number'] ?? ''),
            'i1' => $rowData['balance_name'] ?? null,
        ];

        $t2 = [];
        $side = $this->enumLabel('economy.accbal.accSides', $rowData['acc_side'] ?? null);
        if ($side !== null) {
            $t2[] = ['text' => $side, 'class' => 'muted'];
        }
        $role = $this->enumLabel('economy.accbal.balSides', $rowData['bal_side'] ?? null);
        if ($role !== null) {
            $t2[] = ['text' => $role, 'class' => 'muted'];
        }
        $amounts = $this->enumLabel('economy.accbal.amountsSigns', $rowData['amounts_sign'] ?? null);
        if ($amounts !== null) {
            $t2[] = ['text' => $amounts, 'class' => 'muted'];
        }
        if ((int) ($rowData['modify_sign'] ?? 0) === 1) {
            $t2[] = ['text' => '×−1', 'class' => 'warning'];
        }

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
        if (!empty($rowData['note'])) {
            $row['t3'] = (string) $rowData['note'];
        }
        $row['stateStyle'] = $stateStyle;

        return $row;
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT a.*, b.`name` AS balance_name'
            . ' FROM `' . $this->table . '` a'
            . ' LEFT JOIN `economy_accbal_balances` b ON b.`id` = a.`balance`'
            . ' WHERE a.`id` = %i',
            $recordId,
        );
    }

    protected function detailTabs(array $record): array
    {
        $cs = $this->language === 'cs';

        $items = [];
        // Číslo účtu je titulkem (#140 D26).
        $this->addItem($items, $cs ? 'Saldokonto' : 'Balance', $record['balance_name'] ?? null);
        $this->addItem($items, $cs ? 'Strana' : 'Side', $this->enumLabel('economy.accbal.accSides', $record['acc_side'] ?? null));
        $this->addItem($items, $cs ? 'Částky' : 'Amounts', $this->enumLabel('economy.accbal.amountsSigns', $record['amounts_sign'] ?? null));
        $this->addItem($items, $cs ? 'Předpis/Úhrada' : 'Role', $this->enumLabel('economy.accbal.balSides', $record['bal_side'] ?? null));
        $this->addItem($items, $cs ? 'Obrátit znaménko' : 'Invert sign', (int) ($record['modify_sign'] ?? 0) === 1 ? ($cs ? 'Ano' : 'Yes') : ($cs ? 'Ne' : 'No'));
        $this->addItem($items, $cs ? 'Poznámka' : 'Note', $record['note'] ?? null);
        $this->addItem($items, $cs ? 'Platnost od' : 'Valid from', $this->formatDate($record['valid_from'] ?? null));
        $this->addItem($items, $cs ? 'Platnost do' : 'Valid to', $this->formatDate($record['valid_to'] ?? null));
        $this->addItem($items, $cs ? 'Pořadí' : 'Order', (string) ($record['sort_order'] ?? 0));

        return [$this->propertiesTab([['title' => $cs ? 'Účet saldokonta' : 'Balance account', 'items' => $items]])];
    }

    public function getFilters(): array
    {
        $cs = $this->language === 'cs';

        $options = [];
        $balances = $this->db->fetchAll(
            'SELECT `id`, `name` FROM `economy_accbal_balances`'
            . ' WHERE `docState` != 90 ORDER BY `sort_order` ASC, `name` ASC',
        );
        foreach ($balances as $b) {
            $options[] = ['value' => (int) $b['id'], 'label' => (string) $b['name']];
        }

        return [
            [
                'id'      => 'balance',
                'label'   => $cs ? 'Saldokonto' : 'Balance',
                'type'    => 'select',
                'options' => $options,
            ],
        ];
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
