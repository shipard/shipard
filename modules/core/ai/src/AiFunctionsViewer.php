<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Ai;

use Shipard\Core\Ai\AiCallPlan;
use Shipard\Core\Ai\AiCallPreview;
use Shipard\Core\Ai\AiFunction;
use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\AiTexts;
use Shipard\Core\Ai\RequestChange;
use Shipard\Core\Database\SearchCondition;

/**
 * Viewer „Funkce“ v Nastavení → AI (tasks/ai-functions.md D38,
 * tasks/ai-settings-section.md D51): jeden řádek na deklarovanou funkci,
 * pořadí podle registru (deklarací modulů). Název a popis funkce jdou
 * z registru, ne z databáze; řádek ukazuje efektivní model a připojení.
 *
 * Detail říká, **co se modelu skutečně pošle**: plán z
 * {@see AiFunctionResolver::preview()} (hodnoty s původem, bez
 * dešifrování klíče), úpravy, které by {@see \Shipard\Core\Ai\RequestShaper}
 * provedl, a stav volání — chybu, kterou by volání skončilo, s radou, kde
 * to opravit ({@see AiTexts}). Přidat se nenabízí — řádky zakládá ds-upgrade.
 */
class AiFunctionsViewer extends AiViewerBase
{
    private ?AiFunctionResolver $resolver = null;

