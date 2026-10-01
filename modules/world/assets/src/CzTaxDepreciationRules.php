<?php

declare(strict_types=1);

namespace Shipard\Module\World\Assets;

/**
 * Daňové odpisy podle českého zákona o daních z příjmů (§ 26–§ 32a).
 *
 * Vzorce jsou tady, čísla výhradně v cfgItem `world.assets.cz`
 * (`config/assets-cz.jsonc`). Sazby a koeficienty se volí podle data
 * prvního zařazení majetku (D43).
 */
final class CzTaxDepreciationRules implements TaxDepreciationRules
{
    private const METHOD_STRAIGHT = 'straight';
    private const METHOD_ACCELERATED = 'accelerated';

    /** Druhy metod v konfiguraci → druh pro engine. */
    private const CFG_KINDS = [
        'annual' => self::KIND_ANNUAL,
        'schedule' => self::KIND_MONTHLY,
        'time' => self::KIND_MONTHLY,
        'accounting' => self::KIND_ACCOUNTING,
        'none' => self::KIND_NONE,
    ];

    /** Tabulka sazeb / koeficientů roční metody. */
    private const RATE_TABLES = [
        self::METHOD_STRAIGHT => 'straightRates',
        self::METHOD_ACCELERATED => 'acceleratedCoefficients',
    ];

    /** @param array<string, mixed> $cfg cfgItem `world.assets.cz` */
    public function __construct(private readonly array $cfg)
    {
    }

    public function country(): string
    {
        return 'cz';
    }

    public function availableMethods(?string $acquiredDate, bool $intangible): array
    {
        $intangibleFrom = $this->cfg['intangibleTaxFrom'] ?? null;
        $result = [];
        foreach ($this->cfg['methods'] ?? [] as $method => $def) {
            if (empty($def[$intangible ? 'intangible' : 'tangible'])) {
                continue;
            }
            $kind = $this->methodKind($method);
            // Bez data zařazení (karta před zařazením) se platnost neřeší.
            if ($acquiredDate !== null && ($kind === self::KIND_ANNUAL || $kind === self::KIND_MONTHLY)) {
                // Nehmotný majetek od zrušení § 32a vlastní daňový výpočet nemá.
                if ($intangible && $intangibleFrom !== null && $acquiredDate >= $intangibleFrom) {
                    continue;
                }
                if ($this->rules($method, $acquiredDate) === []) {
                    continue;
                }
            }
            $result[] = $method;
        }
        return $result;
    }

    public function methodKind(string $method): ?string
    {
        $kind = (string) ($this->cfg['methods'][$method]['kind'] ?? '');
        return self::CFG_KINDS[$kind] ?? null;
    }

    public function methodName(string $method): string
    {
        return (string) ($this->cfg['methods'][$method]['name'] ?? $method);
    }

    public function rules(string $method, ?string $acquiredDate): array
    {
        $result = [];
        foreach ($this->ruleSet($method) as $code => $def) {
            if ($acquiredDate === null || $this->ruleValid($method, (string) $code, $acquiredDate)) {
                $result[] = ['code' => (string) $code, 'name' => (string) ($def['name'] ?? $code)];
            }
        }
        return $result;
    }

    public function taxReturnGroup(string $method, ?string $rule): ?string
    {
        $own = $this->cfg['methods'][$method]['taxReturnGroup'] ?? null;
        if ($own !== null) {
            return (string) $own;
        }
        $def = $rule !== null ? ($this->ruleSet($method)[$rule] ?? null) : null;
        if ($def === null) {
            return null;
        }
        // Varianta skupiny (zvýšený odpis 1. roku) dědí zařazení základní skupiny.
        $group = $def['taxReturnGroup']
            ?? (isset($def['group']) ? ($this->cfg['groups'][$def['group']]['taxReturnGroup'] ?? null) : null);

        return $group !== null ? (string) $group : null;
    }

    public function taxReturnGroups(): array
    {
        $groups = [];
        foreach ($this->cfg['taxReturnGroups'] ?? [] as $key => $def) {
            $groups[(string) $key] = (string) ($def['name'] ?? $key);
        }
        return $groups;
    }

    public function isInterruptible(string $method): bool
    {
        return in_array($method, $this->cfg['interruptible'] ?? [], true);
    }

    public function allowsHalfYearOnDisposal(string $method): bool
    {
        return in_array($method, $this->cfg['halfYearOnDisposal'] ?? [], true);
    }

