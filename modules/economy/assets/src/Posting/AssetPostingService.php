<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Posting;

use Shipard\Core\Settings\KeyValueStore;
use Shipard\Module\Economy\Assets\AssetDocument;
use Shipard\Module\Economy\Assets\AssetEventDocument;
use Shipard\Module\Economy\Assets\AssetPlanService;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\PlanRow;
use Shipard\Module\Economy\Assets\DepreciationRunService;
use Shipard\Module\Economy\Assets\SystemDepreciationWriter;

/**
 * „Odpisy a zaúčtování za období“ (docs/assets.md D50–D55): účetní běh,
 * který založí účetní odpisy období a všechny potvrzené nezaúčtované
 * události účetního okruhu s datem v období zaúčtuje **jedním** účetním
 * dokladem k poslednímu dni období.
 *
 * Vyhodnocení karty ({@see evaluate}) sdílí náhled i zaúčtování:
 *   - kandidáti odpisů z {@see DepreciationRunService} (účetní okruh),
 *   - nezaúčtované události karty do konce období,
 *   - vyloučení: nezaúčtované dřívější období (`earlierPeriodUnposted` —
 *     účtuje se bez děr), chyba plánu, neodepsané dřívější období, zamčený
 *     měsíc odpisu, chybějící účet účetní skupiny, neúplný plán vyřazení.
 *
 * `post()` běží v jedné transakci: zámek karet → odpisy
 * (`SystemDepreciationWriter`) → řádky ({@see AssetPostingBuilder}) →
 * doklad `cmnbkp` rovnou V pořádku ({@see AssetPostingDocuments}, deník
 * generuje handler účtování) → `doc_head` na událostech. Chyba kdekoli
 * (i neúspěšné účtování dokladu) = rollback. Idempotence: událost
 * s `doc_head` se znovu neúčtuje; období bez kandidátů doklad nezaloží.
 *
 * `unpost()` zruší jen poslední zaúčtované období: storno dokladu
 * a odpojení událostí. Systémové odpisy zůstávají potvrzené — jdou smazat
 * od konce. Zámek fiskálního měsíce hlídá u zápisu i storna standardní
 * `FiscalMonthLockProvider`.
 *
 * Daňový okruh se neúčtuje — ten dál potvrzuje `DepreciationRunService`.
 * DB přístup je v protected metodách (přepsatelné v testech).
 */
class AssetPostingService
{
    public const SCOPE = AssetEvent::SCOPE_ACC;

    public const REASON_EARLIER_UNPOSTED = 'earlierPeriodUnposted';

    public const ERROR_SERIES_MISSING = 'series_missing';
    public const ERROR_MONTH_LOCKED = 'monthLocked';
    public const ERROR_DOCUMENTS_UNAVAILABLE = 'documents_unavailable';
    public const ERROR_NOTHING_POSTED = 'nothing_posted';
    public const ERROR_NOT_LAST_PERIOD = 'notLastPeriod';

    /** Důvody vyloučení z odpisů, které vylučují kartu i ze zaúčtování (D55). */
    private const BLOCKING_RUN_REASONS = [
        DepreciationRunService::REASON_PLAN_ERROR,
        DepreciationRunService::REASON_EARLIER_MISSING,
        DepreciationRunService::REASON_MONTH_LOCKED,
    ];

    /** Sloupce účtů účetní skupiny → klíče vstupu builderu. */
    private const GROUP_ACCOUNTS = [
        'account_asset'        => AssetPostingInput::ACCOUNT_ASSET,
        'account_acquisition'  => AssetPostingInput::ACCOUNT_ACQUISITION,
        'account_accumulated'  => AssetPostingInput::ACCOUNT_ACCUMULATED,
        'account_depreciation' => AssetPostingInput::ACCOUNT_DEPRECIATION,
        'account_disposal'     => AssetPostingInput::ACCOUNT_DISPOSAL,
    ];

    /** Stavy účtu rozvrhu, na které jde účtovat (shodně s AccountingEngine). */
    private const LINKABLE_ACCOUNT_STATES = [10, 40, 70, 80];

