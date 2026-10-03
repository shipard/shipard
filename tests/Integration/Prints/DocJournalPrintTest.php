<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Prints;

use Shipard\Api\PrintDefinitionLoader;
use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintFormat;
use Shipard\Core\Prints\PrintNotAvailableException;
use Shipard\Core\Prints\PrintRegistry;
use Shipard\Core\Prints\PrintRunner;
use Shipard\Core\Prints\PrintRunnerFactory;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Kontrakt `PrintData` tisku Kontace (#90 D27) nad dev DS: zápisy fixture
 * faktury v cizí měně proti uloženému JSON v `tests/Fixtures/Prints/`,
 * účetní doklad bez snapshotů, doklad bez zápisů a s chybou účtování.
 * Řádky deníku vkládá test přímo — účtovací engine neběží.
 */
class DocJournalPrintTest extends IntegrationTestCase
{
    use PrintFixtureDocuments;

    private const PRINT_ID = 'economy.accounting.docJournal';

    private PrintRunner $runner;
    private PrintRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $modules = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->registry = PrintDefinitionLoader::load($this->dsConfig, $modules, 'cs');
        $this->runner   = PrintRunnerFactory::create($this->registry, $this->dsConfig, $this->db, $modules);

        $this->prepareFixtureDocuments();
    }

    protected function onTearDown(): void
    {
        $this->deleteFixtureDocuments();
    }

    /**
     * Zápisy faktury v EUR: pohledávka MD, tržby a DPH Dal.
     *
     * @param list<array{id: int, number: string, name: string}> $accounts
     * @return list<array<string, mixed>>
     */
    private static function invoiceJournal(array $accounts): array
    {
        $row = static fn (int $i, string $text, array $money): array => $money + [
            'account' => $accounts[$i]['id'], 'account_number' => $accounts[$i]['number'],
            'text' => $text, 'currency' => 'eur',
        ];
        return [
            $row(0, 'Faktura IT-PRINT-INV', ['money_dr' => 19864.6, 'money_dr_cur' => 810.8]),
            $row(1, 'Konzultace', ['money_cr' => 14455.0, 'money_cr_cur' => 590.0]),
            $row(2, 'DPH', ['money_cr' => 5409.6, 'money_cr_cur' => 220.8]),
        ];
    }

    public function testInvoiceJournalMatchesContractSnapshot(): void
    {
        $envelope = $this->expectedEnvelope('docJournalInvoice');
        $expected = $envelope['data'];
        $accounts = $this->anyAccounts(3);
        foreach ($accounts as $i => $account) {
            $expected['journal'][$i]['accountNumber'] = $account['number'];
            $expected['journal'][$i]['accountName']   = $account['name'];
        }

        $headId = $this->insertInvoice($this->expected('invoice'), $this->anyUnit()[0]);
        $this->insertJournal($headId, self::invoiceJournal($accounts));

        $output = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'cs');

        $this->assertSame(self::normalize($expected), self::normalize($output->printData->data));
        $this->assertSame($envelope['meta'], $output->printData->toArray()['meta']);
        $this->assertSame([], $output->printData->messages);
        $this->assertSame(1, $output->printData->version);
    }

    public function testSlovakAndGermanJournalTranslatesCodebookLabels(): void
    {
        // Interní tisk se řídí vlastní zemí; jiný jazyk jen na vyžádání
        // (slovenský zdroj dat tiskne Kontaci slovensky, #90 D29).
        $headId = $this->insertInvoice($this->expected('invoice'), $this->anyUnit()[0]);
        $this->insertJournal($headId, self::invoiceJournal($this->anyAccounts(3)));

        $sk = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'sk')->printData;
        $this->assertSame('Kontácia', $sk->data['document']['title']);
        $this->assertSame('Faktúra vydaná', $sk->data['document']['typeName']);
        $this->assertSame('Zaúčtované', $sk->data['accounting']['stateLabel']);
        $this->assertSame('kontacia-it-print-inv.pdf', $sk->fileName);

        $de = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'de')->printData;
        $this->assertSame('Kontierung', $de->data['document']['title']);
        $this->assertSame('Ausgangsrechnung', $de->data['document']['typeName']);
        $this->assertSame('Gebucht', $de->data['accounting']['stateLabel']);
        $this->assertSame('kontierung-it-print-inv.pdf', $de->fileName);
    }

    public function testInvoiceOffersInvoiceAndJournalPrints(): void
    {
        $headId = $this->insertInvoice($this->expected('invoice'), $this->anyUnit()[0]);
        $record = $this->db->fetchRow('SELECT * FROM [docs_core_heads] WHERE [id] = %i', $headId);

        $this->assertSame(
            ['docs.invoicesOut.invoice', self::PRINT_ID],
            array_map(static fn ($d): string => $d->id, $this->registry->forRecord('docs_core_heads', $record)),
        );
    }

    public function testGeneralDocumentWithoutSnapshotsUsesCurrentOwnCompany(): void
    {
        $accounts = $this->anyAccounts(2);
        $headId   = $this->insertGeneralDocument();
        $this->insertJournal($headId, [
            ['account' => $accounts[0]['id'], 'account_number' => $accounts[0]['number'], 'text' => 'Mzdy', 'money_dr' => 5000],
            ['account' => $accounts[1]['id'], 'account_number' => $accounts[1]['number'], 'text' => 'Mzdy', 'money_cr' => 5000],
        ]);

        $output = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'cs');
        $data   = $output->printData->data;

        $this->assertSame('cmnbkp', $data['document']['type']);
        $this->assertNull($data['document']['tradeDir']);
        $this->assertSame('Kontace', $data['document']['title']);
        $this->assertArrayNotHasKey('titleVariant', $data['document']);
        $this->assertStringStartsWith('Kontace ' . $data['document']['typeName'], $output->printData->title);
        $this->assertSame('kontace-it-print-gen.pdf', $output->printData->fileName);

        // Doklad bez směru nemá snapshoty — účetní jednotka je vlastní firma z aktuálních dat.
        $ownName = $this->db->fetchSingle('SELECT [full_name] FROM [base_persons_persons] WHERE [is_own] = 1 LIMIT 1');
        if ($ownName === null) {
            $this->assertNull($data['accountingUnit']);
        } else {
            $this->assertSame((string) $ownName, $data['accountingUnit']['name']);
        }
        $this->assertNull($data['partner']);

        $this->assertSame([null, null], [$data['journal'][0]['debitCur'], $data['totals']['debitCur']], 'domácí měna');
        $this->assertSame([5000.0, null], [(float) $data['journal'][0]['debit'], $data['journal'][0]['credit']]);
        $this->assertSame(['debit' => 5000.0, 'credit' => 5000.0, 'debitCur' => null, 'creditCur' => null], $data['totals']);
        $this->assertSame([], $output->printData->messages);
    }

    public function testDocumentWithoutEntriesIsPrintedWithWarning(): void
    {
        $headId = $this->insertGeneralDocument();

        $output = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'cs');

        $this->assertSame([], $output->printData->data['journal']);
        $this->assertSame(['debit' => 0.0, 'credit' => 0.0, 'debitCur' => null, 'creditCur' => null], $output->printData->data['totals']);
        $this->assertSame(['noJournal'], array_map(static fn ($m): string => $m->code, $output->printData->messages));
    }

    public function testAccountingErrorIsPrintedWithWarningAndMarkedRow(): void
    {
        $accounts = $this->anyAccounts(1);
        $headId   = $this->insertGeneralDocument();
        $this->insertJournal($headId, [
            ['account' => $accounts[0]['id'], 'account_number' => $accounts[0]['number'], 'money_dr' => 5000],
            ['account' => null, 'account_number' => '???', 'text' => 'Účet nenalezen', 'money_cr' => 5000, 'is_error' => 1],
        ]);
        $this->db->getDibiConnection()->update('docs_core_heads', ['accounting_state' => 2])
            ->where('id = %i', $headId)->execute();

        $output = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'cs');
        $data   = $output->printData->data;

        $this->assertSame(2, $data['accounting']['state']);
        $this->assertSame([false, true], array_column($data['journal'], 'isError'));
        $this->assertNull($data['journal'][1]['accountName']);
        $this->assertSame(['accountingError'], array_map(static fn ($m): string => $m->code, $output->printData->messages));
        $this->assertStringContainsString($data['accounting']['stateLabel'], $output->printData->messages[0]->text);
    }

    public function testJournalDimensionsBecomeColumns(): void
    {
        $dimension = null;
        foreach (JournalDimensionSet::fromConfig(ConfigRuntime::load($this->dsConfig->getDataSourceDir(), 'cs')) as $candidate) {
            $dimension = $candidate;
            break;
        }
        if ($dimension === null) {
            $this->markTestSkipped('DS nemá žádnou dimenzi deníku.');
        }
        $valueId = $this->db->fetchSingle('SELECT [id] FROM %n ORDER BY [id] LIMIT 1', $dimension->table);
        if ($valueId === null) {
            $this->markTestSkipped("DS nemá záznam v tabulce dimenze '{$dimension->id}'.");
        }

        $accounts = $this->anyAccounts(2);
        $headId   = $this->insertGeneralDocument();
        $this->insertJournal($headId, [
            ['account' => $accounts[0]['id'], 'account_number' => $accounts[0]['number'], 'money_dr' => 5000,
             $dimension->journalColumn => (int) $valueId],
            ['account' => $accounts[1]['id'], 'account_number' => $accounts[1]['number'], 'money_cr' => 5000],
        ]);

        $data = $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'cs')->printData->data;

        $this->assertSame([['id' => $dimension->id, 'label' => $dimension->name]], $data['dimensions']);
        $this->assertIsString($data['journal'][0]['dimensions'][$dimension->id]);
        $this->assertNotSame('', $data['journal'][0]['dimensions'][$dimension->id]);
        $this->assertSame([$dimension->id => null], $data['journal'][1]['dimensions']);
    }

    public function testCancelledDocumentHasNoJournalPrint(): void
    {
        $headId = $this->insertInvoice($this->expected('invoice'), $this->anyUnit()[0], ['docState' => 30, 'docStateMain' => 4]);

        $this->expectException(PrintNotAvailableException::class);
        $this->runner->run(self::PRINT_ID, $headId, PrintFormat::Json, 'cs');
    }
}
