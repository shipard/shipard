<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Import;

use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportStatus;
use Shipard\Module\Economy\Assets\AssetDocument;
use Shipard\Module\Economy\Assets\AssetPlanService;
use Shipard\Module\Economy\Assets\Depreciation\Amounts;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessage;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessageTexts;
use Shipard\Module\Economy\Assets\Depreciation\PlanRow;

/**
 * Ověření importu majetku (docs/assets.md D82) — čte, nic nemění:
 *
 *  1. **Zlatý test daňového okruhu (D6):** engine u každého potvrzeného
 *     odpisu počítá hodnotu z historie před ním (`PlanRow::computed`,
 *     přerušení i polovina v roce vyřazení podle pravidel enginu);
 *     importovaný daňový odpis se liší = rozdíl. Karta s chybou plánu
 *     (zablokovaný okruh, chybějící období) je samostatný nález.
 *  2. **Účetní okruh × deník:** per karta a účetní rok součet potvrzených
 *     účetních odpisů × obrat účtů odpisů (všech účetních skupin)
 *     s dimenzí karty v deníku.
 *  3. **Kontrola evidence × deník** (report `economy.assets.journalCheck`)
 *     za každý účetní rok od prvního s dimenzí `asset` v deníku: stav
 *     a počty zpráv po kódech. Běží jen bez filtru na kartu.
 *  4. **Uplynulá doba účetního odpisování (D83):** karty, jejichž plán
 *     nese varování `accPeriodElapsed` — časová metoda by zůstatek
 *     odepsala v jednom období (konec doby, částka nejbližšího plánovaného
 *     období). Upozornění, ne rozdíl importu: do `ok` se nepočítá.
 *
 * `ok` = žádný rozdíl v 1 a 2, žádná chyba plánu a žádný rok kontroly
 * ve stavu `errors` (varování D73 neshazují). DB přístup je v protected
 * metodách (přepsatelné v testech); report dodává closure volajícího.
 */
class AssetImportVerifier
{
    public const CHECK_REPORT = 'economy.assets.journalCheck';

    /**
     * @param \Closure(string, int): ReportResult|null $reports běh kontroly
     *        evidence × deník: (název účetního roku, počet měsíců) → výsledek
     */
    public function __construct(
        protected readonly AssetPlanService $plans,
        protected readonly ?\Dibi\Connection $db,
        private readonly ?\Closure $reports = null,
        private readonly ?PlanMessageTexts $texts = null,
    ) {
    }

