<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Form\SubtableCellFormatter;
use Shipard\Core\Viewer\TableViewer;

class FiscalYearsViewer extends TableViewer
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
        $sql = 'SELECT `id`, `name`, `doc_number_prefix`, `date_begin`, `date_end`,'
            . ' `currency`, `locked`, `docState`, `docStateMain`'
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
            [$searchSql, $searchParams] = $this->buildSearchCondition(['name'], $search);
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY `docStateMain` ASC, `date_begin` DESC, `id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $row = [
            'id' => (int) $rowData['id'],
            't1' => $rowData['name'] ?? '',
            'i1' => $rowData['doc_number_prefix'] ?? null,
        ];

        $t2 = [];

        $dateBegin = $rowData['date_begin'] ?? null;
        $dateEnd = $rowData['date_end'] ?? null;
        if ($dateBegin !== null && $dateEnd !== null) {
            $t2[] = ['text' => $this->formatDate($dateBegin) . ' – ' . $this->formatDate($dateEnd)];
        }

        if (!empty($rowData['currency'])) {
            $t2[] = ['text' => strtoupper((string) $rowData['currency'])];
        }

        if (!empty($rowData['locked'])) {
            $t2[] = ['text' => 'Uzamčeno', 'class' => 'warning'];
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

    /** Přehled a tab Měsíce (fiskální měsíce roku se zámky). */
    protected function detailTabs(array $record): array
    {
        $recordId = (int) $record['id'];
        $tabs = [];

        $tabs[] = [
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => $this->buildOverviewContent($record),
        ];

        $months = $this->db->fetchAll(
            'SELECT `date_begin`, `date_end`, `period_type`, `calendar_year`, `calendar_month`,'
            . ' `locked`, `locked_at`'
            . ' FROM `economy_codebooks_fiscal_months`'
            . ' WHERE `fiscal_year` = %i'
            . ' ORDER BY `date_begin` ASC, `id` ASC',
            $recordId,
        );

        $periodTypeLabels = $this->resolvePeriodTypeLabels();
        $monthRows = [];
        foreach ($months as $m) {
            $type = (int) ($m['period_type'] ?? 1);
            // Zámek měsíce (#55 D27) — přepíná se ve formuláři měsíce
            // (sub-tabulka roku); s časem zamknutí, bez něj (import) holé.
            $lockedAt = !empty($m['locked']) ? SubtableCellFormatter::dateTime($m['locked_at'] ?? null) : null;
            $monthRows[] = [
                'date_begin'     => $this->formatDate($m['date_begin'] ?? null),
                'date_end'       => $this->formatDate($m['date_end'] ?? null),
                'period_type'    => $periodTypeLabels[$type] ?? (string) $type,
                'calendar_year'  => $m['calendar_year'] ?? null,
                'calendar_month' => $m['calendar_month'] ?? null,
                'locked'         => !empty($m['locked']) ? ('Uzamčeno' . ($lockedAt !== null ? " ({$lockedAt})" : '')) : '—',
            ];
        }

        $tabs[] = [
            'id'      => 'months',
            'label'   => $this->detailTabLabel('economy.codebooks.viewerDetailLabels', 'months', 'Months'),
            'content' => [
                'type'    => 'table',
                'columns' => [
                    ['id' => 'date_begin',     'label' => 'Začátek'],
                    ['id' => 'date_end',       'label' => 'Konec'],
                    ['id' => 'period_type',    'label' => 'Typ'],
                    ['id' => 'calendar_year',  'label' => 'Rok'],
                    ['id' => 'calendar_month', 'label' => 'Měsíc'],
                    ['id' => 'locked',         'label' => 'Zámek'],
                ],
                'rows' => $monthRows,
            ],
        ];

        return $tabs;
    }

    private function buildOverviewContent(array $record): array
    {
        $identityItems = [];
        $this->addItem($identityItems, 'Název', $record['name'] ?? null);
        $this->addItem($identityItems, 'Prefix čísla dokladu', $record['doc_number_prefix'] ?? null);

        $periodItems = [];
        $this->addItem($periodItems, 'Začátek', $this->formatDate($record['date_begin'] ?? null));
        $this->addItem($periodItems, 'Konec', $this->formatDate($record['date_end'] ?? null));
        $this->addItem($periodItems, 'Měna', !empty($record['currency']) ? strtoupper((string) $record['currency']) : null);
        $this->addItem($periodItems, 'Uzamčeno', !empty($record['locked']) ? 'Ano' : 'Ne');

        $groups = [];
        if ($identityItems !== []) {
            $groups[] = ['title' => 'Identifikace', 'items' => $identityItems];
        }
        if ($periodItems !== []) {
            $groups[] = ['title' => 'Období', 'items' => $periodItems];
        }

        return ['type' => 'properties', 'groups' => $groups];
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

    /** @return array<int, string> */
    private function resolvePeriodTypeLabels(): array
    {
        if ($this->config === null) {
            return [];
        }
        $cfgData = $this->config->cfgItem('economy.codebooks.fiscalPeriodTypes');
        if (!is_array($cfgData)) {
            return [];
        }
        $labels = [];
        foreach ($cfgData as $key => $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $labels[(int) $key] = (string) $entry['name'];
            }
        }
        return $labels;
    }
}
