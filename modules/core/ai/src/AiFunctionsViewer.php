<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Ai;

use Shipard\Core\Ai\AiFunction;
use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Viewer\TableViewer;

/**
 * Viewer „AI funkce“ (tasks/ai-functions.md D38): jeden řádek na
 * deklarovanou funkci, pořadí podle registru (deklarací modulů). Název
 * a popis funkce jdou z registru, ne z databáze; řádek ukazuje efektivní
 * model a připojení s původem hodnoty (přepsáno / podle úrovně).
 * Přidat se nenabízí — řádky zakládá ds-upgrade.
 */
class AiFunctionsViewer extends TableViewer
{
    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT f.`id`, f.`function_id`, f.`connection`, f.`model`, f.`model_like`,'
            . ' f.`thinking`, f.`effort`, f.`max_tokens`,'
            . ' b.`name` AS `connection_name`, b.`is_active` AS `connection_active`'
            . ' FROM `' . $this->table . '` f'
            . ' LEFT JOIN `core_ai_backends` b ON b.`id` = f.`connection`';
        $params = [];
        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = SearchCondition::anyContains(['f.`function_id`', 'f.`model`'], $search);
            $sql .= ' WHERE ' . $searchSql;
            $params = $searchParams;
        }

        $rows = $this->db->fetchAll($sql, ...$params);

        // Pořadí deklarací modulů; funkce bez deklarace (modul už ji nemá)
        // na konec podle id.
        $order = array_flip($this->registry()->ids());
        usort($rows, static function (array $a, array $b) use ($order): int {
            $ia = $order[(string) ($a['function_id'] ?? '')] ?? PHP_INT_MAX;
            $ib = $order[(string) ($b['function_id'] ?? '')] ?? PHP_INT_MAX;
            return [$ia, (string) ($a['function_id'] ?? '')] <=> [$ib, (string) ($b['function_id'] ?? '')];
        });

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        return array_slice($rows, $offset, $limit);
    }

    public function renderRow(array $rowData): array
    {
        $functionId = (string) ($rowData['function_id'] ?? '');
        $function = $this->registry()->get($functionId);
        $effective = $this->effectiveModel($function, $rowData);

        $row = [
            'id' => (int) $rowData['id'],
            't1' => $function?->name ?? $functionId,
        ];

        $badges = [];
        if ($function !== null) {
            $badges[] = ['text' => $this->tierLabel($function->tier), 'class' => 'muted'];
        } else {
            $badges[] = ['text' => 'bez deklarace', 'class' => 'warning'];
        }
        if ($effective['origin'] === 'row') {
            $badges[] = ['text' => 'vlastní model', 'class' => 'primary'];
        }
        if ($this->hasTuningOverride($rowData)) {
            $badges[] = ['text' => 'vlastní ladění', 'class' => 'primary'];
        }
        $row['i1'] = $badges;

        $connection = $this->connectionLabel($rowData);
        $t2 = trim($effective['model'] . ($effective['model'] !== '' ? ' · ' : '') . $connection);
        $row['t2'] = $t2 !== '' ? ['text' => $t2, 'class' => 'muted'] : null;
        $row['t3'] = $functionId !== '' ? $functionId : null;

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow(
            'SELECT f.`id`, f.`function_id`, f.`connection`, f.`model`, f.`model_like`,'
            . ' f.`thinking`, f.`effort`, f.`max_tokens`, f.`created`, f.`modified`,'
            . ' b.`name` AS `connection_name`, b.`backend_id` AS `connection_code`, b.`is_active` AS `connection_active`'
            . ' FROM `' . $this->table . '` f'
            . ' LEFT JOIN `core_ai_backends` b ON b.`id` = f.`connection`'
            . ' WHERE f.`id` = %i',
            $recordId,
        );
        if ($record === null) {
            return ['tabs' => []];
        }

        $functionId = (string) ($record['function_id'] ?? '');
        $function = $this->registry()->get($functionId);
        $effective = $this->effectiveModel($function, $record);

        $functionItems = [];
        $this->addItem($functionItems, 'Funkce', $function?->name ?? $functionId);
        $this->addItem($functionItems, 'ID', $functionId);
        $this->addItem($functionItems, 'Popis', $function?->description);
        $this->addItem($functionItems, 'Úroveň', $function !== null ? $this->tierLabel($function->tier) : 'bez deklarace');
        if ($function !== null && $function->requires !== []) {
            $this->addItem($functionItems, 'Požadavky na model', implode(', ', $function->requires));
        }

        $callItems = [];
        $this->addItem($callItems, 'AI připojení', $this->connectionLabel($record)
            . (($record['connection'] ?? null) !== null ? ' (přepsáno)' : ''));
        $this->addItem($callItems, 'Model', $effective['model'] !== ''
            ? $effective['model'] . ($effective['origin'] === 'row' ? ' (přepsáno)' : ' (podle úrovně)')
            : null);
        if (($record['model_like'] ?? null) !== null && $record['model_like'] !== '') {
            $this->addItem($callItems, 'Tvarovat jako model', $record['model_like']);
        }

        $tuning = [];
        $this->addItem($tuning, 'Přemýšlení', $this->tuningLabel('core.ai.thinkingModes', $record['thinking'] ?? null));
        $this->addItem($tuning, 'Úsilí', $this->tuningLabel('core.ai.effortLevels', $record['effort'] ?? null));
        $maxTokens = (int) ($record['max_tokens'] ?? 0);
        $this->addItem($tuning, 'Max. tokenů', $maxTokens > 0
            ? "{$maxTokens} (přepsáno)"
            : ($function !== null ? "{$function->maxTokens} (podle funkce)" : null));
        if ($function?->temperature !== null) {
            $this->addItem($tuning, 'Teplota', (string) $function->temperature . ' (podle funkce)');
        }

        $status = [];
        $this->addItem($status, 'Vytvořeno', $record['created'] ?? null);
        $this->addItem($status, 'Změněno', $record['modified'] ?? null);

        return [
            'tabs' => [[
                'id' => 'overview',
                'label' => $this->defaultOverviewLabel(),
                'content' => [
                    'type' => 'properties',
                    'groups' => [
                        ['title' => 'Funkce', 'items' => $functionItems],
                        ['title' => 'Volání', 'items' => $callItems],
                        ['title' => 'Ladění', 'items' => $tuning],
                        ['title' => 'Stav', 'items' => $status],
                    ],
                ],
            ]],
        ];
    }

    /** Bez Přidat — řádky zakládá ds-upgrade pro každou deklarovanou funkci. */
    public function getToolbarActions(?array $selectedRow): array
    {
        return array_values(array_filter(
            parent::getToolbarActions($selectedRow),
            static fn (array $action): bool => ($action['id'] ?? '') !== 'create',
        ));
    }

    private function registry(): AiFunctionRegistry
    {
        return AiFunctionRegistry::fromConfig($this->config);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{model: string, origin: 'row'|'tier'|'none'}
     */
    private function effectiveModel(?AiFunction $function, array $row): array
    {
        $model = trim((string) ($row['model'] ?? ''));
        if ($model !== '') {
            return ['model' => $model, 'origin' => 'row'];
        }
        $tierModel = $function !== null ? ModelCatalog::fromConfig($this->config)->defaultModel($function->tier) : null;
        return $tierModel !== null
            ? ['model' => $tierModel, 'origin' => 'tier']
            : ['model' => '', 'origin' => 'none'];
    }

    /** @param array<string, mixed> $row */
    private function connectionLabel(array $row): string
    {
        if (($row['connection'] ?? null) === null) {
            return 'výchozí připojení';
        }
        $name = trim((string) ($row['connection_name'] ?? ''));
        if ($name === '') {
            return 'připojení #' . (int) $row['connection'] . ' (neexistuje)';
        }
        return $name . (empty($row['connection_active']) ? ' (neaktivní)' : '');
    }

    /** @param array<string, mixed> $row */
    private function hasTuningOverride(array $row): bool
    {
        return (string) ($row['thinking'] ?? 'default') !== 'default'
            || (string) ($row['effort'] ?? 'default') !== 'default'
            || (int) ($row['max_tokens'] ?? 0) > 0;
    }

    private function tierLabel(string $tier): string
    {
        $tiers = ModelCatalog::fromConfig($this->config)->tiers();
        return $tiers[$tier]['name'] ?? $tier;
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
