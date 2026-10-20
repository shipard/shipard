<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\ExchangeRates;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImporter;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImportResult;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListType;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListTypes;

/**
 * Importer (tasks/exchange-rates-core.md §3, §11): chyba schématu, neznámý
 * typ, nesoulad domácí měny, shodný obsah → skipped, jiný obsah → conflict
 * / replaced (hodnoty téže měny s id existujícího řádku); neznámá měna se
 * vyřadí a hlásí, obsah se porovnává po vyřazení (task B U1).
 */
class ExchangeRateListImporterTest extends TestCase
{
    private const EXISTING = [
        10 => [
            'id' => 10, 'list_type' => 'cz.cnb.daily', 'home_currency' => 'czk', 'valid_from' => '2026-10-16',
            'valid_to' => null, 'list_number' => '201', 'source' => 3,
            'values' => [
                ['id' => 11, 'list' => 10, 'currency' => 'eur', 'amount' => 1, 'rate' => '24.325000'],
                ['id' => 12, 'list' => 10, 'currency' => 'jpy', 'amount' => 100, 'rate' => '15.871000'],
            ],
        ],
    ];

    /** @return ExchangeRateListImporter&object{saved: list<array>, store: array, failSave: bool} */
    private function importer(array $existing = [], string $home = 'czk'): ExchangeRateListImporter
    {
        $types = new ExchangeRateListTypes([
            new ExchangeRateListType('cz.cnb.daily', 'ČNB denní', 'czk', 7),
            new ExchangeRateListType('cz.cnb.other', 'ČNB ostatní', 'czk', null),
        ]);
        $currencies = ['czk' => true, 'eur' => true, 'jpy' => true, 'usd' => true, 'thb' => true];

        return new class(
            $this->createMock(Connection::class),
            $this->createMock(TableGateway::class),
            new SchemaValidator(SchemaLoader::default()),
            $types,
            $currencies,
            $home,
            $existing,
        ) extends ExchangeRateListImporter {
            /** @var list<array> */
            public array $saved = [];
            public bool $failSave = false;

            public function __construct(Connection $db, TableGateway $gateway, SchemaValidator $v, ExchangeRateListTypes $t, array $c, string $h, public array $store)
            {
                parent::__construct($db, $gateway, $v, $t, $c, $h);
            }

            protected function findListId(string $listType, string $validFrom): ?int
            {
                foreach ($this->store as $id => $list) {
                    if ($list['list_type'] === $listType && $list['valid_from'] === $validFrom) {
                        return $id;
                    }
                }
                return null;
            }

            protected function loadList(int $id): ?array
            {
                return $this->store[$id] ?? null;
            }

            protected function save(array $data): DocumentResult
            {
                $this->saved[] = $data;
                if ($this->failSave) {
                    return DocumentResult::error('Duplicate entry');
                }
                $id = isset($data['id']) ? (int) $data['id'] : (count($this->store) + 100);
                $this->store[$id] = ['id' => $id] + $data;
                return DocumentResult::ok(['id' => $id]);
            }
        };
    }

    /** @param array<string, mixed> $override */
    private static function list(array $override = []): array
    {
        return array_merge([
            'format'       => ExchangeRateListImporter::FORMAT,
            'listType'     => 'cz.cnb.daily',
            'homeCurrency' => 'CZK',
            'validFrom'    => '2026-10-16',
            'validTo'      => null,
            'listNumber'   => '201',
            'rates'        => [
                ['currency' => 'EUR', 'amount' => 1, 'rate' => 24.325],
                ['currency' => 'JPY', 'amount' => 100, 'rate' => 15.871],
            ],
        ], $override);
    }

    public function testSchemaErrorIsInvalidWithPath(): void
    {
        $importer = $this->importer();
        $result = $importer->import(self::list(['rates' => [['currency' => 'EUR', 'amount' => 0, 'rate' => 24.3]]]), ExchangeRateListImporter::SOURCE_FILE);

        $this->assertSame(ExchangeRateListImportResult::INVALID, $result->outcome);
        $this->assertFalse($result->ok());
        $this->assertStringContainsString('rates.0.amount', $result->errors[0]);
        $this->assertSame('cz.cnb.daily 2026-10-16', $result->label());
        $this->assertSame([], $importer->saved);
    }

