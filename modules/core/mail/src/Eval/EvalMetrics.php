<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

use Shipard\Module\Core\Exchange\Compare\FieldOutcome;
use Shipard\Module\Core\Exchange\Compare\FieldState;

/**
 * Metriky běhu evaluace (#103 D37, D41) z výsledků volání:
 *
 *   - `fields.<skupina>.hit` podíl `match + matchNormalized` z listů
 *     skupiny (úspěšná volání), `.normalized` podíl shody až po normalizaci
 *     (podklad #118), počty `n` / `missing` / `extra` / `wrong`; `fields.all.*`;
 *   - `documents.clean` podíl volání bez jediné odchylky (selhané volání =
 *     ne), `.amountsCorrect` (řádky `totalPrice`, rekapitulace, součty bez
 *     odchylky), `.rowCountOk`, `.classificationOk`, `.tagOk`;
 *   - `calls.total` / `.ok` / `.failed` / `.failed.<typ>`;
 *   - `cost.usd`, `cost.perDocument`, `tokens.*`, `time.*` (součet, průměr).
 *
 * Opakování (D41): podíly jako průměr přes opakování, součty jako součet;
 * `range` nese min–max per opakování, `fieldStability` podíl zpráv se
 * stejným stavem pole ve všech opakováních. `byVariant` / `byForm` podle
 * mapování zpráva → buňka korpusu. `deviationsByField` = počet volání
 * s odchylkou per uzel projekce (nejčastější odchylky, porovnání běhů).
 */
final class EvalMetrics
{
    /** Klíče, které se přes opakování průměrují (ostatní se sčítají). */
    private const array RATIO_PREFIXES = ['fields.', 'documents.'];

    private function __construct()
    {
    }

    /**
     * @param list<EvalCallResult> $results
     * @param list<EvalMessage>    $messages
     * @return array{metrics: array<string, int|float>, range?: array<string, array{min: int|float, max: int|float}>, fieldStability?: array<string, float>, byVariant: array<string, array<string, int|float>>, byForm: array<string, array<string, int|float>>, deviationsByField: array<string, int>}
     */
    public static function compute(array $results, array $messages, int $repeat): array
    {
        $slices = [];
        for ($i = 1; $i <= max(1, $repeat); $i++) {
            $slice = array_values(array_filter($results, static fn (EvalCallResult $r): bool => $r->repeat === $i));
            if ($slice !== []) {
                $slices[] = self::slice($slice);
            }
        }
        $out = ['metrics' => self::aggregate($slices)];
        if (count($slices) > 1) {
            $out['range'] = self::range($slices);
            $out['fieldStability'] = self::fieldStability($results);
        }

        $cells = [];
        foreach ($messages as $m) {
            $cells[$m->messageId] = $m;
        }
        $out['byVariant'] = self::byCell($results, $cells, 'variant');
        $out['byForm'] = self::byCell($results, $cells, 'form');
        $out['deviationsByField'] = self::deviationsByField($results);
        return $out;
    }

