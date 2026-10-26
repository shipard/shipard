<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class AccountsViewer extends TableViewer
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
        $sql = 'SELECT `id`, `number`, `name`, `short_name`, `account_level`,'
            . ' `account_kind`, `valid_from`, `valid_to`, `docState`, `docStateMain`'
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
            [$searchSql, $searchParams] = $this->buildSearchCondition(['number', 'name', 'short_name'], $search);
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY `docStateMain` ASC, `number` ASC, `id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $row = [
            'id' => (int) $rowData['id'],
            't1' => $rowData['name'] ?? '',
            'i1' => $rowData['number'] ?? null,
        ];

        $t2 = [];

        $levelLabel = $this->enumLabel('economy.accounting.accountLevels', $rowData['account_level'] ?? null);
        if ($levelLabel !== null) {
            $t2[] = ['text' => $levelLabel, 'class' => 'muted'];
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
        $this->addItem($identityItems, 'Číslo účtu', $record['number'] ?? null);
        $this->addItem($identityItems, 'Název', $record['name'] ?? null);
        $this->addItem($identityItems, 'Název zkrácený', $record['short_name'] ?? null);

        $classificationItems = [];
        $this->addItem(
            $classificationItems,
            'Úroveň',
            $this->enumLabel('economy.accounting.accountLevels', $record['account_level'] ?? null),
        );
        $this->addItem(
            $classificationItems,
            'Povaha účtu',
            $this->enumLabel('economy.accounting.accountKinds', $record['account_kind'] ?? null),
        );
        $this->addItem(
            $classificationItems,
            'Druh nákladu',
            $this->enumLabel('economy.accounting.costsTypes', $record['costs_type'] ?? null),
        );
        $this->addItem(
            $classificationItems,
            'Druh výsledku',
            $this->enumLabel('economy.accounting.resultsTypes', $record['results_type'] ?? null),
        );

        $settingsItems = [];
        $this->addItem($settingsItems, 'Platnost od', $this->formatDate($record['valid_from'] ?? null));
        $this->addItem(
            $settingsItems,
            'Platnost do',
            $this->formatDate($record['valid_to'] ?? null) ?? 'bez konce',
        );
        $this->addItem($settingsItems, 'Systémový', !empty($record['is_system']) ? 'Ano' : 'Ne');
        $this->addItem($settingsItems, 'Popis', $record['note'] ?? null);

        $groups = [];
        if ($identityItems !== []) {
            $groups[] = ['title' => 'Identifikace', 'items' => $identityItems];
        }
        if ($classificationItems !== []) {
            $groups[] = ['title' => 'Zatřídění', 'items' => $classificationItems];
        }
        if ($settingsItems !== []) {
            $groups[] = ['title' => 'Nastavení', 'items' => $settingsItems];
        }

        return ['type' => 'properties', 'groups' => $groups];
    }

    /** Přeloží číselnou enum hodnotu na lokalizovaný popisek z cfgItem; NULL → null. */
    private function enumLabel(string $cfgItemId, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $items = $this->config?->cfgItem($cfgItemId);
        if (!is_array($items)) {
            return null;
        }
        $key = (string) (int) $value;
        $entry = $items[$key] ?? null;
        if (is_array($entry)) {
            return $entry['name'] ?? null;
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
