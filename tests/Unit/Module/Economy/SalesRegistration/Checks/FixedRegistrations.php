<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Checks;

/** Šev kontrol certifikátu pro testy: pevné registrace a pevné „teď“. */
trait FixedRegistrations
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];
    public string $fixedNow = 'now';

    protected function registrations(): array
    {
        return $this->rows;
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->fixedNow);
    }
}
