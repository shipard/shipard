<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing;

use Shipard\Module\Economy\PeriodicInvoicing\InvoiceBuilder;

/** Builder bez DB: zákazník, jazyk, kódy. */
class FakeInvoiceBuilder extends InvoiceBuilder
{
    protected function customerParty(int $personId): ?array
    {
        return ['name' => 'Zákazník', 'companyId' => '12345678', 'country' => 'cz'];
    }

    protected function personLanguage(int $personId): ?string
    {
        return 'cs';
    }

    protected function costCenterCode(int $costCenterId): ?string
    {
        return null;
    }

    protected function itemCode(int $itemId): ?string
    {
        return null;
    }

    protected function unitShortcut(?int $unitId): ?string
    {
        return null;
    }

    protected function defaultBankAccount(): ?int
    {
        return 3;
    }

    protected function periodTexts(string $language): ?array
    {
        return null;
    }
}
