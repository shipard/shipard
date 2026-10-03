<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Prints;

use Shipard\Api\PrintDefinitionLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintFormat;
use Shipard\Core\Prints\PrintRunner;
use Shipard\Core\Prints\PrintRunnerFactory;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Kontrakt `PrintData` tisku prodejky (#90 D26) nad dev DS: prodejka hotově
 * bez partnera, prodejka převodem s QR a vratka se porovnávají s uloženým
 * JSON v `tests/Fixtures/Prints/`. Vyžaduje pokladnu a řadu prodejek.
 */
class CashRegisterPrintTest extends IntegrationTestCase
{
    use PrintFixtureDocuments;

    private const PRINT_ID = 'docs.cashRegister.receipt';

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

    public function testCashReceiptOfVatPayerWithoutPartner(): void
    {
        $expected = $this->expectedFor('receiptCash');
        $headId   = $this->insertReceipt($expected['data'], $this->cashDesk['id']);

        $output = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'cs');

        $this->assertSame(self::normalize($expected['data']), self::normalize($output->printData->data));
        $this->assertSame($expected['meta'], $output->printData->toArray()['meta']);
        $this->assertSame('cashRegisterVatPayer', $output->printData->data['document']['titleVariant']);
        $this->assertNull($output->printData->data['customer']);
        $this->assertNull($output->printData->data['payment']['qr']);
        $this->assertSame([], $output->printData->messages);
    }

    public function testReceiptPaidByBankTransferHasAccountAndQr(): void
    {
        $expected = $this->expectedFor('receiptTransfer');
        $headId   = $this->insertReceipt($expected['data'], $this->cashDesk['id'], headOverrides: [
            'payment_method'    => 1,
            'due_date'          => '2026-10-14',
            'payment_reference' => '1426000001',
        ]);

        $output = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'cs');
        $data   = $output->printData->data;

        $this->assertSame(self::normalize($expected['data']), self::normalize($data));
        $this->assertSame($expected['meta'], $output->printData->toArray()['meta']);
        $this->assertTrue($data['payment']['bankTransfer']);
        $this->assertSame('spayd', $data['payment']['qr']['standard']);
        $this->assertStringContainsString('ACC:CZ6508000000192000145399', $data['payment']['qr']['payload']);
        $this->assertStringContainsString('X-VS:1426000001', $data['payment']['qr']['payload']);
    }

    public function testRefundHasItsOwnTitle(): void
    {
        $expected = $this->expectedFor('receiptRefund');
        $headId   = $this->insertReceipt($expected['data'], $this->cashDesk['id'], quantity: -1.0);

        $output = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'cs');

        $this->assertSame(self::normalize($expected['data']), self::normalize($output->printData->data));
        $this->assertSame($expected['meta'], $output->printData->toArray()['meta']);
        $this->assertSame('cashRegisterRefund', $output->printData->data['document']['titleVariant']);
        $this->assertSame(-605.0, (float) $output->printData->data['totals']['total']);
    }

    public function testNonPayerReceiptIsPlainReceipt(): void
    {
        $expected = $this->expectedFor('receiptCash');
        $headId   = $this->insertReceipt($expected['data'], $this->cashDesk['id'], headOverrides: [
            'vat_registration' => null,
            'vat_mode'         => 0,
        ]);

        $output = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'en');

        $this->assertSame('cashRegisterNonVatPayer', $output->printData->data['document']['titleVariant']);
        $this->assertSame('Sales receipt', $output->printData->data['document']['title']);
        $this->assertSame('sales-receipt-it-print-rec.pdf', $output->printData->fileName);
        $this->assertSame([], $output->printData->data['vatRecap']);
    }

    public function testSlovakAndGermanReceipt(): void
    {
        $expected = $this->expectedFor('receiptCash');
        $headId   = $this->insertReceipt($expected['data'], $this->cashDesk['id']);

        $sk = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'sk')->printData;
        $this->assertSame('Predajka – daňový doklad', $sk->data['document']['title']);
        $this->assertSame('predajka-it-print-rec.pdf', $sk->fileName);
        $this->assertSame('Hotovosť', $sk->data['payment']['method']['label']);

        $de = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'de')->printData;
        $this->assertSame('Verkaufsbeleg – Steuerbeleg', $de->data['document']['title']);
        $this->assertSame('verkaufsbeleg-it-print-rec.pdf', $de->fileName);
        $this->assertSame('Barzahlung', $de->data['payment']['method']['label']);
    }
}
