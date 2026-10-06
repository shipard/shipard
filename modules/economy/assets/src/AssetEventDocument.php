<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Economy\Assets\Depreciation\Amounts;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\Period;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessageTexts;
use Shipard\Module\Economy\Assets\Depreciation\PlanRow;
use Shipard\Module\Economy\Codebooks\FiscalMonthLookup;

/**
 * Hodnotová událost majetku (economy_assets_events, docs/assets.md D3,
 * D28–D29, D35, D38; pravidla per druh v tasks/assets-phase2b.md).
 *
 * Tvarová pravidla (povinná pole, kladné částky, období) platí vždy;
 * pravidla závislá na kartě a ostatních událostech se ověřují při
 * potvrzení (stav 40):
 *   - karta je dlouhodobá a ve stavu V pořádku, bez potvrzeného vyřazení;
 *   - ruční událost kromě počátečního stavu má datum v založeném účetním
 *     roce (D58, `outsideFiscalYear`) — starší historie patří do
 *     počátečního stavu;
 *   - historie se rozebírá od konce (D29): za událostí nesmí v jejím
 *     okruhu být potvrzený odpis (`notAtEnd`);
 *   - zařazení / počáteční stav ověří odpisové nastavení karty k datu
 *     zařazení (`DepreciationSettingsValidator`);
 *   - snížení nejvýš do zůstatkové ceny obou okruhů, odpis do zůstatku
 *     okruhu, přerušení jen u přerušitelné metody a v roce bez odpisu,
 *     vyřazení až po zařazení a bez událostí za ním; polovina jen když
 *     to pravidla dovolují a majetek byl v evidenci na začátku roku.
 *
 * Importní mód (fáze 6, docs/assets.md §5.7): applier posílá marker
 * `_import` (`IMPORT_KEY`) — událost dostane původ `import`, lock
 * providery se nevolají (`isLockExempt`, historie v zamčených měsících)
 * a pravidla, která historická data splnit nemohou, se neuplatní:
 * `cardNotConfirmed` (karta bez úplné účetní skupiny je koncept, D79),
 * `aboveResidual`, `reductionAboveResidual`, `halfYearNotAllowed`,
 * `yearDepreciated`, `alreadyInterrupted` a `planHasErrors` u vyřazení
 * (chybu plánu vrátí applier jako varování). Potvrzené vyřazení
 * v importu nezakládá poslední odpisy — posílá je runner (D76). Uložená
 * událost původu `import` má volněji jen celé koruny a účetní rok.
 *
 * Efekty na kartu (D38) běží v `afterPersist()` uvnitř transakce, kdykoli
 * událost vstupuje do stavu 40 nebo ho opouští (oprava i smazání):
 * datum pořízení z potvrzeného zařazení / počátečního stavu, datum
 * vyřazení z vyřazení; potvrzené vyřazení nejdřív založí chybějící odpisy
 * obou okruhů (D35, `SystemDepreciationWriter`) a pak přesune kartu do
 * archivu, zrušené vyřazení ji vrátí do V opravě a smaže systémové odpisy
 * k datu vyřazení, které vyřazení založilo (D56); jsou-li zaúčtované,
 * přechod se odmítne (`disposalPosted`). Odpisy dřívějších období, které
 * vyřazení doplnilo, zůstávají — jdou smazat od konce.
 *
 * DB přístup je v protected metodách (přepsatelné v testech).
 */
class AssetEventDocument extends Document
{
    public const TABLE = 'economy_assets_events';

    /** Marker importního módu v payloadu (applier fáze 6); do SQL nejde. */
    public const IMPORT_KEY = '_import';

    public const STATE_CONFIRMED = 40;
    /** mainState stavu 40 v cfgItem economy.assets.eventStates. */
    public const MAIN_CONFIRMED = 3;

    public const STATE_DELETED = 90;
    /** mainState stavu 90 v cfgItem economy.assets.eventStates. */
    public const MAIN_DELETED = 4;

    /** Stavy účetního dokladu, ve kterých vazba `doc_head` neznamená zaúčtování. */
    public const DEAD_DOC_STATES = [30, 90];

    /** Povolené okruhy per druh; jediná hodnota se doplní sama. */
    private const SCOPES_BY_KIND = [
        AssetEvent::KIND_OPENING      => [AssetEvent::SCOPE_TAX, AssetEvent::SCOPE_ACC],
        AssetEvent::KIND_ACTIVATION   => [AssetEvent::SCOPE_BOTH],
        AssetEvent::KIND_IMPROVEMENT  => [AssetEvent::SCOPE_BOTH],
        AssetEvent::KIND_REDUCTION    => [AssetEvent::SCOPE_BOTH],
        AssetEvent::KIND_DEPRECIATION => [AssetEvent::SCOPE_TAX, AssetEvent::SCOPE_ACC],
        AssetEvent::KIND_INTERRUPTION => [AssetEvent::SCOPE_TAX],
        AssetEvent::KIND_DISPOSAL     => [AssetEvent::SCOPE_BOTH],
    ];

