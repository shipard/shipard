<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Shipard\Tests\Fixtures\Core\Database\RecordCodeDb;

/**
 * Databáze číselných řad v paměti pro testy kódu řady (#166 D17): odpovídá
 * na dotazy `NumberSeriesCodeGenerator`, `NumberSeriesDocument`
 * a `NumberSeriesCodeBackfill` nad zadanými řádky řad a kódy pokladen
 * / skladů.
 */
trait NumberSeriesDbTrait
{
    use RecordCodeDb;

    /**
     * @param list<array<string, mixed>> $series řádky `docs_core_number_series`
     * @param array<string, array<int, string>> $entities tabulka → id → kód entity
     * @param \ArrayObject<string, mixed>|null $store úložiště, když test čte
     *        řádky po běhu zpět ({@see RecordCodeDb})
     */
    private function seriesDb(array $series = [], array $entities = [], ?\ArrayObject $store = null): Connection
    {
        $store ??= new \ArrayObject();
        $store['rows'] = $series;
        return $this->recordCodeDb($store, 'doc_type', $entities);
    }
}
