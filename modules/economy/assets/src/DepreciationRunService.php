<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\Plan;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessageTexts;
use Shipard\Module\Economy\Assets\Depreciation\PlanRow;

/**
 * „Odpisy za období“ (docs/assets.md D33): náhled a hromadné potvrzení
 * plánovaných odpisů jednoho okruhu za jedno období — účetní rok
 * (daňový okruh, účetní při roční četnosti) nebo účetní měsíc (účetní
 * okruh při měsíční četnosti, D12).
 *
 * Kandidáti: odepisované dlouhodobé karty ve stavu V pořádku (nebo jedna
 * karta). Karta je v náhledu s částkou, když plán okruhu nabízí plánovaný
 * odpis končící v období; vyloučené karty nesou důvod:
 *   `notActivated`, `disposed`, `planError`, `alreadyDone`,
 *   `earlierPeriodMissing` (dřívější období bez odpisu by dalo
 *   `missingPeriod`), `monthLocked`. Karta, u níž plán v období nic
 *   nenabízí (odepsáno, přerušeno, zařazeno později), se vynechá tiše.
 *
 * Provedení je idempotentní (potvrzené odpisy náhled vyloučí) a atomické:
 * karty zamkne, náhled spočítá znovu uvnitř transakce a odpisy zapíše
 * `SystemDepreciationWriter` (původ `system`, stav 40). Bez HTTP —
 * controller jen překládá vstup a výstup.
 */
class DepreciationRunService
{
    public const REASON_NOT_ACTIVATED = 'notActivated';
    public const REASON_DISPOSED = 'disposed';
    public const REASON_PLAN_ERROR = 'planError';
    public const REASON_ALREADY_DONE = 'alreadyDone';
    public const REASON_EARLIER_MISSING = 'earlierPeriodMissing';
    public const REASON_MONTH_LOCKED = 'monthLocked';

    public function __construct(
        protected readonly AssetPlanService $service,
        protected readonly SystemDepreciationWriter $writer,
        protected readonly ?\Dibi\Connection $db,
        private readonly ?PlanMessageTexts $texts = null,
    ) {
    }

    /**
     * Nabídka dialogu: okruhy, četnost, období (roky vždy, měsíce při
     * měsíční četnosti) a výchozí volba.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $today = $this->service->today();
        $years = array_reverse($this->service->fiscalYears());
        $months = [];
        if ($this->service->isMonthly()) {
            foreach (array_reverse($this->service->fiscalMonths()) as $month) {
                $months[] = [
                    'id'     => $month['id'],
                    'name'   => substr($month['begin'], 0, 7),
                    'begin'  => $month['begin'],
                    'end'    => $month['end'],
                    'locked' => $month['locked'],
                ];
            }
        }
        $defaultYear = null;
        foreach ($years as $year) {
            if ($year['begin'] <= $today && $year['end'] >= $today) {
                $defaultYear = $year['id'];
            }
        }
        $defaultMonth = null;
        foreach ($months as $month) {
            if ($month['begin'] <= $today && $month['end'] >= $today) {
                $defaultMonth = $month['id'];
            }
        }

        return [
            'accPeriodicity' => $this->service->accPeriodicity(),
            'years'          => $years,
            'months'         => $months,
            'defaults'       => [
                'scope'      => AssetEvent::SCOPE_TAX,
                'yearId'     => $defaultYear ?? ($years[0]['id'] ?? null),
                'monthId'    => $defaultMonth ?? ($months[0]['id'] ?? null),
            ],
        ];
    }

    /**
     * @return array<string, mixed> {scope, period, assets[], total, excluded[]}
     * @throws \InvalidArgumentException neznámý okruh nebo období
     */
    public function preview(string $scope, int $periodId, ?int $assetId = null): array
    {
        $period = $this->resolvePeriod($scope, $periodId);
        $cards = $this->loadCandidateCards($assetId);
        $eventsByAsset = $this->service->confirmedEventsOf(array_map(static fn(array $c): int => (int) $c['id'], $cards));
        $categories = $this->service->categories();

        $assets = [];
        $excluded = [];
        $total = 0.0;
        foreach ($cards as $card) {
            if (!$categories->isDepreciable((string) ($card['category'] ?? ''))) {
                continue;
            }
            $events = $eventsByAsset[(int) $card['id']] ?? [];
            $item = $this->evaluate($card, $events, $scope, $period);
            if ($item === null) {
                continue;
            }
            if (isset($item['reason'])) {
                $excluded[] = $item;
            } else {
                $assets[] = $item;
                $total += $item['amount'];
            }
        }

        return [
            'scope'    => $scope,
            'period'   => $period,
            'assets'   => $assets,
            'total'    => $total,
            'excluded' => $excluded,
        ];
    }

