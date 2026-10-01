<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Reports;

use Shipard\Core\Reports\ReportMessage;
use Shipard\Core\Reports\ReportMessageSeverity;
use Shipard\Core\Reports\ReportRequest;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\Assets\AssetCategories;
use Shipard\Module\Economy\Assets\AssetDocument;
use Shipard\Module\Economy\Assets\AssetPlanService;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\Plan;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessageTexts;

/**
 * Sdílená vrstva reportů majetku (docs/assets.md D65, §5.6): zdrojem je
 * evidence — karty, události a plány — ne deník. Buildery ji skládají,
 * nedědí (vzor `JournalReportSupport`).
 *
 * Drží `AssetPlanService` po dobu jednoho běhu reportu: účetní období
 * a pravidla země se čtou jednou, plány všech karet hromadně (D71).
 *
 * DB přístup je v protected metodách (přepsatelné v testech).
 */
class AssetReportSupport
{
    /** Stavy karty, které do přehledů vstupují (bez konceptů a smazaných). */
    public const CARD_STATES = [
        AssetDocument::STATE_CONFIRMED,
        AssetDocument::STATE_ARCHIVED,
        AssetDocument::STATE_EDIT,
    ];

    private ?AssetPlanService $plans = null;

    // ── Prostředí ───────────────────────────────────────────────────────────

    public function planService(ReportRequest $request): AssetPlanService
    {
        return $this->plans ??= new AssetPlanService(
            $request->db->getDibiConnection(),
            $request->config,
            $request->country,
            new SettingsStore($request->db),
        );
    }

    public function categories(ReportRequest $request): AssetCategories
    {
        return $this->planService($request)->categories();
    }

    /**
     * Data zvoleného období a jeho účetního roku. Stav „k datu“ je vždy
     * poslední den období (`end`) — parametr typu datum reporty nemají.
     *
     * @return array{begin: string, end: string, yearBegin: string, yearEnd: string, yearId: int, yearName: string}
     */
    public function period(ReportRequest $request): array
    {
        $range = $request->range;
        if ($range === null) {
            throw new \RuntimeException("Report '{$request->reportId}' requires a fiscal period");
        }
        $plans = $this->planService($request);

        $year = null;
        foreach ($plans->fiscalYears() as $candidate) {
            if ($candidate['id'] === $range->fiscalYearId) {
                $year = $candidate;
                break;
            }
        }
        if ($year === null) {
            throw new \RuntimeException("Fiscal year '{$range->fiscalYear}' not found");
        }

        $begin = null;
        $end = null;
        $inRange = array_flip($range->monthIdsInRange);
        foreach ($plans->fiscalMonths() as $month) {
            if (isset($inRange[$month['id']])) {
                $begin = $begin === null ? $month['begin'] : min($begin, $month['begin']);
                $end = $end === null ? $month['end'] : max($end, $month['end']);
            }
        }

        return [
            'begin'     => $begin ?? $year['begin'],
            'end'       => $end ?? $year['end'],
            'yearBegin' => $year['begin'],
            'yearEnd'   => $year['end'],
            'yearId'    => $year['id'],
            'yearName'  => $year['name'],
        ];
    }

    // ── Karty ───────────────────────────────────────────────────────────────

    /**
     * Karty mimo koncepty a smazané, s názvem účetní skupiny, typu
     * a vlastníka; podle inventárního čísla.
     *
     * @param ?bool $longTerm true = jen dlouhodobý majetek, false = jen drobný, null = vše
     * @return list<array<string, mixed>>
     */
    public function cards(ReportRequest $request, ?bool $longTerm = null): array
    {
        $categories = $this->categories($request);
        $cards = [];
        foreach ($this->loadCards($request) as $card) {
            if ($longTerm === null || $categories->isLongTerm((string) ($card['category'] ?? '')) === $longTerm) {
                $cards[] = $card;
            }
        }
        return $cards;
    }

