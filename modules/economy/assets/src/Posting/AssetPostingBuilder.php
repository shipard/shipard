<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Posting;

use Shipard\Module\Economy\Assets\Depreciation\Amounts;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\PlanRow;

/**
 * Sestavení řádků účetního dokladu z událostí majetku (docs/assets.md D49).
 * Čistá třída — žádná databáze; účty i plán dostane ve vstupu.
 *
 *   zařazení, TZ          MD majetek  / DAL pořízení     částka události
 *   snížení hodnoty       MD pořízení / DAL majetek      (obrácené zařazení)
 *   účetní odpis          MD odpisy   / DAL oprávky
 *   vyřazení              MD oprávky  / DAL majetek      ve výši oprávek
 *                         MD zůst. cena / DAL majetek    ve výši zůstatkové ceny
 *
 * Vyřazení je čistý zápis: po něm je účet majetku i oprávek karty nulový.
 * Oprávky a zůstatkovou cenu bere z plánu účetního okruhu (řádek vyřazení
 * nese stav po posledních odpisech); nulové dvojice se vynechají, takže
 * neodepisovaný majetek dá jen zůstatkovou cenu = vstupní cenu. Počáteční
 * stav, přerušení a události jen daňového okruhu se neúčtují.
 *
 * Chybějící účet skupiny nebo nepotvrzené poslední odpisy před vyřazením
 * jsou chyba celé karty — doklad s dírou nevznikne.
 */
final class AssetPostingBuilder
{
    public const ERROR_ACCOUNT_MISSING = 'accounting_account_missing';
    public const ERROR_DISPOSAL_PLAN = 'disposalPlanIncomplete';

    /** Druh události → operace řádku a pořadí v dokladu. */
    private const OPERATIONS = [
        AssetEvent::KIND_ACTIVATION   => ['asset.activation', 0, 'Zařazení'],
        AssetEvent::KIND_IMPROVEMENT  => ['asset.improvement', 1, 'Technické zhodnocení'],
        AssetEvent::KIND_REDUCTION    => ['asset.reduction', 2, 'Snížení hodnoty'],
        AssetEvent::KIND_DEPRECIATION => ['asset.depreciation', 3, 'Odpis'],
        AssetEvent::KIND_DISPOSAL     => ['asset.disposal', 4, 'Vyřazení'],
    ];

    private const ACCOUNT_LABELS = [
        AssetPostingInput::ACCOUNT_ASSET        => 'účet majetku',
        AssetPostingInput::ACCOUNT_ACQUISITION  => 'účet pořízení',
        AssetPostingInput::ACCOUNT_ACCUMULATED  => 'účet oprávek',
        AssetPostingInput::ACCOUNT_DEPRECIATION => 'účet odpisů',
        AssetPostingInput::ACCOUNT_DISPOSAL     => 'účet zůstatkové ceny při vyřazení',
    ];

    /** Účtuje se událost tohoto druhu a okruhu? */
    public static function isPostable(string $kind, string $scope): bool
    {
        return isset(self::OPERATIONS[$kind]) && $scope !== AssetEvent::SCOPE_TAX;
    }

    public function build(AssetPostingInput $input): AssetPostingResult
    {
        $rows = [];
        $eventIds = [];
        $missing = [];

        foreach ($input->events as $event) {
            $kind = (string) ($event['event_kind'] ?? '');
            if (!self::isPostable($kind, (string) ($event['scope'] ?? AssetEvent::SCOPE_BOTH))) {
                continue;
            }
            $eventId = (int) ($event['id'] ?? 0);
            $amount = round((float) ($event['amount'] ?? 0), 2);

            $pairs = match ($kind) {
                AssetEvent::KIND_ACTIVATION, AssetEvent::KIND_IMPROVEMENT => [
                    [AssetPostingInput::ACCOUNT_ASSET, AssetPostingInput::ACCOUNT_ACQUISITION, $amount, ''],
                ],
                AssetEvent::KIND_REDUCTION => [
                    [AssetPostingInput::ACCOUNT_ACQUISITION, AssetPostingInput::ACCOUNT_ASSET, $amount, ''],
                ],
                AssetEvent::KIND_DEPRECIATION => [
                    [AssetPostingInput::ACCOUNT_DEPRECIATION, AssetPostingInput::ACCOUNT_ACCUMULATED, $amount, ''],
                ],
                AssetEvent::KIND_DISPOSAL => $this->disposalPairs($input, $eventId),
            };
            if ($pairs === null) {
                return AssetPostingResult::failed(
                    self::ERROR_DISPOSAL_PLAN,
                    'Vyřazení nejde zaúčtovat — před ním chybí potvrzené účetní odpisy nebo má plán účetních odpisů chybu.',
                );
            }

            $eventIds[] = $eventId;
            foreach ($pairs as $part => [$debit, $credit, $pairAmount, $suffix]) {
                if (abs($pairAmount) < Amounts::EPSILON) {
                    continue;
                }
                foreach ([$debit, $credit] as $key) {
                    if (empty($input->accounts[$key])) {
                        $missing[$key] = true;
                    }
                }
                if ($missing !== []) {
                    continue;
                }
                $description = $this->description($input, $event, $suffix);
                foreach ([[$debit, AssetPostingRow::SIDE_DEBIT], [$credit, AssetPostingRow::SIDE_CREDIT]] as [$key, $side]) {
                    $rows[] = new AssetPostingRow(
                        self::OPERATIONS[$kind][0],
                        $input->assetId,
                        $input->assetNumber,
                        (int) $input->accounts[$key],
                        $side,
                        $pairAmount,
                        $description,
                        $eventId,
                        (string) ($event['event_date'] ?? ''),
                        $part,
                    );
                }
            }
        }

        if ($missing !== []) {
            $labels = array_values(array_intersect_key(self::ACCOUNT_LABELS, $missing));
            return AssetPostingResult::failed(
                self::ERROR_ACCOUNT_MISSING,
                'Účetní skupina karty nemá ' . implode(', ', $labels) . '.',
            );
        }

        return new AssetPostingResult($rows, $eventIds);
    }