    /**
     * Založí odpisy karet z náhledu bez chyby v jedné transakci.
     *
     * @return array{count: int, total: float, assetIds: list<int>}
     */
    public function run(string $scope, int $periodId, ?int $assetId = null): array
    {
        $this->resolvePeriod($scope, $periodId);

        $this->db?->begin();
        try {
            $this->lockCards($assetId);
            $preview = $this->preview($scope, $periodId, $assetId);
            $count = 0;
            $total = 0.0;
            $ids = [];
            foreach ($preview['assets'] as $item) {
                $total += $this->writer->write((int) $item['id'], $scope, $item['rows']);
                $count++;
                $ids[] = (int) $item['id'];
            }
            $this->db?->commit();
        } catch (\Throwable $e) {
            $this->db?->rollback();
            throw $e;
        }

        return ['count' => $count, 'total' => $total, 'assetIds' => $ids];
    }

    // ── Vyhodnocení karty ──────────────────────────────────────────────────

    /**
     * Položka náhledu (s `rows` pro zápis), vyloučení (s `reason`), nebo
     * null = v období není co odepsat.
     *
     * @param array<string, mixed> $card
     * @param list<array<string, mixed>> $events
     * @param array{id: int, kind: string, begin: string, end: string, name: string} $period
     * @return array<string, mixed>|null
     */
    private function evaluate(array $card, array $events, string $scope, array $period): ?array
    {
        $base = [
            'id'     => (int) $card['id'],
            'number' => (string) ($card['asset_number'] ?? ''),
            'name'   => (string) ($card['name'] ?? ''),
        ];
        foreach ($events as $event) {
            if ($event['event_kind'] === AssetEvent::KIND_DISPOSAL) {
                return $base + ['reason' => self::REASON_DISPOSED];
            }
        }

        $plan = $this->service->plan($card, $events)[$scope];
        if ($plan->rows === []) {
            return $base + ['reason' => self::REASON_NOT_ACTIVATED];
        }
        if ($plan->hasErrors()) {
            return $base + ['reason' => self::REASON_PLAN_ERROR, 'detail' => $this->firstError($plan)];
        }

        $matched = [];
        $confirmedInPeriod = false;
        foreach ($plan->rows as $row) {
            if (!$row->isDepreciation() || $row->period === null) {
                continue;
            }
            $inPeriod = $row->period->end >= $period['begin'] && $row->period->end <= $period['end'];
            if ($row->isPlanned()) {
                if ($row->period->end < $period['begin']) {
                    return $base + [
                        'reason' => self::REASON_EARLIER_MISSING,
                        'detail' => $row->period->begin . ' – ' . $row->period->end,
                    ];
                }
                if ($inPeriod) {
                    $matched[] = $row;
                }
            } elseif ($inPeriod) {
                $confirmedInPeriod = true;
            }
        }
        if ($matched === []) {
            return $confirmedInPeriod ? $base + ['reason' => self::REASON_ALREADY_DONE] : null;
        }

        $amount = 0.0;
        $formulas = [];
        foreach ($matched as $row) {
            $locked = $this->lockedMonthLabel($row->date);
            if ($locked !== null) {
                return $base + ['reason' => self::REASON_MONTH_LOCKED, 'detail' => $locked];
            }
            $amount += $row->amount;
            if ($row->formula !== null && $row->formula !== '') {
                $formulas[] = $row->formula;
            }
        }
        $warnings = [];
        foreach ($plan->allMessages() as $message) {
            $warnings[] = $this->texts?->text($message) ?? $message->code;
        }

        return $base + [
            'amount'   => $amount,
            'formula'  => implode(' + ', array_unique($formulas)),
            'residual' => $matched[count($matched) - 1]->residual,
            'messages' => $warnings,
            'rows'     => $matched,
        ];
    }

