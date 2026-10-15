<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

/**
 * Porovnání dvou běhů evaluace (D38): metriky vedle sebe s rozdílem,
 * označení rozdílu „v rámci šumu“ (menší nebo rovný největšímu rozpětí
 * opakování některého z běhů — jen má-li některý běh `repeat > 1`), pole,
 * která se zlepšila / zhoršila (uzel projekce, počet volání s odchylkou
 * v A vs. B), cena a čas. Běhy nad různými sadami (hash obsahu) odmítne.
 */
final class EvalRunComparison
{
    /** Metriky do přehledu, v tomto pořadí (ostatní jen v JSON). */
    public const array HEADLINE_METRICS = [
        'fields.all.hit', 'fields.all.normalized',
        'fields.header.hit', 'fields.supplier.hit', 'fields.customer.hit', 'fields.vat.hit', 'fields.rows.hit',
        'fields.vatRecap.hit', 'fields.totals.hit', 'fields.tag.hit', 'fields.rowExceptions.hit', 'fields.classification.hit',
        'documents.clean', 'documents.amountsCorrect', 'documents.rowCountOk', 'documents.classificationOk', 'documents.tagOk',
        'calls.total', 'calls.ok', 'calls.failed',
        'cost.usd', 'cost.perDocument', 'tokens.input', 'tokens.output', 'time.ms', 'time.perDocumentMs',
    ];

    /** Metriky, u kterých je vyšší hodnota lepší; ostatní (cena, čas, selhání) naopak. */
    private const array HIGHER_IS_BETTER_PREFIXES = ['fields.', 'documents.', 'calls.ok'];

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private function __construct(
        private readonly array $a,
        private readonly array $b,
    ) {}

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @throws EvalException různé sady
     */
    public static function of(array $a, array $b): self
    {
        $hashA = (string) ($a['set']['hash'] ?? '');
        $hashB = (string) ($b['set']['hash'] ?? '');
        if ($hashA === '' || $hashA !== $hashB) {
            throw new EvalException(sprintf(
                'běhy nejsou nad touž sadou (A %s %s, B %s %s) — porovnání nedává smysl',
                (string) ($a['set']['name'] ?? '?'), substr($hashA, 0, 12), (string) ($b['set']['name'] ?? '?'), substr($hashB, 0, 12),
            ));
        }
        return new self($a, $b);
    }