    /**
     * @return array{
     *     tax: list<array<string, mixed>>, planErrors: list<array<string, mixed>>,
     *     accounting: list<array<string, mixed>>, journalCheck: list<array<string, mixed>>,
     *     accPeriodElapsed: list<array<string, mixed>>, summary: array<string, int>, ok: bool}
     */
    public function run(?string $assetNumber = null): array
    {
        $cards = $this->loadCards($assetNumber);
        $ids = array_keys($cards);
        $events = $this->plans->confirmedEventsOf($ids);

        $tax = [];
        $planErrors = [];
        $accPeriodElapsed = [];
        $taxChecked = 0;
        $evidence = [];
        foreach ($cards as $id => $card) {
            $own = $events[$id] ?? [];
            $identity = ['assetId' => $id, 'number' => (string) ($card['asset_number'] ?? ''), 'name' => (string) ($card['name'] ?? '')];
            $origins = [];
            foreach ($own as $event) {
                $origins[(int) $event['id']] = (string) ($event['origin'] ?? '');
                if ((string) $event['event_kind'] === AssetEvent::KIND_DEPRECIATION && (string) $event['scope'] === AssetEvent::SCOPE_ACC) {
                    $year = $this->yearOf(substr((string) $event['event_date'], 0, 10));
                    if ($year !== null) {
                        $evidence[$id][$year['id']] = ($evidence[$id][$year['id']] ?? 0.0) + (float) $event['amount'];
                    }
                }
            }

            $plan = $this->plans->plan($card, $own);
            foreach ($plan as $circuit => $circuitPlan) {
                foreach ($circuitPlan->allMessages() as $message) {
                    if ($message->isError()) {
                        $planErrors[] = $identity + [
                            'circuit' => $circuit,
                            'code'    => $message->code,
                            'message' => $this->texts?->text($message) ?? $message->code,
                        ];
                    }
                }
            }
            $elapsed = $this->elapsedRow($plan[AssetEvent::SCOPE_ACC]->plannedRows());
            if ($elapsed !== null) {
                [$row, $message] = $elapsed;
                $accPeriodElapsed[] = $identity + [
                    'end'       => (string) ($message->params['end'] ?? ''),
                    'periodEnd' => $row->period?->end ?? $row->date,
                    'amount'    => round($row->amount, 2),
                ];
            }
            foreach ($plan[AssetEvent::SCOPE_TAX]->rows as $row) {
                if (!$row->isDepreciation() || $row->isPlanned() || $row->eventId === null
                    || ($origins[$row->eventId] ?? '') !== AssetEvent::ORIGIN_IMPORT
                ) {
                    continue;
                }
                $taxChecked++;
                if ($row->computed === null) {
                    continue; // zablokovaný okruh — hlásí se jako chyba plánu
                }
                $difference = round($row->amount - $row->computed, 2);
                if (abs($difference) >= Amounts::EPSILON) {
                    $tax[] = $identity + [
                        'year'            => $this->plans->taxCalendar()->yearOf($row->period?->end ?? $row->date)->begin,
                        'periodEnd'       => $row->period?->end ?? $row->date,
                        'imported'        => round($row->amount, 2),
                        'computed'        => round($row->computed, 2),
                        'difference'      => $difference,
                        'halfYear'        => $row->halfYear,
                        'claimUnrecorded' => $row->claimUnrecorded,
                    ];
                }
            }
        }

        $journal = $this->loadDepreciationJournal($ids);
        $years = [];
        foreach ($this->plans->fiscalYears() as $year) {
            $years[$year['id']] = $year;
        }
        $accounting = [];
        $accChecked = 0;
        foreach ($cards as $id => $card) {
            $yearIds = array_unique([...array_keys($evidence[$id] ?? []), ...array_keys($journal[$id] ?? [])]);
            usort($yearIds, static fn(int $a, int $b): int => ($years[$a]['begin'] ?? '') <=> ($years[$b]['begin'] ?? ''));
            foreach ($yearIds as $yearId) {
                $accChecked++;
                $expected = round($evidence[$id][$yearId] ?? 0.0, 2);
                $actual = round($journal[$id][$yearId] ?? 0.0, 2);
                if (abs($expected - $actual) >= Amounts::EPSILON) {
                    $accounting[] = [
                        'assetId'    => $id,
                        'number'     => (string) ($card['asset_number'] ?? ''),
                        'name'       => (string) ($card['name'] ?? ''),
                        'year'       => (string) ($years[$yearId]['name'] ?? $yearId),
                        'evidence'   => $expected,
                        'journal'    => $actual,
                        'difference' => round($expected - $actual, 2),
                    ];
                }
            }
        }

        $journalCheck = [];
        if ($assetNumber === null && $this->reports !== null) {
            $first = $this->firstYearWithAssets();
            foreach ($first !== null ? $this->plans->fiscalYears() : [] as $year) {
                if ($year['begin'] < $first['begin']) {
                    continue;
                }
                $months = 0;
                foreach ($this->plans->fiscalMonths() as $month) {
                    if ($month['fiscal_year'] === $year['id']) {
                        $months++;
                    }
                }
                $result = ($this->reports)($year['name'], max(1, $months));
                $codes = [];
                foreach ($result->messages as $message) {
                    $codes[$message->code] = ($codes[$message->code] ?? 0) + 1;
                }
                ksort($codes);
                $journalCheck[] = ['year' => $year['name'], 'status' => $result->status->value, 'codes' => $codes];
            }
        }

        $checkErrors = count(array_filter($journalCheck, static fn(array $y): bool => $y['status'] === ReportStatus::Errors->value));

        return [
            'tax'              => $tax,
            'planErrors'       => $planErrors,
            'accounting'       => $accounting,
            'journalCheck'     => $journalCheck,
            'accPeriodElapsed' => $accPeriodElapsed,
            'summary'          => [
                'cards'            => count($cards),
                'taxChecked'       => $taxChecked,
                'taxDifferences'   => count($tax),
                'planErrors'       => count($planErrors),
                'accChecked'       => $accChecked,
                'accDifferences'   => count($accounting),
                'checkYears'       => count($journalCheck),
                'checkErrors'      => $checkErrors,
                'accPeriodElapsed' => count($accPeriodElapsed),
            ],
            'ok' => $tax === [] && $planErrors === [] && $accounting === [] && $checkErrors === 0,
        ];
    }