    /**
     * Metriky jedné sady výsledků (každá zpráva nejvýš jednou).
     *
     * @param list<EvalCallResult> $results
     * @return array<string, int|float>
     */
    public static function slice(array $results): array
    {
        $m = ['calls.total' => count($results), 'calls.ok' => 0, 'calls.failed' => 0];
        $groups = [];
        foreach (EvalFieldGroups::GROUPS as $g) {
            $groups[$g] = ['n' => 0, 'hit' => 0, 'normalized' => 0, 'missing' => 0, 'extra' => 0, 'wrong' => 0];
        }
        $all = ['n' => 0, 'hit' => 0, 'normalized' => 0, 'missing' => 0, 'extra' => 0, 'wrong' => 0];
        $clean = 0;
        $amounts = 0;
        $rowCount = 0;
        $classification = 0;
        $tag = 0;
        $cost = 0.0;
        $tokensIn = 0;
        $tokensOut = 0;
        $time = 0;
        $failed = [];

        foreach ($results as $r) {
            $cost += (float) ($r->costUsd ?? 0);
            $tokensIn += (int) ($r->tokensIn ?? 0);
            $tokensOut += (int) ($r->tokensOut ?? 0);
            $time += (int) ($r->durationMs ?? 0);
            if (!$r->isOk()) {
                $m['calls.failed']++;
                $failed[(string) $r->errorType] = ($failed[(string) $r->errorType] ?? 0) + 1;
                continue;
            }
            $m['calls.ok']++;
            $clean += $r->isClean() ? 1 : 0;
            $amountsOk = true;
            $rowCountOk = true;
            foreach ($r->outcomes as $o) {
                $node = $o->nodePath();
                $group = EvalFieldGroups::groupOf($node);
                $key = match ($o->state) {
                    FieldState::Match => 'hit',
                    FieldState::MatchNormalized => 'normalized',
                    FieldState::Missing => 'missing',
                    FieldState::Extra => 'extra',
                    FieldState::Wrong => 'wrong',
                };
                $groups[$group]['n']++;
                $groups[$group][$key]++;
                $all['n']++;
                $all[$key]++;
                if (!$o->state->isHit()) {
                    if (EvalFieldGroups::isAmountNode($node)) {
                        $amountsOk = false;
                    }
                    if ($node === 'rows') {
                        $rowCountOk = false;
                    }
                }
                if ($node === EvalFieldGroups::CLASSIFICATION_PATH && $o->state->isHit()) {
                    $classification++;
                }
                if ($node === '_resolve.contentTag.tag' && $o->state->isHit()) {
                    $tag++;
                }
            }
            $amounts += $amountsOk ? 1 : 0;
            $rowCount += $rowCountOk ? 1 : 0;
        }

        ksort($failed);
        foreach ($failed as $type => $n) {
            $m["calls.failed.{$type}"] = $n;
        }
        foreach ($groups + ['all' => $all] as $g => $c) {
            $m["fields.{$g}.n"] = $c['n'];
            $m["fields.{$g}.hit"] = $c['n'] > 0 ? round(($c['hit'] + $c['normalized']) / $c['n'], 4) : 0.0;
            $m["fields.{$g}.normalized"] = $c['n'] > 0 ? round($c['normalized'] / $c['n'], 4) : 0.0;
            $m["fields.{$g}.missing"] = $c['missing'];
            $m["fields.{$g}.extra"] = $c['extra'];
            $m["fields.{$g}.wrong"] = $c['wrong'];
        }
        $total = max(1, count($results));
        $m['documents.clean'] = round($clean / $total, 4);
        $m['documents.amountsCorrect'] = round($amounts / $total, 4);
        $m['documents.rowCountOk'] = round($rowCount / $total, 4);
        $m['documents.classificationOk'] = round($classification / $total, 4);
        $m['documents.tagOk'] = round($tag / $total, 4);
        $m['cost.usd'] = round($cost, 6);
        $m['cost.perDocument'] = round($cost / $total, 6);
        $m['tokens.input'] = $tokensIn;
        $m['tokens.output'] = $tokensOut;
        $m['tokens.inputPerDocument'] = (int) round($tokensIn / $total);
        $m['tokens.outputPerDocument'] = (int) round($tokensOut / $total);
        $m['time.ms'] = $time;
        $m['time.perDocumentMs'] = (int) round($time / $total);
        return $m;
    }

