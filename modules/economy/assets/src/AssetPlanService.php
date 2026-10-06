<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\DepreciationPlanner;
use Shipard\Module\Economy\Assets\Depreciation\DepreciationSettings;
use Shipard\Module\Economy\Assets\Depreciation\PeriodCalendar;
use Shipard\Module\Economy\Assets\Depreciation\Plan;
use Shipard\Module\World\Assets\TaxDepreciationRules;
use Shipard\Module\World\Assets\TaxRulesRegistry;

/**
 * Most mezi daty a odpisovým enginem (docs/assets.md D32): načte kartu,
 * potvrzené události, účetní období, pravidla země a četnost účetních
 * odpisů (D12) a zavolá `DepreciationPlanner`.
 *
 * Instance drží kalendář a pravidla po dobu jednoho požadavku — dávka
 * karet („Odpisy za období“) je čte jednou. Používá ji detail karty,
 * validace událostí, vyřazení i hromadné odpisy; plán se nikde neukládá.
 *
 * DB přístup je v protected metodách (přepsatelné v testech).
 */
class AssetPlanService
{
    public const SETTING_PERIODICITY = 'economy.assets.accPeriodicity';
    public const PERIODICITY_YEAR = 'year';
    public const PERIODICITY_MONTH = 'month';

    public const EVENTS_TABLE = 'economy_assets_events';
    public const STATE_CONFIRMED = 40;

    private ?TaxDepreciationRules $rules = null;
    private ?string $periodicity = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $years = null;
    /** @var list<array<string, mixed>>|null */
    private ?array $months = null;

    private ?PeriodCalendar $taxCalendar = null;
    /** @var array<string, PeriodCalendar> četnost → kalendář účetního okruhu */
    private array $accCalendars = [];
    private ?AssetCategories $categories = null;

    public function __construct(
        protected readonly ?\Dibi\Connection $db,
        protected readonly ?ConfigRuntime $config,
        protected readonly string $country,
        protected readonly ?SettingsStore $settings = null,
    ) {
    }

    // ── Prostředí ───────────────────────────────────────────────────────────

    public function rules(): TaxDepreciationRules
    {
        return $this->rules ??= TaxRulesRegistry::forCountry($this->config, $this->country);
    }

    public function categories(): AssetCategories
    {
        return $this->categories ??= new AssetCategories($this->config);
    }

    /** Četnost účetních odpisů (D12): `year` (výchozí) nebo `month`. */
    public function accPeriodicity(): string
    {
        if ($this->periodicity === null) {
            $value = $this->settings?->get(self::SETTING_PERIODICITY);
            $this->periodicity = $value === self::PERIODICITY_MONTH
                ? self::PERIODICITY_MONTH
                : self::PERIODICITY_YEAR;
        }
        return $this->periodicity;
    }

    public function isMonthly(): bool
    {
        return $this->accPeriodicity() === self::PERIODICITY_MONTH;
    }

    /**
     * Založené účetní roky, od nejstaršího.
     *
     * @return list<array{id: int, name: string, begin: string, end: string}>
     */
    public function fiscalYears(): array
    {
        return $this->years ??= $this->loadFiscalYears();
    }

    /**
     * Založené běžné účetní měsíce, od nejstaršího.
     *
     * @return list<array{id: int, fiscal_year: int, begin: string, end: string, locked: bool}>
     */
    public function fiscalMonths(): array
    {
        return $this->months ??= $this->loadFiscalMonths();
    }

    /** Zdaňovací období = účetní roky. */
    public function taxCalendar(): PeriodCalendar
    {
        return $this->taxCalendar ??= PeriodCalendar::yearly($this->fiscalYears());
    }

    public function accCalendar(): PeriodCalendar
    {
        return $this->accCalendars[$this->accPeriodicity()] ??= $this->isMonthly()
            ? PeriodCalendar::monthly($this->fiscalYears(), $this->fiscalMonths())
            : PeriodCalendar::yearly($this->fiscalYears());
    }

