<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Schema;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Schema\PaperSchema;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Papírová podoba schématu dokladu (#166 D63, D64,
 * tasks/mail-profile-paper-schema.md U1, U2): projekce nenese žádnou
 * referenci na záznam ani pole pokynu, signály D39 v ní zůstávají
 * a značka `x-paper` validaci formátu nevadí.
 */
final class PaperSchemaTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function full(): array
    {
        $schema = json_decode(
            (string) file_get_contents(dirname(__DIR__, 6) . '/modules/core/exchange/schemas/shpd.docs.document.v1.json'),
            true,
        );
        self::assertIsArray($schema);
        return $schema;
    }

    public function testProjectionCarriesNoReferenceToARecord(): void
    {
        $paper = PaperSchema::ofDocument();

        $this->assertArrayNotHasKey('Reference', $paper['$defs']);
        $encoded = (string) json_encode($paper, JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('#/$defs/Reference', $encoded);
        $this->assertStringNotContainsString(PaperSchema::MARKER, $encoded);

        // Strana a položka řádku jen objektem — reference sama (číslo, "@…") neprojde.
        $this->assertSame(['object', 'null'], $paper['$defs']['Party']['type']);
        $this->assertSame(['object', 'null'], $paper['$defs']['RowItem']['type']);
        foreach (['Party', 'RowItem', 'BankAccount'] as $definition) {
            $this->assertArrayNotHasKey('ref', $paper['$defs'][$definition]['properties'], "{$definition}.ref");
        }
        // Odkaz na přílohu zprávy ("att:12") není reference na záznam.
        $this->assertSame('^att:[0-9]+$', $paper['$defs']['Attachment']['properties']['ref']['pattern']);
    }

    /** @return array<string, array{0: list<string>, 1: list<string>}> cesta k `properties`, vynechané klíče */
    public static function droppedProvider(): array
    {
        return [
            'hlavička' => [[], [
                'cashDesk', 'numberSeries', 'fiscalPeriodType', 'balanceParty', 'partner', 'dimensions',
                '_resolve', 'applyOptions', 'savedDocId',
            ]],
            'source' => [['properties', 'source'], ['ref', 'message', 'raw']],
            'dates' => [['properties', 'dates'], ['vatRateDate']],
            'vat' => [['properties', 'vat'], [
                'registration', 'recapSource', 'calcSource', 'controlStatementMode', 'rateSource', 'roundingMode',
            ]],
            'payment' => [['properties', 'payment'], ['ownBankAccount']],
            'correction' => [['properties', 'correction'], ['originalDoc']],
            'totals' => [['properties', 'totals'], ['totalRoundingAuto', 'totalRoundingMode']],
            'řádek' => [['$defs', 'Row'], ['operation', 'partner', 'dimensions']],
            'DPH řádku' => [['$defs', 'RowVat'], ['rateDate']],
            'rekapitulace' => [['$defs', 'VatRecapEntry'], ['rateDate']],
        ];
    }

    /**
     * @param list<string> $path
     * @param list<string> $dropped
     */
    #[DataProvider('droppedProvider')]
    public function testFieldsOutsideThePaperFormAreDropped(array $path, array $dropped): void
    {
        $full = self::full();
        $paper = PaperSchema::ofDocument();
        foreach ($path as $segment) {
            $full = $full[$segment];
            $paper = $paper[$segment];
        }

        foreach ($dropped as $key) {
            $this->assertArrayHasKey($key, $full['properties'], "plné schéma pole {$key} nese");
            $this->assertArrayNotHasKey($key, $paper['properties'], "papírová podoba pole {$key} nenese");
        }
        // Nic dalšího nezmizelo: rozdíl klíčů je přesně výčet.
        $this->assertSame(
            $dropped,
            array_values(array_diff(array_keys($full['properties']), array_keys($paper['properties']))),
        );
        $this->assertFalse($paper['additionalProperties']);
    }

    public function testNothingElseIsDropped(): void
    {
        $full = self::full();
        $paper = PaperSchema::ofDocument();

        $this->assertSame(
            ['Reference'],
            array_values(array_diff(array_keys($full['$defs']), array_keys($paper['$defs']))),
        );
        // Definice mimo výčet droppedProvider (a reference stran) jsou beze změny.
        foreach (['Address', 'Contact', 'RowComputed', 'Attachment'] as $definition) {
            $this->assertSame($full['$defs'][$definition], $paper['$defs'][$definition], $definition);
        }
        foreach (['format', 'formatVersion', 'docType', 'docNumber', 'selfParty', 'cashDirection', 'currency',
                  'exchangeRate', 'notes', 'rows', 'vatRecap', 'attachments', 'supplier', 'customer'] as $key) {
            $this->assertSame($full['properties'][$key], $paper['properties'][$key], $key);
        }
        $this->assertSame($full['required'], $paper['required']);
    }

    public function testUnitAndAccountKeepTheirPaperShape(): void
    {
        $row = PaperSchema::ofDocument()['$defs']['Row']['properties'];

        // Jednotka jen textem (zkratka z dokladu), ne id jednotky.
        $this->assertSame(['type' => ['string', 'null']], $row['unit']);
        // Účet řádku holým číslem — signál D39, ne reference "@518100".
        $this->assertSame(['type' => ['string', 'null'], 'pattern' => '^[0-9]+$'], $row['account']);
    }

    public function testTemporarySignalsStay(): void
    {
        $paper = PaperSchema::ofDocument();

        $this->assertArrayHasKey('ourCode', $paper['$defs']['RowItem']['properties']);
        $this->assertArrayHasKey('account', $paper['$defs']['Row']['properties']);
        $this->assertArrayHasKey('registrationCountry', $paper['properties']['vat']['properties']);
    }

    public function testProjectionIsNamedApartFromTheFullSchema(): void
    {
        $paper = PaperSchema::ofDocument();

        $this->assertSame('https://shipard.cz/schemas/shpd.docs.document.v1.paper.json', $paper['$id']);
        $this->assertStringEndsWith('— paper form', $paper['title']);
    }

    public function testProjectionIsAValidSchemaAndRejectsFieldsOfTheInstruction(): void
    {
        $paper = json_decode((string) json_encode(PaperSchema::ofDocument()));
        $validator = new Validator();
        $base = ['format' => 'shpd.docs.document', 'formatVersion' => '1.0', 'docType' => 'invoiceReceived'];

        $valid = $base + [
            'supplier' => ['name' => 'Dodavatel', 'bankAccount' => ['accountNumber' => '1/0100']],
            'vat' => ['registrationCountry' => 'CZ'],
            'rows' => [['item' => ['ourCode' => 'A1', 'name' => 'Zboží'], 'unit' => 'ks', 'account' => '518100']],
        ];
        $this->assertTrue($validator->validate(self::object($valid), $paper)->isValid());

        $rejected = [
            'reference strany' => ['supplier' => ['ref' => '@K00042', 'name' => 'Dodavatel']],
            'strana referencí' => ['supplier' => 42],
            'reference položky' => ['rows' => [['item' => ['ref' => '@KOD']]]],
            'položka referencí' => ['rows' => [['item' => '@KOD']]],
            'účet referencí' => ['rows' => [['account' => '@518100']]],
            'jednotka číslem' => ['rows' => [['unit' => 5]]],
            'číselná řada' => ['numberSeries' => '@invno'],
            'účet partnera referencí' => ['supplier' => ['bankAccount' => ['ref' => 7]]],
            'volby uložení' => ['applyOptions' => ['targetDocState' => 40]],
            'volby uživatele' => ['_resolve' => ['partner' => ['userAction' => 'useExisting:1']]],
        ];
        foreach ($rejected as $label => $extra) {
            $this->assertFalse($validator->validate(self::object($base + $extra), $paper)->isValid(), $label);
        }
    }

    public function testFullSchemaIgnoresTheMarker(): void
    {
        // Plné schéma dál přijímá pole, která projekce vynechává.
        $issues = new SchemaValidator(SchemaLoader::default())->validate([
            'format' => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType' => 'invoiceReceived',
            'supplier' => '@K00042',
            'numberSeries' => '@invni',
            'rows' => [['item' => ['ref' => 12], 'unit' => 3, 'account' => '@518100']],
            'totals' => ['totalRoundingMode' => 'unit'],
        ], 'shpd.docs.document', '1');

        $this->assertSame([], $issues);
    }

    public function testProjectDoesNotChangeItsInput(): void
    {
        $full = self::full();
        $before = $full;
        PaperSchema::project($full);
        $this->assertSame($before, $full);
    }

    public function testRulesOfTheMarker(): void
    {
        $paper = PaperSchema::project([
            'type' => 'object',
            'properties' => [
                'kept' => ['type' => 'string'],
                'dropped' => ['$ref' => '#/$defs/Orphan', 'x-paper' => false],
                'replaced' => ['$ref' => '#/$defs/Orphan', 'x-paper' => ['type' => ['string', 'null']]],
                'either' => ['type' => ['object', 'integer', 'string', 'null'], 'properties' => [
                    'inner' => ['type' => 'integer', 'x-paper' => false],
                ]],
                'list' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/Used']],
            ],
            '$defs' => [
                'Orphan' => ['type' => 'integer'],
                'Used' => ['type' => 'object', 'properties' => ['next' => ['$ref' => '#/$defs/Nested']]],
                'Nested' => ['type' => 'string'],
            ],
        ]);

        $this->assertSame(['kept', 'replaced', 'either', 'list'], array_keys($paper['properties']));
        $this->assertSame(['type' => ['string', 'null']], $paper['properties']['replaced']);
        $this->assertSame(['object', 'null'], $paper['properties']['either']['type']);
        $this->assertSame([], $paper['properties']['either']['properties']);
        // Definice dosažitelná jen přes jinou definici zůstává, osiřelá mizí.
        $this->assertSame(['Used', 'Nested'], array_keys($paper['$defs']));
    }

    public function testRequiredPropertyCannotBeDropped(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("required property '/properties/id'");

        PaperSchema::project([
            'type' => 'object',
            'required' => ['id'],
            'properties' => ['id' => ['type' => 'integer', 'x-paper' => false]],
        ]);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidMarkerProvider(): array
    {
        return [
            'true' => [
                ['properties' => ['a' => ['type' => 'string', 'x-paper' => true]]],
                'must be false or a schema object',
            ],
            'seznam' => [
                ['properties' => ['a' => ['type' => 'string', 'x-paper' => ['string']]]],
                'must be false or a schema object',
            ],
            'mimo vlastnost' => [
                ['properties' => ['a' => ['type' => 'array', 'items' => ['type' => 'string', 'x-paper' => false]]]],
                'is not on a property of an object',
            ],
        ];
    }

    /** @param array<string, mixed> $schema */
    #[DataProvider('invalidMarkerProvider')]
    public function testInvalidMarkerIsAnError(array $schema, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        PaperSchema::project($schema);
    }

    private static function object(array $data): \stdClass
    {
        return json_decode((string) json_encode($data));
    }
}
