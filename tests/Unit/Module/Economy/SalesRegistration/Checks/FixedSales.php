<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Checks;

/**
 * Šev upozornění na tržby pro testy: tržby v paměti seskupené stejně jako
 * SQL kontroly (stav, stáří podle času tržby, po registracích) a pevné „teď“.
 */
trait FixedSales
{
    /** @var list<array{registration: int, state: string, sold_at: string}> */
    public array $sales = [];
    public string $fixedNow = 'now';

    protected function groups(array $states, ?string $soldBefore, ?string $soldFrom): array
    {
        $groups = [];
        foreach ($this->sales as $sale) {
            if (!in_array($sale['state'], $states, true)
                || ($soldBefore !== null && !($sale['sold_at'] < $soldBefore))
                || ($soldFrom !== null && !($sale['sold_at'] >= $soldFrom))
            ) {
                continue;
            }
            $id = $sale['registration'];
            $groups[$id] ??= ['registration' => $id, 'name' => 'Registrace ' . $id, 'count' => 0, 'oldest' => $sale['sold_at']];
            $groups[$id]['count']++;
            $groups[$id]['oldest'] = min($groups[$id]['oldest'], $sale['sold_at']);
        }
        ksort($groups);
        return array_values($groups);
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->fixedNow);
    }
}
