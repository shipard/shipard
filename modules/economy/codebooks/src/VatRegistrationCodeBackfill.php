<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Dibi\Connection;
use Shipard\Core\Database\RecordCodeBackfill;

/**
 * Doplní kód (#166 D17) registracím DPH, které ho nemají — pravidlem
 * {@see VatRegistrationCodeGenerator}, živé registrace před košem.
 *
 * Volá ho `ds-upgrade` (vždy, i pod `skipProvisioning`): registrace
 * z doby před zavedením sloupce a registrace založené importem přes
 * generické CRUD mimo `Document`. Idempotentní, bez vlastní transakce
 * ({@see RecordCodeBackfill}).
 */
final class VatRegistrationCodeBackfill
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /** @return int počet doplněných kódů */
    public function run(): int
    {
        $generator = new VatRegistrationCodeGenerator($this->db);
        return RecordCodeBackfill::run(
            $this->db,
            VatRegistrationCodeGenerator::TABLE,
            ['country'],
            $generator->generate(...),
        );
    }
}
