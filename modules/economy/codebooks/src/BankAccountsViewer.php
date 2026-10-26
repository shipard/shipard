<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class BankAccountsViewer extends TableViewer
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
        $sql = 'SELECT `id`, `code`, `name`, `notice`, `bank_name`, `account_number`, `iban`, `bic`,'
            . ' `currency`, `is_default`, `valid_from`, `valid_to`, `sort_order`,'
            . ' `docState`, `docStateMain`'
            . ' FROM `' . $this->table . '`';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        foreach ($filters as $filter) {
            if ($filter['id'] === 'viewGroup') {
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
            [$searchSql, $searchParams] = $this->buildSearchCondition(
                ['code', 'name', 'bank_name', 'account_number', 'iban'],
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

        $accountDisplay = $this->formatAccountDisplay($rowData);
        if ($accountDisplay !== null) {
            $row['t2'] = $accountDisplay;
        }

        $t3 = [];

        if (!empty($rowData['bank_name'])) {
            $t3[] = ['text' => (string) $rowData['bank_name']];
        }

        if (!empty($rowData['currency'])) {
            $t3[] = ['text' => strtoupper((string) $rowData['currency'])];
        }

        if (!empty($rowData['is_default'])) {
            $t3[] = ['text' => 'Výchozí', 'class' => 'primary'];
        }

        $validFrom = $this->formatDate($rowData['valid_from'] ?? null);
        $validTo = $this->formatDate($rowData['valid_to'] ?? null);
        if ($validFrom !== null && $validTo !== null) {
            $t3[] = ['text' => $validFrom . ' – ' . $validTo, 'class' => 'muted'];
        } elseif ($validFrom !== null) {
            $t3[] = ['text' => 'od ' . $validFrom, 'class' => 'muted'];
        } elseif ($validTo !== null) {
            $t3[] = ['text' => 'do ' . $validTo, 'class' => 'muted'];
        }

        $docState = (int) ($rowData['docState'] ?? 10);
        $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
        $stateData = $cfg->getState($docState);
        $stateStyle = $stateData['stateStyle'] ?? 'concept';

        if ($docState !== 10) {
            $t3[] = [
                'text'  => $stateData['stateName'] ?? '',
                'class' => self::STATE_SPAN_CLASS[$stateStyle] ?? 'muted',
            ];
        }

        if ($t3 !== []) {
            $row['t3'] = $t3;
        }

        $row['stateStyle'] = $stateStyle;

        return $row;
    }

    protected function detailTabs(array $record): array
    {
        return [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => $this->buildOverviewContent($record),
        ]];
    }

    private function buildOverviewContent(array $record): array
    {
        $identityItems = [];
        $this->addItem($identityItems, 'Kód', $record['code'] ?? null);
        $this->addItem($identityItems, 'Název', $record['name'] ?? null);
        $this->addItem($identityItems, 'Poznámka', $record['notice'] ?? null);

        $accountItems = [];
        $this->addItem($accountItems, 'Název banky', $record['bank_name'] ?? null);
        $this->addItem($accountItems, 'Číslo účtu', $record['account_number'] ?? null);
        $this->addItem($accountItems, 'IBAN', $record['iban'] ?? null);
        $this->addItem($accountItems, 'BIC/SWIFT', $record['bic'] ?? null);

        $settingsItems = [];
        $this->addItem(
            $settingsItems,
            'Měna',
            !empty($record['currency']) ? strtoupper((string) $record['currency']) : null,
        );
        $this->addItem($settingsItems, 'Výchozí pro měnu', !empty($record['is_default']) ? 'Ano' : 'Ne');
        $this->addItem($settingsItems, 'Platnost od', $this->formatDate($record['valid_from'] ?? null));
        $this->addItem(
            $settingsItems,
            'Platnost do',
            $this->formatDate($record['valid_to'] ?? null) ?? 'bez konce',
        );
        $this->addItem($settingsItems, 'Pořadí', (string) ($record['sort_order'] ?? 0));

        $groups = [];
        if ($identityItems !== []) {
            $groups[] = ['title' => 'Identifikace', 'items' => $identityItems];
        }
        if ($accountItems !== []) {
            $groups[] = ['title' => 'Bankovní účet', 'items' => $accountItems];
        }
        if ($settingsItems !== []) {
            $groups[] = ['title' => 'Nastavení', 'items' => $settingsItems];
        }

        return ['type' => 'properties', 'groups' => $groups];
    }

    private function formatAccountDisplay(array $rowData): ?string
    {
        $accountNumber = trim((string) ($rowData['account_number'] ?? ''));
        $iban = trim((string) ($rowData['iban'] ?? ''));

        if ($accountNumber !== '') {
            return $accountNumber;
        }
        if ($iban !== '') {
            return $iban;
        }
        return null;
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