    public function today(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    // ── Data karty ──────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    public function card(int $assetId): ?array
    {
        return $this->loadCards([$assetId])[$assetId] ?? null;
    }

    /**
     * Potvrzené události karty v pořadí data a id.
     *
     * @return list<array<string, mixed>>
     */
    public function confirmedEvents(int $assetId): array
    {
        return $this->loadConfirmedEvents([$assetId])[$assetId] ?? [];
    }

    /**
     * Potvrzené události více karet jedním dotazem.
     *
     * @param list<int> $assetIds
     * @return array<int, list<array<string, mixed>>> id karty → události
     */
    public function confirmedEventsOf(array $assetIds): array
    {
        return $assetIds === [] ? [] : $this->loadConfirmedEvents($assetIds);
    }

    // ── Plán ────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $card */
    public function settingsOf(array $card): DepreciationSettings
    {
        return DepreciationSettings::fromArray([
            'tax_method' => $card['tax_method'] ?? null,
            'tax_rule'   => $card['tax_rule'] ?? null,
            'acc_method' => $card['acc_method'] ?? null,
            'acc_months' => $card['acc_months'] ?? null,
            'intangible' => $this->categories()->isIntangible((string) ($card['category'] ?? '')),
        ]);
    }

    /**
     * Plán obou okruhů. Řádek události je potvrzený, když má `docState` 40
     * nebo výslovný příznak `confirmed` (simulace ještě neuložené události).
     *
     * @param array<string, mixed> $card řádek karty
     * @param list<array<string, mixed>> $eventRows řádky `economy_assets_events`
     * @return array{tax: Plan, acc: Plan}
     */
    public function plan(array $card, array $eventRows, ?string $asOf = null): array
    {
        $events = [];
        foreach ($eventRows as $row) {
            $row['confirmed'] ??= (int) ($row['docState'] ?? 0) === self::STATE_CONFIRMED;
            $events[] = AssetEvent::fromArray($row);
        }

        return (new DepreciationPlanner())->plan(
            $this->settingsOf($card),
            $events,
            $this->rules(),
            $this->taxCalendar(),
            $this->accCalendar(),
            $asOf ?? $this->today(),
        );
    }

    /**
     * Smí vyřazení k datu uplatnit polovinu ročního daňového odpisu (D35)?
     * Pravidla země to musí dovolovat a majetek musí být v daňovém okruhu
     * v evidenci na začátku účetního roku vyřazení (zařazený dřív, nebo
     * počáteční stav právě k začátku roku).
     *
     * @param array<string, mixed> $card
     * @param list<array<string, mixed>> $eventRows potvrzené události karty
     */
    public function halfYearAllowed(array $card, array $eventRows, string $disposalDate): bool
    {
        $taxMethod = (string) ($card['tax_method'] ?? '');
        if ($taxMethod === '' || !$this->rules()->allowsHalfYearOnDisposal($taxMethod)) {
            return false;
        }
        $year = $this->taxCalendar()->yearOf($disposalDate);
        foreach ($eventRows as $row) {
            $kind = (string) ($row['event_kind'] ?? '');
            $scope = (string) ($row['scope'] ?? '');
            $date = substr((string) ($row['event_date'] ?? ''), 0, 10);
            if ($kind === AssetEvent::KIND_ACTIVATION) {
                return $date < $year->begin;
            }
            if ($kind === AssetEvent::KIND_OPENING && $scope === AssetEvent::SCOPE_TAX) {
                return $date <= $year->begin;
            }
        }
        return false;
    }

    /**
     * Zaúčtování událostí karty (D52): id události → živý účetní doklad
     * (mimo Storno / Smazáno). Importovaná událost (D76) je zaúčtovaná ve
     * starém systému bez dokladu — v mapě je s `docId` null a `external`
     * true. Událost, která v mapě není, zaúčtovaná není.
     *
     * @return array<int, array{docId: ?int, docNumber: string, external: bool}>
     */
    public function postingOf(int $assetId): array
    {
        return $this->loadPosting($assetId);
    }

    /** @return array{tax: Plan, acc: Plan}|null null = karta neexistuje */
    public function planForAsset(int $assetId): ?array
    {
        $card = $this->card($assetId);

        return $card === null ? null : $this->plan($card, $this->confirmedEvents($assetId));
    }

    /**
     * Plány mnoha karet najednou (D71): karty a potvrzené události jedním
     * dotazem na typ dat, účetní období a pravidla země jednou za instanci —
     * ne po kartách. Neexistující id se vynechá.
     *
     * @param list<int> $assetIds
     * @return array<int, array{card: array<string, mixed>, tax: Plan, acc: Plan}> id karty → karta a plány
     */
    public function plansFor(array $assetIds, ?string $asOf = null): array
    {
        $ids = array_values(array_unique(array_map(intval(...), $assetIds)));

        return $ids === [] ? [] : $this->plansOf($this->loadCards($ids), $asOf);
    }

    /**
     * Plány už načtených karet (řádky `economy_assets_assets`, klidně
     * s připojenými sloupci navíc) — události všech karet jedním dotazem.
     *
     * @param iterable<array<string, mixed>> $cards
     * @return array<int, array{card: array<string, mixed>, tax: Plan, acc: Plan}> id karty → karta a plány
     */
    public function plansOf(iterable $cards, ?string $asOf = null): array
    {
        $byId = [];
        foreach ($cards as $card) {
            $byId[(int) $card['id']] = $card;
        }
        $events = $this->confirmedEventsOf(array_keys($byId));
        $asOf ??= $this->today();

        $out = [];
        foreach ($byId as $id => $card) {
            $out[$id] = ['card' => $card] + $this->plan($card, $events[$id] ?? [], $asOf);
        }
        return $out;
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /**
     * @param list<int> $assetIds
     * @return array<int, array<string, mixed>> id → řádek karty
     */
    protected function loadCards(array $assetIds): array
    {
        if ($this->db === null || $assetIds === []) {
            return [];
        }
        $cards = [];
        foreach ($this->db->fetchAll(
            'SELECT * FROM [' . AssetDocument::TABLE . '] WHERE [id] IN %in ORDER BY [id]',
            $assetIds,
        ) as $row) {
            $cards[(int) $row['id']] = self::plain($row);
        }
        return $cards;
    }

    /**
     * @param list<int> $assetIds
     * @return array<int, list<array<string, mixed>>>
     */
    protected function loadConfirmedEvents(array $assetIds): array
    {
        if ($this->db === null || $assetIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM [' . self::EVENTS_TABLE . ']'
            . ' WHERE [asset] IN %in AND [docState] = %i ORDER BY [asset], [event_date], [id]',
            $assetIds,
            self::STATE_CONFIRMED,
        );
        $byAsset = [];
        foreach ($rows as $row) {
            $byAsset[(int) $row['asset']][] = self::plain($row);
        }
        return $byAsset;
    }

    /** @return array<int, array{docId: ?int, docNumber: string, external: bool}> */
    protected function loadPosting(int $assetId): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [e].[id], [e].[origin], [h].[id] AS [doc_id], [h].[doc_number] FROM [' . self::EVENTS_TABLE . '] [e]'
            . ' LEFT JOIN [docs_core_heads] [h] ON [h].[id] = [e].[doc_head] AND [h].[docState] NOT IN %in',
            AssetEventDocument::DEAD_DOC_STATES,
            'WHERE [e].[asset] = %i AND ([h].[id] IS NOT NULL OR [e].[origin] = %s)',
            $assetId,
            AssetEvent::ORIGIN_IMPORT,
        );
        $posting = [];
        foreach ($rows as $row) {
            $docId = $row['doc_id'] !== null ? (int) $row['doc_id'] : null;
            $posting[(int) $row['id']] = [
                'docId'     => $docId,
                'docNumber' => (string) ($row['doc_number'] ?? ''),
                'external'  => $docId === null && (string) $row['origin'] === AssetEvent::ORIGIN_IMPORT,
            ];
        }
        return $posting;
    }