    public function __construct(
        protected readonly ?\Dibi\Connection $db,
        protected readonly AssetPlanService $planService,
        protected readonly DepreciationRunService $runService,
        protected readonly SystemDepreciationWriter $writer,
        protected readonly ?AssetPostingDocuments $documents = null,
        protected readonly ?KeyValueStore $settings = null,
    ) {
    }

    // ── Náhled ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     * @throws \InvalidArgumentException neznámé období
     */
    public function preview(int $periodId): array
    {
        $period = $this->runService->resolvePeriod(self::SCOPE, $periodId);
        $evaluation = $this->evaluate($period);
        $series = $this->resolveSeries();
        $blockers = $this->blockers($period, $series);

        $depreciations = [];
        $depreciationTotal = 0.0;
        $events = [];
        $rows = [];
        foreach ($evaluation['cards'] as $card) {
            if ($card['candidate'] !== null) {
                $depreciations[] = [
                    'id'       => $card['id'],
                    'number'   => $card['number'],
                    'name'     => $card['name'],
                    'amount'   => $card['candidate']['amount'],
                    'formula'  => $card['candidate']['formula'],
                    'residual' => $card['candidate']['residual'],
                    'messages' => $card['candidate']['messages'],
                ];
                $depreciationTotal += $card['candidate']['amount'];
            }
            foreach ($card['events'] as $event) {
                $events[] = [
                    'assetId' => $card['id'],
                    'number'  => $card['number'],
                    'name'    => $card['name'],
                    'kind'    => (string) $event['event_kind'],
                    'date'    => (string) $event['event_date'],
                    'amount'  => (float) ($event['amount'] ?? 0),
                ];
            }
            array_push($rows, ...$card['rows']);
        }

        [$accounts, $debit, $credit] = $this->accountTotals($rows);

        return [
            'period'            => $period,
            'series'            => $series,
            'depreciations'     => $depreciations,
            'depreciationTotal' => $depreciationTotal,
            'events'            => $events,
            'accounts'          => $accounts,
            'totalDebit'        => $debit,
            'totalCredit'       => $credit,
            'rowCount'          => count($rows),
            'excluded'          => $evaluation['excluded'],
            'blockers'          => $blockers,
            'canPost'           => $blockers === [] && $rows !== [],
        ];
    }

