<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Prints;

use Shipard\Api\PrintDefinitionLoader;
use Shipard\Command\DataSource\PrintRunCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintBuildException;
use Shipard\Core\Prints\PrintFormat;
use Shipard\Core\Prints\PrintNotAvailableException;
use Shipard\Core\Prints\PrintRunner;
use Shipard\Core\Prints\PrintRunnerFactory;
use Shipard\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestablePrintRunCommand extends PrintRunCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        private readonly string $modulesPath,
    ) {
        parent::__construct($dsConfig, $dsConnection);
    }

    protected function getModulePathResolver(): ModulePathResolver
    {
        return new ModulePathResolver([$this->modulesPath]);
    }
}

/**
 * Kontrakt `PrintData` tisků dokladů nad reálným dev DS (#90 D15): fixture
 * faktura (plátce, dvě sazby, textový řádek, odpočet zálohy, cizí měna)
 * a fixture proforma se porovnávají s uloženým JSON v
 * `tests/Fixtures/Prints/` — bez renderu.
 *
 * Doklady vkládá a uklízí `PrintFixtureDocuments`; popisky číselníků se
 * berou z konfigurace DS — po změně `world.vat` je potřeba `ds-upgrade`.
 */
class DocPrintBuilderTest extends IntegrationTestCase
{
    use PrintFixtureDocuments;

    private PrintRunner $runner;
    private string $modulesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modulesPath = dirname(__DIR__, 3) . '/modules';
        $modules = new ModulePathResolver([$this->modulesPath]);
        $this->runner = PrintRunnerFactory::create(
            PrintDefinitionLoader::load($this->dsConfig, $modules, 'cs'),
            $this->dsConfig,
            $this->db,
            $modules,
        );