    /** Platforma výchozího aktivního připojení pro odhad v seznamu; `false` = ještě nenačteno. */
    private string|false $defaultPlatform = false;

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT f.`id`, f.`function_id`, f.`connection`, f.`model`, f.`model_like`,'
            . ' f.`thinking`, f.`effort`, f.`max_tokens`,'
            . ' b.`name` AS `connection_name`, b.`is_active` AS `connection_active`, b.`provider` AS `connection_provider`'
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
            $badges[] = ['text' => $this->text('badge.noDeclaration', 'no declaration'), 'class' => 'warning'];
        }
        if ($effective['origin'] === 'row') {
            $badges[] = ['text' => $this->text('badge.customModel', 'custom model'), 'class' => 'primary'];
        }
        if ($this->hasTuningOverride($rowData)) {
            $badges[] = ['text' => $this->text('badge.customTuning', 'custom tuning'), 'class' => 'primary'];
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
        $preview = $this->resolver()->preview($functionId);
        $texts = $this->texts();

        $functionItems = [];
        $this->addItem($functionItems, $this->text('label.function', 'Function'), $function?->name ?? $functionId);
        $this->addItem($functionItems, $this->text('label.id', 'ID'), $functionId);
        $this->addItem($functionItems, $this->text('label.description', 'Description'), $function?->description);
        $this->addItem(
            $functionItems,
            $this->text('label.tier', 'Tier'),
            $function !== null ? $this->tierLabel($function->tier) : $this->text('badge.noDeclaration', 'no declaration'),
        );
        if ($function !== null && $function->requires !== []) {
            $this->addItem($functionItems, $this->text('label.requires', 'Model requirements'), implode(', ', $function->requires));
        }

        [$callItems, $tuning] = $preview->plan !== null
            ? $this->plannedCall($preview->plan, $texts)
            : $this->storedCall($function, $record);

        $groups = [
            ['title' => $this->text('group.function', 'Function'), 'items' => $functionItems],
            ['title' => $this->text('group.call', 'Call'), 'items' => $callItems],
            ['title' => $this->text('group.tuning', 'Tuning'), 'items' => $tuning],
        ];
        if ($preview->plan !== null) {
            $groups[] = ['title' => $this->text('group.changes', 'Request adjustments'), 'items' => $this->changeItems($preview, $texts)];
        }
        $groups[] = ['title' => $this->text('group.callStatus', 'Call status'), 'items' => $this->statusItems($preview, $functionId, $texts)];

        $record_ = [];
        $this->addItem($record_, $this->text('label.created', 'Created'), $record['created'] ?? null);
        $this->addItem($record_, $this->text('label.modified', 'Modified'), $record['modified'] ?? null);
        if ($record_ !== []) {
            $groups[] = ['title' => $this->text('group.status', 'Record'), 'items' => $record_];
        }

        return [
            'title' => $function?->name ?? $functionId,
            'subtitle' => $functionId,
            'badges' => [$preview->isOk()
                ? ['label' => $this->text('badge.callOk', 'OK'), 'style' => 'success']
                : ['label' => $this->text('badge.callError', 'Setup error'), 'style' => 'danger']],
            'tabs' => [[
                'id' => 'overview',
                'label' => $this->defaultOverviewLabel(),
                'content' => ['type' => 'properties', 'groups' => $groups],
            ]],
        ];
    }

    /** Bez Přidat — řádky zakládá ds-upgrade pro každou deklarovanou funkci. */
    public function getToolbarActions(?array $selectedRow): array
    {
        return array_values(array_filter(
            parent::getToolbarActions($selectedRow),
            static fn (array $action): bool => ($action['id'] !== 'create'),
        ));
    }

    /** Seam pro testy — resolver nad DB, compiled configem a konfigurací zdroje dat. */
    protected function resolver(): AiFunctionResolver
    {
        return $this->resolver ??= new AiFunctionResolver($this->db, $this->config, $this->dsConfig);
    }

    protected function texts(): AiTexts
    {
        return new AiTexts($this->config, $this->registry());
    }

    private function registry(): AiFunctionRegistry
    {
        return AiFunctionRegistry::fromConfig($this->config);
    }

    /**
     * Skupiny Volání a Ladění z plánu resolveru: efektivní hodnoty
     * s původem (přepsáno volajícím / nastaveno u funkce / podle deklarace /
     * podle úrovně / výchozí připojení).
     *
     * @return array{0: list<array{label: string, value: string}>, 1: list<array{label: string, value: string}>}
     */
    private function plannedCall(AiCallPlan $plan, AiTexts $texts): array
    {
        $origin = static fn (string $key): string => $texts->origin($plan->origins[$key] ?? '');

        $call = [];
        $this->addItem(
            $call,
            $this->text('label.connection', 'AI connection'),
            $this->connectionName($plan) . ' — ' . $origin('connection'),
        );
        $this->addItem($call, $this->text('label.platform', 'Platform'), $this->platformLabel($plan->platform));
        $this->addItem($call, $this->text('label.baseUrl', 'API address'), $plan->baseUrl);
        if (abs($plan->priceMultiplier - 1.0) > 0.0001) {
            // Příplatek platformy proti ceníku katalogu (tasks/ai-bedrock-eu.md D60).
            $this->addItem($call, $this->text('label.price', 'Price'), $this->text(
                'value.priceMultiplier',
                '{multiplier}× the catalog price list',
                ['multiplier' => rtrim(rtrim(number_format($plan->priceMultiplier, 2, $this->language === 'cs' ? ',' : '.', ''), '0'), ',.')],
            ));
        }
        $this->addItem($call, $this->text('label.model', 'Model'), $plan->model . ' — ' . $origin('model'));
        if ($plan->isCustomModel() && $plan->shapeAs !== null) {
            $this->addItem($call, $this->text('label.shapeAs', 'Shape as model'), $plan->shapeAs);
        }

        $notSent = $this->text('value.notSent', 'not sent');
        $tuning = [];
        $this->addItem(
            $tuning,
            $this->text('label.thinking', 'Thinking'),
            ($plan->thinking !== null ? $this->tuningLabel('core.ai.thinkingModes', $plan->thinking) : $notSent) . ' — ' . $origin('thinking'),
        );
        $this->addItem(
            $tuning,
            $this->text('label.effort', 'Effort'),
            ($plan->effort !== null ? $this->tuningLabel('core.ai.effortLevels', $plan->effort) : $notSent) . ' — ' . $origin('effort'),
        );
        $this->addItem($tuning, $this->text('label.maxTokens', 'Max. tokens'), $plan->maxTokens . ' — ' . $origin('maxTokens'));
        $this->addItem(
            $tuning,
            $this->text('label.temperatureValue', 'Temperature'),
            ($plan->temperature !== null ? (string) $plan->temperature : $notSent) . ' — ' . $texts->origin('declaration'),
        );
        return [$call, $tuning];
    }

    /**
     * Bez plánu (chyba nastavení): co je u funkce uložené, bez tvrzení
     * o efektivních hodnotách.
     *
     * @param array<string, mixed> $record
     * @return array{0: list<array{label: string, value: string}>, 1: list<array{label: string, value: string}>}
     */
    private function storedCall(?AiFunction $function, array $record): array
    {
        $effective = $this->effectiveModel($function, $record);
        $call = [];
        $this->addItem($call, $this->text('label.connection', 'AI connection'), $this->connectionLabel($record));
        $this->addItem($call, $this->text('label.model', 'Model'), $effective['model'] !== '' ? $effective['model'] : null);
        if (($record['model_like'] ?? null) !== null && $record['model_like'] !== '') {
            $this->addItem($call, $this->text('label.shapeAs', 'Shape as model'), $record['model_like']);
        }

        $tuning = [];
        $this->addItem($tuning, $this->text('label.thinking', 'Thinking'), $this->tuningLabel('core.ai.thinkingModes', $record['thinking'] ?? null));
        $this->addItem($tuning, $this->text('label.effort', 'Effort'), $this->tuningLabel('core.ai.effortLevels', $record['effort'] ?? null));
        $maxTokens = (int) ($record['max_tokens'] ?? 0);
        $this->addItem($tuning, $this->text('label.maxTokens', 'Max. tokens'), $maxTokens > 0 ? (string) $maxTokens : ($function?->maxTokens));
        if ($function?->temperature !== null) {
            $this->addItem($tuning, $this->text('label.temperatureValue', 'Temperature'), (string) $function->temperature);
        }
        return [$call, $tuning];
    }

    /** @return list<array{label: string, value: string}> */
    private function changeItems(AiCallPreview $preview, AiTexts $texts): array
    {
        $items = [];
        if ($preview->changes === []) {
            $this->addItem($items, $this->text('label.result', 'Result'), $this->text('value.noChanges', 'None — the model accepts every value as set'));
            return $items;
        }
        foreach ($preview->changes as $change) {
            $this->addItem($items, $this->changeLabel($change), $texts->change($change));
        }
        return $items;
    }

    /** @return list<array{label: string, value: string}> */
    private function statusItems(AiCallPreview $preview, string $functionId, AiTexts $texts): array
    {
        $items = [];
        if ($preview->error === null) {
            $this->addItem($items, $this->text('label.result', 'Result'), $this->text('value.callOk', 'OK — the connection has a key stored; ai-test checks whether it works'));
        } else {
            $this->addItem($items, $this->text('label.result', 'Result'), $texts->error($preview->error, withHint: false));
            $this->addItem($items, $this->text('label.hint', 'Where to fix it'), $texts->hint($preview->error));
        }
        $this->addItem($items, $this->text('label.verify', 'Check from the console'), 'shpd-ds ai-test --function ' . $functionId);
        return $items;
    }

    private function changeLabel(RequestChange $change): string
    {
        return match ($change->code) {
            RequestChange::TEMPERATURE_OMITTED => $this->text('label.temperatureValue', 'Temperature'),
            RequestChange::THINKING_REPLACED, RequestChange::THINKING_OMITTED, RequestChange::THINKING_UNSUPPORTED => $this->text('label.thinking', 'Thinking'),
            RequestChange::EFFORT_OMITTED, RequestChange::EFFORT_LOWERED, RequestChange::EFFORT_CAPPED => $this->text('label.effort', 'Effort'),
            default => $this->text('label.maxTokens', 'Max. tokens'),
        };
    }

    /** Název připojení z plánu (jeden dotaz); bez řádku kód připojení. */
    private function connectionName(AiCallPlan $plan): string
    {
        $row = $this->db->fetchRow('SELECT `name`, `is_active` FROM `core_ai_backends` WHERE `id` = %i', $plan->connectionId);
        $name = trim((string) ($row['name'] ?? ''));
        $label = $name !== '' ? "{$name} ({$plan->connectionCode})" : $plan->connectionCode;
        return $label !== '' ? $label : '#' . $plan->connectionId;
    }

    /**
     * Levný odhad pro řádek seznamu (bez resolveru): model z řádku, jinak
     * z mapy úrovní pro platformu efektivního připojení (připojení řádku,
     * jinak výchozí; `platformModels`, tasks/ai-bedrock-eu.md D58) —
     * ID katalogu, ne ID platformy; to ukáže detail z plánu.
     *
     * @param array<string, mixed> $row
     * @return array{model: string, origin: 'row'|'tier'|'none'}
     */
    private function effectiveModel(?AiFunction $function, array $row): array
    {
        $model = trim((string) ($row['model'] ?? ''));
        if ($model !== '') {
            return ['model' => $model, 'origin' => 'row'];
        }
        $platform = ($row['connection'] ?? null) !== null
            ? trim((string) ($row['connection_provider'] ?? ''))
            : $this->defaultPlatform();
        $tierModel = $function !== null
            ? $this->catalog()->defaultModel($function->tier, $platform !== '' ? $platform : AiFunctionResolver::DEFAULT_PLATFORM)
            : null;
        return $tierModel !== null
            ? ['model' => $tierModel, 'origin' => 'tier']
            : ['model' => '', 'origin' => 'none'];
    }

    /** Platforma výchozího aktivního připojení (jednou za viewer); bez něj `''`. */
    private function defaultPlatform(): string
    {
        if ($this->defaultPlatform === false) {
            $row = $this->db->fetchRow(
                'SELECT `provider` FROM `core_ai_backends` WHERE `is_default` = %i AND `is_active` = %i LIMIT 1',
                1,
                1,
            );
            $this->defaultPlatform = trim((string) ($row['provider'] ?? ''));
        }
        return $this->defaultPlatform;
    }

    /** @param array<string, mixed> $row */
    private function connectionLabel(array $row): string
    {
        if (($row['connection'] ?? null) === null) {
            return $this->text('value.defaultConnection', 'default connection');
        }
        $name = trim((string) ($row['connection_name'] ?? ''));
        if ($name === '') {
            return $this->text('value.connectionMissing', 'connection #{id} (missing)', ['id' => (int) $row['connection']]);
        }
        return $name . (empty($row['connection_active']) ? ' (' . $this->text('value.inactive', 'inactive') . ')' : '');
    }

    /** @param array<string, mixed> $row */
    private function hasTuningOverride(array $row): bool
    {
        return (string) ($row['thinking'] ?? 'default') !== 'default'
            || (string) ($row['effort'] ?? 'default') !== 'default'
            || (int) ($row['max_tokens'] ?? 0) > 0;
    }
}