    // ── Zaúčtování ──────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed> {posted, docId, docNumber, rowCount,
     *     depreciationCount, depreciationTotal, eventCount, assetIds, excluded}
     * @throws \InvalidArgumentException neznámé období
     * @throws AssetPostingException zaúčtování nejde provést nebo selhalo
     */
    public function post(int $periodId): array
    {
        $period = $this->runService->resolvePeriod(self::SCOPE, $periodId);
        $series = $this->resolveSeries();
        $blockers = $this->blockers($period, $series);
        if ($blockers !== []) {
            throw new AssetPostingException($blockers[0]['code'], $blockers[0]['message'], $blockers);
        }

        $this->begin();
        try {
            $this->lockCards();

            // 1. Účetní odpisy období — jen u karet, které se zaúčtují.
            $evaluation = $this->evaluate($period);
            $depreciationCount = 0;
            $depreciationTotal = 0.0;
            foreach ($evaluation['cards'] as $card) {
                if ($card['planned'] !== []) {
                    $depreciationTotal += $this->writer->write($card['id'], self::SCOPE, $card['planned']);
                    $depreciationCount++;
                }
            }
            // Nové odpisy jsou teď události s id — řádky se staví z uložených dat.
            if ($depreciationCount > 0) {
                $evaluation = $this->evaluate($period);
            }

            // 2. Řádky dokladu.
            $rows = [];
            $eventIds = [];
            $assetIds = [];
            foreach ($evaluation['cards'] as $card) {
                if ($card['planned'] !== []) {
                    throw new \LogicException("Karta {$card['id']}: po zápisu odpisů zůstal plánovaný odpis období");
                }
                array_push($rows, ...$card['rows']);
                array_push($eventIds, ...$card['eventIds']);
                $assetIds[] = $card['id'];
            }
            if ($rows === []) {
                $this->rollback();
                return [
                    'posted' => false, 'docId' => null, 'docNumber' => null, 'rowCount' => 0,
                    'depreciationCount' => 0, 'depreciationTotal' => 0.0, 'eventCount' => 0,
                    'assetIds' => [], 'excluded' => $evaluation['excluded'],
                ];
            }

            // 3. Doklad rovnou V pořádku; 4. vazba událostí.
            $document = $this->documents()->create($this->headData($period, (int) $series['id'], $rows));
            $this->linkEvents($eventIds, $document['id']);

            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }

        return [
            'posted'            => true,
            'docId'             => $document['id'],
            'docNumber'         => $document['number'],
            'rowCount'          => count($rows),
            'depreciationCount' => $depreciationCount,
            'depreciationTotal' => $depreciationTotal,
            'eventCount'        => count($eventIds),
            'assetIds'          => $assetIds,
            'excluded'          => $evaluation['excluded'],
        ];
    }

    // ── Zrušení zaúčtování ──────────────────────────────────────────────────

    /**
     * @return array{docIds: list<int>, docNumbers: list<string>, eventCount: int}
     * @throws \InvalidArgumentException neznámé období
     * @throws AssetPostingException období není zaúčtované, není poslední, storno selhalo
     */
    public function unpost(int $periodId): array
    {
        $period = $this->runService->resolvePeriod(self::SCOPE, $periodId);

        $docs = [];
        foreach ($this->loadPostedDocuments() as $doc) {
            if ($doc['accounting_date'] > $period['end']) {
                throw new AssetPostingException(
                    self::ERROR_NOT_LAST_PERIOD,
                    'Zrušit jde jen poslední zaúčtované období — nejdřív zruš zaúčtování dokladu '
                    . $doc['doc_number'] . ' k ' . self::czDate($doc['accounting_date']) . '.',
                );
            }
            if ($doc['accounting_date'] >= $period['begin']) {
                $docs[] = $doc;
            }
        }
        if ($docs === []) {
            throw new AssetPostingException(self::ERROR_NOTHING_POSTED, 'Období nemá zaúčtování majetku.');
        }

        $docIds = array_column($docs, 'id');
        $this->begin();
        try {
            $this->lockCards();
            foreach ($docIds as $docId) {
                $this->documents()->cancel($docId);
            }
            $eventCount = $this->unlinkEvents($docIds);
            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }

        return ['docIds' => $docIds, 'docNumbers' => array_column($docs, 'doc_number'), 'eventCount' => $eventCount];
    }

    /**
     * Poslední zaúčtované období — to jediné jde zrušit (D53).
     *
     * @return array{periodId: int|null, periodName: string, accountingDate: string,
     *     docs: list<array{id: int, number: string}>}|null
     */
    public function lastPosting(): ?array
    {
        $docs = $this->loadPostedDocuments();
        if ($docs === []) {
            return null;
        }
        $lastDate = max(array_column($docs, 'accounting_date'));
        $period = $this->periodOfDate($lastDate);
        $begin = $period['begin'] ?? $lastDate;

        $last = [];
        foreach ($docs as $doc) {
            if ($doc['accounting_date'] >= $begin) {
                $last[] = ['id' => $doc['id'], 'number' => $doc['doc_number']];
            }
        }
        return [
            'periodId'       => $period['id'] ?? null,
            'periodName'     => $period['name'] ?? self::czDate($lastDate),
            'accountingDate' => $lastDate,
            'docs'           => $last,
        ];
    }

    // ── Vyhodnocení karet ───────────────────────────────────────────────────

    /**
     * Karty k zaúčtování a vyloučené karty období.
     *
     * @param array{id: int, kind: string, begin: string, end: string, name: string} $period
     * @return array{cards: list<array<string, mixed>>, excluded: list<array<string, mixed>>}
     */
    private function evaluate(array $period): array
    {
        $run = $this->runService->preview(self::SCOPE, $period['id']);
        $candidates = array_column($run['assets'], null, 'id');
        $runExcluded = array_column($run['excluded'], null, 'id');

        $unposted = [];
        foreach ($this->loadUnpostedEvents($period['end']) as $event) {
            if (AssetPostingBuilder::isPostable((string) $event['event_kind'], (string) $event['scope'])) {
                $unposted[(int) $event['asset']][] = $event;
            }
        }

        $blocked = [];
        foreach ($runExcluded as $id => $item) {
            if (in_array($item['reason'], self::BLOCKING_RUN_REASONS, true)) {
                $blocked[$id] = $item;
            }
        }

        $ids = array_values(array_unique([...array_keys($candidates), ...array_keys($unposted), ...array_keys($blocked)]));
        $cards = $this->loadCards($ids);
        $builder = new AssetPostingBuilder();

        $included = [];
        $excluded = [];
        foreach ($cards as $card) {
            $id = (int) $card['id'];
            $base = ['id' => $id, 'number' => (string) ($card['asset_number'] ?? ''), 'name' => (string) ($card['name'] ?? '')];

            $earlier = null;
            $events = [];
            foreach ($unposted[$id] ?? [] as $event) {
                if ((string) $event['event_date'] < $period['begin']) {
                    $earlier ??= (string) $event['event_date'];
                } else {
                    $events[] = $event;
                }
            }
            if ($earlier !== null) {
                $excluded[] = $base + ['reason' => self::REASON_EARLIER_UNPOSTED, 'detail' => self::czDate($earlier)];
                continue;
            }
            if (isset($blocked[$id])) {
                $excluded[] = $base + ['reason' => $blocked[$id]['reason'], 'detail' => (string) ($blocked[$id]['detail'] ?? '')];
                continue;
            }

            $candidate = $candidates[$id] ?? null;
            /** @var list<PlanRow> $planned */
            $planned = $candidate['rows'] ?? [];
            if ($events === [] && $planned === []) {
                continue;
            }

            $hasDisposal = false;
            foreach ($events as $event) {
                $hasDisposal = $hasDisposal || $event['event_kind'] === AssetEvent::KIND_DISPOSAL;
            }
            $result = $builder->build(new AssetPostingInput(
                $id,
                $base['number'],
                $base['name'],
                $card['accounts'],
                [...$events, ...array_map(fn(PlanRow $row): array => self::plannedEvent($id, $row), $planned)],
                $hasDisposal ? $this->planService->plan($card, $this->planService->confirmedEvents($id))[self::SCOPE] : null,
            ));
            if (!$result->isOk()) {
                $excluded[] = $base + ['reason' => (string) $result->errorCode, 'detail' => (string) $result->errorMessage];
                continue;
            }

            $included[] = $base + [
                'events'    => $events,
                'planned'   => $planned,
                'candidate' => $candidate === null ? null : [
                    'amount'   => (float) $candidate['amount'],
                    'formula'  => (string) ($candidate['formula'] ?? ''),
                    'residual' => (float) ($candidate['residual'] ?? 0),
                    'messages' => $candidate['messages'] ?? [],
                ],
                'rows'      => $result->rows,
                'eventIds'  => $result->eventIds,
            ];
        }

        return ['cards' => $included, 'excluded' => $excluded];
    }

    /**
     * Plánovaný odpis jako událost pro builder (náhled; při zaúčtování už
     * odpis existuje jako uložená událost).
     *
     * @return array<string, mixed>
     */
    private static function plannedEvent(int $assetId, PlanRow $row): array
    {
        return [
            'id'           => 0,
            'asset'        => $assetId,
            'event_kind'   => AssetEvent::KIND_DEPRECIATION,
            'scope'        => self::SCOPE,
            'event_date'   => $row->date,
            'period_begin' => $row->period?->begin,
            'period_end'   => $row->period?->end,
            'amount'       => $row->amount,
        ];
    }

    /**
     * Překážky celého běhu: řada dokladů, zamčený měsíc účetního data
     * dokladu, chybějící definice dokladů.
     *
     * @param array{id: int, kind: string, begin: string, end: string, name: string} $period
     * @param array{id: int, name: string}|null $series
     * @return list<array{code: string, message: string}>
     */
    private function blockers(array $period, ?array $series): array
    {
        $blockers = [];
        if ($series === null) {
            $blockers[] = [
                'code'    => self::ERROR_SERIES_MISSING,
                'message' => 'Není nastavená řada účetních dokladů majetku (Nastavení → Majetek → Odpisy).',
            ];
        }
        $locked = $this->lockedMonthLabel($period['end']);
        if ($locked !== null) {
            $blockers[] = [
                'code'    => self::ERROR_MONTH_LOCKED,
                'message' => "Účetní měsíc {$locked} je zamčený — doklad k " . self::czDate($period['end']) . ' nejde založit.',
            ];
        }
        if ($this->documents === null || !$this->documents->isAvailable()) {
            $blockers[] = [
                'code'    => self::ERROR_DOCUMENTS_UNAVAILABLE,
                'message' => 'Účetní doklady nejsou dostupné — spusťte ds-upgrade.',
            ];
        }
        return $blockers;
    }

    /**
     * @param array{id: int, kind: string, begin: string, end: string, name: string} $period
     * @param list<AssetPostingRow> $rows
     * @return array<string, mixed>
     */
    private function headData(array $period, int $seriesId, array $rows): array
    {
        $docRows = [];
        foreach (AssetPostingBuilder::sort($rows) as $index => $row) {
            $docRows[] = ['order_pos' => $index + 1] + $row->toDocRow();
        }

        return [
            'doc_type'        => AssetPostingSeries::DOC_TYPE,
            'number_series'   => $seriesId,
            'issue_date'      => $this->planService->today(),
            'accounting_date' => $period['end'],
            'doc_text'        => mb_substr('Majetek — zaúčtování ' . $period['name'], 0, 200),
            'vat_mode'        => 0,
            'notice'          => 'Doklad sestavil Shipard z potvrzených událostí majetku. Zaúčtování se ruší v Majetku'
                . ' akcí Zrušit zaúčtování období.',
            'rows'            => $docRows,
        ];
    }

    /**
     * Součty řádků per účet pro náhled.
     *
     * @param list<AssetPostingRow> $rows
     * @return array{list<array<string, mixed>>, float, float} [účty, Σ MD, Σ DAL]
     */
    private function accountTotals(array $rows): array
    {
        $totals = [];
        $debit = 0.0;
        $credit = 0.0;
        foreach ($rows as $row) {
            $totals[$row->account] ??= ['debit' => 0.0, 'credit' => 0.0];
            if ($row->side === AssetPostingRow::SIDE_DEBIT) {
                $totals[$row->account]['debit'] += $row->amount;
                $debit += $row->amount;
            } else {
                $totals[$row->account]['credit'] += $row->amount;
                $credit += $row->amount;
            }
        }
        $labels = $this->loadAccountLabels(array_keys($totals));
        $accounts = [];
        foreach ($totals as $accountId => $sums) {
            $accounts[] = [
                'account' => $accountId,
                'number'  => (string) ($labels[$accountId]['number'] ?? ''),
                'name'    => (string) ($labels[$accountId]['name'] ?? ''),
                'debit'   => round($sums['debit'], 2),
                'credit'  => round($sums['credit'], 2),
            ];
        }
        usort($accounts, static fn(array $a, array $b): int => [$a['number'], $a['account']] <=> [$b['number'], $b['account']]);

        return [$accounts, round($debit, 2), round($credit, 2)];
    }

    /**
     * Účetní období (měsíc při měsíční četnosti, jinak rok), do kterého
     * datum patří.
     *
     * @return array{id: int, begin: string, end: string, name: string}|null
     */
    private function periodOfDate(string $date): ?array
    {
        if ($this->planService->isMonthly()) {
            foreach ($this->planService->fiscalMonths() as $month) {
                if ($month['begin'] <= $date && $month['end'] >= $date) {
                    return ['id' => $month['id'], 'begin' => $month['begin'], 'end' => $month['end'], 'name' => substr($month['begin'], 0, 7)];
                }
            }
            return null;
        }
        foreach ($this->planService->fiscalYears() as $year) {
            if ($year['begin'] <= $date && $year['end'] >= $date) {
                return $year;
            }
        }
        return null;
    }

    /** Popisek zamčeného měsíce (`2024/12`) z kalendáře, null = volný. */
    private function lockedMonthLabel(string $date): ?string
    {
        foreach ($this->planService->fiscalMonths() as $month) {
            if ($month['locked'] && $month['begin'] <= $date && $month['end'] >= $date) {
                return substr($month['begin'], 0, 4) . '/' . substr($month['begin'], 5, 2);
            }
        }
        return null;
    }

    private function documents(): AssetPostingDocuments
    {
        if ($this->documents === null) {
            throw new AssetPostingException(self::ERROR_DOCUMENTS_UNAVAILABLE, 'Účetní doklady nejsou dostupné.');
        }
        return $this->documents;
    }

    private static function czDate(string $date): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $date)
            ? (new \DateTimeImmutable(substr($date, 0, 10)))->format('j. n. Y')
            : $date;
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /** @return array{id: int, name: string}|null */
    protected function resolveSeries(): ?array
    {
        return $this->db !== null && $this->settings !== null
            ? AssetPostingSeries::resolve($this->db, $this->settings)
            : null;
    }

    /**
     * Potvrzené události účetního okruhu bez živého účetního dokladu
     * s datem do `$until`, chronologicky per karta.
     *
     * @return list<array<string, mixed>>
     */
    protected function loadUnpostedEvents(string $until): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [e].* FROM [' . AssetEventDocument::TABLE . '] [e]'
            . ' LEFT JOIN [' . AssetPostingDocuments::HEADS_TABLE . '] [h] ON [h].[id] = [e].[doc_head]'
            . ' WHERE [e].[docState] = %i AND [e].[event_date] <= %d AND [e].[scope] <> %s'
            . ' AND ([e].[doc_head] IS NULL OR [h].[id] IS NULL OR [h].[docState] IN %in)'
            . ' ORDER BY [e].[asset], [e].[event_date], [e].[id]',
            AssetEventDocument::STATE_CONFIRMED,
            $until,
            AssetEvent::SCOPE_TAX,
            AssetEventDocument::DEAD_DOC_STATES,
        );
        $events = [];
        foreach ($rows as $row) {
            $events[] = AssetPlanService::plain($row);
        }
        return $events;
    }

    /**
     * Karty s účty účetní skupiny (`accounts`, klíče builderu; účet mimo
     * odkazovatelné stavy rozvrhu se bere jako nevyplněný), podle inv. čísla.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    protected function loadCards(array $ids): array
    {
        if ($this->db === null || $ids === []) {
            return [];
        }
        $columns = '';
        foreach (array_keys(self::GROUP_ACCOUNTS) as $column) {
            $columns .= ', [g].[' . $column . ']';
        }
        $rows = $this->db->fetchAll(
            'SELECT [a].*' . $columns . ' FROM [' . AssetDocument::TABLE . '] [a]'
            . ' LEFT JOIN [economy_assets_accounting_groups] [g] ON [g].[id] = [a].[accounting_group] AND [g].[docState] <> 90'
            . ' WHERE [a].[id] IN %in ORDER BY [a].[asset_number], [a].[id]',
            $ids,
        );

        $cards = [];
        $accountIds = [];
        foreach ($rows as $row) {
            $card = AssetPlanService::plain($row);
            foreach (array_keys(self::GROUP_ACCOUNTS) as $column) {
                if (!empty($card[$column])) {
                    $accountIds[(int) $card[$column]] = true;
                }
            }
            $cards[] = $card;
        }
        $linkable = [];
        if ($accountIds !== []) {
            foreach ($this->db->fetchAll(
                'SELECT [id] FROM [economy_accounting_accounts] WHERE [id] IN %in AND [docState] IN %in',
                array_keys($accountIds),
                self::LINKABLE_ACCOUNT_STATES,
            ) as $account) {
                $linkable[(int) $account['id']] = true;
            }
        }
        foreach ($cards as &$card) {
            $card['accounts'] = [];
            foreach (self::GROUP_ACCOUNTS as $column => $key) {
                $accountId = (int) ($card[$column] ?? 0);
                $card['accounts'][$key] = isset($linkable[$accountId]) ? $accountId : null;
            }
        }
        unset($card);

        return $cards;
    }

    /**
     * @param list<int> $accountIds
     * @return array<int, array{number: string, name: string}>
     */
    protected function loadAccountLabels(array $accountIds): array
    {
        if ($this->db === null || $accountIds === []) {
            return [];
        }
        $labels = [];
        foreach ($this->db->fetchAll(
            'SELECT [id], [number], [name] FROM [economy_accounting_accounts] WHERE [id] IN %in',
            $accountIds,
        ) as $row) {
            $labels[(int) $row['id']] = ['number' => (string) $row['number'], 'name' => (string) $row['name']];
        }
        return $labels;
    }

    /**
     * Živé účetní doklady (mimo Storno / Smazáno), kterými jsou události
     * majetku zaúčtované, podle účetního data.
     *
     * @return list<array{id: int, doc_number: string, accounting_date: string}>
     */
    protected function loadPostedDocuments(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT [h].[id], [h].[doc_number], [h].[accounting_date]'
            . ' FROM [' . AssetEventDocument::TABLE . '] [e]'
            . ' JOIN [' . AssetPostingDocuments::HEADS_TABLE . '] [h] ON [h].[id] = [e].[doc_head]'
            . ' WHERE [h].[docState] NOT IN %in ORDER BY [h].[accounting_date], [h].[id]',
            AssetEventDocument::DEAD_DOC_STATES,
        );
        $docs = [];
        foreach ($rows as $row) {
            $row = AssetPlanService::plain($row);
            $docs[] = [
                'id'              => (int) $row['id'],
                'doc_number'      => (string) $row['doc_number'],
                'accounting_date' => (string) $row['accounting_date'],
            ];
        }
        return $docs;
    }

    /** @param list<int> $eventIds */
    protected function linkEvents(array $eventIds, int $docId): void
    {
        if ($this->db === null || $eventIds === []) {
            return;
        }
        $this->db->query(
            'UPDATE [' . AssetEventDocument::TABLE . '] SET [doc_head] = %i WHERE [id] IN %in',
            $docId,
            $eventIds,
        );
    }

    /**
     * @param list<int> $docIds
     * @return int počet odpojených událostí
     */
    protected function unlinkEvents(array $docIds): int
    {
        if ($this->db === null || $docIds === []) {
            return 0;
        }
        $this->db->query(
            'UPDATE [' . AssetEventDocument::TABLE . '] SET [doc_head] = NULL WHERE [doc_head] IN %in',
            $docIds,
        );
        return $this->db->getAffectedRows();
    }

    /**
     * Zámek karet do konce transakce — dva běhy naráz nezaloží odpis ani
     * doklad dvakrát. Včetně archivovaných (vyřazený majetek se účtuje taky).
     */
    protected function lockCards(): void
    {
        $this->db?->query(
            'SELECT [id] FROM [' . AssetDocument::TABLE . '] WHERE [docState] IN %in FOR UPDATE',
            [AssetDocument::STATE_CONFIRMED, AssetDocument::STATE_ARCHIVED],
        );
    }

    protected function begin(): void
    {
        $this->db?->begin();
    }

    protected function commit(): void
    {
        $this->db?->commit();
    }

    protected function rollback(): void
    {
        $this->db?->rollback();
    }
}
