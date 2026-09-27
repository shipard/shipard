<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class AIProfilesViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        // JOIN na backend kvůli názvu v seznamu. Sloupce kvalifikujeme aliasem
        // `p.` — `docState`/`name` existují i v joinované tabulce, proto
        // viewGroup filtr skládáme ručně a search voláme přes SearchCondition
        // s prefixem (buildViewGroupFilter / buildSearchCondition sloupce nekvalifikují).
        $sql = 'SELECT p.`id`, p.`profile_id`, p.`name`, p.`language`, p.`prompt_version`,'
            . ' p.`is_default`, p.`is_active`, p.`docState`, p.`docStateMain`,'
            . ' b.`name` AS `backend_name`'
            . ' FROM `' . $this->table . '` p'
            . ' LEFT JOIN `core_ai_backends` b ON b.`id` = p.`backend`';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        foreach ($filters as $filter) {
            if ($filter['id'] === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
            }
        }

        if ($viewGroup !== 'all' && $this->config !== null) {
            $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
            $states = $cfg->getViewGroupStates($viewGroup);
            if ($states === []) {
                $conditions[] = '1=0';
            } else {
                $placeholders = implode(', ', array_fill(0, count($states), '%i'));
                $conditions[] = 'p.`docState` IN (' . $placeholders . ')';
                $params = array_merge($params, $states);
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = SearchCondition::anyContains([
                'p.`name`', 'p.`profile_id`',
            ], $search);
            $conditions[] = $searchSql;
            $params = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY p.`docStateMain` ASC, p.`is_default` DESC, p.`name` ASC, p.`id` ASC';

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

        $backendName = (string) ($rowData['backend_name'] ?? '');
        $language = (string) ($rowData['language'] ?? '');
        $t2 = trim($backendName . ($backendName !== '' && $language !== '' ? ' · ' : '') . $language);
        $row['t2'] = $t2 !== '' ? ['text' => $t2, 'class' => 'muted'] : null;

        $profileId = (string) ($rowData['profile_id'] ?? '');
        $promptVersion = (string) ($rowData['prompt_version'] ?? '');
        $t3 = trim($profileId . ($profileId !== '' && $promptVersion !== '' ? ' · ' : '') . $promptVersion);
        $row['t3'] = $t3 !== '' ? $t3 : null;

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow(
            'SELECT p.`profile_id`, p.`name`, b.`name` AS `backend_name`,'
            . ' p.`supported_doc_types`, p.`language`, p.`prompt_version`,'
            . ' p.`max_tokens`, p.`is_default`, p.`is_active`, p.`created`, p.`modified`'
            . ' FROM `' . $this->table . '` p'
            . ' LEFT JOIN `core_ai_backends` b ON b.`id` = p.`backend`'
            . ' WHERE p.`id` = %i',
            $recordId,
        );

        if ($record === null) {
            return ['tabs' => []];
        }

        $identity = [];
        $this->addItem($identity, 'Kód profilu', $record['profile_id'] ?? null);
        $this->addItem($identity, 'Název', $record['name'] ?? null);
        $this->addItem($identity, 'Backend', $record['backend_name'] ?? null);

        $scope = [];
        $this->addItem($scope, 'Podporované typy dokumentů', $record['supported_doc_types'] ?? null);
        $this->addItem($scope, 'Jazyk', $record['language'] ?? null);

        // prompt_template / output_schema / confidence_thresholds jsou dlouhé
        // strojové hodnoty — do properties nepatří, zobrazíme jen verzi promptu.
        $prompt = [];
        $this->addItem($prompt, 'Verze promptu', $record['prompt_version'] ?? null);
        // 0 = bez override — limit se řeší kaskádou backend → default analyzéru.
        if (((int) ($record['max_tokens'] ?? 0)) !== 0) {
            $this->addItem($prompt, 'Max. tokenů', (int) $record['max_tokens']);
        }

        $flags = [];
        $this->addItem($flags, 'Výchozí profil', !empty($record['is_default']) ? 'Ano' : 'Ne');
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
                        ['title' => 'Záběr', 'items' => $scope],
                        ['title' => 'Prompt', 'items' => $prompt],
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

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
