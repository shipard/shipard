<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Chat;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Read-only admin overview of chat conversations (Settings). The chat UI
 * itself is served by ChatController under /_chat/conversations; this viewer
 * only exists so conversations are inspectable from Nastavení.
 */
class ConversationsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    private const STATE_SPAN_CLASS = [
        'concept' => 'warning',
        'done'    => 'success',
        'edit'    => 'warning',
        'archive' => 'muted',
        'trash'   => 'muted',
    ];

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT `id`, `title`, `model_snapshot`, `created`, `modified`,'
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
            [$searchSql, $searchParams] = $this->buildSearchCondition(['title'], $search);
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY `docStateMain` ASC, `modified` DESC, `id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? 10);

        $title = trim((string) ($rowData['title'] ?? ''));
        if ($title === '') {
            $title = '(bez názvu)';
        }

        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => $title,
            'stateStyle' => $this->resolveStateStyle($docState),
            't2'         => null,
            't3'         => null,
        ];

        $model = trim((string) ($rowData['model_snapshot'] ?? ''));
        if ($model !== '') {
            $row['t2'] = [['text' => $model, 'class' => 'muted']];
        }

        $created = $rowData['created'] ?? null;
        if ($created !== null && $created !== '') {
            $row['t3'] = (string) $created;
        }

        return $row;
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM `' . $this->table . '` WHERE `id` = %i',
            $recordId,
        );
    }

    protected function detailTabs(array $record): array
    {
        $items = [];
        $this->addItem($items, 'Název', $record['title'] ?? null);
        $this->addItem($items, 'Model', $record['model_snapshot'] ?? null);
        $this->addItem($items, 'Vstupní tokeny', (string) ($record['tokens_input'] ?? 0));
        $this->addItem($items, 'Výstupní tokeny', (string) ($record['tokens_output'] ?? 0));
        $this->addItem($items, 'Náklady', (string) ($record['cost'] ?? 0));
        $this->addItem($items, 'Vytvořeno', $record['created'] ?? null);
        $this->addItem($items, 'Změněno', $record['modified'] ?? null);

        return [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => [
                'type'   => 'properties',
                'groups' => [['title' => 'Konverzace', 'items' => $items]],
            ],
        ]];
    }

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
