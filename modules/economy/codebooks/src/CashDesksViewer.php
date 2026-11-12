<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class CashDesksViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    private const LABELS_CFG_ITEM = 'economy.codebooks.viewerDetailLabels';

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
        $sql = 'SELECT `id`, `code`, `name`, `notice`, `currency`, `is_default`,'
            . ' `valid_from`, `valid_to`, `sort_order`, `docState`, `docStateMain`'
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
            [$searchSql, $searchParams] = $this->buildSearchCondition(['code', 'name'], $search);
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

        if (!empty($rowData['currency'])) {
            $t2[] = ['text' => strtoupper((string) $rowData['currency'])];
        }

        if (!empty($rowData['is_default'])) {
            $t2[] = ['text' => $this->text('default', 'Default'), 'class' => 'primary'];
        }

        $validFrom = $this->formatDate($rowData['valid_from'] ?? null);
        $validTo = $this->formatDate($rowData['valid_to'] ?? null);
        if ($validFrom !== null && $validTo !== null) {
            $t2[] = ['text' => $validFrom . ' – ' . $validTo, 'class' => 'muted'];
        } elseif ($validFrom !== null) {
            $t2[] = ['text' => $this->sharedText('fromDate', 'from {date}', ['date' => $validFrom]), 'class' => 'muted'];
        } elseif ($validTo !== null) {
            $t2[] = ['text' => $this->sharedText('toDate', 'to {date}', ['date' => $validTo]), 'class' => 'muted'];
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

        if (!empty($rowData['notice'])) {
            $row['t3'] = (string) $rowData['notice'];
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
        $this->addItem($identityItems, $this->columnLabel('notice', 'Notice'), $record['notice'] ?? null);

        $settingsItems = [];
        $this->addItem(
            $settingsItems,
            $this->columnLabel('currency', 'Currency'),
            !empty($record['currency']) ? strtoupper((string) $record['currency']) : null,
        );
        $this->addItem($settingsItems, $this->text('defaultForCurrency', 'Default for currency'), $this->yesNoText(!empty($record['is_default'])));
        $this->addItem($settingsItems, $this->columnLabel('valid_from', 'Valid from'), $this->formatDate($record['valid_from'] ?? null));
        $this->addItem(
            $settingsItems,
            $this->columnLabel('valid_to', 'Valid to'),
            $this->formatDate($record['valid_to'] ?? null) ?? $this->sharedText('noEnd', 'no end'),
        );
        $this->addItem($settingsItems, $this->columnLabel('sort_order', 'Order'), (string) ($record['sort_order'] ?? 0));

        $groups = [];
        if ($identityItems !== []) {
            $groups[] = ['title' => $this->sharedText('groupIdentity', 'Identity'), 'items' => $identityItems];
        }
        if ($settingsItems !== []) {
            $groups[] = ['title' => $this->text('groupSettings', 'Settings'), 'items' => $settingsItems];
        }

        return ['type' => 'properties', 'groups' => $groups];
    }

    /**
     * Text z katalogu modulu (`economy.codebooks.viewerDetailLabels`, sekce `texts`).
     *
     * @param array<string, scalar> $params
     */
    private function text(string $key, string $englishFallback, array $params = []): string
    {
        return $this->detailText(self::LABELS_CFG_ITEM, $key, $englishFallback, $params);
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