    private static function isRatio(string $key): bool
    {
        foreach (self::RATIO_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                // počty polí (n, missing, extra, wrong) jsou součty
                return !preg_match('/\.(n|missing|extra|wrong)$/', $key);
            }
        }
        return false;
    }

    /**
     * @param list<array<string, int|float>> $slices
     * @return array<string, int|float>
     */
    private static function aggregate(array $slices): array
    {
        if ($slices === []) {
            return self::slice([]);
        }
        if (count($slices) === 1) {
            return $slices[0];
        }
        $keys = [];
        foreach ($slices as $s) {
            foreach (array_keys($s) as $k) {
                $keys[$k] = true;
            }
        }
        $out = [];
        foreach (array_keys($keys) as $key) {
            $values = array_map(static fn (array $s): int|float => $s[$key] ?? 0, $slices);
            $out[$key] = self::isRatio($key)
                ? round(array_sum($values) / count($values), 4)
                : (is_float($values[0]) ? round(array_sum($values), 6) : (int) array_sum($values));
        }
        $total = max(1, (int) ($out['calls.total'] ?? 1));
        $out['cost.perDocument'] = round((float) ($out['cost.usd'] ?? 0) / $total, 6);
        $out['tokens.inputPerDocument'] = (int) round((int) ($out['tokens.input'] ?? 0) / $total);
        $out['tokens.outputPerDocument'] = (int) round((int) ($out['tokens.output'] ?? 0) / $total);
        $out['time.perDocumentMs'] = (int) round((int) ($out['time.ms'] ?? 0) / $total);
        return $out;
    }

    /**
     * @param list<array<string, int|float>> $slices
     * @return array<string, array{min: int|float, max: int|float}>
     */
    private static function range(array $slices): array
    {
        $out = [];
        foreach (array_keys($slices[0]) as $key) {
            $values = array_map(static fn (array $s): int|float => $s[$key] ?? 0, $slices);
            $out[$key] = ['min' => min($values), 'max' => max($values)];
        }
        return $out;
    }

    /**
     * Podíl zpráv, u kterých má pole ve všech opakováních stejný stav.
     *
     * @param list<EvalCallResult> $results
     * @return array<string, float>
     */
    private static function fieldStability(array $results): array
    {
        /** @var array<string, array<string, array<int, string>>> uzel → zpráva → opakování → stav */
        $states = [];
        foreach ($results as $r) {
            if (!$r->isOk()) {
                continue;
            }
            foreach ($r->outcomes as $o) {
                $states[$o->nodePath()][$r->messageId][$r->repeat] = $o->state->value;
            }
        }
        $out = [];
        foreach ($states as $node => $perMessage) {
            $stable = 0;
            foreach ($perMessage as $byRepeat) {
                if (count(array_unique($byRepeat)) === 1) {
                    $stable++;
                }
            }
            $out[$node] = round($stable / count($perMessage), 4);
        }
        ksort($out);
        return $out;
    }

    /**
     * @param list<EvalCallResult>       $results
     * @param array<string, EvalMessage> $cells
     * @return array<string, array<string, int|float>>
     */
    private static function byCell(array $results, array $cells, string $property): array
    {
        $buckets = [];
        foreach ($results as $r) {
            $value = $cells[$r->messageId]->$property ?? null;
            if ($value === null) {
                continue;
            }
            $buckets[$value][] = $r;
        }
        ksort($buckets);
        $out = [];
        foreach ($buckets as $value => $bucket) {
            $m = self::slice($bucket);
            $out[(string) $value] = [
                'calls.total' => $m['calls.total'],
                'calls.ok' => $m['calls.ok'],
                'fields.all.hit' => $m['fields.all.hit'],
                'fields.all.normalized' => $m['fields.all.normalized'],
                'documents.clean' => $m['documents.clean'],
                'documents.amountsCorrect' => $m['documents.amountsCorrect'],
                'documents.rowCountOk' => $m['documents.rowCountOk'],
                'cost.usd' => $m['cost.usd'],
            ];
        }
        return $out;
    }

    /**
     * @param list<EvalCallResult> $results
     * @return array<string, int> uzel → počet volání s odchylkou, nejčastější první
     */
    public static function deviationsByField(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $seen = [];
            foreach ($r->deviations() as $o) {
                $seen[$o->nodePath()] = true;
            }
            foreach (array_keys($seen) as $node) {
                $out[$node] = ($out[$node] ?? 0) + 1;
            }
        }
        uksort($out, static fn (string $a, string $b): int => [$out[$b], $a] <=> [$out[$a], $b]);
        return $out;
    }

    /** @param list<FieldOutcome> $outcomes @return list<string> */
    public static function deviationPaths(array $outcomes): array
    {
        return array_values(array_map(
            static fn (FieldOutcome $o): string => $o->path,
            array_filter($outcomes, static fn (FieldOutcome $o): bool => !$o->state->isHit()),
        ));
    }
}
