<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Common;

use Shipard\Core\Document\TableGateway;

/**
 * TableGateway variant that does NOT manage its own DB transaction —
 * begin/commit/rollback are no-ops. Used by exchange Appliers so the
 * Applier can own the transaction (side-creates + doc save + lineage
 * update must be atomic, see docs/exchange-format.md §10). The Applier
 * runs the save in `NestedTransaction` — its own transaction, or a
 * SAVEPOINT inside the caller's (document generators, #110).
 *
 * Reason this can't be solved with nested $db->begin(): MariaDB has no
 * concept of nested START TRANSACTION — a second begin implicitly
 * commits the outer one. Dibi exposes savepoints (`begin('name')`) but
 * TableGateway doesn't pass any savepoint name through, so when Applier
 * calls a TableGateway-internal transaction, the outer Applier
 * transaction would silently commit and lineage update would never
 * roll back together with the doc save.
 *
 * Centralising transaction control in the Applier sidesteps that.
 *
 * The same rule extends to Document-internal transactions:
 * transactionsExternal() = true propagates to Document::$externalTransaction
 * via injectDocServices, so hooks like DocDocument::assignDocumentNumber
 * skip their own begin/commit and run inside the Applier's transaction.
 */
class TransactionlessTableGateway extends TableGateway
{
    protected function beginTransaction(): void {}
    protected function commitTransaction(): void {}
    protected function rollbackTransaction(): void {}

    protected function transactionsExternal(): bool
    {
        return true;
    }
}
