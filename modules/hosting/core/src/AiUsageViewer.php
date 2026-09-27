<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Viewer\TableViewer;

/**
 * Hosting — spotřeba AI (hosting_core_ai_usage). Append-only log průchodů
 * AI gatewayí (D5) — vždy read-only: žádné new/edit/delete akce, žádné
 * docState taby ($docStatesCfgItem = null, vzor JournalViewer). Grid layout
 * s footer sumami tokenů přes celý filtrovaný set.
 */
class AiUsageViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = null;

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT u.`id`, u.`data_source`, u.`model`, u.`input_tokens`,'
            . ' u.`output_tokens`, u.`cache_creation_tokens`, u.`cache_read_tokens`,'
            . ' u.`http_status`, u.`stream`, u.`duration_ms`, u.`created`,'
            . ' ds.`name` AS ds_name, ds.`ds_id` AS ds_ds_id'
            . ' FROM `' . $this->table . '` u'
            . ' LEFT JOIN `hosting_core_data_sources` ds ON ds.`id` = u.`data_source`';

        [$conditions, $params] = $this->buildConditions($search, $filters);

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ' . $this->buildSortedOrderBy(
            [
                'created'       => 'u.`created`',
                'input_tokens'  => 'u.`input_tokens`',
                'output_tokens' => 'u.`output_tokens`',
                'duration_ms'   => 'u.`duration_ms`',
            ],
            'ORDER BY u.`created` DESC, u.`id` DESC',
            'u.`id`',
        );

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    /**
     * Skladba WHERE podmínek — sdílená mezi selectRows() a renderGridFooter(),
     * aby součty vždy odpovídaly filtrovanému setu.
     *
     * @return array{0: list<string>, 1: list<mixed>} [conditions, params]
     */
    private function buildConditions(?string $search, array $filters): array
    {
        $conditions = [];
        $params = [];

        foreach ($filters as $filter) {
            $id = $filter['id'] ?? null;
            $value = $filter['value'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($id === 'data_source') {
                $conditions[] = 'u.`data_source` = %i';
                $params[] = (int) $value;
            } elseif ($id === 'only_errors' && (string) $value === '1') {
                $conditions[] = 'u.`http_status` >= 400';
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = SearchCondition::anyContains([
                'u.`model`', 'ds.`name`', 'ds.`ds_id`',
            ], $search);
            $conditions[] = $searchSql;
            $params = array_merge($params, $searchParams);
        }

        return [$conditions, $params];
    }

    public function renderRow(array $rowData): array
    {
        $isCs = ($this->language ?? 'en') === 'cs';
        $status = (int) ($rowData['http_status'] ?? 0);
        $isError = $status >= 400 || $status === 0;

        $dsName = trim((string) ($rowData['ds_name'] ?? ''));

        $t2 = [
            ['text' => $dsName !== '' ? $dsName : (string) ($rowData['ds_ds_id'] ?? ''), 'class' => 'muted'],
            ['text' => $this->formatDateTime($rowData['created'] ?? null), 'class' => 'muted'],
        ];
        if ($isError) {
            $t2[] = ['text' => 'HTTP ' . $status, 'class' => 'danger'];
        }

        return [
            'id'         => (int) $rowData['id'],
            't1'         => (string) ($rowData['model'] ?? ''),
            't2'         => $t2,
            'i2'         => [
                ['text' => ($isCs ? 'in ' : 'in ') . $this->formatCount($rowData['input_tokens'] ?? 0), 'class' => 'muted'],
                ['text' => 'out ' . $this->formatCount($rowData['output_tokens'] ?? 0), 'class' => 'amount'],
            ],
            'stateStyle' => $isError ? 'error' : 'done',
        ];
    }

    // ── Grid layout (docs/viewer-grid.md) ───────────────────────────────────

    public function getDefaultLayout(): string
    {
        return 'grid';
    }

    public function getGridColumns(): ?array
    {
        $cs = $this->language === 'cs';

        return [
            ['id' => 'created', 'label' => $cs ? 'Čas' : 'Time', 'width' => 140, 'sortable' => true],
            ['id' => 'ds_name', 'label' => $cs ? 'Zdroj dat' : 'Data source'],
            ['id' => 'model', 'label' => 'Model'],
            ['id' => 'input_tokens', 'label' => 'In', 'width' => 90, 'align' => 'right', 'sortable' => true],
            ['id' => 'output_tokens', 'label' => 'Out', 'width' => 90, 'align' => 'right', 'sortable' => true],
            ['id' => 'cache_creation_tokens', 'label' => $cs ? 'Cache zápis' : 'Cache write', 'width' => 100, 'align' => 'right'],
            ['id' => 'cache_read_tokens', 'label' => $cs ? 'Cache čtení' : 'Cache read', 'width' => 100, 'align' => 'right'],
            ['id' => 'http_status', 'label' => 'HTTP', 'width' => 60, 'align' => 'right'],
            ['id' => 'stream', 'label' => 'SSE', 'width' => 50],
            ['id' => 'duration_ms', 'label' => $cs ? 'Trvání' : 'Duration', 'width' => 90, 'align' => 'right', 'sortable' => true],
        ];
    }

    public function renderGridRow(array $rowData): array
    {
        $status = (int) ($rowData['http_status'] ?? 0);
        $isError = $status >= 400 || $status === 0;
        $dsName = trim((string) ($rowData['ds_name'] ?? ''));

        return [
            'id'         => (int) $rowData['id'],
            'stateStyle' => null,
            'rowClass'   => $isError ? 'error' : null,
            'cells' => [
                'created'               => $this->formatDateTime($rowData['created'] ?? null),
                'ds_name'               => $dsName !== '' ? $dsName : (string) ($rowData['ds_ds_id'] ?? ''),
                'model'                 => (string) ($rowData['model'] ?? ''),
                'input_tokens'          => $this->gridCountCell($rowData['input_tokens'] ?? 0),
                'output_tokens'         => $this->gridCountCell($rowData['output_tokens'] ?? 0),
                'cache_creation_tokens' => $this->gridCountCell($rowData['cache_creation_tokens'] ?? 0),
                'cache_read_tokens'     => $this->gridCountCell($rowData['cache_read_tokens'] ?? 0),
                'http_status'           => $isError
                    ? ['text' => (string) $status, 'class' => 'danger']
                    : (string) $status,
                'stream'                => (int) ($rowData['stream'] ?? 0) === 1 ? '✓' : '',
                'duration_ms'           => ($rowData['duration_ms'] ?? null) !== null
                    ? $this->formatCount($rowData['duration_ms']) . ' ms'
                    : '',
            ],
        ];
    }

    /**
     * Součty tokenů přes celý filtrovaný set — stejná WHERE skladba jako
     * selectRows() (buildConditions), jinak by footer neodpovídal filtrům.
     */
    public function renderGridFooter(?string $search, array $filters): ?array
    {
        $sql = 'SELECT SUM(u.`input_tokens`) AS sum_in, SUM(u.`output_tokens`) AS sum_out,'
            . ' SUM(u.`cache_creation_tokens`) AS sum_cc, SUM(u.`cache_read_tokens`) AS sum_cr'
            . ' FROM `' . $this->table . '` u'
            . ' LEFT JOIN `hosting_core_data_sources` ds ON ds.`id` = u.`data_source`';

        [$conditions, $params] = $this->buildConditions($search, $filters);

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $r = $this->db->fetchRow($sql, ...$params);

        return [
            'created'               => 'Σ',
            'input_tokens'          => ['text' => $this->formatCount($r['sum_in'] ?? 0), 'class' => 'amount'],
            'output_tokens'         => ['text' => $this->formatCount($r['sum_out'] ?? 0), 'class' => 'amount'],
            'cache_creation_tokens' => ['text' => $this->formatCount($r['sum_cc'] ?? 0), 'class' => 'amount'],
            'cache_read_tokens'     => ['text' => $this->formatCount($r['sum_cr'] ?? 0), 'class' => 'amount'],
        ];
    }

    public function renderDetail(int $recordId): array
    {
        $r = $this->db->fetchRow(
            'SELECT u.*, ds.`name` AS ds_name, ds.`ds_id` AS ds_ds_id'
            . ' FROM `' . $this->table . '` u'
            . ' LEFT JOIN `hosting_core_data_sources` ds ON ds.`id` = u.`data_source`'
            . ' WHERE u.`id` = %i',
            $recordId,
        );

        if ($r === null) {
            return ['tabs' => []];
        }

        $cs = ($this->language ?? 'en') === 'cs';

        $items = [
            [
                'label' => $cs ? 'Zdroj dat' : 'Data source',
                'value' => trim((string) ($r['ds_name'] ?? '')) . ' (' . $r['ds_ds_id'] . ')',
            ],
            ['label' => 'Model', 'value' => (string) $r['model']],
            ['label' => $cs ? 'Čas' : 'Time', 'value' => $this->formatDateTime($r['created'])],
            ['label' => 'HTTP status', 'value' => (string) $r['http_status']],
            ['label' => 'Stream', 'value' => (int) ($r['stream'] ?? 0) === 1 ? ($cs ? 'ano' : 'yes') : ($cs ? 'ne' : 'no')],
        ];
        if (($r['duration_ms'] ?? null) !== null) {
            $items[] = ['label' => $cs ? 'Trvání' : 'Duration', 'value' => $this->formatCount($r['duration_ms']) . ' ms'];
        }

        $tokenItems = [
            ['label' => $cs ? 'Vstupní tokeny' : 'Input tokens', 'value' => $this->formatCount($r['input_tokens'] ?? 0)],
            ['label' => $cs ? 'Výstupní tokeny' : 'Output tokens', 'value' => $this->formatCount($r['output_tokens'] ?? 0)],
            ['label' => $cs ? 'Tokeny — zápis cache' : 'Cache creation tokens', 'value' => $this->formatCount($r['cache_creation_tokens'] ?? 0)],
            ['label' => $cs ? 'Tokeny — čtení cache' : 'Cache read tokens', 'value' => $this->formatCount($r['cache_read_tokens'] ?? 0)],
        ];

        return [
            'tabs' => [[
                'id'      => 'overview',
                'label'   => $this->defaultOverviewLabel(),
                'content' => [
                    'type'   => 'properties',
                    'groups' => [
                        ['title' => $cs ? 'Průchod' : 'Request', 'items' => $items],
                        ['title' => $cs ? 'Tokeny' : 'Tokens', 'items' => $tokenItems],
                    ],
                ],
            ]],
        ];
    }

    public function getFilters(): array
    {
        $cs = ($this->language ?? 'en') === 'cs';

        $dsOptions = [];
        $rows = $this->db->fetchAll(
            'SELECT `id`, `name`, `ds_id` FROM `hosting_core_data_sources`'
            . ' WHERE `docState` != 90 ORDER BY `name` ASC',
        );
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $dsOptions[] = [
                'value' => (int) $row['id'],
                'label' => $name !== '' ? $name : (string) $row['ds_id'],
            ];
        }

        return [
            [
                'id'      => 'data_source',
                'label'   => $cs ? 'Zdroj dat' : 'Data source',
                'type'    => 'select',
                'options' => $dsOptions,
            ],
            [
                'id'    => 'only_errors',
                'label' => $cs ? 'Jen chyby' : 'Errors only',
                'type'  => 'checkbox',
            ],
        ];
    }

    /** Log je read-only — žádné Add/Open toolbar akce. */
    public function getToolbarActions(?array $selectedRow): array
    {
        return [];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function formatCount(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 0, ',', ' ');
    }

    private function formatDateTime(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('j. n. Y H:i');
        }
        $ts = is_string($value) ? strtotime($value) : false;
        return $ts !== false ? date('j. n. Y H:i', $ts) : (string) $value;
    }
}
