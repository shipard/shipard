<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Ai;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class AIBackendsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        // api_key se NIKDY neselektuje jako hodnota — jen boolean příznak
        // has_api_key (zašifrovaný klíč se nastavuje výhradně přes CLI).
        $sql = 'SELECT `id`, `backend_id`, `name`, `provider`, `model`, `base_url`,'
            . ' `max_tokens`, `temperature`, `is_default`, `is_active`,'
            . ' `docState`, `docStateMain`,'
            . ' (`api_key` IS NOT NULL AND `api_key` != \'\') AS `has_api_key`'
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
                ['name', 'backend_id', 'model', 'provider'],
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

        $sql .= ' ORDER BY `docStateMain` ASC, `is_default` DESC, `name` ASC, `id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => (string) ($rowData['name'] ?? ''),
            'stateStyle' => $this->resolveStateStyle((int) ($rowData['docState'] ?? 10)),
        ];

        $badges = [];
        if (!empty($rowData['is_default'])) {
            $badges[] = ['text' => 'výchozí', 'class' => 'success'];
        }
        $badges[] = !empty($rowData['is_active'])
            ? ['text' => 'aktivní', 'class' => 'primary']
            : ['text' => 'neaktivní', 'class' => 'muted'];
        $row['i1'] = $badges;

        $provider = (string) ($rowData['provider'] ?? '');
        $model = (string) ($rowData['model'] ?? '');
        $t2 = trim($provider . ($provider !== '' && $model !== '' ? ' / ' : '') . $model);
        $row['t2'] = $t2 !== '' ? ['text' => $t2, 'class' => 'muted'] : null;

        $backendId = (string) ($rowData['backend_id'] ?? '');
        $row['t3'] = $backendId !== '' ? $backendId : null;

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        // api_key NIKDY jako hodnota — jen has_api_key příznak.
        $record = $this->db->fetchRow(
            'SELECT `id`, `backend_id`, `name`, `provider`, `model`, `base_url`,'
            . ' `max_tokens`, `temperature`, `thinking`, `effort`, `is_default`, `is_active`,'
            . ' `created`, `modified`,'
            . ' (`api_key` IS NOT NULL AND `api_key` != \'\') AS `has_api_key`'
            . ' FROM `' . $this->table . '` WHERE `id` = %i',
            $recordId,
        );

        if ($record === null) {
            return ['tabs' => []];
        }

        $identity = [];
        $this->addItem($identity, 'Kód backendu', $record['backend_id'] ?? null);
        $this->addItem($identity, 'Název', $record['name'] ?? null);

        $provider = [];
        $this->addItem($provider, 'Provider', $record['provider'] ?? null);
        $this->addItem($provider, 'Model', $record['model'] ?? null);
        $this->addItem($provider, 'Base URL', $record['base_url'] ?? null);

        $access = [];
        $this->addItem($access, 'API klíč', !empty($record['has_api_key']) ? 'nastaven' : 'nenastaven');

        // NULL teplota a `auto` = parametr se neposílá (tasks/ai-models-phase0.md F0-D1, F0-D2).
        $tuning = [];
        $this->addItem($tuning, 'Max. tokenů', ((int) ($record['max_tokens'] ?? 0)) ?: 'automaticky');
        $this->addItem($tuning, 'Teplota', $record['temperature'] ?? 'výchozí modelu');
        $this->addItem($tuning, 'Přemýšlení', $this->tuningLabel('core.ai.thinkingModes', $record['thinking'] ?? null));
        $this->addItem($tuning, 'Úsilí', $this->tuningLabel('core.ai.effortLevels', $record['effort'] ?? null));

        $flags = [];
        $this->addItem($flags, 'Výchozí backend', !empty($record['is_default']) ? 'Ano' : 'Ne');
        $this->addItem($flags, 'Aktivní', !empty($record['is_active']) ? 'Ano' : 'Ne');

        $status = [];
        $this->addItem($status, 'Vytvořeno', $record['created'] ?? null);
        $this->addItem($status, 'Změněno', $record['modified'] ?? null);

        return [
            'tabs' => [[
                'id'      => 'overview',
                'label'   => $this->defaultOverviewLabel(),
                'content' => [
                    'type'   => 'properties',
                    'groups' => [
                        ['title' => 'Identifikace', 'items' => $identity],
                        ['title' => 'Provider', 'items' => $provider],
                        ['title' => 'Přístup', 'items' => $access],
                        ['title' => 'Ladění', 'items' => $tuning],
                        ['title' => 'Příznaky', 'items' => $flags],
                        ['title' => 'Stav', 'items' => $status],
                    ],
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

    /** Popisek hodnoty z cfgItemu (`name` po lokalizaci); bez konfigurace surová hodnota. */
    private function tuningLabel(string $cfgItemId, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (string) $value;
        $cfg = $this->config?->cfgItem($cfgItemId);
        $entry = is_array($cfg) ? ($cfg[$value] ?? null) : null;
        return is_array($entry) && isset($entry['name']) ? (string) $entry['name'] : $value;
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