    /**
     * @return array{
     *     set: array<string, mixed>,
     *     runs: array{a: array<string, mixed>, b: array<string, mixed>},
     *     metrics: list<array{key: string, a: int|float|null, b: int|float|null, delta: int|float|null, noise: ?float, withinNoise: bool, better: ?bool}>,
     *     fields: array{improved: list<array{node: string, a: int, b: int}>, worsened: list<array{node: string, a: int, b: int}>},
     *     sameSelection: bool
     * }
     */
    public function toArray(): array
    {
        $metricsA = (array) ($this->a['metrics'] ?? []);
        $metricsB = (array) ($this->b['metrics'] ?? []);
        $rangeA = (array) ($this->a['range'] ?? []);
        $rangeB = (array) ($this->b['range'] ?? []);

        $keys = self::HEADLINE_METRICS;
        foreach (array_keys($metricsA + $metricsB) as $key) {
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        $rows = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $metricsA) && !array_key_exists($key, $metricsB)) {
                continue;
            }
            $va = $metricsA[$key] ?? null;
            $vb = $metricsB[$key] ?? null;
            $delta = is_numeric($va) && is_numeric($vb) ? round($vb - $va, 6) : null;
            $noise = self::noise($rangeA[$key] ?? null, $rangeB[$key] ?? null);
            $better = null;
            if ($delta !== null && abs($delta) > 1e-9) {
                $better = self::higherIsBetter($key) ? $delta > 0 : $delta < 0;
            }
            $rows[] = [
                'key' => $key,
                'a' => $va,
                'b' => $vb,
                'delta' => $delta,
                'noise' => $noise,
                'withinNoise' => $delta !== null && $noise !== null && abs($delta) <= $noise + 1e-9,
                'better' => $better,
            ];
        }

        $devA = (array) ($this->a['deviationsByField'] ?? []);
        $devB = (array) ($this->b['deviationsByField'] ?? []);
        $improved = [];
        $worsened = [];
        foreach (array_unique(array_merge(array_keys($devA), array_keys($devB))) as $node) {
            $ca = (int) ($devA[$node] ?? 0);
            $cb = (int) ($devB[$node] ?? 0);
            if ($cb < $ca) {
                $improved[] = ['node' => (string) $node, 'a' => $ca, 'b' => $cb];
            } elseif ($cb > $ca) {
                $worsened[] = ['node' => (string) $node, 'a' => $ca, 'b' => $cb];
            }
        }
        usort($improved, static fn (array $x, array $y): int => [$y['a'] - $y['b'], $x['node']] <=> [$x['a'] - $x['b'], $y['node']]);
        usort($worsened, static fn (array $x, array $y): int => [$y['b'] - $y['a'], $x['node']] <=> [$x['b'] - $x['a'], $y['node']]);

        return [
            'set' => ['name' => $this->a['set']['name'] ?? null, 'hash' => $this->a['set']['hash'] ?? null],
            'runs' => ['a' => self::summary($this->a), 'b' => self::summary($this->b)],
            'metrics' => $rows,
            'fields' => ['improved' => $improved, 'worsened' => $worsened],
            'sameSelection' => ($this->a['set']['messages'] ?? null) === ($this->b['set']['messages'] ?? null)
                && ($this->a['set']['only'] ?? []) === ($this->b['set']['only'] ?? [])
                && ($this->a['set']['limit'] ?? null) === ($this->b['set']['limit'] ?? null),
        ];
    }

    /** Lidský výpis. @return list<string> */
    public function lines(): array
    {
        $c = $this->toArray();
        $lines = [];
        $lines[] = sprintf('<info>Porovnání běhů</info> — sada %s', $c['set']['name']);
        foreach (['a' => 'A', 'b' => 'B'] as $key => $label) {
            $r = $c['runs'][$key];
            $lines[] = sprintf(
                '  %s: %s — varianta %s, model %s (%s), %d zpráv × %d, %s%s',
                $label, $r['file'] ?? '?', $r['variant'], $r['model'], $r['connection'], $r['messages'], $r['repeat'], $r['startedAt'],
                $r['dirty'] ? ', neuložené změny' : '',
            );
        }
        if (!$c['sameSelection']) {
            $lines[] = '<comment>Běhy mají jiný výběr zpráv (--only / --limit) — porovnávej s rezervou.</comment>';
        }
        $lines[] = sprintf('  %-32s %12s %12s %12s', 'metrika', 'A', 'B', 'rozdíl');
        foreach ($c['metrics'] as $row) {
            if (!in_array($row['key'], self::HEADLINE_METRICS, true)) {
                continue;
            }
            $lines[] = sprintf(
                '  %-32s %12s %12s %12s%s',
                $row['key'],
                self::fmt($row['key'], $row['a']),
                self::fmt($row['key'], $row['b']),
                $row['delta'] === null ? '—' : (($row['delta'] > 0 ? '+' : '') . self::fmt($row['key'], $row['delta'])),
                $row['delta'] === null || abs($row['delta']) < 1e-9 ? '' : ($row['withinNoise'] ? '  v rámci šumu' : ($row['better'] ? '  <info>lepší</info>' : '  <comment>horší</comment>')),
            );
        }
        $format = static fn (array $items): string => implode(', ', array_map(static fn (array $f): string => "{$f['node']} {$f['a']}→{$f['b']}", $items));
        $lines[] = 'Pole zlepšená (počet volání s odchylkou A→B): ' . ($c['fields']['improved'] === [] ? '—' : $format(array_slice($c['fields']['improved'], 0, 10)));
        $lines[] = 'Pole zhoršená: ' . ($c['fields']['worsened'] === [] ? '—' : $format(array_slice($c['fields']['worsened'], 0, 10)));
        return $lines;
    }

    /** @param array<string, mixed> $run @return array<string, mixed> */
    private static function summary(array $run): array
    {
        $effective = (array) ($run['variant']['effective'] ?? []);
        return [
            'file' => $run['_file'] ?? null,
            'variant' => $run['variant']['name'] ?? null,
            'model' => $effective['model'] ?? null,
            'connection' => $effective['connection'] ?? null,
            'promptHash' => $effective['promptHash'] ?? null,
            'messages' => (int) ($run['set']['messages'] ?? 0),
            'repeat' => (int) ($run['repeat'] ?? 1),
            'startedAt' => $run['startedAt'] ?? null,
            'commit' => $run['git']['commit'] ?? null,
            'dirty' => (bool) ($run['git']['dirty'] ?? false),
        ];
    }

    /**
     * Šum = největší rozpětí (max − min) přes opakování v A nebo B; null,
     * když žádný běh opakování nemá.
     *
     * @param array{min: int|float, max: int|float}|null $a
     * @param array{min: int|float, max: int|float}|null $b
     */
    private static function noise(?array $a, ?array $b): ?float
    {
        $values = [];
        foreach ([$a, $b] as $range) {
            if (is_array($range) && isset($range['min'], $range['max'])) {
                $values[] = (float) $range['max'] - (float) $range['min'];
            }
        }
        return $values === [] ? null : round(max($values), 6);
    }

    private static function higherIsBetter(string $key): bool
    {
        foreach (self::HIGHER_IS_BETTER_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return !preg_match('/\.(missing|extra|wrong)$/', $key);
            }
        }
        return false;
    }

    private static function fmt(string $key, int|float|null $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (str_starts_with($key, 'cost.')) {
            return number_format((float) $value, 4, '.', '');
        }
        if ((str_starts_with($key, 'fields.') && !preg_match('/\.(n|missing|extra|wrong)$/', $key)) || str_starts_with($key, 'documents.')) {
            return number_format((float) $value * 100, 1, ',', ' ') . ' %';
        }
        return is_float($value) ? number_format($value, 2, '.', '') : (string) $value;
    }
}
