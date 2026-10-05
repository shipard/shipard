<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Prints;

use Shipard\Api\PrintDefinitionLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintData;
use Shipard\Core\Prints\PrintFormat;
use Shipard\Core\Prints\PrintRunner;
use Shipard\Core\Prints\PrintRunnerFactory;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Texty na tiscích nad skutečnou databází (#90 D47–D52): výběr podle tisku,
 * typu dokladu, číselné řady, jazyka, stavu a platnosti ke dni tisku —
 * a vykreslení do slotů obálky. Všechno běží v transakci; texty, které na
 * zdroji dat už jsou, se v ní na dobu testu vypnou.
 */
class PrintTextsTest extends IntegrationTestCase
{
    use PrintFixtureDocuments;

    private const TABLE   = 'core_prints_texts';
    private const INVOICE = 'docs.invoicesOut.invoice';
    private const CASH    = 'docs.cashDocs.cash';

    private PrintRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        if (!isset($this->tables[self::TABLE])) {
            $this->markTestSkipped('DS nemá modul core.prints s texty na tiscích.');
        }

        // Vše od této chvíle vrátí rollback v onTearDown.
        $this->db->begin();
        $this->db->execute('UPDATE %n SET [docState] = 10', self::TABLE);
        $this->prepareFixtureDocuments();

        $modules = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->runner = PrintRunnerFactory::create(
            PrintDefinitionLoader::load($this->dsConfig, $modules, 'cs'),
            $this->dsConfig,
            $this->db,
            $modules,
        );
    }

    protected function onTearDown(): void
    {
        $this->db->rollback();
    }

    /** @param array<string, mixed> $overrides */
    private function insertText(string $text, array $overrides = []): int
    {
        foreach (['prints', 'doc_types', 'number_series'] as $column) {
            if (is_array($overrides[$column] ?? null)) {
                $overrides[$column] = json_encode($overrides[$column]);
            }
        }
        $dibi = $this->db->getDibiConnection();
        $dibi->insert(self::TABLE, $overrides + [
            'name'         => 'IT text',
            'slot'         => 'afterRows',
            'text'         => $text,
            'order_pos'    => 0,
            'docState'     => 40,
            'docStateMain' => 2,
        ])->execute();
        return (int) $dibi->getInsertId();
    }

    private function invoice(): int
    {
        return $this->insertInvoice($this->expected('invoice'), $this->anyUnit()[0]);
    }

    private function printData(string $printId, int $headId, ?string $language = 'cs'): PrintData
    {
        return $this->runner->run($printId, $headId, PrintFormat::Json, $language)->printData;
    }

    private static function day(string $modifier): string
    {
        return (new \DateTimeImmutable('today'))->modify($modifier)->format('Y-m-d');
    }

    public function testTextForInvoiceAndItsSeriesIsOnThatInvoiceAndNotOnCashDocument(): void
    {
        $invoiceId = $this->invoice();
        $series    = (int) $this->db->fetchSingle('SELECT [number_series] FROM [docs_core_heads] WHERE [id] = %i', $invoiceId);
        $cashId    = $this->insertCashSale($this->expected('cashInSale'), $this->anyCashDesk()['id']);

        $this->insertText('Jen faktury této řady: **{{ data.document.number }}**', [
            'prints' => [self::INVOICE], 'doc_types' => ['invno'], 'number_series' => [$series],
        ]);
        $this->insertText('Jiná řada', ['doc_types' => ['invno'], 'number_series' => [$series + 100000]]);
        $this->insertText('Jen pokladní doklady', ['doc_types' => ['cash']]);

        $invoice = $this->printData(self::INVOICE, $invoiceId);
        $this->assertSame(
            ['afterRows' => '<div class="print-text"><p>Jen faktury této řady: <strong>IT-PRINT-INV</strong></p></div>'],
            $invoice->texts,
        );
        $this->assertSame([], $invoice->messages);

        $cash = $this->printData(self::CASH, $cashId);
        $this->assertSame(
            ['afterRows' => '<div class="print-text"><p>Jen pokladní doklady</p></div>'],
            $cash->texts,
        );
    }

    public function testValidityIsCountedToPrintDayIncludingBothEnds(): void
    {
        $invoiceId = $this->invoice();
        // Doklad je ze září 2026 — rozhoduje dnešek, ne datum dokladu.
        $this->insertText('skončil včera', ['valid_to' => self::day('-1 day'), 'order_pos' => 1]);
        $this->insertText('začne zítra', ['valid_from' => self::day('+1 day'), 'order_pos' => 2]);
        $this->insertText('jen dnes', ['valid_from' => self::day('today'), 'valid_to' => self::day('today'), 'order_pos' => 3]);
        $this->insertText('od dneška', ['valid_from' => self::day('today'), 'order_pos' => 4]);
        $this->insertText('do dneška', ['valid_to' => self::day('today'), 'order_pos' => 5]);

        $html = $this->printData(self::INVOICE, $invoiceId)->texts['afterRows'];

        $this->assertStringNotContainsString('skončil včera', $html);
        $this->assertStringNotContainsString('začne zítra', $html);
        $this->assertSame(
            ['jen dnes', 'od dneška', 'do dneška'],
            array_map('strip_tags', explode("\n", $html)),
        );
    }

    public function testOnlyConfirmedTextsInPrintLanguageAndOrder(): void
    {
        $invoiceId = $this->invoice();
        $this->insertText('koncept', ['docState' => 10, 'docStateMain' => 1]);
        $this->insertText('v opravě', ['docState' => 80, 'docStateMain' => 2]);
        $this->insertText('v archivu', ['docState' => 70, 'docStateMain' => 4]);
        $this->insertText('druhý', ['order_pos' => 20]);
        $this->insertText('první', ['order_pos' => 10]);
        $this->insertText('nur deutsch', ['language' => 'de', 'order_pos' => 30]);
        $this->insertText('jen česky', ['language' => 'cs', 'order_pos' => 40]);

        $czech = $this->printData(self::INVOICE, $invoiceId, 'cs')->texts['afterRows'];
        $this->assertSame(['první', 'druhý', 'jen česky'], array_map('strip_tags', explode("\n", $czech)));

        $german = $this->printData(self::INVOICE, $invoiceId, 'de')->texts['afterRows'];
        $this->assertSame(['první', 'druhý', 'nur deutsch'], array_map('strip_tags', explode("\n", $german)));
    }

    public function testEachSlotGetsItsTextsAndBrokenTextIsLeftOut(): void
    {
        $invoiceId = $this->invoice();
        $this->insertText('nahoře', ['slot' => 'header']);
        $this->insertText('před řádky', ['slot' => 'beforeRows']);
        $this->insertText('na konci', ['slot' => 'footer']);
        $broken = $this->insertText('{{ data.document.neexistuje }}', ['slot' => 'footer']);
        $this->insertText('Faktura {{ data.document.number }}', ['slot' => 'emailSubject']);
        $this->insertText("Dobrý den,\n\nposíláme fakturu.", ['slot' => 'emailBody']);

        $data = $this->printData(self::INVOICE, $invoiceId);

        $this->assertSame([
            'header'       => '<div class="print-text"><p>nahoře</p></div>',
            'beforeRows'   => '<div class="print-text"><p>před řádky</p></div>',
            'footer'       => '<div class="print-text"><p>na konci</p></div>',
            'emailSubject' => 'Faktura IT-PRINT-INV',
            'emailBody'    => "Dobrý den,\n\nposíláme fakturu.",
        ], $data->texts);
        $this->assertCount(1, $data->messages);
        $this->assertSame('textError', $data->messages[0]->code);
        $this->assertStringContainsString("č. {$broken} ", $data->messages[0]->text);
    }

    public function testJournalPrintCarriesNoUserTexts(): void
    {
        $invoiceId = $this->invoice();
        $this->insertText('všude', ['slot' => 'footer']);

        $this->assertSame([], $this->printData('economy.accounting.docJournal', $invoiceId)->texts);
    }

    public function testHtmlPageCarriesTextAtItsSlot(): void
    {
        $invoiceId = $this->invoice();
        $this->insertText('Příští týden máme **dovolenou**.');

        $html = $this->runner->run(self::INVOICE, $invoiceId, PrintFormat::Html, 'cs')->document?->html ?? '';

        $text = '<div class="print-text"><p>Příští týden máme <strong>dovolenou</strong>.</p></div>';
        $this->assertStringContainsString($text, $html);
        // Pod řádky, nad součty.
        $this->assertGreaterThan(strpos($html, '<table class="doc-rows">'), strpos($html, $text));
        $this->assertLessThan(strpos($html, 'doc-summary'), strpos($html, $text));
    }
}
