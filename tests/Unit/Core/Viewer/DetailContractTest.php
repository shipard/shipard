<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Viewer;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\RecordPattern;
use Shipard\Core\Database\SchemaLoader;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Core\Viewer\ViewerDefinition;

/**
 * Kontrakt detailu záznamu (#136 D23) nad všemi `viewers[]` v repozitáři:
 * třída vieweru dědí `TableViewer`, detail skládá builder (viewer
 * `renderDetail()` nepřepisuje), placeholdery `detailHeader` jsou sloupce
 * tabulky, viewer bez tabulky dodává vlastní hlavičku, umístění příloh je
 * známé. Výjimky drží `NOT_CONVERTED`.
 */
class DetailContractTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../modules';

    /**
     * Viewery, které detail dál skládají vlastním `renderDetail()`.
     * Seznam JEN UBÝVÁ: převod vieweru ho odsud odebere ve stejném commitu
     * (viewer ze seznamu, který už `renderDetail()` nepřepisuje, test
     * shodí); task B #136 ho vyprázdní a `renderDetail()` bude `final`.
     */
    public const NOT_CONVERTED = [
        // core.ai
        // core.alerts
        // core.chat
        // core.exchange
        // core.mail
        // core.prints
        // core.system
        // core.units
        // docs.core
        // economy.accbal
        'economy.accbal.balances',
        'economy.accbal.balanceAccounts',
        'economy.accbal.cases',
        'economy.accbal.ledger',
        // economy.accounting
        'economy.accounting.accounts',
        'economy.accounting.journal',
        // economy.bank
        'economy.bank.transactions',
        'economy.bank.statements',
        // economy.codebooks
        // economy.exchangeRates
        'economy.exchangeRates.lists',
        // economy.items
        // economy.vat
        // hosting.core
        'hosting.core.servers',
        'hosting.core.dataSources',
        'hosting.core.dsUsers',
        'hosting.core.mailRouters',
        'hosting.core.aiTokens',
        'hosting.core.aiUsage',
        // tasks.core
        'tasks.core',
    ];

    /** @var array<string, array{module: string, def: array<string, mixed>}> id vieweru → deklarace */
    private static array $viewers = [];

    /** @var array<string, TableDefinition> */
    private static array $tables = [];

    public static function setUpBeforeClass(): void
    {
        $moduleIds = [];
        foreach (glob(self::MODULES . '/*/*/module.jsonc') ?: [] as $file) {
            $module = JsoncParser::parseFile($file);
            $moduleIds[] = $module['id'];
            foreach ($module['viewers'] ?? [] as $viewer) {
                self::$viewers[$viewer['id']] = ['module' => $module['id'], 'def' => $viewer];
            }
        }
        ksort(self::$viewers);

        $result = SchemaLoader::loadResolvedTables(new ModulePathResolver([self::MODULES]), $moduleIds);
        self::assertSame([], $result['errors']);
        self::$tables = $result['tables'];
    }

    public function testRepositoryDeclaresViewers(): void
    {
        $this->assertGreaterThan(50, count(self::$viewers));
    }

    public function testEveryViewerClassExtendsTableViewer(): void
    {
        foreach (self::$viewers as $id => ['def' => $def]) {
            $class = $def['class'] ?? null;
            $this->assertIsString($class, "viewer {$id}: chybí class");
            $this->assertTrue(class_exists($class), "viewer {$id}: třída {$class} neexistuje");
            $this->assertTrue(is_subclass_of($class, TableViewer::class), "viewer {$id}: {$class} nedědí TableViewer");
        }
    }

    public function testRenderDetailIsOverriddenExactlyByNotConvertedViewers(): void
    {
        foreach (self::NOT_CONVERTED as $id) {
            $this->assertArrayHasKey($id, self::$viewers, "NOT_CONVERTED: viewer {$id} neexistuje");
        }

        foreach (self::$viewers as $id => ['def' => $def]) {
            $declaring = (new \ReflectionMethod($def['class'], 'renderDetail'))->getDeclaringClass()->getName();
            $overrides = $declaring !== TableViewer::class;
            if (in_array($id, self::NOT_CONVERTED, true)) {
                $this->assertTrue(
                    $overrides,
                    "viewer {$id} už renderDetail() nepřepisuje — odeber ho z DetailContractTest::NOT_CONVERTED (seznam jen ubývá)",
                );
            } else {
                $this->assertFalse(
                    $overrides,
                    "viewer {$id} přepisuje renderDetail() v {$declaring} — detail skládá builder TableViewer, viewer dodává háčky",
                );
            }
        }
    }

    public function testDetailHeaderPlaceholdersAreColumnsOfTheTable(): void
    {
        $checked = 0;
        foreach (self::$viewers as $id => ['def' => $def]) {
            $header = $def['detailHeader'] ?? null;
            if ($header === null) {
                continue;
            }
            $this->assertIsString($def['table'] ?? null, "viewer {$id}: detailHeader bez tabulky");
            $table = self::$tables[$def['table']] ?? null;
            $this->assertNotNull($table, "viewer {$id}: tabulka {$def['table']} nenalezena");
            $columns = ['id', ...array_map(static fn($c): string => $c->id, $table->columns)];

            foreach (['title', 'subtitle'] as $key) {
                if (!isset($header[$key])) {
                    continue;
                }
                $checked++;
                foreach ((new RecordPattern($header[$key]))->columns() as $column) {
                    $this->assertContains($column, $columns, "viewer {$id}: detailHeader.{$key} používá neznámý sloupec {$column}");
                }
            }
        }
        // Bez deklarací (před prvním převodem) test prošel bez kontroly.
        $this->addToAssertionCount($checked === 0 ? 1 : 0);
    }

    public function testConvertedViewerWithoutTableProvidesItsOwnHeader(): void
    {
        foreach (self::$viewers as $id => ['def' => $def]) {
            if (($def['table'] ?? null) !== null || in_array($id, self::NOT_CONVERTED, true)) {
                continue;
            }
            $declaring = (new \ReflectionMethod($def['class'], 'detailHeader'))->getDeclaringClass()->getName();
            $this->assertNotSame(TableViewer::class, $declaring, "viewer {$id} bez tabulky nemá displayPattern — musí přepsat detailHeader()");
        }
        $this->addToAssertionCount(1);
    }

    public function testDetailAttachmentsPlacementIsKnown(): void
    {
        foreach (self::$viewers as $id => ['def' => $def, 'module' => $module]) {
            $placement = $def['detailAttachments'] ?? ViewerDefinition::ATTACHMENTS_OVERVIEW;
            $this->assertContains(
                $placement,
                ViewerDefinition::ATTACHMENT_PLACEMENTS,
                "viewer {$id} ({$module}): neznámé detailAttachments '{$placement}'",
            );
        }
    }
}