        $this->prepareFixtureDocuments();
    }

    protected function onTearDown(): void
    {
        $this->deleteFixtureDocuments();
    }

    // ── kontrakt data ───────────────────────────────────────────────────────

    public function testInvoiceDataMatchesContractSnapshot(): void
    {
        $expected = $this->expected('invoice');
        [$unitId, $unitLabel] = $this->anyUnit();
        $expected['rows'][0]['unit'] = ['id' => $unitId, 'label' => $unitLabel];

        $headId = $this->insertInvoice($expected, $unitId);
        $output = $this->runner->run('docs.invoicesOut.invoice', $headId, PrintFormat::Json, 'cs');

        $this->assertSame(self::normalize($expected), self::normalize($output->printData->data));

        $envelope = $output->printData->toArray();
        $this->assertSame('docs.invoicesOut.invoice', $envelope['printId']);
        $this->assertSame(1, $envelope['version']);
        $this->assertSame('cs', $envelope['language']);
        $this->assertSame(['table' => 'docs_core_heads', 'id' => $headId, 'docState' => 40], $envelope['record']);
        $this->assertSame(
            ['title' => 'Faktura – daňový doklad IT-PRINT-INV', 'fileName' => 'faktura-it-print-inv.pdf', 'watermark' => null],
            $envelope['meta'],
        );
        $this->assertSame($this->expectedEnvelope('invoice')['meta'], $envelope['meta']);
        $this->assertSame([], $envelope['messages']);
    }

    public function testProformaDataMatchesContractSnapshot(): void
    {
        $expected = $this->expected('proforma');

        $headId = $this->insertProforma($expected);
        $output = $this->runner->run('docs.proformasOut.proforma', $headId, PrintFormat::Json, 'cs');

        $this->assertSame(self::normalize($expected), self::normalize($output->printData->data));
        $this->assertSame(
            $this->expectedEnvelope('proforma')['meta'],
            $output->printData->toArray()['meta'],
        );
    }

    // ── storno (D23) ────────────────────────────────────────────────────────

    public function testCancelledInvoiceIsPrintedWithWatermark(): void
    {
        $expected = $this->expected('invoice');
        $headId   = $this->insertInvoice($expected, $this->anyUnit()[0], ['docState' => 30, 'docStateMain' => 4]);

        $output = $this->runner->run('docs.invoicesOut.invoice', $headId, PrintFormat::Json, 'cs');
        $this->assertSame(
            ['title' => 'Faktura – daňový doklad IT-PRINT-INV', 'fileName' => 'faktura-it-print-inv.pdf', 'watermark' => 'STORNO'],
            $output->printData->toArray()['meta'],
        );
        $this->assertSame(30, $output->printData->docState);

        $output = $this->runner->run('docs.invoicesOut.invoice', $headId, PrintFormat::Json, 'en');
        $this->assertSame('CANCELLED', $output->printData->watermark);
    }

    // ── strany (D24) ────────────────────────────────────────────────────────

    public function testDocumentWithoutPartnerHasNoCustomer(): void
    {
        // Fixture hlavička nemá `partner` — bez snapshotu odběratele je strana null.
        $headId = $this->insertProforma($this->expected('proforma'), ['customer_snapshot' => null]);

        $data = $this->runner->run('docs.proformasOut.proforma', $headId, PrintFormat::Json, 'cs')->printData->data;

        $this->assertNull($data['customer']);
        $this->assertSame('Tiskárna Vzorová s.r.o.', $data['supplier']['name']);
        $this->assertSame(1, $data['document']['tradeDir']);
    }

    public function testDocumentWithPartnerNeedsPartnerSnapshot(): void
    {
        $headId = $this->insertProforma(
            $this->expected('proforma'),
            ['customer_snapshot' => null, 'partner' => $this->anyPersonId()],
        );

        $this->expectException(PrintBuildException::class);
        $this->expectExceptionMessage('has no party snapshot');
        $this->runner->run('docs.proformasOut.proforma', $headId, PrintFormat::Json, 'cs');
    }

    public function testEnglishPrintTranslatesTitleAndCodebookLabels(): void
    {
        $expected = $this->expected('invoice');
        $headId = $this->insertInvoice($expected, $this->systemUnitId('pcs'));

        $output = $this->runner->run('docs.invoicesOut.invoice', $headId, PrintFormat::Json, 'en');
        $data   = $output->printData->data;

        $this->assertSame('en', $output->printData->language);
        $this->assertSame('Invoice – tax document', $data['document']['title']);
        $this->assertSame('invoice-it-print-inv.pdf', $output->printData->fileName);
        $this->assertSame('Bank transfer', $data['payment']['method']['label']);
        $this->assertSame('Standard rate', $data['rows'][0]['vat']['label']);
        $this->assertSame('pcs', $data['rows'][0]['unit']['label'], 'zkratka systémové jednotky v jazyce tisku');
        $this->assertSame(
            ['Standard rate', 'Reduced rate', 'Zero rate'],
            array_column($data['vatRecap'], 'label'),
        );
    }

    public function testSlovakAndGermanPrintsTranslateTitleAndCodebookLabels(): void
    {
        $headId = $this->insertInvoice($this->expected('invoice'), $this->systemUnitId('pcs'));

        $sk = $this->runner->run('docs.invoicesOut.invoice', $headId, PrintFormat::Json, 'sk')->printData;
        $this->assertSame('sk', $sk->language);
        $this->assertSame('Faktúra – daňový doklad', $sk->data['document']['title']);
        $this->assertSame('faktura-it-print-inv.pdf', $sk->fileName);
        $this->assertSame('Prevodom', $sk->data['payment']['method']['label']);
        $this->assertSame('ks', $sk->data['rows'][0]['unit']['label']);
        $this->assertSame(['Základná', 'Znížená', 'Bez dane'], array_column($sk->data['vatRecap'], 'label'));
        $this->assertSame([], $sk->messages);

        $de = $this->runner->run('docs.invoicesOut.invoice', $headId, PrintFormat::Json, 'de')->printData;
        $this->assertSame('de', $de->language);
        $this->assertSame('Rechnung', $de->data['document']['title']);
        $this->assertSame('rechnung-it-print-inv.pdf', $de->fileName);
        $this->assertSame('Überweisung', $de->data['payment']['method']['label']);
        $this->assertSame('Stk', $de->data['rows'][0]['unit']['label']);
        $this->assertSame(['Normalsatz', 'Ermäßigter Satz', 'Nullsatz'], array_column($de->data['vatRecap'], 'label'));
        $this->assertSame([], $de->messages);
    }

    public function testCustomerFromSlovakiaIsPrintedInSlovakWithoutLanguageParameter(): void
    {
        // Odběratel fixture má slovenskou adresu; jazyk osoby by ji přebil
        // (#94 D2), proto partner bez nastaveného jazyka.
        $partnerId = $this->db->fetchSingle(
            "SELECT [id] FROM [base_persons_persons] WHERE [language] IS NULL OR [language] = '' ORDER BY [id] LIMIT 1",
        );
        if ($partnerId === null) {
            $this->markTestSkipped('DS nemá osobu bez nastaveného jazyka dokumentů.');
        }
        $expected = $this->expected('invoice');
        $this->assertSame('sk', $expected['customer']['address']['country']);
        $headId = $this->insertInvoice($expected, $this->anyUnit()[0], ['partner' => (int) $partnerId]);

        $output = $this->runner->run('docs.invoicesOut.invoice', $headId, PrintFormat::Json);

        $this->assertSame('sk', $output->printData->language);
        $this->assertSame('Faktúra – daňový doklad', $output->printData->data['document']['title']);
        $this->assertSame([], $output->printData->messages);
    }

    // ── dostupnost a tvrdé chyby ────────────────────────────────────────────

    public function testInvoicePrintIsNotDeclaredForProforma(): void
    {
        $headId = $this->insertProforma($this->expected('proforma'));

        $this->expectException(PrintNotAvailableException::class);
        $this->runner->run('docs.invoicesOut.invoice', $headId, PrintFormat::Json, 'cs');
    }

    public function testDraftIsNotPrinted(): void
    {
        $headId = $this->insertProforma($this->expected('proforma'), ['docState' => 10, 'docStateMain' => 1]);

        $this->expectException(PrintNotAvailableException::class);
        $this->runner->run('docs.proformasOut.proforma', $headId, PrintFormat::Json, 'cs');
    }

    public function testIssuedDocumentWithoutOwnSnapshotFails(): void
    {
        $headId = $this->insertProforma($this->expected('proforma'), ['supplier_snapshot' => null]);

        $this->expectException(PrintBuildException::class);
        $this->expectExceptionMessage('has no party snapshot');
        $this->runner->run('docs.proformasOut.proforma', $headId, PrintFormat::Json, 'cs');
    }

    // ── CLI print-run ───────────────────────────────────────────────────────

    public function testCliPrintsJsonToStdout(): void
    {
        $headId = $this->insertProforma($this->expected('proforma'));

        $tester = $this->commandTester();
        $exit = $tester->execute(
            ['printId' => 'docs.proformasOut.proforma', 'recordId' => (string) $headId, '--language' => 'cs'],
            ['capture_stderr_separately' => true],
        );

        $this->assertSame(Command::SUCCESS, $exit, $tester->getErrorOutput());
        $json = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('docs.proformasOut.proforma', $json['printId']);
        $this->assertSame('IT-PRINT-PRO', $json['data']['document']['number']);
        $this->assertSame('', $tester->getErrorOutput());
    }

    public function testCliRejectsInvalidInputAndReportsUnavailablePrint(): void
    {
        $headId = $this->insertProforma($this->expected('proforma'));
        $run = function (array $input): array {
            $tester = $this->commandTester();
            $exit = $tester->execute($input, ['capture_stderr_separately' => true]);
            return [$exit, $tester->getErrorOutput()];
        };

        [$exit, $err] = $run(['printId' => 'docs.proformasOut.proforma', 'recordId' => (string) $headId, '--format' => 'xml']);
        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('Invalid --format', $err);

        [$exit, $err] = $run(['printId' => 'docs.proformasOut.proforma', 'recordId' => (string) $headId, '--format' => 'pdf']);
        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('requires --output', $err);

        [$exit, $err] = $run(['printId' => 'docs.proformasOut.proforma', 'recordId' => 'abc']);
        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('Invalid recordId', $err);

        [$exit, $err] = $run(['printId' => 'docs.missing.print', 'recordId' => (string) $headId]);
        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('Available prints: ', $err);
        $this->assertStringContainsString('docs.invoicesOut.invoice', $err);

        [$exit, $err] = $run(['printId' => 'docs.invoicesOut.invoice', 'recordId' => (string) $headId]);
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('not declared for this kind of record', $err);

        [$exit, $err] = $run(['printId' => 'docs.proformasOut.proforma', 'recordId' => (string) $headId, '--language' => 'fr']);
        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString("'language' must be one of", $err);
    }

    private function commandTester(): CommandTester
    {
        return new CommandTester(new TestablePrintRunCommand($this->dsConfig, $this->db, $this->modulesPath));
    }
}