    public function testSemanticErrors(): void
    {
        $importer = $this->importer();

        $r = $importer->import(self::list(['listType' => 'cz.customs']), 1);
        $this->assertSame(ExchangeRateListImportResult::INVALID, $r->outcome);
        $this->assertStringContainsString('Neznámý typ lístku „cz.customs“', $r->errors[0]);
        $this->assertStringContainsString('cz.cnb.daily, cz.cnb.other', $r->errors[0]);

        $r = $importer->import(self::list(['homeCurrency' => 'EUR']), 1);
        $this->assertStringContainsString('neodpovídá měně typu CZK', $r->errors[0]);
        $this->assertStringContainsString('neodpovídá domácí měně zdroje dat CZK', $r->errors[1]);

        $r = $this->importer(home: 'eur')->import(self::list(), 1);
        $this->assertSame(['Měna lístku CZK neodpovídá domácí měně zdroje dat EUR.'], $r->errors);

        $r = $importer->import(self::list(['listType' => 'cz.cnb.other']), 1);
        $this->assertSame(['Typ cz.cnb.other nemá maxAgeDays — lístek musí nést validTo.'], $r->errors);

        $r = $importer->import(self::list(['listType' => 'cz.cnb.other', 'validTo' => '2026-10-01']), 1);
        $this->assertSame(['validTo 2026-10-01 je před validFrom 2026-10-16.'], $r->errors);

        $r = $importer->import(self::list(['validFrom' => '2026-02-30']), 1);
        $this->assertSame(['Neplatné datum validFrom „2026-02-30“.'], $r->errors);

        $r = $importer->import(self::list(['rates' => [['currency' => 'eur', 'amount' => 1, 'rate' => 24.3]]]), 1);
        $this->assertStringContainsString('rates.0.currency', $r->errors[0], 'malá písmena odmítá už schéma');
        $r = $importer->import(self::list(['rates' => [['currency' => 'EUR', 'amount' => 1, 'rate' => 24.3], ['currency' => 'EUR', 'amount' => 1, 'rate' => 24.4]]]), 1);
        $this->assertSame(['Měna EUR je v lístku dvakrát.'], $r->errors);

        $this->assertSame([], $importer->saved);
    }

    public function testUnknownCurrencyIsSkippedAndReportedRestIsSaved(): void
    {
        $importer = $this->importer();
        $result = $importer->import(self::list(['rates' => [
            ['currency' => 'EUR', 'amount' => 1, 'rate' => 24.325],
            ['currency' => 'XDR', 'amount' => 1, 'rate' => 28.5],
            ['currency' => 'JPY', 'amount' => 100, 'rate' => 15.871],
            ['currency' => 'VEF', 'amount' => 1000, 'rate' => 0.001],
        ]]), 1);

        $this->assertSame(ExchangeRateListImportResult::CREATED, $result->outcome);
        $this->assertTrue($result->ok());
        $this->assertSame([], $result->errors);
        $this->assertSame(['XDR', 'VEF'], $result->skippedCurrencies, 'velkými, v pořadí lístku');
        $this->assertSame(['eur', 'jpy'], array_column($importer->saved[0]['values'], 'currency'));
    }

    public function testListWithoutKnownCurrencyIsInvalid(): void
    {
        $importer = $this->importer();
        $result = $importer->import(self::list(['rates' => [['currency' => 'XXX', 'amount' => 1, 'rate' => 1.0]]]), 1);

        $this->assertSame(ExchangeRateListImportResult::INVALID, $result->outcome);
        $this->assertSame(['Lístek nemá žádnou známou měnu.'], $result->errors);
        $this->assertSame(['XXX'], $result->skippedCurrencies);
        $this->assertSame([], $importer->saved);
    }

    public function testRepeatedImportWithUnknownCurrencyIsSkippedNotConflict(): void
    {
        $importer = $this->importer(self::EXISTING);
        $list = self::list();
        $list['rates'][] = ['currency' => 'XDR', 'amount' => 1, 'rate' => 28.5];
        $result = $importer->import($list, ExchangeRateListImporter::SOURCE_SERVICE);

        $this->assertSame(ExchangeRateListImportResult::SKIPPED, $result->outcome, 'obsah se porovnává po vyřazení neznámé měny');
        $this->assertSame(['XDR'], $result->skippedCurrencies);
        $this->assertSame([], $importer->saved);
    }

    public function testWithoutCurrencyCatalogNothingIsSkipped(): void
    {
        $importer = new class(
            $this->createMock(Connection::class),
            $this->createMock(TableGateway::class),
            new SchemaValidator(SchemaLoader::default()),
            new ExchangeRateListTypes([new ExchangeRateListType('cz.cnb.daily', 'ČNB denní', 'czk', 7)]),
            [],
            'czk',
        ) extends ExchangeRateListImporter {
            public array $saved = [];

            protected function findListId(string $listType, string $validFrom): ?int
            {
                return null;
            }

            protected function save(array $data): DocumentResult
            {
                $this->saved[] = $data;
                return DocumentResult::ok(['id' => 1]);
            }
        };
        $result = $importer->import(self::list(['rates' => [['currency' => 'XXX', 'amount' => 1, 'rate' => 1.0]]]), 1);

        $this->assertSame(ExchangeRateListImportResult::CREATED, $result->outcome);
        $this->assertSame([], $result->skippedCurrencies);
        $this->assertSame(['xxx'], array_column($importer->saved[0]['values'], 'currency'));
    }

