<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Core\Database\TableDefinition;

/**
 * Přeloží hodnotu dimenze deníku z výměnného formátu (objekt `dimensions`,
 * #110 T2) na id záznamu cílové tabulky: klíč = id dimenze
 * (`journalDimensions` v economy.accounting), hodnota = reference na záznam
 * cílové tabulky (#166 D30; přirozený klíč říká `exchangeKey` v její
 * definici, D8 — středisko kód, zakázka číslo, majetek inventární číslo).
 * Hledá obecný {@see ReferenceResolver}.
 *
 * Bez fuzzy hledání a bez userAction — reference je autoritativní.
 * Nenalezeno, nejednoznačné i špatný tvar je {@see ReferenceException}
 * (applier z ní udělá chybu s kódem resolveru, doklad se neuloží). Záznam
 * v koši (docState 90) se nepočítá, archivovaný ano — historický doklad smí
 * nést středisko, které už neplatí.
 */
final class DimensionResolver
{
    private readonly ReferenceResolver $references;

    /**
     * @param array<string, TableDefinition> $tables aktivní tabulky DS —
     *        přirozený klíč cílové tabulky dimenze
     */
    public function __construct(
        Connection $db,
        private readonly JournalDimensionSet $dimensions,
        array $tables = [],
    ) {
        $this->references = new ReferenceResolver($db, $tables);
    }

    /** Je dimenze deklarovaná a má její cílová tabulka klíč pro výměnný formát? */
    public function knows(string $dimensionId): bool
    {
        $dimension = $this->dimensions->get($dimensionId);
        return $dimension !== null && $this->references->hasKey($dimension->table);
    }

    /** Lokalizovaný název dimenze pro zprávy issue; neznámá dimenze = id. */
    public function name(string $dimensionId): string
    {
        return $this->dimensions->get($dimensionId)?->name ?? $dimensionId;
    }

    /**
     * Id záznamu cílové tabulky dimenze podle reference (#166 D30):
     * `42`, `"@STR1"`, `"@sloupec:hodnota"`.
     *
     * @throws ReferenceException neznámá dimenze nebo tabulka bez klíče
     *         (`reference_table_unknown`), špatný tvar, nenalezeno, nejednoznačné
     */
    public function resolve(string $dimensionId, mixed $value): int
    {
        $dimension = $this->dimensions->get($dimensionId);
        if ($dimension === null) {
            throw ReferenceException::tableUnknown($dimensionId);
        }
        return $this->references->resolve($dimension->table, $value);
    }
}