    /** @return list<array{id: int, name: string, begin: string, end: string}> */
    protected function loadFiscalYears(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [id], [name], [date_begin], [date_end] FROM [economy_codebooks_fiscal_years]'
            . ' WHERE [docState] <> 90 ORDER BY [date_begin], [id]',
        );
        $years = [];
        foreach ($rows as $row) {
            $row = self::plain($row);
            $years[] = [
                'id'    => (int) $row['id'],
                'name'  => (string) $row['name'],
                'begin' => (string) $row['date_begin'],
                'end'   => (string) $row['date_end'],
            ];
        }
        return $years;
    }

    /** @return list<array{id: int, fiscal_year: int, begin: string, end: string, locked: bool}> */
    protected function loadFiscalMonths(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [m].[id], [m].[fiscal_year], [m].[date_begin], [m].[date_end], [m].[locked]'
            . ' FROM [economy_codebooks_fiscal_months] [m]'
            . ' JOIN [economy_codebooks_fiscal_years] [y] ON [y].[id] = [m].[fiscal_year]'
            . ' WHERE [m].[period_type] = 1 AND [y].[docState] <> 90 ORDER BY [m].[date_begin], [m].[id]',
        );
        $months = [];
        foreach ($rows as $row) {
            $row = self::plain($row);
            $months[] = [
                'id'          => (int) $row['id'],
                'fiscal_year' => (int) $row['fiscal_year'],
                'begin'       => (string) $row['date_begin'],
                'end'         => (string) $row['date_end'],
                'locked'      => !empty($row['locked']),
            ];
        }
        return $months;
    }

    /**
     * Řádek z Dibi jako obyčejné pole; data jako `Y-m-d` (Dibi je vrací
     * jako objekty a engine chce řetězce).
     *
     * @param iterable<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function plain(iterable $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[$key] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }
        return $out;
    }
}