    /**
     * První plánovaný řádek s varováním uplynulé doby (D83).
     *
     * @param list<PlanRow> $rows
     * @return array{PlanRow, PlanMessage}|null
     */
    private function elapsedRow(array $rows): ?array
    {
        foreach ($rows as $row) {
            foreach ($row->messages as $message) {
                if ($message->code === PlanMessage::ACC_PERIOD_ELAPSED) {
                    return [$row, $message];
                }
            }
        }
        return null;
    }

    /** @return array{id: int, name: string, begin: string, end: string}|null založený účetní rok data */
    private function yearOf(string $date): ?array
    {
        foreach ($this->plans->fiscalYears() as $year) {
            if ($date >= $year['begin'] && $date <= $year['end']) {
                return $year;
            }
        }
        return null;
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /**
     * Dlouhodobé karty mimo smazané (jedna podle inventárního čísla), podle čísla.
     *
     * @return array<int, array<string, mixed>> id → karta
     */
    protected function loadCards(?string $assetNumber): array
    {
        if ($this->db === null) {
            return [];
        }
        $args = ['SELECT * FROM [' . AssetDocument::TABLE . '] WHERE [docState] <> %i', AssetDocument::STATE_DELETED];
        if ($assetNumber !== null) {
            array_push($args, 'AND [asset_number] = %s', $assetNumber);
        }
        $args[] = 'ORDER BY [asset_number], [id]';
        $categories = $this->plans->categories();
        $cards = [];
        foreach ($this->db->fetchAll(...$args) as $row) {
            $card = AssetPlanService::plain($row);
            if ($categories->isLongTerm((string) ($card['category'] ?? ''))) {
                $cards[(int) $card['id']] = $card;
            }
        }
        return $cards;
    }

    /**
     * Obrat účtů odpisů (všech účetních skupin) s dimenzí karty po účetních
     * letech: MD − DAL.
     *
     * @param list<int> $assetIds
     * @return array<int, array<int, float>> id karty → id účetního roku → obrat
     */
    protected function loadDepreciationJournal(array $assetIds): array
    {
        if ($this->db === null || $assetIds === []) {
            return [];
        }
        $accounts = [];
        foreach ($this->db->fetchAll(
            'SELECT DISTINCT [a].[number] FROM [economy_assets_accounting_groups] [g]'
            . ' JOIN [economy_accounting_accounts] [a] ON [a].[id] = [g].[account_depreciation]'
            . ' WHERE [g].[docState] <> %i',
            AssetDocument::STATE_DELETED,
        ) as $row) {
            $accounts[] = (string) $row['number'];
        }
        if ($accounts === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT [asset], [fiscal_year], SUM([money_dr] - [money_cr]) AS [amount] FROM [economy_accounting_journal]'
            . ' WHERE [asset] IN %in AND [account_number] IN %in GROUP BY [asset], [fiscal_year]',
            $assetIds,
            $accounts,
        ) as $row) {
            $out[(int) $row['asset']][(int) $row['fiscal_year']] = (float) $row['amount'];
        }
        return $out;
    }

    /**
     * Nejstarší účetní rok, ve kterém deník nese dimenzi `asset`.
     *
     * @return array{id: int, name: string, begin: string, end: string}|null
     */
    protected function firstYearWithAssets(): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $ids = [];
        foreach ($this->db->fetchAll('SELECT DISTINCT [fiscal_year] FROM [economy_accounting_journal] WHERE [asset] > 0') as $row) {
            $ids[(int) $row['fiscal_year']] = true;
        }
        foreach ($this->plans->fiscalYears() as $year) {
            if (isset($ids[$year['id']])) {
                return $year;
            }
        }
        return null;
    }
}
