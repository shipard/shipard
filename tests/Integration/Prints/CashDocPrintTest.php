<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Prints;

use Shipard\Api\PrintDefinitionLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintBuildException;
use Shipard\Core\Prints\PrintFormat;
use Shipard\Core\Prints\PrintRunner;
use Shipard\Core\Prints\PrintRunnerFactory;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Kontrakt `PrintData` tisku pokladního dokladu (#90 D25) nad dev DS:
 * fixture doklady v obou směrech se porovnávají s uloženým JSON
 * v `tests/Fixtures/Prints/`. Vyžaduje pokladnu a řadu pokladních dokladů.
 */
class CashDocPrintTest extends IntegrationTestCase
{
    use PrintFixtureDocuments;

    private const PRINT_ID = 'docs.cashDocs.cash';

    private PrintRunner $runner;

    /** @var array{id: int, code: string, name: string} */
    private array $cashDesk;

    protected function setUp(): void
    {
        parent::setUp();

        $modules = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->runner = PrintRunnerFactory::create(
            PrintDefinitionLoader::load($this->dsConfig, $modules, 'cs'),
            $this->dsConfig,
            $this->db,
            $modules,
        );

        $this->prepareFixtureDocuments();
        $this->cashDesk = $this->anyCashDesk();
    }

    protected function onTearDown(): void
    {
        $this->deleteFixtureDocuments();
    }

    /**
     * Fixture nese pevnou pokladnu; skutečná je ta z DS.
     *
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function expectedFor(string $fixture): array
    {
        $envelope = $this->expectedEnvelope($fixture);
        $envelope['data']['cashDesk'] = $this->cashDesk;
        return ['data' => $envelope['data'], 'meta' => $envelope['meta']];
    }

    public function testReceiptWithSaleOfVatPayerIsTaxDocument(): void
    {
        $expected = $this->expectedFor('cashInSale');
        $headId   = $this->insertCashSale($expected['data'], $this->cashDesk['id']);

        $output = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'cs');

        $this->assertSame(self::normalize($expected['data']), self::normalize($output->printData->data));
        $this->assertSame($expected['meta'], $output->printData->toArray()['meta']);
        $this->assertSame('cashInTaxDocument', $output->printData->data['document']['titleVariant']);
        $this->assertSame([], $output->printData->messages);
    }

    public function testReceiptPayingInvoiceIsPlainCashReceipt(): void
    {
        $expected = $this->expectedFor('cashInPayment');
        $headId   = $this->insertCashInvoicePayment($expected['data'], $this->cashDesk['id']);

        $output = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'cs');

        $this->assertSame(self::normalize($expected['data']), self::normalize($output->printData->data));
        $this->assertSame($expected['meta'], $output->printData->toArray()['meta']);
        $this->assertSame('cashIn', $output->printData->data['document']['titleVariant']);
    }

    public function testDisbursementWithoutPartnerHasNoSupplier(): void
    {
        $expected = $this->expectedFor('cashOut');
        $headId   = $this->insertCashDisbursement($expected['data'], $this->cashDesk['id']);

        $output = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'cs');

        $this->assertSame(self::normalize($expected['data']), self::normalize($output->printData->data));
        $this->assertSame($expected['meta'], $output->printData->toArray()['meta']);
        $this->assertNull($output->printData->data['supplier']);
        $this->assertSame('cashOut', $output->printData->data['document']['titleVariant']);
    }

    public function testEnglishTitlesAndCancelledWatermark(): void
    {
        $expected = $this->expectedFor('cashInSale');
        $headId   = $this->insertCashSale($expected['data'], $this->cashDesk['id'], ['docState' => 30, 'docStateMain' => 4]);

        $output = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'en');

        $this->assertSame('Cash receipt – tax document', $output->printData->data['document']['title']);
        $this->assertSame('cash-receipt-it-print-cash.pdf', $output->printData->fileName);
        $this->assertSame('CANCELLED', $output->printData->watermark);
    }

    public function testSlovakAndGermanTitlesAndCancelledWatermark(): void
    {
        $expected = $this->expectedFor('cashInSale');
        $headId   = $this->insertCashSale($expected['data'], $this->cashDesk['id'], ['docState' => 30, 'docStateMain' => 4]);

        $sk = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'sk')->printData;
        $this->assertSame('Príjmový pokladničný doklad – daňový doklad', $sk->data['document']['title']);
        $this->assertSame('prijmovy-pokladnicny-doklad-it-print-cash.pdf', $sk->fileName);
        $this->assertSame('STORNO', $sk->watermark);

        $de = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'de')->printData;
        $this->assertSame('Kasseneinnahmebeleg – Steuerbeleg', $de->data['document']['title']);
        $this->assertSame('kasseneinnahmebeleg-it-print-cash.pdf', $de->fileName);
        $this->assertSame('STORNIERT', $de->watermark);
    }

    public function testDisbursementWithoutOwnSnapshotFails(): void
    {
        $expected = $this->expectedFor('cashOut');
        $expected['data']['customer'] = null;
        $headId = $this->insertCashDisbursement($expected['data'], $this->cashDesk['id']);

        $this->expectException(PrintBuildException::class);
        $this->expectExceptionMessage('has no party snapshot');
        $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'cs');
    }
}
