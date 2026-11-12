<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class AIProfilesViewer extends TableViewer
{
    private const LABELS_CFG_ITEM = 'core.mail.viewerDetailLabels';

    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        // JOIN na backend kvůli názvu v seznamu. Sloupce kvalifikujeme aliasem
        // `p.` — `docState`/`name` existují i v joinované tabulce, proto
        // viewGroup filtr skládáme ručně a search voláme přes SearchCondition
        // s prefixem (buildViewGroupFilter / buildSearchCondition sloupce nekvalifikují).
        $sql = 'SELECT p.`id`, p.`profile_id`, p.`name`, p.`language`, p.`prompt_version`, p.`model`,'
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
            $badges[] = ['text' => $this->sharedText('statusDefault', 'default'), 'class' => 'success'];
        }
        $badges[] = !empty($rowData['is_active'])
            ? ['text' => $this->sharedText('statusActive', 'active'), 'class' => 'primary']
            : ['text' => $this->sharedText('statusInactive', 'inactive'), 'class' => 'muted'];
        $row['i1'] = $badges;

        // Připojení i model jsou přepsání funkce mail.extraction (D4):
        // prázdné = podle funkce.
        $parts = [];
        $backendName = (string) ($rowData['backend_name'] ?? '');
        if ($backendName !== '') {
            $parts[] = $backendName;
        }
        $model = trim((string) ($rowData['model'] ?? ''));
        if ($model !== '') {
            $parts[] = $model;
        }
        $language = (string) ($rowData['language'] ?? '');
        if ($language !== '') {
            $parts[] = $language;
        }
        $t2 = implode(' · ', $parts);
        $row['t2'] = $t2 !== '' ? ['text' => $t2, 'class' => 'muted'] : null;

        $profileId = (string) ($rowData['profile_id'] ?? '');
        $promptVersion = (string) ($rowData['prompt_version'] ?? '');
        $t3 = trim($profileId . ($profileId !== '' && $promptVersion !== '' ? ' · ' : '') . $promptVersion);
        $row['t3'] = $t3 !== '' ? $t3 : null;

        return $row;
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT p.`id`, p.`docState`, p.`profile_id`, p.`name`, p.`backend`, b.`name` AS `backend_name`,'
            . ' p.`supported_doc_types`, p.`language`, p.`prompt_version`,'
            . ' p.`model`, p.`model_like`, p.`thinking`, p.`effort`,'
            . ' p.`max_tokens`, p.`is_default`, p.`is_active`, p.`created`, p.`modified`'
            . ' FROM `' . $this->table . '` p'
            . ' LEFT JOIN `core_ai_backends` b ON b.`id` = p.`backend`'
            . ' WHERE p.`id` = %i',
            $recordId,
        );
    }

    protected function detailTabs(array $record): array
    {
        $identity = [];
        $this->addItem($identity, $this->columnLabel('profile_id', 'Profile code'), $record['profile_id'] ?? null);

        $scope = [];
        $this->addItem($scope, $this->columnLabel('supported_doc_types', 'Supported doc types'), $record['supported_doc_types'] ?? null);
        $this->addItem($scope, $this->columnLabel('language', 'Language'), $record['language'] ?? null);

        // prompt_template / output_schema / confidence_thresholds jsou dlouhé
        // strojové hodnoty — do properties nepatří, zobrazíme jen verzi promptu.
        $prompt = [];
        $this->addItem($prompt, $this->columnLabel('prompt_version', 'Prompt version'), $record['prompt_version'] ?? null);

        // Přepsání AI funkce mail.extraction (D4): prázdné = podle funkce.
        $byFunction = $this->text('byFunction', 'by function');
        $override = [];
        $this->addItem($override, $this->columnLabel('backend', 'AI connection'), ($record['backend'] ?? null) !== null
            ? (string) ($record['backend_name'] ?? ('#' . (int) $record['backend']))
            : $byFunction);
        $this->addItem($override, $this->columnLabel('model', 'Model'), $record['model'] ?? $byFunction);
        $this->addItem($override, $this->columnLabel('model_like', 'Shape request like model'), $record['model_like'] ?? null);
        $this->addItem($override, $this->columnLabel('thinking', 'Thinking'), $this->tuningLabel('core.ai.thinkingModes', $record['thinking'] ?? null));
        $this->addItem($override, $this->columnLabel('effort', 'Effort'), $this->tuningLabel('core.ai.effortLevels', $record['effort'] ?? null));
        // Popisek sloupce nese nápovědu formuláře „(0 = podle funkce)“ — v detailu
        // se místo nuly ukazuje text, proto vlastní popisek z katalogu.
        $this->addItem($override, $this->text('maxTokens', 'Max tokens'), ((int) ($record['max_tokens'] ?? 0)) > 0 ? (int) $record['max_tokens'] : $byFunction);

        $flags = [];
        $this->addItem($flags, $this->columnLabel('is_default', 'Default profile'), $this->yesNoText(!empty($record['is_default'])));
        $this->addItem($flags, $this->columnLabel('is_active', 'Active'), $this->yesNoText(!empty($record['is_active'])));

        $status = [];
        $this->addItem($status, $this->columnLabel('created', 'Created'), $this->formatDateTime($record['created'] ?? null));
        $this->addItem($status, $this->columnLabel('modified', 'Modified'), $this->formatDateTime($record['modified'] ?? null));

        return [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => [
                'type'   => 'properties',
                'groups' => [
                    ['title' => $this->sharedText('groupIdentity', 'Identity'), 'items' => $identity],
                    ['title' => $this->text('groupScope', 'Scope'), 'items' => $scope],
                    ['title' => $this->text('prompt', 'Prompt'), 'items' => $prompt],
                    ['title' => $this->text('groupFunctionOverride', 'AI function override'), 'items' => $override],
                    ['title' => $this->text('groupFlags', 'Flags'), 'items' => $flags],
                    ['title' => $this->text('status', 'Status'), 'items' => $status],
                ],
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

    /**
     * Text vieweru z katalogu `core.mail.viewerDetailLabels` (sekce `texts`).
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
