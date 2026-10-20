<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Verify;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyExpectations;

class VerifyExpectationsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_expect_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    public function testLoadsCorpusDataset(): void
    {
        $reader = DatasetReader::open(dirname(__DIR__, 7) . '/tests/datasets/cz/vat-payer');
        $e = VerifyExpectations::load($reader);
        $this->assertCount(20, $e->vat);
        $this->assertCount(20, $e->costs);
        $this->assertSame(['customer'], $e->projection->nullableNodes());
        $this->assertArrayHasKey('MSG-CORPUS-VP-0101', $e->vatByMessage());
        $this->assertSame('CZK', $e->costsByMessage()['MSG-CORPUS-VP-0101']['currency']);
        // Schválené snímky deníku (#103 F3, 2026-10-15; spolek po #122) se načtou
        // u všech tří sad — chybějící snímek kryje test volitelného snímku níže.
        foreach (['vat-payer' => $e, 'non-payer' => null, 'association' => null] as $set => $loaded) {
            $loaded ??= VerifyExpectations::load(DatasetReader::open(dirname(__DIR__, 7) . "/tests/datasets/cz/{$set}"));
            $this->assertIsArray($loaded->ledgerSnapshot, "schválený snímek deníku sady {$set} se načte");
            $this->assertSame('economy.accounting.generalLedger', $loaded->ledgerSnapshot['reportId'] ?? null, $set);
        }
    }

    public function testLoadsEmptyVatForNonPayerAndOptionalSnapshot(): void
    {
        $w = DatasetWriter::create($this->tmp);
        $w->copyFile(dirname(__DIR__, 7) . '/tests/corpus/projection.jsonc', 'expected/projection.jsonc');
        $w->writeRaw('expected/vat.json', "[]\n");
        $w->writeRaw('expected/costs.json', "[{\"messageId\": \"M\", \"currency\": \"CZK\", \"cost\": 1}]\n");
        $w->writeRaw('expected/general-ledger.json', "{\"reportId\": \"economy.accounting.generalLedger\", \"rows\": []}\n");
        $w->writeManifest(new DatasetManifest('x', 'X', null, 'fixed', '2026-10-14T00:00:00Z', []));
        $e = VerifyExpectations::load(DatasetReader::open($this->tmp));
        $this->assertSame([], $e->vat);
        $this->assertSame('economy.accounting.generalLedger', $e->ledgerSnapshot['reportId'] ?? null);
        $this->assertNull($e->exchangeRates, 'sada bez exchange-rates.json (před #103 D79)');
        $this->assertNull($e->exchangeRatesByMessage());
    }

    /** `exchange-rates.json` je volitelný (#103 D79): záznamy per zpráva jako u DPH a nákladů. */
    public function testLoadsOptionalExchangeRates(): void
    {
        $w = DatasetWriter::create($this->tmp);
        $w->copyFile(dirname(__DIR__, 7) . '/tests/corpus/projection.jsonc', 'expected/projection.jsonc');
        $w->writeRaw('expected/vat.json', "[]\n");
        $w->writeRaw('expected/costs.json', "[]\n");
        $w->writeRaw('expected/exchange-rates.json', "[{\"messageId\": \"M\", \"currency\": \"EUR\", \"rateDate\": \"2026-08-31\", \"listType\": \"cz.cnb.daily\", \"validFrom\": \"2026-08-31\", \"rate\": 24.325}]\n");
        $w->writeManifest(new DatasetManifest('x', 'X', null, 'fixed', '2026-10-14T00:00:00Z', []));
        $e = VerifyExpectations::load(DatasetReader::open($this->tmp));
        $this->assertSame(24.325, $e->exchangeRatesByMessage()['M']['rate'] ?? null);

        $w->writeRaw('expected/exchange-rates.json', "[{\"currency\": \"EUR\"}]\n");
        $this->expectException(DatasetException::class);
        VerifyExpectations::load(DatasetReader::open($this->tmp));
    }

    public function testMissingFilesAreAnError(): void
    {
        $w = DatasetWriter::create($this->tmp);
        $w->writeManifest(new DatasetManifest('x', 'X', null, 'fixed', '2026-10-14T00:00:00Z', []));
        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage('expected/projection.jsonc');
        VerifyExpectations::load(DatasetReader::open($this->tmp));
    }

    public function testRecordWithoutMessageIdIsAnError(): void
    {
        $w = DatasetWriter::create($this->tmp);
        $w->copyFile(dirname(__DIR__, 7) . '/tests/corpus/projection.jsonc', 'expected/projection.jsonc');
        $w->writeRaw('expected/vat.json', "[{\"period\": \"2026-08\"}]\n");
        $w->writeRaw('expected/costs.json', "[]\n");
        $w->writeManifest(new DatasetManifest('x', 'X', null, 'fixed', '2026-10-14T00:00:00Z', []));
        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage('bez messageId');
        VerifyExpectations::load(DatasetReader::open($this->tmp));
    }
}