    /**
     * Pořadí řádků v dokladu: zařazení, TZ, snížení, odpisy, vyřazení;
     * v rámci druhu podle inventárního čísla, pak data události. Dvojice
     * MD / DAL zůstává pohromadě.
     *
     * @param list<AssetPostingRow> $rows
     * @return list<AssetPostingRow>
     */
    public static function sort(array $rows): array
    {
        $order = array_column(self::OPERATIONS, 1, 0);
        usort($rows, static fn(AssetPostingRow $a, AssetPostingRow $b): int => [
            $order[$a->operation] ?? PHP_INT_MAX, $a->assetNumber, $a->assetId, $a->eventDate, $a->eventId, $a->part, $a->side,
        ] <=> [
            $order[$b->operation] ?? PHP_INT_MAX, $b->assetNumber, $b->assetId, $b->eventDate, $b->eventId, $b->part, $b->side,
        ]);
        return $rows;
    }

    /**
     * Dvojice zápisů vyřazení: oprávky a zůstatková cena proti účtu majetku.
     * Null = plán účetního okruhu vyřazení nezná, má chybu nebo před
     * vyřazením ještě nabízí nepotvrzený odpis (částky by neseděly na deník).
     *
     * @return list<array{string, string, float, string}>|null
     */
    private function disposalPairs(AssetPostingInput $input, int $eventId): ?array
    {
        $plan = $input->accPlan;
        if ($plan === null || $plan->hasErrors()) {
            return null;
        }
        $disposal = null;
        foreach ($plan->rows as $row) {
            if ($row->isPlanned() && $row->isDepreciation()) {
                return null;
            }
            if ($row->kind === AssetEvent::KIND_DISPOSAL && $row->eventId === $eventId) {
                $disposal = $row;
            }
        }
        if (!$disposal instanceof PlanRow) {
            return null;
        }

        // Řádek vyřazení: `amount` = zůstatková cena před vyřazením,
        // `entryPrice` = vstupní cena; oprávky jsou jejich rozdíl.
        $residual = round($disposal->amount, 2);
        $accumulated = round($disposal->entryPrice - $disposal->amount, 2);

        return [
            [AssetPostingInput::ACCOUNT_ACCUMULATED, AssetPostingInput::ACCOUNT_ASSET, $accumulated, ' — oprávky'],
            [AssetPostingInput::ACCOUNT_DISPOSAL, AssetPostingInput::ACCOUNT_ASSET, $residual, ' — zůstatková cena'],
        ];
    }

    /** @param array<string, mixed> $event */
    private function description(AssetPostingInput $input, array $event, string $suffix): string
    {
        $kind = (string) $event['event_kind'];
        $text = self::OPERATIONS[$kind][2] . $suffix . ' ' . trim($input->assetNumber . ' ' . $input->assetName);

        if ($kind === AssetEvent::KIND_DEPRECIATION) {
            $begin = self::czDate($event['period_begin'] ?? null);
            $end = self::czDate($event['period_end'] ?? null);
            if ($begin !== null && $end !== null) {
                $text .= " ({$begin} – {$end})";
            }
        }
        return $text;
    }

    private static function czDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('j. n. Y');
        }
        $value = substr((string) ($value ?? ''), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? (new \DateTimeImmutable($value))->format('j. n. Y') : null;
    }
}