    /**
     * Dlouhodobé karty v evidenci v období: zařazené (nebo s počátečním
     * stavem) do jeho konce a nevyřazené před jeho začátkem. Karta bez
     * potvrzeného zařazení v evidenci není.
     *
     * @return list<AssetYear>
     */
    public function longTermInPeriod(ReportRequest $request, string $begin, string $end): array
    {
        $out = [];
        foreach ($this->planService($request)->plansOf($this->cards($request, true), $end) as $entry) {
            $item = new AssetYear(
                $entry['card'],
                $entry['tax'],
                $entry['acc'],
                CircuitYear::of($entry['tax'], $begin, $end),
                CircuitYear::of($entry['acc'], $begin, $end),
            );
            $start = $item->startDate();
            $disposal = $item->disposalDate();
            if ($start === null || $start > $end || ($disposal !== null && $disposal < $begin)) {
                continue;
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Potvrzené události účetního pohledu (okruh `both` / `acc`) daných
     * druhů s datem v období, od nejstarší. Daňové události se vynechávají —
     * pohyb majetku by jinak u karty s oddělenými okruhy vyšel dvakrát.
     *
     * @param list<string> $kinds
     * @return list<array<string, mixed>>
     */
    public function eventsInPeriod(ReportRequest $request, string $begin, string $end, array $kinds): array
    {
        return $kinds === [] ? [] : $this->loadEvents($request, $begin, $end, $kinds);
    }

    // ── Popisky ─────────────────────────────────────────────────────────────

    /** Název druhu události z cfgItem `economy.assets.eventKinds`; bez konfigurace klíč. */
    public static function eventKindLabel(ReportRequest $request, string $kind): string
    {
        $kinds = $request->config?->cfgItem('economy.assets.eventKinds');

        return is_array($kinds) && is_string($kinds[$kind]['name'] ?? null) ? $kinds[$kind]['name'] : $kind;
    }

    /** @param array<string, mixed> $card */
    public static function number(array $card): string
    {
        return trim((string) ($card['asset_number'] ?? ''));
    }

    /** @param array<string, mixed> $card */
    public static function name(array $card): string
    {
        $name = trim((string) ($card['name'] ?? ''));

        return $name !== '' ? $name : '#' . (int) ($card['id'] ?? 0);
    }

    /** Inventární číslo a název pro texty zpráv. @param array<string, mixed> $card */
    public static function title(array $card): string
    {
        return trim(self::number($card) . ' ' . self::name($card));
    }

    /** „Odpisová skupina 2 / Rovnoměrný“; prázdné, když karta daňovou metodu nemá. @param array<string, mixed> $card */
    public function taxRuleLabel(ReportRequest $request, array $card): string
    {
        $method = (string) ($card['tax_method'] ?? '');
        if ($method === '') {
            return '';
        }
        $rules = $this->planService($request)->rules();
        $methodName = $rules->methodName($method);
        $rule = (string) ($card['tax_rule'] ?? '');
        if ($rule === '') {
            return $methodName;
        }
        foreach ($rules->rules($method, null) as $def) {
            if ($def['code'] === $rule) {
                return $def['name'] . ' / ' . $methodName;
            }
        }
        return $rule . ' / ' . $methodName;
    }

    /** @param array<string, mixed> $card */
    public static function accountingGroupLabel(array $card, bool $cs): string
    {
        $label = trim(trim((string) ($card['group_code'] ?? '')) . ' ' . trim((string) ($card['group_name'] ?? '')));

        return $label !== '' ? $label : ($cs ? 'Bez účetní skupiny' : 'No accounting group');
    }

    /** @param array<string, mixed> $card */
    public static function typeLabel(array $card, bool $cs): string
    {
        $label = trim((string) ($card['type_name'] ?? ''));

        return $label !== '' ? $label : ($cs ? 'Bez typu' : 'No type');
    }

    // ── Buňky a řádky ───────────────────────────────────────────────────────

    /**
     * Peněžní buňka z jedné hodnoty evidence: kladná na MD, záporná na D,
     * `balance` = hodnota — součty po sloupcích pak sedí i u rozdílů.
     *
     * @return array{md: float, d: float, balance: float}
     */
    public static function money(float $value): array
    {
        $value = round($value, 2);

        return [
            'md'      => $value > 0 ? $value : 0.0,
            'd'       => $value < 0 ? -$value : 0.0,
            'balance' => $value == 0 ? 0.0 : $value,
        ];
    }

    /**
     * Index řádku ve výsledku podle `key` — pro `rowRef` zpráv po seskupení.
     *
     * @param list<ReportRow> $rows
     * @return array<string, int>
     */
    public static function rowIndex(array $rows): array
    {
        $index = [];
        foreach ($rows as $i => $row) {
            if ($row->key !== null) {
                $index[$row->key] = $i;
            }
        }
        return $index;
    }

    /**
     * Chyby plánů karty jako zprávy reportu: plán s chybou není spolehlivý
     * a čísla karty tím také ne (`status: errors`).
     *
     * @param array<string, int> $rowIndex výstup `rowIndex()`
     * @return list<ReportMessage>
     */
    public function planErrors(ReportRequest $request, AssetYear $item, array $rowIndex): array
    {
        $cs = $request->language === 'cs';
        $texts = new PlanMessageTexts($request->config);
        $index = $rowIndex['asset:' . $item->id()] ?? null;

        $messages = [];
        foreach ([[$item->taxPlan, $cs ? 'daňových' : 'tax'], [$item->accPlan, $cs ? 'účetních' : 'accounting']] as [$plan, $circuit]) {
            $error = self::firstError($plan, $texts);
            if ($error === null) {
                continue;
            }
            $messages[] = new ReportMessage(
                ReportMessageSeverity::Error,
                'assets.planError',
                $cs
                    ? sprintf('%s: plán %s odpisů má chybu — %s', self::title($item->card), $circuit, $error)
                    : sprintf('%s: the %s depreciation plan has an error — %s', self::title($item->card), $circuit, $error),
                $index !== null ? "rows.{$index}" : null,
            );
        }
        return $messages;
    }

    private static function firstError(Plan $plan, PlanMessageTexts $texts): ?string
    {
        foreach ($plan->allMessages() as $message) {
            if ($message->isError()) {
                return $texts->text($message);
            }
        }
        return null;
    }

    /** „3 karty“ / „5 karet“ — počet karet v textu zprávy. */
    public static function cardCount(int $count, bool $cs): string
    {
        if (!$cs) {
            return $count . ($count === 1 ? ' card' : ' cards');
        }
        return $count . ' ' . ($count === 1 ? 'karta' : ($count >= 2 && $count <= 4 ? 'karty' : 'karet'));
    }

    public static function czDate(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('j. n. Y');
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /** @return list<array<string, mixed>> */
    protected function loadCards(ReportRequest $request): array
    {
        $rows = $request->db->getDibiConnection()->fetchAll(
            'SELECT [a].*, [g].[code] AS [group_code], [g].[name] AS [group_name], [g].[sort_order] AS [group_order],'
            . ' [t].[name] AS [type_name], [p].[full_name] AS [owner_name]'
            . ' FROM [' . AssetDocument::TABLE . '] [a]'
            . ' LEFT JOIN [economy_assets_accounting_groups] [g] ON [g].[id] = [a].[accounting_group]'
            . ' LEFT JOIN [economy_assets_types] [t] ON [t].[id] = [a].[asset_type]'
            . ' LEFT JOIN [base_persons_persons] [p] ON [p].[id] = [a].[owner]'
            . ' WHERE [a].[docState] IN %in'
            . ' ORDER BY [a].[asset_number], [a].[id]',
            self::CARD_STATES,
        );
        return array_map(static fn(iterable $row): array => AssetPlanService::plain($row), $rows);
    }

    /**
     * @param list<string> $kinds
     * @return list<array<string, mixed>>
     */
    protected function loadEvents(ReportRequest $request, string $begin, string $end, array $kinds): array
    {
        $rows = $request->db->getDibiConnection()->fetchAll(
            'SELECT * FROM [' . AssetPlanService::EVENTS_TABLE . ']'
            . ' WHERE [docState] = %i AND [event_kind] IN %in AND [scope] <> %s'
            . ' AND [event_date] >= %s AND [event_date] <= %s'
            . ' ORDER BY [event_date], [id]',
            AssetPlanService::STATE_CONFIRMED,
            $kinds,
            AssetEvent::SCOPE_TAX,
            $begin,
            $end,
        );
        return array_map(static fn(iterable $row): array => AssetPlanService::plain($row), $rows);
    }
}
