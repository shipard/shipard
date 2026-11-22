<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

use Dibi\Connection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Exchange\Export\SetupExporter;
use Shipard\Module\Core\Exchange\Resolve\ReferenceException;
use Shipard\Module\Core\Exchange\Resolve\ReferenceResolver;

/**
 * Cizí klíč v sadě přes přirozený klíč cílové tabulky (#131 fáze 2b, D29) —
 * fasáda datových sad nad obecným {@see ReferenceResolver} (#166 D8). Říká,
 * kam FK sloupec sady umí ukázat a jak se hodnota překládá tam (dump)
 * a zpět (seed):
 *
 *  - tabulka sekce `setup/` (`SetupExporter::TABLES`) s přirozeným klíčem
 *    v definici (`exchangeKey`) → hodnota klíče (druh zakázky názvem,
 *    bankovní účet kódem, středisko kódem, účet osnovy číslem, registrace
 *    DPH kódem, …);
 *  - tabulka, kterou sada nenese, ale její záznamy zakládají provisionery
 *    se známým kódem ({@see self::PROVISIONED_TABLES}) → hodnota klíče:
 *    řada dokladů kódem (`"invno"`, `"cash-POK"`; #166 D16, D17);
 *  - ostatní reference sada nepřenáší — ani na tabulku, která přirozený
 *    klíč má (osoby, položky, doklady): sekce setup je nenese, odkaz by
 *    na cílovém zdroji dat neměl kam ukázat.
 *
 * Seed hledá přísně: nenalezený nebo nejednoznačný klíč je
 * `DatasetException` — nikdy tiché vynechání ani první nalezený. Záznam
 * v koši (`docState` 90) se nepočítá, archivovaný ano. Výjimka: účet osnovy
 * v sekci setup zůstává jako dřív (chybějící účet = null, hlídá ho až
 * engine) — řeší volající.
 */
final class SetupReferences
{
    public const ACCOUNTS_TABLE = 'economy_accounting_accounts';
    public const DOC_SERIES_TABLE = 'docs_core_number_series';

    /**
     * Tabulky mimo sekci setup, na které sada přesto umí odkázat: jejich
     * záznamy na cílovém zdroji dat založí provisionery (`ds-reset`
     * + `ds-upgrade`) a kód dostanou pravidlem — výchozí řada typu `invno`,
     * řada pokladny `cash-<kód pokladny>`, řada Majetek `cmnbkp-MA`. Řadu,
     * kterou uživatel založil ručně, sada nenese a odkaz na ni seed odmítne.
     */
    public const PROVISIONED_TABLES = [self::DOC_SERIES_TABLE];

    private readonly ReferenceResolver $resolver;

    /** @var array<string, array<int, mixed>> tabulka → id → hodnota pro sadu */
    private array $exportCache = [];

    /** @var array<string, array<string, int>> tabulka → klíč → id */
    private array $resolveCache = [];

    /**
     * @param array<string, TableDefinition> $tables aktivní tabulky DS —
     *        přirozený klíč cílové tabulky a to, zda má `docState`
     */
    public function __construct(Connection $db, array $tables = [])
    {
        $this->resolver = new ReferenceResolver($db, $tables);
    }

    /** Umí sada řádek tabulky odkázat přirozeným klíčem? */
    public function isPortable(string $table): bool
    {
        return $this->keyColumn($table) !== null;
    }

    /**
     * Přirozený klíč (`exchangeKey`) tabulky, na kterou sada umí odkázat —
     * tabulka sekce setup nebo tabulka z {@see self::PROVISIONED_TABLES};
     * null = tabulka klíč nemá, není aktivní, nebo na ni sada neodkazuje.
     */
    public function keyColumn(string $table): ?string
    {
        if (in_array($table, self::PROVISIONED_TABLES, true)) {
            return $this->resolver->keyColumn($table);
        }
        foreach (SetupExporter::TABLES as $key => $spec) {
            if ($key !== SetupExporter::SETTINGS_TABLE && $spec['table'] === $table) {
                return $this->resolver->keyColumn($table);
            }
        }
        return null;
    }

    /**
     * Hodnota FK pro soubor sady: přirozený klíč cílového řádku. Null =
     * prázdná reference, neexistující řádek, řádek bez klíče nebo
     * nepřenosná tabulka.
     */
    public function export(string $table, mixed $id): mixed
    {
        $id = ValueNormalizer::int($id);
        if ($id === null || $id <= 0 || !$this->isPortable($table)) {
            return null;
        }
        if (array_key_exists($id, $this->exportCache[$table] ?? [])) {
            return $this->exportCache[$table][$id];
        }

        return $this->exportCache[$table][$id] = ValueNormalizer::str($this->resolver->keyOf($table, $id));
    }

    /**
     * Id cílového řádku podle hodnoty ze sady.
     *
     * @throws DatasetException nepřenosná tabulka, špatný tvar hodnoty,
     *         nenalezený nebo nejednoznačný klíč
     */
    public function resolve(string $table, mixed $value): int
    {
        if (!$this->isPortable($table)) {
            throw new DatasetException("tabulka {$table}: reference se přirozeným klíčem nepřenáší");
        }
        if (!is_scalar($value) || (string) $value === '') {
            throw new DatasetException("{$table}: klíč musí být neprázdný řetězec");
        }
        $cacheKey = (string) $value;
        if (isset($this->resolveCache[$table][$cacheKey])) {
            return $this->resolveCache[$table][$cacheKey];
        }

        $label = "{$table} {$this->keyColumn($table)} '{$value}'";
        try {
            $ids = [$this->resolver->resolveByKey($table, (string) $value)];
        } catch (ReferenceException $e) {
            // Sady drží vlastní znění chyb — z obecné chyby se bere jen druh a id.
            $ids = match ($e->errorCode) {
                ReferenceException::NOT_FOUND => [],
                ReferenceException::AMBIGUOUS => $e->ids,
                default => throw new DatasetException("{$label}: {$e->getMessage()}"),
            };
        }

        if ($ids === []) {
            throw new DatasetException("{$label}: na tomto zdroji dat není");
        }
        if (count($ids) > 1) {
            throw new DatasetException("{$label}: není jednoznačná (" . count($ids) . ' záznamů: #' . implode(', #', $ids) . ')');
        }
        return $this->resolveCache[$table][$cacheKey] = $ids[0];
    }
}