    /** Druhy jen pro odepisovaný majetek. */
    private const DEPRECIABLE_ONLY = [
        AssetEvent::KIND_OPENING,
        AssetEvent::KIND_DEPRECIATION,
        AssetEvent::KIND_INTERRUPTION,
    ];

    private const SCOPE_LABELS = [
        AssetEvent::SCOPE_TAX => 'daňový',
        AssetEvent::SCOPE_ACC => 'účetní',
    ];

    private const PLAN_LABELS = [
        AssetEvent::SCOPE_TAX => 'plán daňových odpisů',
        AssetEvent::SCOPE_ACC => 'plán účetních odpisů',
    ];

    /** Sloučený řádek z beforeSave — afterPersist ho potřebuje i u částečného payloadu. */
    private array $row = [];

    /** Datum události před uložením — zrušené vyřazení maže odpisy k původnímu datu. */
    private ?string $originalDate = null;

    /** Uložení v importním módu (marker `_import`) — vyřazení nezakládá odpisy. */
    private bool $importMode = false;

    private ?AssetPlanService $planService = null;

    // ── Validace ────────────────────────────────────────────────────────────

    /** Importní mód: marker `_import` v payloadu (applier fáze 6). */
    public function isLockExempt(array $data): bool
    {
        return !empty($data[self::IMPORT_KEY]);
    }

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $import = $this->isLockExempt($data);
        $original = !empty($data['id']) ? $this->loadEvent((int) $data['id']) : null;
        $row = $original !== null ? array_merge($original, $data) : $data;
        // Původ určuje server (beforeSave) — payload ho nesmí podvrhnout;
        // importní mód ho nese markerem, ne polem.
        $row['origin'] = $import ? AssetEvent::ORIGIN_IMPORT : (string) ($original['origin'] ?? AssetEvent::ORIGIN_MANUAL);

        $kind = (string) ($row['event_kind'] ?? '');
        if (!AssetEvent::isKind($kind)) {
            $result->addError('event_kind', 'Neznámý druh události.', 'invalid');
            return $result;
        }
        if ($original !== null) {
            if ((string) ($original['event_kind'] ?? '') !== $kind) {
                $result->addError('event_kind', 'Druh uložené události nelze měnit.', 'immutable');
            }
            if ((int) ($original['asset'] ?? 0) !== (int) ($row['asset'] ?? 0)) {
                $result->addError('asset', 'Událost nelze přesunout na jinou kartu.', 'immutable');
            }
            if (!$result->isValid()) {
                return $result;
            }
        }

        $assetId = (int) ($row['asset'] ?? 0);
        $card = $assetId > 0 ? $this->loadCard($assetId) : null;
        if ($card === null) {
            $result->addError('asset', 'Událost musí patřit kartě majetku.', 'required');
            return $result;
        }

        // Zrušené vyřazení maže systémové odpisy, které založilo (D56) —
        // zaúčtované se smazat nedají, nejdřív se ruší zaúčtování období.
        if ($original !== null
            && $kind === AssetEvent::KIND_DISPOSAL
            && (int) ($original['docState'] ?? 0) === self::STATE_CONFIRMED
            && (int) ($row['docState'] ?? 10) !== self::STATE_CONFIRMED
        ) {
            $originalDate = self::date($original['event_date'] ?? null);
            $posted = $originalDate !== null ? $this->postedDisposalDepreciations($assetId, $originalDate) : [];
            if ($posted !== []) {
                $result->addError(
                    ValidationError::FIELD_FORM,
                    'Odpisy založené vyřazením jsou zaúčtované (doklad ' . implode(', ', $posted)
                    . '). Nejdřív zruš zaúčtování období.',
                    'disposalPosted',
                );
                return $result;
            }
        }

