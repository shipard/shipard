<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

/**
 * Porovnání dvou běhů evaluace (D38): metriky vedle sebe s rozdílem,
 * označení rozdílu „v rámci šumu“ (menší nebo rovný největšímu rozpětí
 * opakování některého z běhů — jen má-li některý běh `repeat > 1`), pole,
 * která se zlepšila / zhoršila, cena a čas. Běhy nad různými sadami (hash
 * obsahu) odmítne.
 *
 * Běhy s různým počtem opakování (D41, × 1 proti × 3) jsou souměřitelné jen
 * na jedno opakování: součty přes volání (`calls.*`, `tokens.input`
 * / `.output`, `cost.usd`, `time.ms`, počty polí `fields.*.n|missing|extra|wrong`)
 * se dělí `repeat` — pak sedí i k `range`, které je per opakování; podíly
 * (`fields.*.hit`, `documents.*`) a hodnoty na doklad (`cost.perDocument`,
 * `tokens.*PerDocument`, `time.perDocumentMs`) už průměr jsou. Počet volání
 * s odchylkou per uzel (`deviationsByField`) se dělí stejně; za zlepšení
 * / zhoršení platí rozdíl aspoň {@see self::FIELD_CHANGE_THRESHOLD} volání
 * na opakování (při × 1 každý rozdíl, při × 3 aspoň dvě volání ze tří) —
 * jediné odlišné volání z opakovaného běhu není změna.
 *
 * `messages` (D53): doklady vedle sebe — průměr odchylek na úspěšné volání
 * a počet selhání v A a B, pro prohlížeč výsledků; CLI výpis je nevypisuje.
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
        'cost.usd', 'cost.perDocument', 'cost.totalUsd', 'cost.totalPerDocument', 'tags.usd', 'tags.calls',
        'tokens.input', 'tokens.output', 'tokens.inputPerDocument', 'tokens.outputPerDocument',
        'time.ms', 'time.perDocumentMs',
    ];

    /** Nejmenší rozdíl počtu volání s odchylkou na jedno opakování, který platí za zlepšení / zhoršení pole. */
    public const float FIELD_CHANGE_THRESHOLD = 0.5;

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
     * Součty (`perRepetition: true`) jsou na jedno opakování běhu; `fields`
     * nese počty volání s odchylkou per uzel na jedno opakování a práh změny.
     *
     * @return array{
     *     set: array<string, mixed>,
     *     runs: array{a: array<string, mixed>, b: array<string, mixed>},
     *     metrics: list<array{key: string, a: int|float|null, b: int|float|null, delta: int|float|null, noise: ?float, withinNoise: bool, better: ?bool, perRepetition: bool}>,
     *     fields: array{basis: string, threshold: float, improved: list<array{node: string, a: int|float, b: int|float, delta: float}>, worsened: list<array{node: string, a: int|float, b: int|float, delta: float}>},
     *     sameSelection: bool
     * }
     */
    public function toArray(): array
    {
        $metricsA = (array) ($this->a['metrics'] ?? []);
        $metricsB = (array) ($this->b['metrics'] ?? []);
        $rangeA = (array) ($this->a['range'] ?? []);
        $rangeB = (array) ($this->b['range'] ?? []);
        $repeatA = self::repeatOf($this->a);
        $repeatB = self::repeatOf($this->b);

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
            $perRepetition = self::isPerRepetitionSum($key);
            $va = $metricsA[$key] ?? null;
            $vb = $metricsB[$key] ?? null;
            if ($perRepetition) {
                $va = is_int($va) || is_float($va) ? self::perRepetition($va, $repeatA, 6) : $va;
                $vb = is_int($vb) || is_float($vb) ? self::perRepetition($vb, $repeatB, 6) : $vb;
            }
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
                'perRepetition' => $perRepetition,
            ];
        }

        $devA = (array) ($this->a['deviationsByField'] ?? []);
        $devB = (array) ($this->b['deviationsByField'] ?? []);
        $improved = [];
        $worsened = [];
        foreach (array_unique(array_merge(array_keys($devA), array_keys($devB))) as $node) {
            $ca = self::perRepetition((int) ($devA[$node] ?? 0), $repeatA, 4);
            $cb = self::perRepetition((int) ($devB[$node] ?? 0), $repeatB, 4);
            $delta = round($cb - $ca, 4);
            $entry = ['node' => (string) $node, 'a' => $ca, 'b' => $cb, 'delta' => $delta];
            if ($delta <= -self::FIELD_CHANGE_THRESHOLD + 1e-9) {
                $improved[] = $entry;
            } elseif ($delta >= self::FIELD_CHANGE_THRESHOLD - 1e-9) {
                $worsened[] = $entry;
            }
        }
        usort($improved, static fn (array $x, array $y): int => [$x['delta'], $x['node']] <=> [$y['delta'], $y['node']]);
        usort($worsened, static fn (array $x, array $y): int => [$y['delta'], $x['node']] <=> [$x['delta'], $y['node']]);

        return [
            'set' => ['name' => $this->a['set']['name'] ?? null, 'hash' => $this->a['set']['hash'] ?? null],
            'runs' => ['a' => self::summary($this->a), 'b' => self::summary($this->b)],
            'metrics' => $rows,
            'fields' => [
                'basis' => 'perRepetition',
                'threshold' => self::FIELD_CHANGE_THRESHOLD,
                'improved' => $improved,
                'worsened' => $worsened,
            ],
            'sameSelection' => ($this->a['set']['messages'] ?? null) === ($this->b['set']['messages'] ?? null)
                && ($this->a['set']['only'] ?? []) === ($this->b['set']['only'] ?? [])
                && ($this->a['set']['limit'] ?? null) === ($this->b['set']['limit'] ?? null),
            'messages' => self::perMessage($this->a, $this->b),
        ];
    }

    /**
     * Doklady vedle sebe (#103 D53, prohlížeč výsledků): per `messageId`
     * průměrný počet odchylek na úspěšné volání a počet selhání v A a B.
     * Selhané volání odchylky nemá (počítá se jen do `failed`), doklad jen
     * v jednom běhu má druhou stranu null. Řazeno podle velikosti rozdílu
     * (největší první, bez rozdílu nakonec), pak podle id.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return list<array{messageId: string, a: array{deviations: ?float, failed: int, calls: int}, b: array{deviations: ?float, failed: int, calls: int}, delta: ?float}>
     */
    private static function perMessage(array $a, array $b): array
    {
        $sideA = self::deviationsByMessage($a);
        $sideB = self::deviationsByMessage($b);
        $empty = ['deviations' => null, 'failed' => 0, 'calls' => 0];
        $out = [];
        foreach (array_unique(array_merge(array_keys($sideA), array_keys($sideB))) as $messageId) {
            $ra = $sideA[$messageId] ?? $empty;
            $rb = $sideB[$messageId] ?? $empty;
            $delta = $ra['deviations'] !== null && $rb['deviations'] !== null ? round($rb['deviations'] - $ra['deviations'], 4) : null;
            $out[] = ['messageId' => (string) $messageId, 'a' => $ra, 'b' => $rb, 'delta' => $delta];
        }
        usort($out, static function (array $x, array $y): int {
            $ax = $x['delta'] === null ? -1.0 : abs($x['delta']);
            $ay = $y['delta'] === null ? -1.0 : abs($y['delta']);
            return [$ay, $x['messageId']] <=> [$ax, $y['messageId']];
        });
        return $out;
    }

    /**
     * @param array<string, mixed> $run
     * @return array<string, array{deviations: ?float, failed: int, calls: int}>
     */
    private static function deviationsByMessage(array $run): array
    {
        $acc = [];
        foreach ((array) ($run['calls'] ?? []) as $call) {
            if (!is_array($call) || !is_string($call['messageId'] ?? null)) {
                continue;
            }
            $id = $call['messageId'];
            $acc[$id] ??= ['sum' => 0, 'ok' => 0, 'failed' => 0, 'calls' => 0];
            $acc[$id]['calls']++;
            if (($call['status'] ?? null) === EvalCallResult::STATUS_OK) {
                $acc[$id]['ok']++;
                $acc[$id]['sum'] += count((array) ($call['deviations'] ?? []));
            } else {
                $acc[$id]['failed']++;
            }
        }
        $out = [];
        foreach ($acc as $id => $v) {
            $out[$id] = [
                'deviations' => $v['ok'] > 0 ? round($v['sum'] / $v['ok'], 4) : null,
                'failed' => $v['failed'],
                'calls' => $v['calls'],
            ];
        }
        return $out;
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
        if (max($c['runs']['a']['repeat'], $c['runs']['b']['repeat']) > 1) {
            $lines[] = '  Součty (volání, tokeny, cena, čas, počty polí) jsou přepočtené na jedno opakování.';
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
        $format = static fn (array $items): string => implode(', ', array_map(
            static fn (array $f): string => "{$f['node']} " . self::fmtCount($f['a']) . '→' . self::fmtCount($f['b']),
            $items,
        ));
        $lines[] = sprintf(
            'Pole zlepšená (počet volání s odchylkou na jedno opakování A→B; změna pod %s volání se nepočítá): %s',
            self::fmtCount(self::FIELD_CHANGE_THRESHOLD),
            $c['fields']['improved'] === [] ? '—' : $format(array_slice($c['fields']['improved'], 0, 10)),
        );
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
            'repeat' => self::repeatOf($run),
            'startedAt' => $run['startedAt'] ?? null,
            'commit' => $run['git']['commit'] ?? null,
            'dirty' => (bool) ($run['git']['dirty'] ?? false),
        ];
    }

    /** @param array<string, mixed> $run */
    private static function repeatOf(array $run): int
    {
        return max(1, (int) ($run['repeat'] ?? 1));
    }

    /**
     * Součet přes všechna volání běhu (sčítá se přes opakování) — na rozdíl
     * od podílů a hodnot na doklad, které jsou průměrem už v souboru.
     */
    private static function isPerRepetitionSum(string $key): bool
    {
        if (str_starts_with($key, 'documents.')) {
            return false;
        }
        if (str_starts_with($key, 'fields.')) {
            return (bool) preg_match('/\.(n|missing|extra|wrong)$/', $key);
        }
        return !str_contains(strtolower($key), 'perdocument');
    }

    /** Hodnota na jedno opakování; celé číslo zůstává celé, jde-li dělit beze zbytku. */
    private static function perRepetition(int|float $value, int $repeat, int $precision): int|float
    {
        if ($repeat <= 1) {
            return $value;
        }
        if (is_int($value) && $value % $repeat === 0) {
            return intdiv($value, $repeat);
        }
        return round($value / $repeat, $precision);
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
        if (str_starts_with($key, 'cost.') || $key === 'tags.usd') {
            return number_format((float) $value, 4, '.', '');
        }
        if ((str_starts_with($key, 'fields.') && !preg_match('/\.(n|missing|extra|wrong)$/', $key)) || str_starts_with($key, 'documents.')) {
            return number_format((float) $value * 100, 1, ',', ' ') . ' %';
        }
        if (str_starts_with($key, 'tokens.') || str_starts_with($key, 'time.')) {
            return number_format((float) $value, 0, '.', '');
        }
        return is_float($value) && abs($value - round($value)) > 1e-9 ? number_format($value, 2, '.', '') : (string) (int) round($value);
    }

    /** Počet volání na opakování: celé číslo bez desetinných míst, jinak jedno. */
    private static function fmtCount(int|float $value): string
    {
        return abs($value - round($value)) < 1e-9 ? (string) (int) round($value) : number_format((float) $value, 1, '.', '');
    }
}
