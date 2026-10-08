<?php

declare(strict_types=1);

namespace Shipard\Core\Numbering;

/**
 * Kde {@see SequenceCounter} drží čítače a kde hledá přidělená pořadí.
 * Čítače zůstávají per doména (#110 N2): doklady `docs_core_number_counters`
 * + `docs_core_heads`, zakázky vlastní dvojice tabulek. Rozsah (scope) je
 * u dokladů fiskální rok nebo NULL pro průběžnou řadu — jádro zná jen
 * nullable int.
 *
 * Identifikátory se vkládají do SQL přímo jako `[name]` (ne jako parametry
 * Dibi), proto je konstruktor omezuje na bezpečný tvar.
 */
final readonly class SequenceStorage
{
    public function __construct(
        public string $countersTable,
        public string $counterSeriesColumn,
        public string $counterScopeColumn,
        public string $counterValueColumn,
        public string $recordsTable,
        public string $recordSeriesColumn,
        public string $recordScopeColumn,
        public string $recordSequenceColumn,
    ) {
        foreach (get_object_vars($this) as $property => $identifier) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $identifier)) {
                throw new \InvalidArgumentException("SequenceStorage::{$property}: neplatný identifikátor „{$identifier}“");
            }
        }
    }
}
