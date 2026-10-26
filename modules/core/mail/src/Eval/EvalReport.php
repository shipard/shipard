<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

/** Lidský souhrn běhu evaluace na terminál (D37) ze souboru výsledků. */
final class EvalReport
{
    private function __construct()
    {
    }

    /** @param array<string, mixed> $run @return list<string> */
    public static function lines(array $run, ?string $file = null): array
    {
        $m = $run['metrics'];
        $range = $run['range'] ?? null;
        $set = $run['set'];
        $effective = $run['variant']['effective'];
        $repeat = (int) ($run['repeat'] ?? 1);

        $lines = [];
        $lines[] = sprintf(
            '<info>Evaluace AI</info> — sada %s (%d zpráv × %d), varianta %s',
            $set['name'], (int) ($set['messages'] ?? 0), $repeat, $run['variant']['name'],
        );
        $lines[] = sprintf(
            'Model %s (%s, připojení %s — %s), profil %s %s%s',
            $effective['model'], $effective['platform'], $effective['connection'], $effective['connectionOrigin'] ?? '?',
            $effective['profile'], $effective['promptVersion'],
            ($effective['promptSource'] ?? '') === 'file' ? ' (prompt ze souboru)' : '',
        );
        $failed = [];
        foreach ($m as $key => $value) {
            if (str_starts_with($key, 'calls.failed.')) {
                $failed[] = substr($key, strlen('calls.failed.')) . ' ' . $value;
            }
        }
        $lines[] = sprintf('Volání: %d, úspěšných %d%s', $m['calls.total'], $m['calls.ok'], $failed !== [] ? ', selhání: ' . implode(', ', $failed) : '');
        $lines[] = 'Shoda polí (match + po normalizaci):';
        foreach (EvalFieldGroups::GROUPS as $group) {
            if (($m["fields.{$group}.n"] ?? 0) === 0) {
                continue;
            }
            $lines[] = sprintf(
                '  %-22s %s  (po normalizaci %s)  chybí %d · navíc %d · špatně %d',
                EvalFieldGroups::label($group),
                self::ratio($m["fields.{$group}.hit"], $range["fields.{$group}.hit"] ?? null),
                self::pct($m["fields.{$group}.normalized"]),
                $m["fields.{$group}.missing"], $m["fields.{$group}.extra"], $m["fields.{$group}.wrong"],
            );
        }
        $lines[] = sprintf('  %-22s %s  (po normalizaci %s)', 'Celkem', self::ratio($m['fields.all.hit'], $range['fields.all.hit'] ?? null), self::pct($m['fields.all.normalized']));
        $lines[] = sprintf(
            'Doklady: bez odchylky %s · správné částky %s · počet řádků sedí %s · klasifikace %s · štítek %s',
            self::ratio($m['documents.clean'], $range['documents.clean'] ?? null),
            self::ratio($m['documents.amountsCorrect'], $range['documents.amountsCorrect'] ?? null),
            self::ratio($m['documents.rowCountOk'], $range['documents.rowCountOk'] ?? null),
            self::pct($m['documents.classificationOk']),
            self::pct($m['documents.tagOk']),
        );
        $lines[] = sprintf(
            'Cena %s USD (%s / doklad), tokeny %s / %s, čas %s (%s / doklad)',
            number_format((float) $m['cost.usd'], 4, '.', ''),
            number_format((float) $m['cost.perDocument'], 4, '.', ''),
            self::k((int) $m['tokens.input']), self::k((int) $m['tokens.output']),
            self::seconds((int) $m['time.ms']), self::seconds((int) $m['time.perDocumentMs']),
        );
        $top = array_slice($run['deviationsByField'] ?? [], 0, 8, true);
        if ($top !== []) {
            $parts = [];
            foreach ($top as $node => $count) {
                $parts[] = "{$node} {$count}×";
            }
            $lines[] = 'Nejčastější odchylky: ' . implode(', ', $parts);
        }
        // Informativní pole (#103 D114): porovnané, ale mimo shodu polí a doklady bez odchylky; běh v1 klíče nemá.
        $g = EvalFieldGroups::INFORMATIVE_GROUP;
        if (($m["fields.{$g}.n"] ?? 0) > 0) {
            $parts = [];
            foreach ($run['informativeByField'] ?? [] as $node => $count) {
                $parts[] = "{$node} {$count}×";
            }
            $lines[] = sprintf(
                'Informativní pole (mimo shodu polí a doklady bez odchylky): %s  (po normalizaci %s)  chybí %d · navíc %d · špatně %d%s',
                self::ratio($m["fields.{$g}.hit"], $range["fields.{$g}.hit"] ?? null),
                self::pct($m["fields.{$g}.normalized"]),
                $m["fields.{$g}.missing"], $m["fields.{$g}.extra"], $m["fields.{$g}.wrong"],
                $parts !== [] ? ' — ' . implode(', ', $parts) : '',
            );
        }

        if (!empty($run['byVariant'])) {
            $lines[] = 'Po variantách korpusu:';
            foreach ($run['byVariant'] as $variant => $v) {
                $lines[] = sprintf(
                    '  %-28s %d volání, shoda %s, bez odchylky %s, částky %s',
                    $variant, $v['calls.total'], self::pct($v['fields.all.hit']), self::pct($v['documents.clean']), self::pct($v['documents.amountsCorrect']),
                );
            }
        }
        if (!empty($run['byForm'])) {
            $parts = [];
            foreach ($run['byForm'] as $form => $v) {
                $parts[] = sprintf('%s shoda %s, bez odchylky %s', $form, self::pct($v['fields.all.hit']), self::pct($v['documents.clean']));
            }
            $lines[] = 'Po podobách: ' . implode(' · ', $parts);
        }
        if (!empty($run['byPath'])) {
            $parts = [];
            foreach ($run['byPath'] as $path => $v) {
                $parts[] = sprintf(
                    '%s %d volání, shoda %s, bez odchylky %s, cena %s USD',
                    $path, $v['calls.total'], self::pct($v['fields.all.hit']), self::pct($v['documents.clean']),
                    number_format((float) $v['cost.usd'] + (float) ($v['tags.usd'] ?? 0), 4, '.', ''),
                );
            }
            $lines[] = 'Po cestách (ai = extrakce modelem, isdoc = import ISDOC + štítky): ' . implode(' · ', $parts);
        }
        if ($repeat > 1 && !empty($run['fieldStability'])) {
            $unstable = array_filter($run['fieldStability'], static fn (float $v): bool => $v < 1.0);
            asort($unstable);
            $parts = [];
            foreach (array_slice($unstable, 0, 6, true) as $node => $v) {
                $parts[] = "{$node} " . self::pct($v);
            }
            $lines[] = 'Stabilita polí přes opakování: ' . ($parts === [] ? 'všechna pole stabilní' : 'nejméně stabilní ' . implode(', ', $parts));
        }
        if ($repeat > 1 && !empty($run['formatStability'])) {
            $unstable = array_filter($run['formatStability'], static fn (float $v): bool => $v < 1.0);
            asort($unstable);
            if ($unstable !== []) {
                $parts = [];
                foreach (array_slice($unstable, 0, 6, true) as $node => $v) {
                    $parts[] = "{$node} " . self::pct($v);
                }
                $lines[] = 'Stabilita formátu (jen tvar shodné hodnoty, např. mezery): nejméně stabilní ' . implode(', ', $parts);
            }
        }
        foreach ($run['calls'] as $call) {
            if (($call['status'] ?? '') !== EvalCallResult::STATUS_OK) {
                $lines[] = sprintf('<comment>✗ %s (%d): %s — %s</comment>', $call['messageId'], $call['repeat'], $call['errorType'], $call['note']);
            }
        }
        // Nálezy hodnot (#118 D17): neplatné IČO / DIČ / PSČ / účet / IBAN / BIC
        // a opravy IBAN ↔ číslo účtu u úspěšných volání — mimo metriky.
        $withFindings = array_values(array_filter($run['calls'], static fn (array $c): bool => EvalCallResult::valueFindingsOf($c) !== []));
        if ($withFindings !== []) {
            $lines[] = sprintf('Nálezy hodnot (IČO, DIČ, PSČ, účet, IBAN, BIC, opravy): %d volání', count($withFindings));
            foreach ($withFindings as $call) {
                $lines[] = sprintf('  %s (%d): %s', $call['messageId'], (int) ($call['repeat'] ?? 1), self::findings(EvalCallResult::valueFindingsOf($call)));
            }
        }
        if ($file !== null) {
            $lines[] = "Soubor: {$file}";
        }
        return $lines;
    }

