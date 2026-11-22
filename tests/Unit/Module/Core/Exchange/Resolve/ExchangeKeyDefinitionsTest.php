<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Resolve;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Export\SetupExporter;

/**
 * Přirozené klíče tabulek (`exchangeKey`, #166 D8) nad skutečnými
 * definicemi: které klíče nemají unikátní index (vědomé rozhodnutí, ne
 * opomenutí) a že tabulky sekce `setup/` datových sad nesou týž klíč, jaký
 * měly v mapě `SetupExporter::TABLES`.
 */
class ExchangeKeyDefinitionsTest extends TestCase
{
    /**
     * Tabulky, jejichž přirozený klíč nemá unikátní index — nejednoznačnost
     * se u nich pozná až při hledání (`reference_ambiguous`, D10). Nová
     * tabulka sem patří jen jako rozhodnutí: buď dostane unikátní index,
     * nebo zápis tady.
     */
    private const WITHOUT_UNIQUE_INDEX = [
        'base_registry_binders',
        'docs_core_heads',
        'economy_assets_type_groups',
        'economy_assets_types',
        'economy_work_orders_kinds',
        'economy_work_orders_number_series',
    ];

    /** Klíč cizího klíče v sadách před #166 — soubory sad se nesmí změnit. */
    private const SETUP_KEYS = [
        'economy_accounting_accounts'       => 'number',
        'economy_codebooks_bank_accounts'   => 'code',
        'economy_codebooks_cash_desks'      => 'code',
        'economy_codebooks_cost_centers'    => 'code',
        'base_registry_binders'             => 'name',
        'core_mail_mailboxes'               => 'mailbox_id',
        'economy_work_orders_kinds'         => 'name',
        'economy_work_orders_number_series' => 'number_code',
        'economy_assets_accounting_groups'  => 'code',
        'economy_assets_type_groups'        => 'name',
        'economy_assets_types'              => 'name',
    ];

    /**
     * Tabulky sekce setup bez přirozeného klíče. Registrace DPH sloupec
     * `code` s unikátním indexem už má (#166 D16); `exchangeKey` dostane
     * spolu s převodem sad, až klíč upsertu (země, název) z mapy zmizí.
     */
    private const SETUP_WITHOUT_KEY = ['core_system_settings', 'economy_codebooks_vat_registrations'];

    /** @var array<string, TableDefinition>|null */
    private static ?array $tables = null;

    /** @return array<string, TableDefinition> název tabulky → definice (bez extensions) */
    private static function tables(): array
    {
        if (self::$tables === null) {
            self::$tables = [];
            foreach (glob(dirname(__DIR__, 6) . '/modules/*/*/tables/*.jsonc') ?: [] as $path) {
                self::$tables[basename($path, '.jsonc')] = TableDefinition::fromArray(JsoncParser::parseFile($path));
            }
        }
        return self::$tables;
    }

    public function testKeysWithoutUniqueIndexAreListed(): void
    {
        $without = [];
        foreach (self::tables() as $name => $def) {
            if ($def->exchangeKey === null) {
                continue;
            }
            $unique = false;
            foreach ($def->indexes as $index) {
                if ($index->type === 'unique' && array_column($index->columns, 'column') === [$def->exchangeKey]) {
                    $unique = true;
                }
            }
            if (!$unique) {
                $without[] = $name;
            }
        }
        sort($without);

        $this->assertSame(self::WITHOUT_UNIQUE_INDEX, $without);
    }

    public function testSetupTablesKeepTheirNaturalKey(): void
    {
        $tables = self::tables();
        $setupTables = array_column(SetupExporter::TABLES, 'table');
        sort($setupTables);
        $expected = [...array_keys(self::SETUP_KEYS), ...self::SETUP_WITHOUT_KEY];
        sort($expected);
        $this->assertSame($expected, $setupTables, 'nová tabulka sekce setup: doplň klíč sem i do definice');

        foreach (self::SETUP_KEYS as $table => $column) {
            $this->assertSame($column, $tables[$table]->exchangeKey, $table);
        }
        foreach (self::SETUP_WITHOUT_KEY as $table) {
            $this->assertNull($tables[$table]->exchangeKey, "{$table}: složený klíč, exchangeKey nemá");
        }
        // #166 D16, D17: řada dokladů má vlastní kód s unikátním indexem.
        $this->assertSame('code', $tables['docs_core_number_series']->exchangeKey);
    }

    public function testRecordsOutsideDatasetsHaveKeysAndLookupColumns(): void
    {
        $tables = self::tables();

        $this->assertSame('person_id', $tables['base_persons_persons']->exchangeKey);
        $this->assertSame(['company_id', 'vat_id'], $tables['base_persons_persons']->exchangeLookupColumns);
        $this->assertSame('code', $tables['economy_items']->exchangeKey);
        $this->assertSame(['ean', 'sku'], $tables['economy_items']->exchangeLookupColumns);
        $this->assertSame('doc_number', $tables['docs_core_heads']->exchangeKey);
    }
}
