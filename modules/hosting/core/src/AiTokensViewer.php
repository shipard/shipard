<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Hosting — AI gateway tokeny (hosting_core_ai_tokens). Settings viewer,
 * tabulka je adminOnly (D9) — přístup hlídá TableAccessGuard. Token se
 * vydává přes CLI `hosting-ai-token`; viewer ukazuje jen prefix a stav.
 */
class AiTokensViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT t.`id`, t.`data_source`, t.`token_prefix`, t.`active`,'
            . ' t.`last_used`, t.`note`, t.`created`, t.`docState`, t.`docStateMain`,'
            . ' ds.`name` AS ds_name, ds.`ds_id` AS ds_ds_id'
            . ' FROM `' . $this->table . '` t'
            . ' LEFT JOIN `hosting_core_data_sources` ds ON ds.`id` = t.`data_source`';

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
                // JOIN na data_sources má vlastní docState — kvalifikovat.
                $conditions[] = str_starts_with($vgSql, '`docState`') ? 't.' . $vgSql : $vgSql;
                $params = array_merge($params, $vgParams);
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = SearchCondition::anyContains([
                'ds.`name`', 'ds.`ds_id`', 't.`note`',
            ], $search);
            $conditions[] = $searchSql;
            $params = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY t.`docStateMain` ASC, t.`created` DESC, t.`id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $isCs = ($this->language ?? 'en') === 'cs';
        $isActive = (int) ($rowData['active'] ?? 0) === 1;

        $dsName = trim((string) ($rowData['ds_name'] ?? ''));
        $t1 = $dsName !== '' ? $dsName : (string) ($rowData['ds_ds_id'] ?? '');

        $t2 = [
            ['text' => (string) ($rowData['token_prefix'] ?? '') . '…', 'class' => 'muted'],
            $isActive
                ? ['text' => $isCs ? 'aktivní' : 'active', 'class' => 'success']
                : ['text' => $isCs ? 'revokován' : 'revoked', 'class' => 'danger'],
        ];
        if (!empty($rowData['last_used'])) {
            $t2[] = [
                'text'  => ($isCs ? 'použit ' : 'used ') . $this->formatDateTime($rowData['last_used']),
                'class' => 'muted',
            ];
        }
        $note = trim((string) ($rowData['note'] ?? ''));
        if ($note !== '') {
            $t2[] = ['text' => $note, 'class' => 'muted'];
        }

        return [
            'id'         => (int) $rowData['id'],
            't1'         => $t1,
            't2'         => $t2,
            'stateStyle' => $isActive ? $this->resolveStateStyle((int) ($rowData['docState'] ?? 10)) : 'cancelled',
        ];
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow(
            'SELECT t.*, ds.`name` AS ds_name, ds.`ds_id` AS ds_ds_id'
            . ' FROM `' . $this->table . '` t'
            . ' LEFT JOIN `hosting_core_data_sources` ds ON ds.`id` = t.`data_source`'
            . ' WHERE t.`id` = %i',
            $recordId,
        );

        if ($record === null) {
            return ['tabs' => []];
        }

        $isCs = ($this->language ?? 'en') === 'cs';
        $isActive = (int) ($record['active'] ?? 0) === 1;

        $items = [
            [
                'label' => $isCs ? 'Zdroj dat' : 'Data source',
                'value' => trim((string) ($record['ds_name'] ?? '')) . ' (' . $record['ds_ds_id'] . ')',
            ],
            ['label' => $isCs ? 'Token' : 'Token', 'value' => (string) $record['token_prefix'] . '…'],
            [
                'label' => $isCs ? 'Stav' : 'State',
                'value' => $isActive ? ($isCs ? 'aktivní' : 'active') : ($isCs ? 'revokován' : 'revoked'),
            ],
        ];
        if (!empty($record['last_used'])) {
            $items[] = [
                'label' => $isCs ? 'Naposledy použit' : 'Last used',
                'value' => $this->formatDateTime($record['last_used']),
            ];
        }
        if (!empty($record['note'])) {
            $items[] = ['label' => $isCs ? 'Poznámka' : 'Note', 'value' => (string) $record['note']];
        }
        $items[] = ['label' => $isCs ? 'Vytvořen' : 'Created', 'value' => $this->formatDateTime($record['created'])];

        return [
            'tabs' => [[
                'id'      => 'overview',
                'label'   => $this->defaultOverviewLabel(),
                'content' => [
                    'type'   => 'properties',
                    'groups' => [['title' => $isCs ? 'AI gateway token' : 'AI gateway token', 'items' => $items]],
                ],
            ]],
        ];
    }

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
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
