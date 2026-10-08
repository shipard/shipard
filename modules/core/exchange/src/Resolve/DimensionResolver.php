<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Core\Database\TableDefinition;

/**
 * Přeloží hodnotu dimenze deníku z výměnného formátu (objekt `dimensions`,
 * #110 T2) na id záznamu cílové tabulky: klíč = id dimenze
 * (`journalDimensions` v economy.accounting), hodnota = přirozený klíč
 * (`exchangeKey` dimenze — středisko kód, majetek inventární číslo).
 *
 * Bez fuzzy hledání a bez userAction — klíč je autoritativní. Nenalezeno →
 * null (applier hlásí chybu `dimension_not_found`, doklad se neuloží).
 * Záznam v koši (docState 90) se nepočítá, archivovaný ano — historický
 * doklad smí nést středisko, které už neplatí. Cache per běh podle hodnoty.
 */
final class DimensionResolver
{
    /** @var array<string, array<string, ?int>> */
    private array $cache = [];

    /**
     * @param array<string, TableDefinition> $tables aktivní tabulky DS — jen
     *        kvůli tomu, zda má cílová tabulka `docState` (koš se vylučuje)
     */
    public function __construct(
        private readonly Connection $db,
        private readonly JournalDimensionSet $dimensions,
        private readonly array $tables = [],
    ) {}

    /** Je dimenze deklarovaná a má klíč pro výměnný formát? */
    public function knows(string $dimensionId): bool
    {
        return $this->dimensions->get($dimensionId)?->exchangeKey !== null;
    }

    /** Lokalizovaný název dimenze pro zprávy issue; neznámá dimenze = id. */
    public function name(string $dimensionId): string
    {
        return $this->dimensions->get($dimensionId)?->name ?? $dimensionId;
    }

    public function resolve(string $dimensionId, string $value): ?int
    {
        $dimension = $this->dimensions->get($dimensionId);
        $value = trim($value);
        if ($dimension === null || $dimension->exchangeKey === null || $value === '') {
            return null;
        }
        if (array_key_exists($value, $this->cache[$dimensionId] ?? [])) {
            return $this->cache[$dimensionId][$value];
        }

        // Tabulka i sloupec jsou identifikátory ověřené při načtení modulu.
        $hasDocState = ($this->tables[$dimension->table] ?? null)?->docStates !== null;
        $row = $this->db->fetch(
            "SELECT [id] FROM [{$dimension->table}] WHERE [{$dimension->exchangeKey}] = %s"
            . ($hasDocState ? ' AND [docState] <> 90' : '')
            . ' ORDER BY [id] LIMIT 1',
            $value,
        );

        return $this->cache[$dimensionId][$value] = ($row !== null ? (int) $row['id'] : null);
    }
}
