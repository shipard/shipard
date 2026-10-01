<?php

declare(strict_types=1);

namespace Shipard\Core\Form\Lookup;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;

/**
 * Abstraktní bázová třída pro lookup endpoint na konkrétní tabulce.
 *
 * Konkrétní implementace (např. `PersonsLookup`) sahá do své tabulky,
 * sestaví SQL hledání podle `$q`, případně aplikuje whitelistované
 * filtery (`getAllowedFilterKeys`) a vrátí `LookupItem[]` s display popisem.
 *
 * Registrace v `module.jsonc` → `lookups: [{table, class}]`. Načítá
 * `LookupLoader` (analogie `FormLoader`).
 */
abstract class TableLookup
{
    protected ?ConfigRuntime $config = null;
    protected ?DataSourceConnection $db = null;
    protected ?TableDefinition $tableDef = null;

    final public function setDb(DataSourceConnection $db): void
    {
        $this->db = $db;
    }

    final public function setConfig(?ConfigRuntime $config): void
    {
        $this->config = $config;
    }

    final public function setTableDef(TableDefinition $def): void
    {
        $this->tableDef = $def;
    }

    /**
     * Hledá záznamy podle volného textu.
     *
     * @param string                $q      Volně psaný term; prázdný = první stránka záznamů
     * @param array<string, scalar> $filter Whitelistované filter páry (sloupec → hodnota)
     * @param int                   $limit  1..50; controller už hodnotu sřízl
     * @return list<LookupItem>
     */
    abstract public function search(string $q, array $filter, int $limit): array;

    /**
     * Vrátí display popisy pro seznam ID.
     *
     * Pořadí výstupu nemusí odpovídat vstupu. Neexistující ID se prostě
     * v poli nevyskytnou — žádná chyba.
     *
     * @param list<int|string> $ids
     * @return list<LookupItem>
     */
    abstract public function resolve(array $ids): array;

    /**
     * Výchozí hodnoty nového záznamu zakládaného z lookup pole („+ Vytvořit
     * nový“) podle formuláře, ze kterého se zakládá. Volá se jen u pole
     * s flagem `createDefaults`; klient výsledek předá vnořenému formuláři
     * jako prefill (`defaults[…]`), takže platí totéž co pro každý prefill —
     * je to návrh, uživatel ho může přepsat a validuje se až uložení.
     *
     * Vstup je neuložený stav formulářů z klienta: nedůvěryhodný, jen
     * k odvození návrhu. Cokoli z DB (číslo účtu, název položky) se dohledá
     * podle id z těchto dat tady.
     *
     * @param array<string, mixed> $parentRow  data formuláře, ve kterém pole je
     * @param array<string, mixed> $parentHead data jeho rodičovského formuláře
     *        (řádek dokladu → hlavička); prázdné, když formulář rodiče nemá
     * @return array<string, scalar|null> sloupec cílové tabulky → hodnota
     */
    public function createDefaults(array $parentRow, array $parentHead): array
    {
        return [];
    }

    /**
     * Whitelist filter klíčů, které smí klient v `?filter[…]` poslat.
     * Default: žádné. Subclassy s cascade overridují.
     *
     * @return list<string>
     */
    public function getAllowedFilterKeys(): array
    {
        return [];
    }
}
