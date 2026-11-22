<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Dibi\Connection;
use Shipard\Core\Database\RecordCodeBackfill;

/**
 * Doplní kód (#166 D17) číselným řadám dokladů, které ho nemají — pravidlem
 * {@see NumberSeriesCodeGenerator}, živé řady před košem.
 *
 * Volá ho `ds-upgrade` (vždy, i pod `skipProvisioning` — kód je
 * infrastruktura jako vázané řady) a každý provisioner řad hned po vložení
 * řádku: `NumberSeriesProvisioner`, `BoundNumberSeriesProvisioner` (i líně
 * z `DocumentApplier`) a `AssetPostingSeriesProvisioner`. Provisionery
 * vkládají řádek mimo `Document`, kód tak řada dostane nejpozději na konci
 * operace, která ji založila. Idempotentní, bez vlastní transakce
 * ({@see RecordCodeBackfill}).
 */
final class NumberSeriesCodeBackfill
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /** @return int počet doplněných kódů */
    public function run(): int
    {
        $generator = new NumberSeriesCodeGenerator($this->db);
        return RecordCodeBackfill::run(
            $this->db,
            NumberSeriesCodeGenerator::TABLE,
            ['doc_type', 'doc_number_code', ...array_keys(NumberSeriesDocument::BINDINGS)],
            $generator->generate(...),
        );
    }
}
