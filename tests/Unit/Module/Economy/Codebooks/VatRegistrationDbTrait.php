<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use Dibi\Connection;
use Shipard\Tests\Fixtures\Core\Database\RecordCodeDb;

/**
 * Databáze registrací DPH v paměti pro testy kódu registrace (#166 D17):
 * odpovídá na dotazy `VatRegistrationCodeGenerator`
 * a `VatRegistrationCodeBackfill` nad zadanými řádky.
 */
trait VatRegistrationDbTrait
{
    use RecordCodeDb;

    /**
     * @param list<array<string, mixed>> $registrations řádky `economy_codebooks_vat_registrations`
     * @param \ArrayObject<string, mixed>|null $store úložiště, když test čte
     *        řádky po běhu zpět ({@see RecordCodeDb})
     */
    private function registrationsDb(array $registrations = [], ?\ArrayObject $store = null): Connection
    {
        $store ??= new \ArrayObject();
        $store['rows'] = $registrations;
        return $this->recordCodeDb($store, 'country');
    }
}