    public function allowsShortPeriodHalfYear(string $method): bool
    {
        return in_array($method, $this->cfg['shortPeriodHalfYear'] ?? [], true);
    }

    public function allowsImprovement(string $method): bool
    {
        return (bool) ($this->cfg['methods'][$method]['improvement'] ?? true);
    }

    /**
     * Zaokrouhlení dle `rounding` (CZ: nahoru na celé koruny). Hodnota se
     * nejdřív srovná na 4 místa — prosté `ceil(50000 * 5.15 / 100)` dá kvůli
     * plovoucí čárce 2 576 místo 2 575.
     */
    public function round(float $amount): float
    {
        $factor = 10 ** (int) ($this->cfg['rounding']['precision'] ?? 0);
        $scaled = round($amount * $factor, 4);

        $rounded = match ($this->cfg['rounding']['mode'] ?? 'ceil') {
            'floor' => floor($scaled),
            'round' => round($scaled),
            default => ceil($scaled),
        };

        return $rounded / $factor;
    }

    public function annualAmount(TaxYearInput $in): TaxAmount
    {
        $rate = $this->rateFor($in->method, $in->ruleCode, $in->acquiredDate);
        $residual = max($in->residual, 0.0);

        if ($in->method === self::METHOD_STRAIGHT) {
            // § 31 odst. 7 a 8: jedna setina součinu (zvýšené) vstupní ceny a sazby.
            $pct = (float) ($in->increased
                ? $rate['increased']
                : ($in->yearsApplied === 0 ? $rate['first'] : $rate['next']));
            $exact = $in->entryPrice * $pct / 100.0;
            $formula = self::money($in->entryPrice) . ' × ' . self::trimmed($pct) . ' %';
        } else {
            [$exact, $formula] = $this->accelerated($in, $rate, $residual);
        }

        if ($in->halfYear || ($in->shortPeriod && $this->allowsShortPeriodHalfYear($in->method))) {
            // § 26 odst. 7: polovina ročního odpisu — rok vyřazení nebo
            // zdaňovací období kratší než 12 měsíců; obojí najednou je
            // pořád jedna polovina.
            $exact /= 2;
            $formula = "({$formula}) / 2";
        }

        return $this->capped($exact, $formula, $residual);
    }

    public function scheduleMonths(TaxScheduleInput $in): int
    {
        $rule = $this->scheduleRule($in->method, $in->ruleCode);

        if (isset($rule['schedule'])) {
            return (int) array_sum(array_column($rule['schedule'], 'months'));
        }
        if ($in->increased) {
            // § 32a odst. 6: po zbývající dobu, nejméně však `monthsIncreased`.
            return max($in->remainingAtIncrease, (int) ($rule['monthsIncreased'] ?? 0), 1);
        }
        return (int) $rule['months'];
    }

    public function scheduleAmount(TaxScheduleInput $in): TaxAmount
    {
        $rule = $this->scheduleRule($in->method, $in->ruleCode);
        $total = $this->scheduleMonths($in);
        $months = max(min($in->months, $total - $in->monthsDone), 0);
        $residual = max($in->residual, 0.0);

        if ($months === 0) {
            return new TaxAmount(0.0, '', 0.0);
        }

        if (isset($rule['schedule'])) {
            [$exact, $formula] = $this->stages($rule['schedule'], $in->base, $in->monthsDone, $months);
        } else {
            $exact = $in->base / $total * $months;
            $formula = self::money($in->base) . " / {$total} × {$months}";
        }

        // Poslední úsek rozpisu dorovná zůstatek — odpisuje se do 100 % ceny.
        if ($in->monthsDone + $months >= $total) {
            return new TaxAmount($residual, $formula, $exact);
        }
        return $this->capped($exact, $formula, $residual);
    }