    private function firstError(Plan $plan): string
    {
        foreach ($plan->allMessages() as $message) {
            if ($message->isError()) {
                return $this->texts?->text($message) ?? $message->code;
            }
        }
        return '';
    }

    /**
     * @return array{id: int, kind: string, begin: string, end: string, name: string}
     */
    private function resolvePeriod(string $scope, int $periodId): array
    {
        if (!in_array($scope, [AssetEvent::SCOPE_TAX, AssetEvent::SCOPE_ACC], true)) {
            throw new \InvalidArgumentException("Neznámý okruh '{$scope}'");
        }
        $monthly = $scope === AssetEvent::SCOPE_ACC && $this->service->isMonthly();
        if ($monthly) {
            foreach ($this->service->fiscalMonths() as $month) {
                if ($month['id'] === $periodId) {
                    return ['id' => $periodId, 'kind' => 'month', 'begin' => $month['begin'], 'end' => $month['end'], 'name' => substr($month['begin'], 0, 7)];
                }
            }
            throw new \InvalidArgumentException("Účetní měsíc {$periodId} neexistuje");
        }
        foreach ($this->service->fiscalYears() as $year) {
            if ($year['id'] === $periodId) {
                return ['id' => $periodId, 'kind' => 'year', 'begin' => $year['begin'], 'end' => $year['end'], 'name' => $year['name']];
            }
        }
        throw new \InvalidArgumentException("Účetní rok {$periodId} neexistuje");
    }

    /** Popisek zamčeného měsíce (`2024/03`) z kalendáře služby, null = volný. */
    private function lockedMonthLabel(string $date): ?string
    {
        foreach ($this->service->fiscalMonths() as $month) {
            if ($month['locked'] && $month['begin'] <= $date && $month['end'] >= $date) {
                return substr($month['begin'], 0, 4) . '/' . substr($month['begin'], 5, 2);
            }
        }
        return null;
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /**
     * Dlouhodobé karty ve stavu V pořádku (druh filtruje volající).
     *
     * @return list<array<string, mixed>>
     */
    protected function loadCandidateCards(?int $assetId): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $assetId !== null
            ? $this->db->fetchAll(
                'SELECT * FROM [' . AssetDocument::TABLE . '] WHERE [id] = %i AND [docState] = %i',
                $assetId,
                AssetDocument::STATE_CONFIRMED,
            )
            : $this->db->fetchAll(
                'SELECT * FROM [' . AssetDocument::TABLE . '] WHERE [docState] = %i ORDER BY [asset_number], [id]',
                AssetDocument::STATE_CONFIRMED,
            );
        $cards = [];
        foreach ($rows as $row) {
            $cards[] = AssetPlanService::plain($row);
        }
        return $cards;
    }

    /** Zámek kandidátních karet do konce transakce — dva běhy naráz nezaloží odpis dvakrát. */
    protected function lockCards(?int $assetId): void
    {
        if ($this->db === null) {
            return;
        }
        if ($assetId !== null) {
            $this->db->query('SELECT [id] FROM [' . AssetDocument::TABLE . '] WHERE [id] = %i FOR UPDATE', $assetId);
        } else {
            $this->db->query(
                'SELECT [id] FROM [' . AssetDocument::TABLE . '] WHERE [docState] = %i FOR UPDATE',
                AssetDocument::STATE_CONFIRMED,
            );
        }
    }
}
