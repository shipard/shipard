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
        // Snímek deníku plátce je schválený (#103 F3, 2026-10-15) a načte se;
        // spolek ho zatím nemá (schvaluje se až po #122) → null.
        $this->assertIsArray($e->ledgerSnapshot, 'schválený snímek deníku plátce se načte');
        $this->assertSame('economy.accounting.generalLedger', $e->ledgerSnapshot['reportId'] ?? null);

        $association = VerifyExpectations::load(DatasetReader::open(dirname(__DIR__, 7) . '/tests/datasets/cz/association'));
        $this->assertNull($association->ledgerSnapshot, 'snímek deníku spolku se schvaluje až po prvním běhu');
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