    /**
     * Kontrola vnitřní konzistence konfigurace. Prázdné pole = v pořádku.
     *
     * @return list<string>
     */
    public function validateConfig(): array
    {
        $errors = [];
        $methods = $this->cfg['methods'] ?? [];
        $groups = $this->cfg['groups'] ?? [];

        foreach ($methods as $method => $def) {
            if (!isset(self::CFG_KINDS[$def['kind'] ?? ''])) {
                $errors[] = "methods['{$method}']: unknown kind '" . ($def['kind'] ?? '') . "'";
            }
        }
        foreach (['halfYearOnDisposal', 'shortPeriodHalfYear', 'interruptible'] as $list) {
            foreach ($this->cfg[$list] ?? [] as $method) {
                if (!isset($methods[$method])) {
                    $errors[] = "{$list}: unknown method '{$method}'";
                }
            }
        }
        foreach ($groups as $code => $def) {
            if (isset($def['group']) && !isset($groups[$def['group']])) {
                $errors[] = "groups['{$code}']: unknown group '{$def['group']}'";
            }
            foreach ($def['methods'] ?? [] as $method) {
                if (!isset(self::RATE_TABLES[$method])) {
                    $errors[] = "groups['{$code}']: method '{$method}' has no rate table";
                }
            }
        }
        foreach (self::RATE_TABLES as $table) {
            $byCode = [];
            foreach ($this->cfg[$table] ?? [] as $i => $entry) {
                $code = $entry['code'] ?? '';
                if (!isset($groups[$code])) {
                    $errors[] = "{$table}[{$i}]: unknown group '{$code}'";
                }
                foreach (['first', 'next', 'increased'] as $key) {
                    if (!is_numeric($entry[$key] ?? null) || $entry[$key] <= 0) {
                        $errors[] = "{$table}[{$i}]: '{$key}' must be positive";
                    }
                }
                foreach ($byCode[$code] ?? [] as $other) {
                    if (self::overlaps($entry, $other)) {
                        $errors[] = "{$table}[{$i}]: interval of '{$code}' overlaps another one";
                    }
                }
                $byCode[$code][] = $entry;
            }
        }
        foreach ($this->cfg['timeRules'] ?? [] as $code => $rule) {
            if ((int) ($rule['months'] ?? 0) <= 0) {
                $errors[] = "timeRules['{$code}']: 'months' must be positive";
            }
        }
        foreach ($this->cfg['extraordinaryRules'] ?? [] as $code => $rule) {
            $pct = array_sum(array_column($rule['schedule'] ?? [], 'pct'));
            if (abs($pct - 100.0) > 0.0001) {
                $errors[] = "extraordinaryRules['{$code}']: schedule sums to {$pct} %, expected 100";
            }
            foreach ($rule['schedule'] ?? [] as $i => $stage) {
                if ((int) ($stage['months'] ?? 0) <= 0) {
                    $errors[] = "extraordinaryRules['{$code}'].schedule[{$i}]: 'months' must be positive";
                }
            }
            foreach ($rule['groups'] ?? [] as $group) {
                if (!isset($groups[$group])) {
                    $errors[] = "extraordinaryRules['{$code}']: unknown group '{$group}'";
                }
            }
        }

        $returnGroups = $this->cfg['taxReturnGroups'] ?? [];
        foreach (['methods' => $methods, 'groups' => $groups, 'timeRules' => $this->cfg['timeRules'] ?? [],
            'extraordinaryRules' => $this->cfg['extraordinaryRules'] ?? []] as $section => $defs) {
            foreach ($defs as $code => $def) {
                if (isset($def['taxReturnGroup']) && !isset($returnGroups[(string) $def['taxReturnGroup']])) {
                    $errors[] = "{$section}['{$code}']: unknown taxReturnGroup '{$def['taxReturnGroup']}'";
                }
            }
        }

        return $errors;
    }

    /**
     * § 32 odst. 2 a 3.
     *
     * @param array<string, mixed> $rate
     * @return array{float, string}
     */
    private function accelerated(TaxYearInput $in, array $rate, float $residual): array
    {
        if (!$in->increased && $in->yearsApplied === 0) {
            $first = (int) $rate['first'];
            return [$in->entryPrice / $first, self::money($in->entryPrice) . " / {$first}"];
        }

        // Po technickém zhodnocení se počítají jen roky odpisované ze zvýšené
        // zůstatkové ceny; v roce zhodnocení (0 let) vyjde odst. 3 písm. a).
        $coefficient = (int) ($in->increased ? $rate['increased'] : $rate['next']);
        $years = $in->increased ? $in->yearsSinceIncrease : $in->yearsApplied;
        $divisor = $coefficient - $years;

        if ($divisor < 1) {
            // Doba odpisování uplynula (např. po snížení hodnoty) — zbytek najednou.
            return [$residual, self::money($residual)];
        }
        $formula = '2 × ' . self::money($residual) . ' / '
            . ($years === 0 ? (string) $coefficient : "({$coefficient} − {$years})");

        return [2 * $residual / $divisor, $formula];
    }

