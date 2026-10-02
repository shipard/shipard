<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintRegistry;

class PrintDefinitionTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function declaration(array $overrides = []): array
    {
        return $overrides + [
            'id'        => 'docs.invoicesOut.invoice',
            'name'      => 'Faktura',
            'table'     => 'docs_core_heads',
            'filter'    => ['doc_type' => ['invno']],
            'docStates' => [40],
            'audience'  => 'external',
            'builder'   => 'Shipard\\Module\\Docs\\Core\\Prints\\DocPrintBuilder',
            'template'  => '@docs.invoicesOut/invoice',
            'catalogs'  => ['@docs.core/_layout'],
            'paper'     => ['format' => 'A4', 'orientation' => 'portrait'],
            'order'     => 10,
        ];
    }

    // ── fromArray ───────────────────────────────────────────────────────────

    public function testFromArrayParsesFullDeclaration(): void
    {
        $def = PrintDefinition::fromArray(self::declaration(), 'docs.invoicesOut');

        $this->assertSame('docs.invoicesOut.invoice', $def->id);
        $this->assertSame('Faktura', $def->name);
        $this->assertSame('docs_core_heads', $def->table);
        $this->assertSame(['doc_type' => ['invno']], $def->filter);
        $this->assertSame([40], $def->docStates);
        $this->assertSame('external', $def->audience);
        $this->assertSame('Shipard\\Module\\Docs\\Core\\Prints\\DocPrintBuilder', $def->builderClass);
        $this->assertSame('@docs.invoicesOut/invoice', $def->template);
        $this->assertSame(['@docs.core/_layout'], $def->catalogs);
        $this->assertSame('A4', $def->paperFormat);
        $this->assertSame('portrait', $def->orientation);
        $this->assertSame(10, $def->order);
        $this->assertSame('docs.invoicesOut', $def->moduleId);
    }

    public function testFromArrayDefaults(): void
    {
        $raw = self::declaration();
        unset($raw['filter'], $raw['audience'], $raw['catalogs'], $raw['paper'], $raw['order']);

        $def = PrintDefinition::fromArray($raw, 'docs.invoicesOut');

        $this->assertSame([], $def->filter);
        $this->assertSame('external', $def->audience);
        $this->assertSame([], $def->catalogs);
        $this->assertSame('A4', $def->paperFormat);
        $this->assertSame('portrait', $def->orientation);
        $this->assertSame(1000, $def->order);
        $this->assertSame([], $def->margins);
    }

    public function testPaperMargins(): void
    {
        $def = PrintDefinition::fromArray(
            self::declaration(['paper' => ['margins' => ['top' => '3.2cm', 'bottom' => '18mm']]]),
            'docs.invoicesOut',
        );

        $this->assertSame(['top' => '3.2cm', 'bottom' => '18mm'], $def->margins);
        $this->assertSame('A4', $def->paperFormat);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidDeclarations(): array
    {
        return [
            'chybí id'              => [['id' => null], "missing or invalid 'id'"],
            'id s pomlčkou'         => [['id' => 'docs-invoice'], "missing or invalid 'id'"],
            'chybí name'            => [['name' => ''], "missing 'name'"],
            'chybí table'           => [['table' => null], "invalid 'table'"],
            'table s tečkou'        => [['table' => 'docs.heads'], "invalid 'table'"],
            'filter není objekt'    => [['filter' => 'invno'], "'filter' must be an object"],
            'filter prázdné hodnoty' => [['filter' => ['doc_type' => []]], 'non-empty array of values'],
            'filter skalár'         => [['filter' => ['doc_type' => 'invno']], 'non-empty array of values'],
            'filter hodnota pole'   => [['filter' => ['doc_type' => [['x']]]], 'strings or integers'],
            'chybí docStates'       => [['docStates' => null], "'docStates' must be a non-empty array"],
            'prázdné docStates'     => [['docStates' => []], "'docStates' must be a non-empty array"],
            'docStates text'        => [['docStates' => ['40']], "'docStates' must be a non-empty array"],
            'audience'              => [['audience' => 'public'], "'audience' must be one of external|internal"],
            'chybí builder'         => [['builder' => ''], "missing 'builder' class"],
            'template bez modulu'   => [['template' => 'invoice'], "'template' must be a path"],
            'template s ..'         => [['template' => '@docs.core/../x'], "'template' must be a path"],
            'catalogs není pole'    => [['catalogs' => '@docs.core/_layout'], "'catalogs' must be an array"],
            'catalogs špatná cesta' => [['catalogs' => ['_layout']], "'catalogs' entries must be paths"],
            'paper formát'          => [['paper' => ['format' => 'B5']], "paper 'format' must be one of"],
            'paper orientace'       => [['paper' => ['orientation' => 'sideways']], "paper 'orientation' must be one of"],
            'order text'            => [['order' => '10'], "'order' must be an integer"],
            'margins neznámá strana' => [['paper' => ['margins' => ['middle' => '1cm']]], "paper 'margins' must map"],
            'margins bez jednotky'  => [['paper' => ['margins' => ['top' => '3']]], "paper 'margins' must map"],
            'margins není objekt'   => [['paper' => ['margins' => '1cm']], "paper 'margins' must be an object"],
            'watermarks není objekt' => [['watermarks' => 'watermark.cancelled'], "'watermarks' must be an object"],
            'watermarks stav mimo docStates' => [
                ['watermarks' => ['30' => 'watermark.cancelled']], "'watermarks' keys must be states listed in 'docStates'",
            ],
            'watermarks nečíselný stav' => [
                ['docStates' => [40, 30], 'watermarks' => ['storno' => 'watermark.cancelled']],
                "'watermarks' keys must be states listed in 'docStates'",
            ],
            'watermarks prázdná hodnota' => [
                ['docStates' => [40, 30], 'watermarks' => ['30' => ' ']], "'watermarks' values must be non-empty catalog keys",
            ],
            'watermarks hodnota není text' => [
                ['docStates' => [40, 30], 'watermarks' => ['30' => true]], "'watermarks' values must be non-empty catalog keys",
            ],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidDeclarations')]
    public function testFromArrayRejectsInvalidDeclaration(array $overrides, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        PrintDefinition::fromArray(self::declaration($overrides), 'docs.invoicesOut');
    }

    public function testWatermarksMapDocStatesToCatalogKeys(): void
    {
        $this->assertSame([], PrintDefinition::fromArray(self::declaration(), 'docs.invoicesOut')->watermarks);

        // Klíče JSON objektu jsou řetězce — deklarace je drží jako čísla stavů.
        $raw = json_decode('{"docStates": [40, 30], "watermarks": {"30": "watermark.cancelled"}}', true);
        $def = PrintDefinition::fromArray(self::declaration($raw), 'docs.invoicesOut');

        $this->assertSame([30 => 'watermark.cancelled'], $def->watermarks);
        $this->assertTrue($def->matches(['doc_type' => 'invno', 'docState' => 30]), 'storno se tiskne');
    }

    // ── dostupnost pro záznam ───────────────────────────────────────────────

    public function testMatchesFilterAndDocState(): void
    {
        $def = PrintDefinition::fromArray(self::declaration(), 'docs.invoicesOut');

        $this->assertTrue($def->matches(['doc_type' => 'invno', 'docState' => 40]));
        $this->assertFalse($def->matches(['doc_type' => 'invno', 'docState' => 10]), 'koncept se netiskne');
        $this->assertFalse($def->matches(['doc_type' => 'invni', 'docState' => 40]), 'jiný typ dokladu');
        $this->assertFalse($def->matches(['docState' => 40]), 'záznam bez sloupce filtru');
        $this->assertFalse($def->matches(['doc_type' => 'invno']), 'záznam bez stavu');
    }

    public function testFilterComparesIntsAndStringsLoosely(): void
    {
        $def = PrintDefinition::fromArray(
            self::declaration(['filter' => ['kind' => [1, '2']], 'docStates' => [40, 80]]),
            'docs.invoicesOut',
        );

        $this->assertTrue($def->matches(['kind' => '1', 'docState' => '40']));
        $this->assertTrue($def->matches(['kind' => 2, 'docState' => 80]));
        $this->assertFalse($def->matches(['kind' => 3, 'docState' => 40]));
    }

    public function testEmptyFilterMatchesAnyRecordInAllowedState(): void
    {
        $raw = self::declaration();
        unset($raw['filter']);
        $def = PrintDefinition::fromArray($raw, 'economy.assets');

        $this->assertTrue($def->matches(['docState' => 40]));
        $this->assertFalse($def->matches(['docState' => 90]));
    }

    // ── PrintRegistry ───────────────────────────────────────────────────────

    public function testRegistryRejectsDuplicateIdAcrossModules(): void
    {
        $registry = new PrintRegistry();
        $registry->add(PrintDefinition::fromArray(self::declaration(), 'docs.invoicesOut'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            "duplicate print id 'docs.invoicesOut.invoice' — registered in 'docs.invoicesOut' and 'customer.prints'",
        );
        $registry->add(PrintDefinition::fromArray(self::declaration(), 'customer.prints'));
    }

    public function testRegistryGetAndGetAll(): void
    {
        $registry = new PrintRegistry();
        $this->assertNull($registry->get('docs.invoicesOut.invoice'));
        $this->assertSame([], $registry->getAll());

        $def = PrintDefinition::fromArray(self::declaration(), 'docs.invoicesOut');
        $registry->add($def);

        $this->assertSame($def, $registry->get('docs.invoicesOut.invoice'));
        $this->assertSame([$def], $registry->getAll());
    }

    public function testForRecordFiltersByTableTypeAndStateAndSortsByOrder(): void
    {
        $registry = new PrintRegistry();
        $add = static function (array $overrides) use ($registry): void {
            $registry->add(PrintDefinition::fromArray(self::declaration($overrides), 'test.module'));
        };
        $add(['id' => 'test.invoiceCopy', 'order' => 20]);
        $add(['id' => 'test.invoice', 'order' => 10]);
        // Stejné pořadí jako test.invoiceCopy → rozhoduje id.
        $add(['id' => 'test.invoiceB', 'order' => 20]);
        $add(['id' => 'test.proforma', 'filter' => ['doc_type' => ['invpo']]]);
        $add(['id' => 'test.draft', 'docStates' => [10]]);
        $add(['id' => 'test.asset', 'table' => 'economy_assets_assets', 'filter' => []]);

        $ids = static fn (array $definitions): array => array_map(
            static fn (PrintDefinition $d): string => $d->id,
            $definitions,
        );

        $this->assertSame(
            ['test.invoice', 'test.invoiceB', 'test.invoiceCopy'],
            $ids($registry->forRecord('docs_core_heads', ['doc_type' => 'invno', 'docState' => 40])),
        );
        $this->assertSame(
            ['test.draft'],
            $ids($registry->forRecord('docs_core_heads', ['doc_type' => 'invno', 'docState' => 10])),
        );
        $this->assertSame(
            ['test.proforma'],
            $ids($registry->forRecord('docs_core_heads', ['doc_type' => 'invpo', 'docState' => 40])),
        );
        $this->assertSame(
            ['test.asset'],
            $ids($registry->forRecord('economy_assets_assets', ['docState' => 40])),
        );
        $this->assertSame([], $registry->forRecord('base_persons_persons', ['docState' => 40]));
    }
}