        $categories = $this->categories();
        $category = (string) ($card['category'] ?? '');
        if (!$categories->isLongTerm($category)) {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Události hodnoty lze zadávat jen u dlouhodobého majetku.',
                'notLongTerm',
            );
            return $result;
        }
        $depreciable = $categories->isDepreciable($category);
        if (!$depreciable && in_array($kind, self::DEPRECIABLE_ONLY, true)) {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Neodepisovaný majetek nemá odpisy, přerušení ani počáteční stav odpisů.',
                'notDepreciable',
            );
            return $result;
        }

        $allowedScopes = self::SCOPES_BY_KIND[$kind];
        $scope = count($allowedScopes) === 1 ? $allowedScopes[0] : (string) ($row['scope'] ?? '');
        if (!in_array($scope, $allowedScopes, true)) {
            $result->addError('scope', 'Pro tento druh události zvol daňový, nebo účetní okruh.', 'invalidScope');
        }
        $data['scope'] = $scope;
        $row['scope'] = $scope;

        $date = self::date($row['event_date'] ?? null);
        if ($date === null) {
            $result->addError('event_date', 'Datum události je povinné.', 'required');
        }

        $amount = $row['amount'] ?? null;
        if ($amount !== null && $amount !== '' && !is_numeric($amount)) {
            $result->addError('amount', 'Částka musí být číslo.', 'invalid');
            $amount = 0.0;
        }
        $amount = (float) ($amount ?? 0);
        if ($amount < 0) {
            $result->addError('amount', 'Částka nesmí být záporná.', 'negative');
        }

        $this->validateShape($kind, $row, $amount, $result);

        if (!$result->isValid() || (int) ($row['docState'] ?? 10) !== self::STATE_CONFIRMED || $date === null) {
            return $result;
        }

        $this->validateConfirmation($kind, $scope, $date, $amount, $row, $card, $depreciable, $import, $result);

        return $result;
    }

    /**
     * Tvarová pravidla per druh — platí i pro koncept.
     *
     * @param array<string, mixed> $row
     */
    private function validateShape(string $kind, array $row, float $amount, ValidationResult $result): void
    {
        switch ($kind) {
            case AssetEvent::KIND_ACTIVATION:
            case AssetEvent::KIND_IMPROVEMENT:
            case AssetEvent::KIND_REDUCTION:
                if ($amount <= 0) {
                    $result->addError('amount', 'Částka musí být větší než nula.', 'positive');
                }
                break;

            case AssetEvent::KIND_OPENING:
                if ($amount <= 0) {
                    $result->addError('amount', 'Vstupní cena musí být větší než nula.', 'positive');
                }
                $accumulated = (float) ($row['accumulated'] ?? 0);
                if ($accumulated < 0 || $accumulated > $amount + Amounts::EPSILON) {
                    $result->addError('accumulated', 'Oprávky musí být mezi nulou a vstupní cenou.', 'invalid_range');
                }
                if ((int) ($row['units_done'] ?? 0) < 0) {
                    $result->addError('units_done', 'Počet odepsaných období nesmí být záporný.', 'negative');
                }
                $originalDate = self::date($row['original_date'] ?? null);
                $date = self::date($row['event_date'] ?? null);
                if ($originalDate === null) {
                    $result->addError('original_date', 'Datum původního zařazení je povinné.', 'required');
                } elseif ($date !== null && $originalDate > $date) {
                    $result->addError('original_date', 'Původní zařazení nemůže být po datu počátečního stavu.', 'invalid_range');
                }
                break;

            case AssetEvent::KIND_DEPRECIATION:
                $begin = self::date($row['period_begin'] ?? null);
                $end = self::date($row['period_end'] ?? null);
                if ($begin === null) {
                    $result->addError('period_begin', 'Začátek období odpisu je povinný.', 'required');
                }
                if ($end === null) {
                    $result->addError('period_end', 'Konec období odpisu je povinný.', 'required');
                } elseif ($begin !== null && $begin > $end) {
                    $result->addError('period_end', 'Konec období nesmí být před jeho začátkem.', 'invalid_range');
                }
                break;
        }
    }

    /**
     * Pravidla při potvrzení — závislá na kartě a ostatních událostech.
     * `$import` = importní mód (uvolněná pravidla, viz doc-comment třídy).
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $card
     */
    private function validateConfirmation(
        string $kind,
        string $scope,
        string $date,
        float $amount,
        array $row,
        array $card,
        bool $depreciable,
        bool $import,
        ValidationResult $result,
    ): void {
        $assetId = (int) $card['id'];
        $siblings = $this->confirmedEvents($assetId, !empty($row['id']) ? (int) $row['id'] : null);

        foreach ($siblings as $sibling) {
            if ($sibling['event_kind'] === AssetEvent::KIND_DISPOSAL) {
                $result->addError(
                    ValidationError::FIELD_FORM,
                    'Majetek je vyřazen — po potvrzeném vyřazení nelze potvrzovat další události.',
                    'afterDisposal',
                );
                return;
            }
        }
        // Import smí potvrzovat historii i na kartě-konceptu (D79).
        if (!$import && (int) ($card['docState'] ?? 0) !== AssetDocument::STATE_CONFIRMED) {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Událost lze potvrdit jen u karty ve stavu V pořádku.',
                'cardNotConfirmed',
            );
            return;
        }

        // D58: ruční událost mimo založené účetní roky by nešla zaúčtovat
        // ani odepsat — majetek ze starší doby se zadává počátečním stavem.
        if ($kind !== AssetEvent::KIND_OPENING
            && (string) $row['origin'] === AssetEvent::ORIGIN_MANUAL
            && !$this->inFiscalYear($date)
        ) {
            $result->addError(
                'event_date',
                'Datum není v žádném účetním roce. Majetek zařazený před prvním účetním obdobím zadej jako počáteční stav.',
                'outsideFiscalYear',
            );
            return;
        }

        // Historie se rozebírá od konce (D29): za událostí nesmí v jejím
        // okruhu být potvrzený odpis.
        $key = self::orderKey($row);
        foreach ($siblings as $sibling) {
            if ($sibling['event_kind'] === AssetEvent::KIND_DEPRECIATION
                && self::inScope((string) $sibling['scope'], $scope)
                && self::orderKey($sibling) > $key
            ) {
                $result->addError(
                    ValidationError::FIELD_FORM,
                    'Za událostí už je potvrzený ' . self::SCOPE_LABELS[(string) $sibling['scope']]
                    . ' odpis za období do ' . self::czDate((string) $sibling['period_end'])
                    . ' — historie se rozebírá od konce, nejdřív zruš pozdější odpisy.',
                    'notAtEnd',
                );
                return;
            }
        }

        $activation = null;
        $openings = [];
        foreach ($siblings as $sibling) {
            if ($sibling['event_kind'] === AssetEvent::KIND_ACTIVATION) {
                $activation = $sibling;
            } elseif ($sibling['event_kind'] === AssetEvent::KIND_OPENING) {
                $openings[(string) $sibling['scope']] = $sibling;
            }
        }
        $start = static fn(string $circuit): ?array => $activation ?? $openings[$circuit] ?? null;
        $service = $this->planService();
        $calendar = $service->taxCalendar();

        switch ($kind) {
            case AssetEvent::KIND_ACTIVATION:
                if ($activation !== null || $openings !== []) {
                    $result->addError(ValidationError::FIELD_FORM, 'Majetek už je zařazen.', 'alreadyActivated');
                    return;
                }
                $this->addSettingsProblems($card, $date, $result);
                break;

            case AssetEvent::KIND_OPENING:
                if ($activation !== null) {
                    $result->addError(
                        ValidationError::FIELD_FORM,
                        'Majetek je zařazen v Shipardu — počáteční stav se zadává jen u majetku s historií jinde.',
                        'alreadyActivated',
                    );
                    return;
                }
                if (isset($openings[$scope])) {
                    $result->addError(ValidationError::FIELD_FORM, 'Počáteční stav tohoto okruhu už je zadaný.', 'openingExists');
                    return;
                }
                if ($calendar->yearOf($date)->begin !== $date) {
                    $result->addError(
                        'event_date',
                        'Počáteční stav se zadává k prvnímu dni účetního roku ('
                        . self::czDate($calendar->yearOf($date)->begin) . ').',
                        'openingNotAtYearStart',
                    );
                }
                $this->addSettingsProblems($card, self::date($row['original_date'] ?? null), $result);
                break;

            case AssetEvent::KIND_IMPROVEMENT:
            case AssetEvent::KIND_REDUCTION:
                $starts = [$start(AssetEvent::SCOPE_TAX), $start(AssetEvent::SCOPE_ACC)];
                if (in_array(null, $starts, true)) {
                    $result->addError(
                        ValidationError::FIELD_FORM,
                        'Majetek musí být nejdřív zařazen (nebo mít počáteční stav obou okruhů).',
                        'notActivated',
                    );
                    return;
                }
                $this->requireAfterStart($starts, $date, $result);
                if ($kind === AssetEvent::KIND_IMPROVEMENT) {
                    $taxMethod = (string) ($card['tax_method'] ?? '');
                    if ($depreciable && $taxMethod !== '' && !$service->rules()->allowsImprovement($taxMethod)) {
                        $result->addError(
                            ValidationError::FIELD_FORM,
                            'Technické zhodnocení majetku s touto daňovou metodou se odpisuje samostatně — založ pro něj vlastní kartu.',
                            'improvementOnSchedule',
                        );
                    }
                } else {
                    $plans = $service->plan($card, $siblings);
                    $residual = min($plans['tax']->residual, $plans['acc']->residual);
                    if (!$import && $amount > $residual + Amounts::EPSILON) {
                        $result->addError(
                            'amount',
                            'Snížení hodnoty nesmí být větší než zůstatková cena (' . Amounts::money($residual) . ').',
                            'reductionAboveResidual',
                        );
                    }
                }
                break;

            case AssetEvent::KIND_DEPRECIATION:
                $circuitStart = $start($scope);
                if ($circuitStart === null) {
                    $result->addError(ValidationError::FIELD_FORM, 'Majetek není v tomto okruhu zařazen.', 'notActivated');
                    return;
                }
                $begin = (string) self::date($row['period_begin'] ?? null);
                $end = (string) self::date($row['period_end'] ?? null);
                if ($calendar->yearOf($date)->begin !== $calendar->yearOf($end)->begin || $date < $begin) {
                    $result->addError(
                        'event_date',
                        'Datum odpisu musí ležet v účetním roce konce období odpisu, ne před jeho začátkem.',
                        'dateOutsidePeriod',
                    );
                }
                foreach ($siblings as $sibling) {
                    if ($sibling['event_kind'] === AssetEvent::KIND_DEPRECIATION
                        && (string) $sibling['scope'] === $scope
                        && (string) $sibling['period_end'] >= $begin
                        && (string) $sibling['period_begin'] <= $end
                    ) {
                        $result->addError('period_begin', 'Období se překrývá s potvrzeným odpisem.', 'periodOverlap');
                        break;
                    }
                }
                if ((string) ($row['origin'] ?? '') !== AssetEvent::ORIGIN_IMPORT && !$this->isWhole($scope, $amount)) {
                    $result->addError('amount', 'Odpis musí být zaokrouhlený na celé koruny.', 'notWholeUnits');
                }
                $plan = $service->plan($card, $siblings)[$scope];
                if (!$import && $amount > $plan->residual + Amounts::EPSILON) {
                    $result->addError(
                        'amount',
                        'Odpis nesmí být větší než zůstatková cena okruhu (' . Amounts::money($plan->residual) . ').',
                        'aboveResidual',
                    );
                }
                break;

            case AssetEvent::KIND_INTERRUPTION:
                if ($start(AssetEvent::SCOPE_TAX) === null) {
                    $result->addError(ValidationError::FIELD_FORM, 'Majetek není daňově zařazen.', 'notActivated');
                    return;
                }
                $taxMethod = (string) ($card['tax_method'] ?? '');
                if ($taxMethod === '' || !$service->rules()->isInterruptible($taxMethod)) {
                    $result->addError(
                        ValidationError::FIELD_FORM,
                        'Daňovou metodu této karty nelze přerušit.',
                        'notInterruptible',
                    );
                    return;
                }
                $year = $calendar->yearOf($date);
                foreach ($import ? [] : $siblings as $sibling) {
                    $isTaxDepreciation = $sibling['event_kind'] === AssetEvent::KIND_DEPRECIATION
                        && (string) $sibling['scope'] === AssetEvent::SCOPE_TAX
                        && $year->contains((string) $sibling['period_end']);
                    if ($isTaxDepreciation) {
                        $result->addError(
                            ValidationError::FIELD_FORM,
                            'V účetním roce ' . self::czDate($year->begin) . ' – ' . self::czDate($year->end)
                            . ' už je potvrzený daňový odpis — přerušit lze jen rok bez odpisu.',
                            'yearDepreciated',
                        );
                        return;
                    }
                    if ($sibling['event_kind'] === AssetEvent::KIND_INTERRUPTION
                        && $year->contains((string) $sibling['event_date'])
                    ) {
                        $result->addError(ValidationError::FIELD_FORM, 'Odpisy tohoto roku už jsou přerušené.', 'alreadyInterrupted');
                        return;
                    }
                }
                break;

            case AssetEvent::KIND_DISPOSAL:
                $starts = array_values(array_filter([$activation, ...array_values($openings)]));
                if ($starts === []) {
                    $result->addError(ValidationError::FIELD_FORM, 'Majetek není zařazen — není co vyřadit.', 'notActivated');
                    return;
                }
                if (!$this->requireAfterStart($starts, $date, $result)) {
                    return;
                }
                foreach ($siblings as $sibling) {
                    if (self::orderKey($sibling) > self::orderKey($row)) {
                        $result->addError(
                            'event_date',
                            'Po datu vyřazení existuje potvrzená událost (' . self::czDate((string) $sibling['event_date']) . ').',
                            'eventsAfterDisposal',
                        );
                        return;
                    }
                }
                if (!$import && !empty($row['half_year']) && !$service->halfYearAllowed($card, $siblings, $date)) {
                    $result->addError(
                        'half_year',
                        'Polovinu ročního odpisu lze uplatnit jen u majetku evidovaného na začátku roku a u metod, které to dovolují.',
                        'halfYearNotAllowed',
                    );
                }
                if (!$result->isValid() || $import) {
                    return;
                }
                // Vyřazení zakládá poslední odpisy (D35) — plán obou okruhů musí být čistý.
                $simulated = $row;
                $simulated['confirmed'] = true;
                unset($simulated['id']);
                $plans = $service->plan($card, [...$siblings, $simulated]);
                $texts = new PlanMessageTexts($this->config);
                foreach ($plans as $circuit => $plan) {
                    foreach ($plan->allMessages() as $message) {
                        if ($message->isError()) {
                            $result->addError(
                                ValidationError::FIELD_FORM,
                                'Vyřazení nelze potvrdit — ' . self::PLAN_LABELS[$circuit] . ' má chybu: '
                                . $texts->text($message),
                                'planHasErrors',
                            );
                            break;
                        }
                    }
                }
                break;
        }
    }

    /**
     * @param list<array<string, mixed>|null> $starts
     * @return bool false = datum je před zařazením (chyba přidána)
     */
    private function requireAfterStart(array $starts, string $date, ValidationResult $result): bool
    {
        foreach ($starts as $startEvent) {
            if ($startEvent !== null && $date < (string) $startEvent['event_date']) {
                $result->addError(
                    'event_date',
                    'Datum nesmí být před zařazením (' . self::czDate((string) $startEvent['event_date']) . ').',
                    'beforeActivation',
                );
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $card */
    private function addSettingsProblems(array $card, ?string $acquiredDate, ValidationResult $result): void
    {
        $service = $this->planService();
        $validator = new DepreciationSettingsValidator($service->rules(), $service->categories());
        foreach ($validator->problems($card, $acquiredDate) as $problem) {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Odpisové nastavení karty: ' . $problem['message'],
                $problem['code'],
            );
        }
    }

    /** Leží datum v některém založeném účetním roce? */
    private function inFiscalYear(string $date): bool
    {
        foreach ($this->planService()->fiscalYears() as $year) {
            if ($date >= $year['begin'] && $date <= $year['end']) {
                return true;
            }
        }
        return false;
    }

    private function isWhole(string $scope, float $amount): bool
    {
        if ($scope === AssetEvent::SCOPE_TAX) {
            return abs($this->planService()->rules()->round($amount) - $amount) < Amounts::EPSILON;
        }
        return Amounts::isWhole($amount);
    }

    // ── Uložení ─────────────────────────────────────────────────────────────

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $this->trackStateChange($data, $originalData);

        // Marker importního módu je virtuální pole — ven z dat před SQL.
        $this->importMode = $this->isLockExempt($data);
        unset($data[self::IMPORT_KEY]);

        // Vazbu na účetní doklad píše jen zaúčtování majetku (D52).
        unset($data['doc_head']);

        $row = array_merge($originalData ?? [], $data);
        $kind = (string) ($row['event_kind'] ?? '');
        $allowedScopes = self::SCOPES_BY_KIND[$kind] ?? [];
        if (count($allowedScopes) === 1) {
            $data['scope'] = $allowedScopes[0];
        }

        // Původ určuje server: ruční zápis přes dokument je `manual`,
        // systémové odpisy vznikají mimo dokument, import nese marker.
        $data['origin'] = $originalData === null
            ? ($this->importMode ? AssetEvent::ORIGIN_IMPORT : AssetEvent::ORIGIN_MANUAL)
            : (string) ($originalData['origin'] ?? AssetEvent::ORIGIN_MANUAL);

        if ($kind !== AssetEvent::KIND_OPENING) {
            $data['accumulated'] = null;
            $data['units_done'] = null;
            $data['price_increased'] = 0;
            $data['original_date'] = null;
        }
        if ($kind === AssetEvent::KIND_INTERRUPTION) {
            $year = $this->planService()->taxCalendar()->yearOf((string) self::date($row['event_date'] ?? null));
            $data['period_begin'] = $year->begin;
            $data['period_end'] = $year->end;
        } elseif ($kind !== AssetEvent::KIND_DEPRECIATION) {
            $data['period_begin'] = null;
            $data['period_end'] = null;
        }
        if ($kind === AssetEvent::KIND_INTERRUPTION || $kind === AssetEvent::KIND_DISPOSAL) {
            $data['amount'] = 0;
        }
        if ($kind !== AssetEvent::KIND_DISPOSAL && $kind !== AssetEvent::KIND_DEPRECIATION) {
            $data['half_year'] = 0;
        }
        if ($data['origin'] !== AssetEvent::ORIGIN_IMPORT) {
            $data['claim_unrecorded'] = 0;
        }
        if (array_key_exists('note', $data) && $data['note'] !== null) {
            $trimmed = trim((string) $data['note']);
            $data['note'] = $trimmed === '' ? null : $trimmed;
        }

        $this->row = array_merge($row, $data);
        $this->originalDate = self::date($originalData['event_date'] ?? null);
    }

    public function afterPersist(array $data): void
    {
        $t = $this->stateTransition;
        if ($t === null) {
            return;
        }
        $was = $t['old'] === self::STATE_CONFIRMED;
        $is = $t['new'] === self::STATE_CONFIRMED;
        if ($was === $is) {
            return;
        }

        $row = array_merge($this->row, $data);
        $assetId = (int) ($row['asset'] ?? 0);
        $kind = (string) ($row['event_kind'] ?? '');
        if ($assetId <= 0) {
            return;
        }

        // Import posílá poslední odpisy sám (D76) — vyřazení je nezakládá.
        if ($kind === AssetEvent::KIND_DISPOSAL && $is && !$this->importMode) {
            $this->writeFinalDepreciations($assetId);
        }
        if ($kind === AssetEvent::KIND_DISPOSAL && $was && $this->originalDate !== null) {
            $this->writer()->removeForDisposal($assetId, $this->originalDate);
        }
        $this->syncCard($assetId, $kind === AssetEvent::KIND_DISPOSAL ? $is : null);
    }

    /**
     * Poslední odpisy obou okruhů při vyřazení (D35): vše, co plán
     * s potvrzeným vyřazením ještě nabízí — daňový odpis roku vyřazení
     * (polovina dle `half_year`), účetní do měsíce vyřazení a případná
     * dřívější neodepsaná období. Potvrzené odpisy se nepřepisují.
     */
    private function writeFinalDepreciations(int $assetId): void
    {
        $plans = $this->planService()->planForAsset($assetId);
        if ($plans === null) {
            return;
        }
        $writer = $this->writer();
        foreach ($plans as $circuit => $plan) {
            if ($plan->hasErrors()) {
                throw new \DomainException(
                    'Vyřazení nelze potvrdit — ' . self::PLAN_LABELS[$circuit] . ' má chyby.',
                );
            }
            $rows = array_values(array_filter(
                $plan->plannedRows(),
                static fn(PlanRow $r): bool => $r->isDepreciation(),
            ));
            if ($rows !== []) {
                $writer->write($assetId, $circuit, $rows);
            }
        }
    }

    /**
     * Data karty z potvrzených událostí (D38): datum pořízení ze zařazení
     * nebo z původního zařazení počátečního stavu, datum vyřazení
     * z vyřazení. Potvrzené vyřazení přesune kartu do archivu, zrušené ji
     * vrátí do V opravě — systémový přechod přímým UPDATE uvnitř transakce
     * (Document nemá gateway; karta prošla validací už při vlastním
     * potvrzení).
     *
     * @param bool|null $disposalConfirmed true = vyřazení právě potvrzeno,
     *     false = právě zrušeno, null = jiná událost
     */
    private function syncCard(int $assetId, ?bool $disposalConfirmed): void
    {
        $acquired = null;
        $openings = [];
        $disposed = null;
        foreach ($this->confirmedEvents($assetId, null) as $event) {
            switch ($event['event_kind']) {
                case AssetEvent::KIND_ACTIVATION:
                    $acquired = (string) $event['event_date'];
                    break;
                case AssetEvent::KIND_OPENING:
                    $openings[(string) $event['scope']] = self::date($event['original_date'] ?? null);
                    break;
                case AssetEvent::KIND_DISPOSAL:
                    $disposed ??= (string) $event['event_date'];
                    break;
            }
        }
        $acquired ??= $openings[AssetEvent::SCOPE_TAX] ?? $openings[AssetEvent::SCOPE_ACC] ?? null;

        $update = ['acquired_date' => $acquired, 'disposed_date' => $disposed];
        $card = $this->loadCard($assetId);
        $cardState = (int) ($card['docState'] ?? 0);
        if ($disposalConfirmed === true && $cardState === AssetDocument::STATE_CONFIRMED) {
            $update['docState'] = AssetDocument::STATE_ARCHIVED;
            $update['docStateMain'] = AssetDocument::MAIN_ARCHIVED;
        } elseif ($disposalConfirmed === false && $cardState === AssetDocument::STATE_ARCHIVED) {
            $update['docState'] = AssetDocument::STATE_EDIT;
            $update['docStateMain'] = AssetDocument::MAIN_EDIT;
        }
        $this->updateCard($assetId, $update);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Přechod docState (vzor AssetDocument). Nový záznam vzniklý rovnou
     * mimo Koncept je přechod s old = 0.
     */
    protected function trackStateChange(array $data, ?array $originalData): void
    {
        $this->stateTransition = null;

        if ($originalData === null) {
            $newState = (int) ($data['docState'] ?? 10);
            if ($newState !== 10) {
                $this->stateTransition = ['old' => 0, 'new' => $newState];
            }
            return;
        }

        $newState = (int) ($data['docState'] ?? $originalData['docState'] ?? 10);
        $oldState = (int) ($originalData['docState'] ?? 10);
        if ($newState !== $oldState) {
            $this->stateTransition = ['old' => $oldState, 'new' => $newState];
        }
    }

    /**
     * Pořadí události v historii: datum, pořadí druhu v rámci dne
     * (`AssetEvent::kindOrder`), id; neuložená událost je za uloženými
     * téhož dne a druhu.
     *
     * @param array<string, mixed> $row
     * @return array{string, int, int}
     */
    public static function orderKey(array $row): array
    {
        return [
            self::date($row['event_date'] ?? null) ?? '',
            AssetEvent::kindOrder((string) ($row['event_kind'] ?? '')),
            !empty($row['id']) ? (int) $row['id'] : PHP_INT_MAX,
        ];
    }

    /** Patří událost okruhu `$eventScope` do okruhu `$scope` (`both` = oba)? */
    public static function inScope(string $eventScope, string $scope): bool
    {
        return $scope === AssetEvent::SCOPE_BOTH
            || $eventScope === AssetEvent::SCOPE_BOTH
            || $eventScope === $scope;
    }

    public static function date(mixed $value): ?string
    {
        $date = FiscalMonthLookup::isoDate($value);

        return $date !== null && Period::isDate($date) ? $date : null;
    }

    private static function czDate(string $date): string
    {
        return Period::isDate($date) ? (new \DateTimeImmutable($date))->format('j. n. Y') : $date;
    }

    protected function categories(): AssetCategories
    {
        return new AssetCategories($this->config);
    }

    protected function planService(): AssetPlanService
    {
        return $this->planService ??= new AssetPlanService(
            $this->db,
            $this->config,
            $this->dsConfig?->getCountry() ?? 'cz',
            $this->settings,
        );
    }

    protected function writer(): SystemDepreciationWriter
    {
        return new SystemDepreciationWriter($this->db);
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /** @return array<string, mixed>|null */
    protected function loadEvent(int $id): ?array
    {
        $row = $this->db?->fetch('SELECT * FROM [' . self::TABLE . '] WHERE [id] = %i', $id);

        return $row === null || $row === false ? null : AssetPlanService::plain($row);
    }

    /** @return array<string, mixed>|null */
    protected function loadCard(int $assetId): ?array
    {
        return $this->planService()->card($assetId);
    }

    /**
     * Potvrzené události karty kromě `$excludeId`, chronologicky.
     *
     * @return list<array<string, mixed>>
     */
    protected function confirmedEvents(int $assetId, ?int $excludeId): array
    {
        $events = $this->planService()->confirmedEvents($assetId);
        if ($excludeId === null) {
            return $events;
        }
        return array_values(array_filter(
            $events,
            static fn(array $e): bool => (int) ($e['id'] ?? 0) !== $excludeId,
        ));
    }

    /**
     * Čísla živých účetních dokladů, kterými jsou zaúčtované systémové
     * odpisy karty k datu vyřazení.
     *
     * @return list<string>
     */
    protected function postedDisposalDepreciations(int $assetId, string $date): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT [h].[id], [h].[doc_number] FROM [' . self::TABLE . '] [e]'
            . ' JOIN [docs_core_heads] [h] ON [h].[id] = [e].[doc_head]'
            . ' WHERE [e].[asset] = %i AND [e].[event_kind] = %s AND [e].[origin] = %s'
            . ' AND [e].[event_date] = %d AND [e].[docState] = %i AND [h].[docState] NOT IN %in'
            . ' ORDER BY [h].[id]',
            $assetId,
            AssetEvent::KIND_DEPRECIATION,
            AssetEvent::ORIGIN_SYSTEM,
            $date,
            self::STATE_CONFIRMED,
            self::DEAD_DOC_STATES,
        );
        $numbers = [];
        foreach ($rows as $row) {
            $numbers[] = (string) ($row['doc_number'] ?? '') !== '' ? (string) $row['doc_number'] : '#' . (int) $row['id'];
        }
        return $numbers;
    }

    /** @param array<string, mixed> $values */
    protected function updateCard(int $assetId, array $values): void
    {
        $this->db?->query('UPDATE [' . AssetDocument::TABLE . '] SET %a WHERE [id] = %i', $values, $assetId);
    }
}