    /**
     * Součet měsíců napříč úseky rozpisu (§ 30a): úsek = `pct` % základu
     * rovnoměrně do `months` měsíců.
     *
     * @param list<array{months: int, pct: float|int}> $schedule
     * @return array{float, string}
     */
    private function stages(array $schedule, float $base, int $monthsDone, int $months): array
    {
        $exact = 0.0;
        $parts = [];
        $stageBegin = 0;
        foreach ($schedule as $stage) {
            $stageEnd = $stageBegin + (int) $stage['months'];
            $count = min($monthsDone + $months, $stageEnd) - max($monthsDone, $stageBegin);
            if ($count > 0) {
                $exact += $base * (float) $stage['pct'] / 100.0 / (int) $stage['months'] * $count;
                $parts[] = self::money($base) . ' × ' . self::trimmed((float) $stage['pct'])
                    . ' % / ' . (int) $stage['months'] . " × {$count}";
            }
            $stageBegin = $stageEnd;
        }
        return [$exact, implode(' + ', $parts)];
    }

    private function capped(float $exact, string $formula, float $residual): TaxAmount
    {
        $amount = $this->round($exact);
        if ($amount > $residual) {
            return new TaxAmount($residual, "min({$formula}; " . self::money($residual) . ')', $exact);
        }
        return new TaxAmount($amount, $formula, $exact);
    }

    /**
     * Pravidla metody: skupiny ročních metod, časová nebo mimořádná pravidla.
     *
     * @return array<string, array<string, mixed>>
     */
    private function ruleSet(string $method): array
    {
        if (isset(self::RATE_TABLES[$method])) {
            return array_filter(
                $this->cfg['groups'] ?? [],
                static fn(array $group): bool => !isset($group['methods'])
                    || in_array($method, $group['methods'], true),
            );
        }
        return match ($this->cfg['methods'][$method]['kind'] ?? null) {
            'time' => $this->cfg['timeRules'] ?? [],
            'schedule' => $this->cfg['extraordinaryRules'] ?? [],
            default => [],
        };
    }

    private function ruleValid(string $method, string $code, string $acquiredDate): bool
    {
        if (isset(self::RATE_TABLES[$method])) {
            return $this->findRate($method, $code, $acquiredDate) !== null;
        }
        $rule = $this->ruleSet($method)[$code] ?? null;

        return $rule !== null && self::inInterval($rule, $acquiredDate);
    }

    /** @return array<string, mixed> */
    private function rateFor(string $method, string $code, string $acquiredDate): array
    {
        if (!isset(self::RATE_TABLES[$method])) {
            throw new \LogicException("Metoda '{$method}' není roční daňová metoda");
        }
        return $this->findRate($method, $code, $acquiredDate)
            ?? throw new \LogicException(
                "Pro skupinu '{$code}' a metodu '{$method}' není sazba platná k {$acquiredDate}",
            );
    }

    /** @return array<string, mixed>|null */
    private function findRate(string $method, string $code, string $acquiredDate): ?array
    {
        if (!isset($this->ruleSet($method)[$code])) {
            return null;
        }
        foreach ($this->cfg[self::RATE_TABLES[$method]] ?? [] as $entry) {
            if (($entry['code'] ?? null) === $code && self::inInterval($entry, $acquiredDate)) {
                return $entry;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function scheduleRule(string $method, string $code): array
    {
        if ($this->methodKind($method) !== self::KIND_MONTHLY) {
            throw new \LogicException("Metoda '{$method}' není měsíční daňová metoda");
        }
        return $this->ruleSet($method)[$code]
            ?? throw new \LogicException("Neznámé pravidlo '{$code}' metody '{$method}'");
    }

    /** @param array<string, mixed> $entry */
    private static function inInterval(array $entry, string $date): bool
    {
        return (($entry['from'] ?? null) === null || $date >= $entry['from'])
            && (($entry['to'] ?? null) === null || $date <= $entry['to']);
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function overlaps(array $a, array $b): bool
    {
        $aFrom = $a['from'] ?? '0000-00-00';
        $aTo = $a['to'] ?? '9999-12-31';
        $bFrom = $b['from'] ?? '0000-00-00';
        $bTo = $b['to'] ?? '9999-12-31';

        return $aFrom <= $bTo && $bFrom <= $aTo;
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }

    /** Číslo bez koncových nul: 22,25 · 20 · 33,3. */
    private static function trimmed(float $value): string
    {
        $formatted = number_format($value, 4, ',', ' ');
        return rtrim(rtrim($formatted, '0'), ',');
    }
}