    /** Jeden řádek průběhu per volání. */
    public static function progressLine(EvalCallResult $r, EvalMessage $message, int $repeat): string
    {
        $label = $message->stem ?? $message->messageId;
        $suffix = $repeat > 1 ? " ({$r->repeat})" : '';
        if (!$r->isOk()) {
            return sprintf('<comment>✗</comment> %-32s%s %s: %s', $label, $suffix, $r->errorType, mb_substr((string) $r->note, 0, 120));
        }
        $counts = $r->counts();
        $n = array_sum($counts) - $counts['notCarried'];
        $hit = $n > 0 ? ($counts['match'] + $counts['matchNormalized']) / $n : 0.0;
        $deviations = $counts['missing'] + $counts['extra'] + $counts['wrong'];
        return sprintf(
            '<info>✓</info> %-32s%s %s  $%s  shoda %s%s%s',
            $label, $suffix, self::seconds((int) $r->durationMs), number_format((float) $r->costUsd, 4, '.', ''), self::pct($hit),
            $deviations > 0 ? " ({$deviations} odchylek)" : '',
            $r->valueFindings !== [] ? '  nálezy: ' . self::findings($r->valueFindings) : '',
        );
    }

    /** @param list<array{path: string, code: string}> $findings */
    private static function findings(array $findings): string
    {
        return implode(', ', array_map(static fn (array $f): string => "{$f['code']}@{$f['path']}", $findings));
    }

    private static function pct(int|float $ratio): string
    {
        return number_format($ratio * 100, 1, ',', ' ') . ' %';
    }

    /** @param array{min: int|float, max: int|float}|null $range */
    private static function ratio(int|float $value, ?array $range): string
    {
        $text = self::pct($value);
        if ($range !== null && $range['min'] !== $range['max']) {
            $text .= sprintf(' [%s–%s]', self::pct($range['min']), self::pct($range['max']));
        }
        return $text;
    }

    private static function k(int $tokens): string
    {
        return $tokens >= 10000 ? number_format($tokens / 1000, 0, ',', ' ') . ' k' : (string) $tokens;
    }

    private static function seconds(int $ms): string
    {
        return number_format($ms / 1000, 1, ',', ' ') . ' s';
    }
}