    public function testCreatesNormalizedListWithValuesAndSource(): void
    {
        $importer = $this->importer();
        $result = $importer->import(self::list(), ExchangeRateListImporter::SOURCE_DATASET);

        $this->assertSame(ExchangeRateListImportResult::CREATED, $result->outcome);
        $this->assertTrue($result->ok());
        $this->assertSame(100, $result->listId);
        $this->assertSame([
            'list_type'     => 'cz.cnb.daily',
            'home_currency' => 'czk',
            'valid_from'    => '2026-10-16',
            'valid_to'      => null,
            'list_number'   => '201',
            'source'        => 2,
            'values'        => [
                ['currency' => 'eur', 'amount' => 1, 'rate' => 24.325],
                ['currency' => 'jpy', 'amount' => 100, 'rate' => 15.871],
            ],
        ], $importer->saved[0]);
    }

    public function testMonthlyListKeepsValidToAndMissingListNumber(): void
    {
        $importer = $this->importer();
        $list = self::list(['listType' => 'cz.cnb.other', 'validFrom' => '2026-10-01', 'validTo' => '2026-10-31', 'rates' => [['currency' => 'THB', 'amount' => 100, 'rate' => 69.5]]]);
        unset($list['listNumber']);

        $this->assertSame(ExchangeRateListImportResult::CREATED, $importer->import($list, 1)->outcome);
        $this->assertSame('2026-10-31', $importer->saved[0]['valid_to']);
        $this->assertNull($importer->saved[0]['list_number']);
    }

    public function testSameContentIsSkippedRegardlessOfSourceAndIds(): void
    {
        $importer = $this->importer(self::EXISTING);
        $result = $importer->import(self::list(), ExchangeRateListImporter::SOURCE_FILE);

        $this->assertSame(ExchangeRateListImportResult::SKIPPED, $result->outcome);
        $this->assertTrue($result->ok());
        $this->assertSame(10, $result->listId);
        $this->assertSame([], $importer->saved);
    }

    public function testDifferentContentIsConflictWithoutReplace(): void
    {
        $importer = $this->importer(self::EXISTING);
        $result = $importer->import(self::list(['rates' => [['currency' => 'EUR', 'amount' => 1, 'rate' => 24.4]]]), 1);

        $this->assertSame(ExchangeRateListImportResult::CONFLICT, $result->outcome);
        $this->assertSame(10, $result->listId);
        $this->assertStringContainsString('jiným obsahem', $result->errors[0]);
        $this->assertSame([], $importer->saved);
    }

    public function testReplaceReusesValueIdsPerCurrency(): void
    {
        $importer = $this->importer(self::EXISTING);
        $result = $importer->import(
            self::list(['listNumber' => '201a', 'rates' => [['currency' => 'EUR', 'amount' => 1, 'rate' => 24.4], ['currency' => 'USD', 'amount' => 1, 'rate' => 21.0]]]),
            ExchangeRateListImporter::SOURCE_FILE,
            replace: true,
        );

        $this->assertSame(ExchangeRateListImportResult::REPLACED, $result->outcome);
        $saved = $importer->saved[0];
        $this->assertSame(10, $saved['id']);
        $this->assertSame('201a', $saved['list_number']);
        $this->assertSame(1, $saved['source']);
        $this->assertSame(
            [['currency' => 'eur', 'amount' => 1, 'rate' => 24.4, 'id' => 11], ['currency' => 'usd', 'amount' => 1, 'rate' => 21.0]],
            $saved['values'],
            'EUR update přes existující id, USD insert, JPY chybí = gateway smaže',
        );
    }

    public function testFailedSaveIsReported(): void
    {
        $importer = $this->importer();
        $importer->failSave = true;
        $result = $importer->import(self::list(), 1);

        $this->assertSame(ExchangeRateListImportResult::FAILED, $result->outcome);
        $this->assertSame(['Zápis selhal: Duplicate entry'], $result->errors);
    }

    public function testFingerprintIgnoresSourceIdsAndDateObjects(): void
    {
        $a = self::EXISTING[10];
        $a['valid_to'] = new \DateTimeImmutable('2026-10-31');
        $b = ['home_currency' => 'CZK', 'valid_to' => '2026-10-31', 'list_number' => '201', 'source' => 1,
              'values' => [['currency' => 'JPY', 'amount' => 100, 'rate' => 15.871], ['currency' => 'eur', 'amount' => 1, 'rate' => 24.325]]];

        $this->assertSame(ExchangeRateListImporter::fingerprint($a), ExchangeRateListImporter::fingerprint($b));
        $b['values'][0]['rate'] = 15.8711;
        $this->assertNotSame(ExchangeRateListImporter::fingerprint($a), ExchangeRateListImporter::fingerprint($b));
    }
}
