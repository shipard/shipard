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
 * Doklady test vkládá přímo (stav 40 se snapshoty z fixture souboru)
 * a uklízí; popisky číselníků bere z konfigurace DS. Vyžaduje řady invno
 * a invpo a registraci k DPH; po změně `world.vat` je potřeba `ds-upgrade`.
 */
class DocPrintBuilderTest extends IntegrationTestCase
{
    private const INVOICE_NUMBER  = 'IT-PRINT-INV';
    private const PROFORMA_NUMBER = 'IT-PRINT-PRO';

    /** @var list<int> */
    private array $createdHeads = [];

    private PrintRunner $runner;
    private string $modulesPath;
    private int $vatRegistration = 0;

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

        $registration = $this->db->fetchSingle(
            'SELECT [id] FROM [economy_codebooks_vat_registrations] WHERE [country] = %s ORDER BY [id] LIMIT 1',
            'cz',
        );
        if ($registration === null) {
            $this->markTestSkipped('DS nemá registraci k DPH pro cz.');
        }
        $this->vatRegistration = (int) $registration;

        // Zbytky po spadlém běhu — čísla fixture dokladů jsou pevná.
        foreach ($this->db->fetchAll(
            'SELECT [id] FROM [docs_core_heads] WHERE [doc_number] IN %in',
            [self::INVOICE_NUMBER, self::PROFORMA_NUMBER],
        ) as $leftover) {
            $this->deleteHead((int) $leftover['id']);
        }
    }

    protected function onTearDown(): void
    {
        foreach ($this->createdHeads as $id) {
            $this->deleteHead($id);
        }
    }

    private function deleteHead(int $id): void
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $id)->execute();
        $dibi->delete('docs_core_rows')->where('doc_head = %i', $id)->execute();
        $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
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
            ['title' => 'Faktura – daňový doklad IT-PRINT-INV', 'fileName' => 'faktura-it-print-inv.pdf'],
            $envelope['meta'],
        );
        $this->assertSame([], $envelope['messages']);
    }

    public function testProformaDataMatchesContractSnapshot(): void
    {
        $expected = $this->expected('proforma');

        $headId = $this->insertProforma($expected);
        $output = $this->runner->run('docs.proformasOut.proforma', $headId, PrintFormat::Json, 'cs');

        $this->assertSame(self::normalize($expected), self::normalize($output->printData->data));
        $this->assertSame(
            ['title' => 'Zálohová faktura IT-PRINT-PRO', 'fileName' => 'zalohova-faktura-it-print-pro.pdf'],
            $output->printData->toArray()['meta'],
        );
    }

    public function testEnglishPrintTranslatesTitleAndCodebookLabels(): void
    {
        $expected = $this->expected('invoice');
        $headId = $this->insertInvoice($expected, $this->anyUnit()[0]);

        $output = $this->runner->run('docs.invoicesOut.invoice', $headId, PrintFormat::Json, 'en');
        $data   = $output->printData->data;

        $this->assertSame('en', $output->printData->language);
        $this->assertSame('Invoice – tax document', $data['document']['title']);
        $this->assertSame('invoice-it-print-inv.pdf', $output->printData->fileName);
        $this->assertSame('Bank transfer', $data['payment']['method']['label']);
        $this->assertSame('Standard rate', $data['rows'][0]['vat']['label']);
        $this->assertSame(
            ['Standard rate', 'Reduced rate', 'Zero rate'],
            array_column($data['vatRecap'], 'label'),
        );
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

    public function testConfirmedDocumentWithoutSnapshotFails(): void
    {
        $headId = $this->insertProforma($this->expected('proforma'), ['customer_snapshot' => null]);

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

        [$exit, $err] = $run(['printId' => 'docs.proformasOut.proforma', 'recordId' => (string) $headId, '--language' => 'de']);
        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString("'language' must be one of", $err);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function commandTester(): CommandTester
    {
        return new CommandTester(new TestablePrintRunCommand($this->dsConfig, $this->db, $this->modulesPath));
    }

    /** @return array<string, mixed> */
    private function expected(string $name): array
    {
        $file = dirname(__DIR__, 2) . '/Fixtures/Prints/' . $name . '.data.json';
        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Čísla sjednotí na float — JSON fixture nese `1000`, builder `1000.0`;
     * porovnání pak může být striktní (null ≠ 0 ≠ false).
     */
    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::normalize(...), $value);
        }
        return is_int($value) ? (float) $value : $value;
    }

    /** @return array{0: int, 1: string} */
    private function anyUnit(): array
    {
        $unit = $this->db->fetchRow('SELECT [id], [shortcut] FROM [core_units] ORDER BY [id] LIMIT 1');
        if ($unit === null) {
            $this->markTestSkipped('DS nemá žádnou jednotku.');
        }
        return [(int) $unit['id'], (string) $unit['shortcut']];
    }

    private function seriesFor(string $docType): int
    {
        $series = $this->db->fetchSingle(
            'SELECT [id] FROM [docs_core_number_series] WHERE [doc_type] = %s AND [docState] IN (10, 40, 80) LIMIT 1',
            $docType,
        );
        if ($series === null) {
            $this->markTestSkipped("DS nemá řadu {$docType}.");
        }
        return (int) $series;
    }

    /**
     * @param array<string, mixed> $expected Fixture — snapshoty stran se berou z ní.
     * @param array<string, mixed> $head
     */
    private function insertHead(array $expected, array $head): int
    {
        $json = static fn (array $snapshot): string => (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE);

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', $head + [
            'number_series'     => $this->seriesFor((string) $head['doc_type']),
            'issue_date'        => '2026-09-30',
            'accounting_date'   => '2026-09-30',
            'due_date'          => '2026-10-14',
            'vat_registration'  => $this->vatRegistration,
            'vat_mode'          => 1,
            'payment_method'    => 1,
            'home_currency'     => 'czk',
            'supplier_snapshot' => $json($expected['supplier']),
            'customer_snapshot' => $json($expected['customer']),
            'docState'          => 40,
            'docStateMain'      => 2,
        ])->execute();
        $headId = (int) $dibi->getInsertId();
        $this->createdHeads[] = $headId;
        return $headId;
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertRows(int $headId, array $rows): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($rows as $pos => $row) {
            $dibi->insert('docs_core_rows', $row + ['doc_head' => $headId, 'order_pos' => $pos + 1])->execute();
        }
    }

    /** @param list<array<string, mixed>> $recap */
    private function insertRecap(int $headId, array $recap): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($recap as $pos => $row) {
            $dibi->insert('docs_core_vat_recap', $row + ['doc_head' => $headId, 'order_pos' => $pos])->execute();
        }
    }

    /** @param array<string, mixed> $expected */
    private function insertInvoice(array $expected, int $unitId): int
    {
        $headId = $this->insertHead($expected, [
            'doc_type'          => 'invno',
            'doc_number'        => self::INVOICE_NUMBER,
            'doc_text'          => 'Konzultace a tiskoviny za září',
            'doc_notice'        => 'Děkujeme za včasnou úhradu.',
            'notice'            => 'Interní poznámka se netiskne.',
            'vat_duzp'          => '2026-09-30',
            'period_from'       => '2026-09-01',
            'period_to'         => '2026-09-30',
            'doc_currency'      => 'eur',
            'exchange_rate'     => 24.5,
            'payment_reference' => '2026000123',
            'specific_symbol'   => '',
            'constant_symbol'   => '0308',
            'total_base'        => 590.0, 'total_vat' => 220.8, 'total_amount' => 810.8,
            'total_base_dom'    => 14455.0, 'total_vat_dom' => 5409.6, 'total_amount_dom' => 19864.6,
        ]);

        $this->insertRows($headId, [
            [
                'row_kind' => 1, 'operation' => 'sale.services', 'description' => 'Konzultace',
                'quantity' => 10, 'unit' => $unitId, 'unit_price' => 100, 'total_price' => 1000,
                'vat_code' => 'cz-120', 'vat_pct' => 21,
                'vat_base' => 1000, 'vat_amount' => 210, 'vat_total' => 1210,
            ],
            [
                'row_kind' => 1, 'operation' => 'sale.goods', 'description' => 'Tištěná příručka',
                'quantity' => 2, 'unit_price' => 50, 'discount_pct' => 10, 'total_price' => 90,
                'vat_code' => 'cz-121', 'vat_pct' => 12,
                'vat_base' => 90, 'vat_amount' => 10.8, 'vat_total' => 100.8,
            ],
            ['row_kind' => 0, 'description' => 'Děkujeme za spolupráci.'],
            [
                'row_kind' => 1, 'operation' => 'sale.advanceDeduction',
                'description' => 'Odpočet zálohy dle zálohové faktury',
                'unit_price' => 0, 'total_price' => -500, 'price_calc_mode' => 1,
                'vat_code' => 'cz-122', 'vat_pct' => 0,
                'vat_base' => -500, 'vat_amount' => 0, 'vat_total' => -500,
            ],
        ]);

        $this->insertRecap($headId, [
            ['vat_code' => 'cz-120', 'vat_pct' => 21, 'base' => 1000, 'tax' => 210, 'total' => 1210,
             'base_dom' => 24500, 'tax_dom' => 5145, 'total_dom' => 29645],
            // Druhá strana reverse charge páru se netiskne.
            ['vat_code' => 'cz-120', 'vat_pct' => 21, 'base' => 77, 'tax' => 16.17, 'total' => 93.17,
             'base_dom' => 1886.5, 'tax_dom' => 396.17, 'total_dom' => 2282.67, 'is_reverse_pair' => 1],
            ['vat_code' => 'cz-121', 'vat_pct' => 12, 'base' => 90, 'tax' => 10.8, 'total' => 100.8,
             'base_dom' => 2205, 'tax_dom' => 264.6, 'total_dom' => 2469.6],
            ['vat_code' => 'cz-122', 'vat_pct' => 0, 'base' => -500, 'tax' => 0, 'total' => -500,
             'base_dom' => -12250, 'tax_dom' => 0, 'total_dom' => -12250],
        ]);

        return $headId;
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $headOverrides
     */
    private function insertProforma(array $expected, array $headOverrides = []): int
    {
        $headId = $this->insertHead($expected, $headOverrides + [
            'doc_type'          => 'invpo',
            'doc_number'        => self::PROFORMA_NUMBER,
            'doc_text'          => 'Záloha na dodávku tiskovin',
            'doc_currency'      => 'czk',
            'exchange_rate'     => 1.0,
            'payment_reference' => '2026000045',
            'total_base'        => 10000.0, 'total_vat' => 2100.0, 'total_amount' => 12100.0,
            'total_base_dom'    => 10000.0, 'total_vat_dom' => 2100.0, 'total_amount_dom' => 12100.0,
        ]);

        $this->insertRows($headId, [[
            'row_kind' => 1, 'operation' => 'sale.services', 'description' => 'Záloha na dodávku tiskovin',
            'quantity' => 1, 'unit_price' => 10000, 'total_price' => 10000,
            'vat_code' => 'cz-120', 'vat_pct' => 21,
            'vat_base' => 10000, 'vat_amount' => 2100, 'vat_total' => 12100,
        ]]);
        $this->insertRecap($headId, [[
            'vat_code' => 'cz-120', 'vat_pct' => 21, 'base' => 10000, 'tax' => 2100, 'total' => 12100,
            'base_dom' => 10000, 'tax_dom' => 2100, 'total_dom' => 12100,
        ]]);

        return $headId;
    }
}
