<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

use Shipard\Module\Core\Exchange\Compare\FieldOutcome;
use Shipard\Module\Core\Exchange\Compare\FieldState;

/**
 * Metriky běhu evaluace (#103 D37, D41) z výsledků volání:
 *
 *   - `fields.<skupina>.hit` podíl `match + matchNormalized` z listů
 *     skupiny, `.normalized` podíl shody až po normalizaci (podklad #118),
 *     počty `n` / `missing` / `extra` / `wrong`; `fields.all.*`. Selhané
 *     volání nese každý neprázdný list očekávání jako `missing` (D44,
 *     {@see EvalRunner}) — běh se selháním u nejhoršího dokladu tak
 *     nevychází lichotivěji; listy `notCarried` (cesta `isdoc`, pole, která
 *     ISDOC nenese — #103 D76) jsou mimo `n` i odchylky, jen
 *     `fields.<skupina>.notCarried`;
 *   - `documents.clean` podíl volání bez jediné odchylky (selhané volání =
 *     ne), `.amountsCorrect` (řádky `totalPrice`, rekapitulace, součty bez
 *     odchylky), `.rowCountOk`, `.classificationOk`, `.tagOk`;
 *   - `calls.total` / `.ok` / `.failed` / `.failed.<typ>`;
 *   - `cost.usd`, `cost.perDocument`, `tokens.*`, `time.*` (součet, průměr) —
 *     jen extrakce; k tomu `tags.calls` / `tags.usd` / `tags.tokens.*` (LLM
 *     klasifikace štítků, #103 F6b D56) a `cost.totalUsd` /
 *     `cost.totalPerDocument` = extrakce + štítky.
 *
 * Opakování (D41): podíly jako průměr přes opakování, součty jako součet;
 * `range` nese min–max per opakování. `fieldStability` = podíl zpráv se
 * stejnou **třídou** stavu pole ve všech opakováních — shoda je `match`
 * i `matchNormalized` (D44: mezery v IBAN nebo telefonu nejsou věcná
 * nestabilita), `missing` / `extra` / `wrong` každý zvlášť;
 * `formatStability` = zvlášť podíl zpráv se shodou ve všech opakováních,
 * která je i stejného tvaru (žádný mix `match` / `matchNormalized`), jen
 * uzly s takovou zprávou. `byVariant` / `byForm` podle mapování zpráva →
 * buňka korpusu, `byPath` podle cesty výpočtu volání (`ai` / `isdoc`,
 * D76 — buňka `isdoc` má `cost.usd` 0 a nese jen `tags.usd`).
 * `deviationsByField` = počet volání s odchylkou per uzel
 * projekce (nejčastější odchylky, porovnání běhů). Stabilita
 * i `deviationsByField` jen z úspěšných volání — selhání by zhoršilo každý
 * uzel o 1 / opakování a je vidět v `calls.failed`.
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
     * @return array{metrics: array<string, int|float>, range?: array<string, array{min: int|float, max: int|float}>, fieldStability?: array<string, float>, formatStability?: array<string, float>, byVariant: array<string, array<string, int|float>>, byForm: array<string, array<string, int|float>>, byPath: array<string, array<string, int|float>>, deviationsByField: array<string, int>}
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
            $out['formatStability'] = self::formatStability($results);
        }

        $cells = [];
        foreach ($messages as $m) {
            $cells[$m->messageId] = $m;
        }
        $out['byVariant'] = self::byBucket($results, static fn (EvalCallResult $r): ?string => $cells[$r->messageId]->variant ?? null);
        $out['byForm'] = self::byBucket($results, static fn (EvalCallResult $r): ?string => $cells[$r->messageId]->form ?? null);
        $out['byPath'] = self::byBucket($results, static fn (EvalCallResult $r): ?string => $r->path);
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
            $groups[$g] = ['n' => 0, 'hit' => 0, 'normalized' => 0, 'missing' => 0, 'extra' => 0, 'wrong' => 0, 'notCarried' => 0];
        }
        $all = ['n' => 0, 'hit' => 0, 'normalized' => 0, 'missing' => 0, 'extra' => 0, 'wrong' => 0, 'notCarried' => 0];
        $clean = 0;
        $amounts = 0;
        $rowCount = 0;
        $classification = 0;
        $tag = 0;
        $cost = 0.0;
        $tokensIn = 0;
        $tokensOut = 0;
        $time = 0;
        $tagsCalls = 0;
        $tagsCost = 0.0;
        $tagsIn = 0;
        $tagsOut = 0;
        $failed = [];

        foreach ($results as $r) {
            $cost += (float) ($r->costUsd ?? 0);
            $tokensIn += (int) ($r->tokensIn ?? 0);
            $tokensOut += (int) ($r->tokensOut ?? 0);
            $time += (int) ($r->durationMs ?? 0);
            if ($r->tags !== null) {
                $tagsCalls++;
                $tagsCost += $r->tagsCostUsd();
                $tagsIn += (int) ($r->tags['tokensIn'] ?? 0);
                $tagsOut += (int) ($r->tags['tokensOut'] ?? 0);
            }
            // Pole: i selhané volání (jeho outcomes = missing neprázdných polí očekávání, D44);
            // notCarried mimo n (D76).
            foreach ($r->outcomes as $o) {
                $group = EvalFieldGroups::groupOf($o->nodePath());
                $key = match ($o->state) {
                    FieldState::Match => 'hit',
                    FieldState::MatchNormalized => 'normalized',
                    FieldState::Missing => 'missing',
                    FieldState::Extra => 'extra',
                    FieldState::Wrong => 'wrong',
                    FieldState::NotCarried => 'notCarried',
                };
                $groups[$group][$key]++;
                $all[$key]++;
                if ($o->state->isCounted()) {
                    $groups[$group]['n']++;
                    $all['n']++;
                }
            }
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
                if (!$o->state->isHit() && $o->state->isCounted()) {
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
            $m["fields.{$g}.notCarried"] = $c['notCarried'];
        }
        $total = max(1, count($results));
        $m['documents.clean'] = round($clean / $total, 4);
        $m['documents.amountsCorrect'] = round($amounts / $total, 4);
        $m['documents.rowCountOk'] = round($rowCount / $total, 4);
        $m['documents.classificationOk'] = round($classification / $total, 4);
        $m['documents.tagOk'] = round($tag / $total, 4);
        $m['cost.usd'] = round($cost, 6);
        $m['cost.perDocument'] = round($cost / $total, 6);
        $m['cost.totalUsd'] = round($cost + $tagsCost, 6);
        $m['cost.totalPerDocument'] = round(($cost + $tagsCost) / $total, 6);
        $m['tags.calls'] = $tagsCalls;
        $m['tags.usd'] = round($tagsCost, 6);
        $m['tags.tokens.input'] = $tagsIn;
        $m['tags.tokens.output'] = $tagsOut;
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
                // počty polí (n, missing, extra, wrong, notCarried) jsou součty
                return !preg_match('/\.(n|missing|extra|wrong|notCarried)$/', $key);
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
        $out['cost.totalPerDocument'] = round((float) ($out['cost.totalUsd'] ?? 0) / $total, 6);
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
     * Podíl zpráv, u kterých má pole ve všech opakováních stejnou třídu stavu
     * (shoda = `match` i `matchNormalized`; jinak stav sám) — věcná stabilita
     * bez formátového šumu (D44). Jen úspěšná volání.
     *
     * @param list<EvalCallResult> $results
     * @return array<string, float>
     */
    private static function fieldStability(array $results): array
    {
        $out = [];
        foreach (self::statesByNode($results) as $node => $perMessage) {
            $stable = 0;
            foreach ($perMessage as $byRepeat) {
                $classes = array_map(static fn (FieldState $s): string => $s->isHit() ? 'hit' : $s->value, $byRepeat);
                if (count(array_unique($classes)) === 1) {
                    $stable++;
                }
            }
            $out[$node] = round($stable / count($perMessage), 4);
        }
        ksort($out);
        return $out;
    }

    /**
     * Podíl zpráv se shodou ve všech opakováních, u kterých je shoda i stejného
     * tvaru (samé `match`, nebo samé `matchNormalized`) — formátová stabilita
     * (mezery v IBAN, telefonu). Jmenovatel = zprávy se shodou ve všech
     * opakováních; uzel bez takové zprávy se nevypisuje.
     *
     * @param list<EvalCallResult> $results
     * @return array<string, float>
     */
    private static function formatStability(array $results): array
    {
        $out = [];
        foreach (self::statesByNode($results) as $node => $perMessage) {
            $hits = 0;
            $sameShape = 0;
            foreach ($perMessage as $byRepeat) {
                if (array_filter($byRepeat, static fn (FieldState $s): bool => !$s->isHit()) !== []) {
                    continue;
                }
                $hits++;
                if (count(array_unique(array_map(static fn (FieldState $s): string => $s->value, $byRepeat))) === 1) {
                    $sameShape++;
                }
            }
            if ($hits > 0) {
                $out[$node] = round($sameShape / $hits, 4);
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * @param list<EvalCallResult> $results
     * @return array<string, array<string, array<int, FieldState>>> uzel → zpráva → opakování → stav (jen úspěšná volání, bez `notCarried`)
     */
    private static function statesByNode(array $results): array
    {
        $states = [];
        foreach ($results as $r) {
            if (!$r->isOk()) {
                continue;
            }
            foreach ($r->outcomes as $o) {
                if (!$o->state->isCounted()) {
                    continue;
                }
                $states[$o->nodePath()][$r->messageId][$r->repeat] = $o->state;
            }
        }
        return $states;
    }

    /**
     * Kyblíky podle klíče volání (varianta a podoba z buňky korpusu, cesta
     * výpočtu); volání bez klíče se vynechá.
     *
     * @param list<EvalCallResult>              $results
     * @param \Closure(EvalCallResult): ?string $keyOf
     * @return array<string, array<string, int|float>>
     */
    private static function byBucket(array $results, \Closure $keyOf): array
    {
        $buckets = [];
        foreach ($results as $r) {
            $value = $keyOf($r);
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
                'tags.usd' => $m['tags.usd'],
            ];
        }
        return $out;
    }

    /**
     * @param list<EvalCallResult> $results
     * @return array<string, int> uzel → počet úspěšných volání s odchylkou, nejčastější první
     */
    public static function deviationsByField(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            if (!$r->isOk()) {
                continue;   // selhání = missing všeho; porovnání běhů by hlásilo každé pole
            }
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
            array_filter($outcomes, static fn (FieldOutcome $o): bool => !$o->state->isHit() && $o->state->isCounted()),
        ));
    }
}
