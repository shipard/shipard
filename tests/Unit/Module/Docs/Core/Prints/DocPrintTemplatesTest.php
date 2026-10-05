<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\RenderConfig;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintCatalogLoader;
use Shipard\Core\Prints\PrintData;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintDocument;
use Shipard\Core\Prints\PrintRenderer;
use Shipard\Core\Prints\PrintRenderException;
use Shipard\Core\Prints\PrintTemplatePaths;
use Shipard\Core\Prints\Twig\PrintTwigFactory;
use Shipard\Core\Render\Engine\RenderEngineInterface;
use Shipard\Core\Render\PdfOptions;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Render\RenderErrorKind;
use Shipard\Core\Render\RenderResult;
use Shipard\Core\Settings\BrandingStorage;
use Shipard\Core\Utils\JsoncParser;

/**
 * Šablony faktury a zálohové faktury z repozitáře vykreslené do HTML nad
 * fixture `PrintData` (tests/Fixtures/Prints) — bez render služby. PDF
 * cesta jde přes fake engine, který zachytí, co by šlo do Gotenbergu.
 */
class DocPrintTemplatesTest extends TestCase
{
    private const NBSP = "\u{00A0}";

    private ?string $dsPath = null;

    protected function setUp(): void
    {
        RenderClient::resetWarningForTesting();
    }

    protected function tearDown(): void
    {
        if ($this->dsPath !== null) {
            foreach (glob($this->dsPath . '/branding/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dsPath . '/branding');
            @rmdir($this->dsPath);
        }
    }

    private static function modules(): ModulePathResolver
    {
        return new ModulePathResolver([dirname(__DIR__, 6) . '/modules']);
    }

    private static function definition(string $module, string $printId): PrintDefinition
    {
        $file = self::modules()->getPath($module) . '/config/prints.jsonc';
        foreach (JsoncParser::parseFile($file) as $raw) {
            if ($raw['id'] === $printId) {
                return PrintDefinition::fromArray(ConfigLocalizer::localize($raw, 'cs'), $module);
            }
        }
        self::fail("Print '{$printId}' is not declared in {$file}");
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed>|null $modify
     * @param array<string, string> $branding Vzhled z nastavení (`logoPlacement`, `accentColor`).
     */
    private static function printData(
        string $fixture,
        string $printId,
        string $language = 'cs',
        ?callable $modify = null,
        ?string $logo = null,
        ?string $watermark = null,
        array $branding = [],
    ): PrintData {
        $envelope = json_decode(
            (string) file_get_contents(dirname(__DIR__, 5) . '/Fixtures/Prints/' . $fixture . '.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if ($modify !== null) {
            $envelope['data'] = $modify($envelope['data']);
        }
        $envelope['printId']  = $printId;
        $envelope['language'] = $language;
        $envelope['branding'] = ['logo' => $logo] + $branding;
        $envelope['meta']['watermark'] = $watermark;
        $envelope['meta']['title'] = $envelope['data']['document']['title'] . ' ' . $envelope['data']['document']['number'];

        return PrintData::fromArray($envelope);
    }

    private function renderer(?RenderEngineInterface $engine = null, ?BrandingStorage $branding = null): PrintRenderer
    {
        $paths = new PrintTemplatePaths(self::modules());
        return new PrintRenderer(
            $paths,
            new PrintTwigFactory($paths),
            $engine === null
                ? new RenderClient(null)
                : new RenderClient(new RenderConfig('http://127.0.0.1:3000'), $engine),
            $branding,
        );
    }

    private function render(PrintDefinition $definition, PrintData $data, ?BrandingStorage $branding = null): PrintDocument
    {
        $paths = new PrintTemplatePaths(self::modules());
        return $this->renderer(branding: $branding)->renderDocument(
            $definition,
            $data,
            (new PrintCatalogLoader($paths))->translator($definition, $data->language),
        );
    }

    // ── faktura ─────────────────────────────────────────────────────────────

    public function testInvoiceOfVatPayerInForeignCurrencyWithAdvance(): void
    {
        $definition = self::definition('docs.invoicesOut', 'docs.invoicesOut.invoice');
        $document   = $this->render($definition, self::printData('invoice', $definition->id));
        $html       = $document->html;

        // Titulek a číslo jsou v záhlaví, na každé straně.
        $this->assertNotNull($document->header);
        $this->assertStringContainsString('Faktura – daňový doklad', $document->header);
        $this->assertStringContainsString('IT-PRINT-INV', $document->header);
        $this->assertStringNotContainsString('<img', $document->header, 'bez loga v brandingu');

        $this->assertStringContainsString('<link rel="stylesheet" href="doc-base.css">', $html);
        $this->assertArrayHasKey('doc-base.css', $document->assets);

        // Strany ze snapshotů
        $this->assertStringContainsString('Tiskárna Vzorová s.r.o.', $html);
        $this->assertStringContainsString("Dlouhá 1<br />\n760 01 Zlín", $html);
        $this->assertStringContainsString('IČ: 00000019', $html);
        $this->assertStringContainsString('DIČ: CZ00000019', $html);
        $this->assertStringContainsString('C 12345 vedená u Krajského soudu v Brně', $html);
        $this->assertStringContainsString('Odběratel Zkušební a.s.', $html);
        $this->assertStringContainsString('DIČ: SK2020000027', $html);

        // Data
        $this->assertStringContainsString('30.' . self::NBSP . '9.' . self::NBSP . '2026', $html);
        $this->assertStringContainsString('Datum zdanitelného plnění', $html);
        $this->assertStringContainsString('Období', $html);

        // Platba + QR
        $this->assertStringContainsString('Převodem', $html);
        $this->assertStringContainsString('CZ6508000000192000145399', $html);
        $this->assertStringContainsString('Konstantní symbol', $html);
        $this->assertStringNotContainsString('Specifický symbol', $html);
        $this->assertStringContainsString('<svg', $html);

        // Řádky: sloupce DPH, sleva, textový řádek, odlišený odpočet zálohy
        $this->assertStringContainsString('Sazba DPH', $html);
        $this->assertStringContainsString('sleva 10' . self::NBSP . '%', $html);
        $this->assertStringContainsString('<tr class="row-text">', $html);
        $this->assertStringContainsString('Děkujeme za spolupráci.', $html);
        $this->assertStringContainsString('<tr class="row-advance">', $html);
        $this->assertSame(2, substr_count($html, '<tr class="row-item">'));

        // Rekapitulace v cizí měně a součty
        $this->assertStringContainsString('Rekapitulace DPH', $html);
        $this->assertStringContainsString('Základ v CZK', $html);
        $this->assertStringContainsString('24' . self::NBSP . '500,00', $html);
        $this->assertStringContainsString('1 EUR = 24,5 CZK', $html);
        $this->assertStringContainsString('Uhrazeno zálohami', $html);
        $this->assertStringContainsString('1' . self::NBSP . '310,80' . self::NBSP . 'EUR', $html);
        $this->assertStringContainsString('-500,00' . self::NBSP . 'EUR', $html);
        $this->assertStringContainsString('810,80' . self::NBSP . 'EUR', $html);
        $this->assertStringContainsString('19' . self::NBSP . '864,60' . self::NBSP . 'CZK', $html);

        // Poznámka na doklad; interní poznámka v datech vůbec není.
        $this->assertStringContainsString('Děkujeme za včasnou úhradu.', $html);
        $this->assertStringNotContainsString('Nejedná se o daňový doklad', $html);

        // Zápatí: stránkování doplní render služba.
        $this->assertNotNull($document->footer);
        $this->assertStringContainsString('Strana <span class="pageNumber"></span> / <span class="totalPages"></span>', $document->footer);
        $this->assertStringContainsString('Tiskárna Vzorová s.r.o.', $document->footer);
    }

    public function testCancelledDocumentHasWatermarkAndOthersDoNot(): void
    {
        $definition = self::definition('docs.invoicesOut', 'docs.invoicesOut.invoice');

        $cancelled = $this->render($definition, self::printData('invoice', $definition->id, watermark: 'STORNO'));
        $this->assertStringContainsString('<div class="doc-watermark">STORNO</div>', $cancelled->html);
        $this->assertStringContainsString('.doc-watermark', $cancelled->assets['doc-base.css']);
        // Vodoznak je věc stránky — záhlaví a zápatí ho nenesou.
        $this->assertStringNotContainsString('STORNO', (string) $cancelled->header);

        $regular = $this->render($definition, self::printData('invoice', $definition->id));
        $this->assertStringNotContainsString('doc-watermark', $regular->html);
    }

    public function testMissingPartyIsLeftOutAndItsPlaceStaysEmpty(): void
    {
        $definition = self::definition('docs.invoicesOut', 'docs.invoicesOut.invoice');

        $noCustomer = $this->render($definition, self::printData('invoice', $definition->id, modify: static function (array $data): array {
            $data['customer'] = null;
            return $data;
        }));
        $this->assertStringContainsString('Dodavatel', $noCustomer->html);
        $this->assertStringNotContainsString('Odběratel', $noCustomer->html);
        $this->assertStringNotContainsString('party--customer', $noCustomer->html, 'prázdné místo bez rámečku');
        $this->assertSame(2, substr_count($noCustomer->html, '<div class="party'), 'sloupec strany zůstává');

        // Vstup bez partnera: dodavatel chybí, vlastní firma je odběratel — i v zápatí.
        $noSupplier = $this->render($definition, self::printData('invoice', $definition->id, modify: static function (array $data): array {
            $data['document']['tradeDir'] = 2;
            $data['supplier'] = null;
            return $data;
        }));
        $this->assertStringNotContainsString('Dodavatel', $noSupplier->html);
        $this->assertStringContainsString('Odběratel Zkušební a.s.', $noSupplier->html);
        $this->assertStringContainsString('Odběratel Zkušební a.s.', (string) $noSupplier->footer);
    }

    public function testInvoiceOfNonPayerHasNoVatColumnsOrRecap(): void
    {
        $definition = self::definition('docs.invoicesOut', 'docs.invoicesOut.invoice');
        $data = self::printData('invoice', $definition->id, modify: static function (array $data): array {
            $data['document'] = ['titleVariant' => 'invoiceNonVatPayer', 'title' => 'Faktura',
                'vatPayer' => false, 'vatMode' => 0] + $data['document'];
            $data['vatRecap'] = [];
            foreach ($data['rows'] as &$row) {
                if ($row['kind'] === 'item') {
                    $row['vat'] = null;
                }
            }
            return $data;
        });

        $document = $this->render($definition, $data);

        $this->assertStringContainsString('Faktura', (string) $document->header);
        $this->assertStringNotContainsString('daňový doklad', (string) $document->header);
        $this->assertStringNotContainsString('Sazba DPH', $document->html);
        $this->assertStringNotContainsString('Rekapitulace DPH', $document->html);
        $this->assertStringNotContainsString('Datum zdanitelného plnění', $document->html);
        $this->assertStringContainsString('<td colspan="5">', $document->html, 'textový řádek přes 5 sloupců');
        $this->assertStringContainsString('K úhradě', $document->html);
    }

    public function testEnglishInvoice(): void
    {
        $definition = self::definition('docs.invoicesOut', 'docs.invoicesOut.invoice');
        $document   = $this->render($definition, self::printData('invoice', $definition->id, 'en'));

        $this->assertStringContainsString('<html lang="en">', $document->html);
        $this->assertStringContainsString('Supplier', $document->html);
        $this->assertStringContainsString('Tax point date', $document->html);
        $this->assertStringContainsString('30' . self::NBSP . 'Sep' . self::NBSP . '2026', $document->html);
        $this->assertStringContainsString('Amount due', $document->html);
        $this->assertStringContainsString('810.80' . self::NBSP . 'EUR', $document->html);
        $this->assertStringContainsString('discount 10%', $document->html);
        $this->assertStringContainsString('Page <span class="pageNumber">', (string) $document->footer);
    }

    public function testSnapshotWithoutOptionalKeysStillRenders(): void
    {
        $definition = self::definition('docs.invoicesOut', 'docs.invoicesOut.invoice');
        $data = self::printData('invoice', $definition->id, modify: static function (array $data): array {
            // Starší / importovaný snapshot: bez display_block, bez kontaktu,
            // odběratel bez adresy, dodavatel bez účtu.
            $data['supplier'] = [
                'name' => 'Dodavatel bez účtu',
                'address' => ['street' => 'Úzká 5', 'city' => 'Brno', 'zip' => '602 00', 'display_block' => null],
            ];
            $data['customer'] = ['name' => 'Odběratel bez adresy'];
            $data['payment']['bankAccount'] = null;
            $data['payment']['qr'] = null;
            return $data;
        });

        $document = $this->render($definition, $data);

        $this->assertStringContainsString('Úzká 5', $document->html);
        $this->assertStringContainsString('602 00 Brno', $document->html);
        $this->assertStringContainsString('Odběratel bez adresy', $document->html);
        $this->assertStringNotContainsString('<svg', $document->html);
        $this->assertStringNotContainsString('IBAN', $document->html);
    }

    // ── zálohová faktura ────────────────────────────────────────────────────

    public function testProformaSaysItIsNotTaxDocument(): void
    {
        $definition = self::definition('docs.proformasOut', 'docs.proformasOut.proforma');
        $document   = $this->render($definition, self::printData('proforma', $definition->id));

        $this->assertStringContainsString('Zálohová faktura', (string) $document->header);
        $this->assertStringContainsString('IT-PRINT-PRO', (string) $document->header);
        $this->assertStringContainsString('Nejedná se o daňový doklad.', $document->html);
        $this->assertStringNotContainsString('Datum zdanitelného plnění', $document->html);
        $this->assertStringContainsString('Záloha na dodávku tiskovin', $document->html);
        $this->assertStringContainsString('<svg', $document->html);
        $this->assertStringContainsString('12' . self::NBSP . '100,00' . self::NBSP . 'CZK', $document->html);
        $this->assertStringNotContainsString('Uhrazeno zálohami', $document->html);
    }

    // ── logo ────────────────────────────────────────────────────────────────

    // ── pokladní doklad ─────────────────────────────────────────────────────

    public function testCashReceiptWithSale(): void
    {
        $definition = self::definition('docs.cashDocs', 'docs.cashDocs.cash');
        $document   = $this->render($definition, self::printData('cashInSale', $definition->id));
        $html       = $document->html;

        $this->assertStringContainsString('Příjmový pokladní doklad – daňový doklad', (string) $document->header);

        // Strany: kdo peníze přijal a kdo vydal.
        $this->assertStringContainsString('Dodavatel / přijal', $html);
        $this->assertStringContainsString('Odběratel / vydal', $html);
        $this->assertStringContainsString('Papírnictví U Brány s.r.o.', $html);

        // Data bez splatnosti, s DUZP, dnem přijetí platby a pokladnou.
        $this->assertStringNotContainsString('Datum splatnosti', $html);
        $this->assertStringContainsString('Datum zdanitelného plnění', $html);
        $this->assertStringContainsString('Datum přijetí platby', $html);
        $this->assertStringContainsString('29.' . self::NBSP . '9.' . self::NBSP . '2026', $html);
        $this->assertStringContainsString('<dt>Pokladna</dt>', $html);
        $this->assertStringContainsString('Hlavní pokladna', $html);

        // Platba: jen způsob úhrady — žádný účet ani QR.
        $this->assertStringContainsString('Hotovost', $html);
        $this->assertStringNotContainsString('Bankovní účet', $html);
        $this->assertStringNotContainsString('<svg', $html);

        // Řádky a součty s DPH; částka už je zaplacená — „Celkem“, ne „K úhradě“.
        $this->assertStringContainsString('Tonery do tiskárny', $html);
        $this->assertStringContainsString('Rekapitulace DPH', $html);
        $this->assertStringNotContainsString('K úhradě', $html);
        $this->assertStringContainsString('1' . self::NBSP . '210,00' . self::NBSP . 'CZK', $html);

        // Podpisy příjmového dokladu.
        $this->assertStringContainsString('<div class="doc-signature">Podpis</div>', $html);
        $this->assertStringContainsString('<div class="doc-signature">Podpis pokladníka</div>', $html);
        $this->assertStringNotContainsString('Podpis příjemce', $html);

        $this->assertStringContainsString('Tiskárna Vzorová s.r.o.', (string) $document->footer);
    }

    public function testCashReceiptPayingInvoiceHasNoVat(): void
    {
        $definition = self::definition('docs.cashDocs', 'docs.cashDocs.cash');
        $document   = $this->render($definition, self::printData('cashInPayment', $definition->id));

        $this->assertStringContainsString('Příjmový pokladní doklad', (string) $document->header);
        $this->assertStringNotContainsString('daňový doklad', (string) $document->header);
        $this->assertStringContainsString('Úhrada faktury 2026000123', $document->html);
        $this->assertStringNotContainsString('Rekapitulace DPH', $document->html);
    }

    public function testCashDisbursementWithoutPartner(): void
    {
        $definition = self::definition('docs.cashDocs', 'docs.cashDocs.cash');
        $document   = $this->render($definition, self::printData('cashOut', $definition->id));
        $html       = $document->html;

        $this->assertStringContainsString('Výdajový pokladní doklad', (string) $document->header);

        // Bez partnera: dodavatel chybí, vlastní firma je odběratel — i v zápatí.
        $this->assertStringNotContainsString('Dodavatel / přijal', $html);
        $this->assertStringContainsString('Odběratel / vydal', $html);
        $this->assertStringContainsString('Tiskárna Vzorová s.r.o.', $html);
        $this->assertStringContainsString('Tiskárna Vzorová s.r.o.', (string) $document->footer);

        $this->assertStringNotContainsString('Datum přijetí platby', $html);
        $this->assertStringNotContainsString('Datum zdanitelného plnění', $html, 'neplátce');

        // Podpisy výdajového dokladu.
        $this->assertStringContainsString('<div class="doc-signature">Podpis příjemce</div>', $html);
        $this->assertStringContainsString('<div class="doc-signature">Podpis pokladníka</div>', $html);
    }

    public function testEnglishCashDocument(): void
    {
        $definition = self::definition('docs.cashDocs', 'docs.cashDocs.cash');
        $document   = $this->render($definition, self::printData('cashOut', $definition->id, 'en'));

        $this->assertStringContainsString('Customer / paid by', $document->html);
        $this->assertStringContainsString('Cash desk', $document->html);
        $this->assertStringContainsString("Recipient's signature", html_entity_decode($document->html, ENT_QUOTES));
    }

    // ── prodejka ────────────────────────────────────────────────────────────

    public function testCashReceiptWithoutPartnerIsPaidOnTheSpot(): void
    {
        $definition = self::definition('docs.cashRegister', 'docs.cashRegister.receipt');
        $document   = $this->render($definition, self::printData('receiptCash', $definition->id));
        $html       = $document->html;

        $this->assertStringContainsString('Prodejka – daňový doklad', (string) $document->header);

        // Bez partnera: místo odběratele zůstává prázdné.
        $this->assertStringContainsString('Dodavatel', $html);
        $this->assertStringNotContainsString('Odběratel', $html);

        // Hotově: žádná splatnost, účet ani QR; částka je „Celkem“.
        $this->assertStringNotContainsString('Datum splatnosti', $html);
        $this->assertStringNotContainsString('Bankovní účet', $html);
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('K úhradě', $html);
        $this->assertStringContainsString('<dt>Pokladna</dt>', $html);
        $this->assertStringContainsString('Datum zdanitelného plnění', $html);
        $this->assertStringContainsString('Rekapitulace DPH', $html);
        $this->assertStringNotContainsString('doc-signature', $html, 'prodejka podpisy nemá');
    }

    public function testReceiptPaidByBankTransferLooksLikeInvoice(): void
    {
        $definition = self::definition('docs.cashRegister', 'docs.cashRegister.receipt');
        $html = $this->render($definition, self::printData('receiptTransfer', $definition->id))->html;

        $this->assertStringContainsString('Papírnictví U Brány s.r.o.', $html);
        $this->assertStringContainsString('Datum splatnosti', $html);
        $this->assertStringContainsString('14.' . self::NBSP . '10.' . self::NBSP . '2026', $html);
        $this->assertStringContainsString('CZ6508000000192000145399', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('K úhradě', $html);
    }

    public function testRefundHasOnlyDifferentTitleAndNegativeAmounts(): void
    {
        $definition = self::definition('docs.cashRegister', 'docs.cashRegister.receipt');
        $document   = $this->render($definition, self::printData('receiptRefund', $definition->id));

        $this->assertStringContainsString('Prodejka – vratka', (string) $document->header);
        $this->assertStringContainsString('-605,00' . self::NBSP . 'CZK', $document->html);
        $this->assertStringContainsString('<tr class="row-item">', $document->html);
    }

    // ── Kontace ─────────────────────────────────────────────────────────────

    public function testJournalOfInvoiceInForeignCurrency(): void
    {
        $definition = self::definition('economy.accounting', 'economy.accounting.docJournal');
        $document   = $this->render($definition, self::printData('docJournalInvoice', $definition->id));
        $html       = $document->html;

        // Záhlaví dokladů s titulkem Kontace a číslem dokladu, vlastní styly a zápatí.
        $this->assertStringContainsString('Kontace', (string) $document->header);
        $this->assertStringContainsString('IT-PRINT-INV', (string) $document->header);
        $this->assertStringContainsString('<link rel="stylesheet" href="doc-journal.css">', $html);
        $this->assertArrayHasKey('doc-journal.css', $document->assets);
        $this->assertArrayHasKey('doc-base.css', $document->assets);
        $this->assertStringContainsString('Tiskárna Vzorová s.r.o.', (string) $document->footer);

        // Hlavička: strany, typ dokladu, data, stav účtování, měna a kurz.
        $this->assertStringContainsString('Účetní jednotka', $html);
        $this->assertStringContainsString('<h2>Partner</h2>', $html);
        $this->assertStringContainsString('Odběratel Zkušební a.s.', $html);
        $this->assertStringContainsString('Faktura vydaná', $html);
        $this->assertStringContainsString('Účetní datum', $html);
        $this->assertStringContainsString('Datum zdanitelného plnění', $html);
        $this->assertStringContainsString('Zaúčtováno', $html);
        $this->assertStringContainsString('1 EUR = 24,5 CZK', $html);

        // Tabulka zápisů se sloupci v cizí měně a součtem.
        $this->assertStringContainsString('Název účtu', $html);
        $this->assertStringContainsString('<th class="col-num">MD EUR</th>', $html);
        $this->assertStringContainsString('<th class="col-num">Dal EUR</th>', $html);
        $this->assertSame(3, substr_count($html, '<tr class="row-item">'));
        $this->assertStringContainsString('311000', $html);
        $this->assertStringContainsString('Odběratelé', $html);
        $this->assertStringContainsString('19' . self::NBSP . '864,60', $html);
        $this->assertStringContainsString('810,80', $html);
        $this->assertStringContainsString('<tr class="row-total">', $html);
        $this->assertStringNotContainsString('row-error', $html);

        // Bloky dokladu, které Kontace netiskne.
        $this->assertStringNotContainsString('Způsob úhrady', $html);
        $this->assertStringNotContainsString('Rekapitulace DPH', $html);
        $this->assertStringNotContainsString('K úhradě', $html);
        $this->assertStringNotContainsString('Děkujeme za včasnou úhradu.', $html, 'poznámka na doklad');

        $this->assertStringContainsString('<div class="doc-signature">Zaúčtoval</div>', $html);
    }

    public function testJournalWithDimensionsErrorRowAndNoParties(): void
    {
        $definition = self::definition('economy.accounting', 'economy.accounting.docJournal');
        $data = self::printData('docJournalInvoice', $definition->id, modify: static function (array $data): array {
            // Účetní doklad v domácí měně, bez stran, s dimenzí a chybovým řádkem.
            $data['document'] = ['type' => 'cmnbkp', 'tradeDir' => null, 'typeName' => 'Účetní doklad',
                'currency' => 'CZK', 'exchangeRate' => null, 'foreignCurrency' => false] + $data['document'];
            $data['dates']['duzp'] = null;
            $data['accountingUnit'] = null;
            $data['partner'] = null;
            $data['accounting'] = ['state' => 2, 'stateLabel' => 'Chyba účtování'];
            $data['dimensions'] = [['id' => 'asset', 'label' => 'Majetek']];
            $data['journal'] = [
                ['accountNumber' => '022000', 'accountName' => 'Stroje', 'text' => 'Zařazení', 'debit' => 5000, 'credit' => null,
                 'debitCur' => null, 'creditCur' => null, 'dimensions' => ['asset' => 'M-0001 Tiskový stroj'], 'isError' => false],
                ['accountNumber' => '???', 'accountName' => null, 'text' => null, 'debit' => null, 'credit' => 5000,
                 'debitCur' => null, 'creditCur' => null, 'dimensions' => ['asset' => null], 'isError' => true],
            ];
            $data['totals'] = ['debit' => 5000, 'credit' => 5000, 'debitCur' => null, 'creditCur' => null];
            return $data;
        });

        $document = $this->render($definition, $data);
        $html     = $document->html;

        $this->assertStringNotContainsString('Účetní jednotka', $html);
        $this->assertStringNotContainsString('<h2>Partner</h2>', $html);
        $this->assertStringNotContainsString('party--customer', $html);
        $this->assertStringNotContainsString('Datum zdanitelného plnění', $html);
        $this->assertStringContainsString('Chyba účtování', $html);

        $this->assertStringContainsString('<th>Majetek</th>', $html);
        $this->assertStringContainsString('M-0001 Tiskový stroj', $html);
        $this->assertSame(1, substr_count($html, '<tr class="row-error">'));
        $this->assertStringNotContainsString('MD CZK', $html, 'domácí měna bez sloupců měny');
        $this->assertStringContainsString('.row-error', $document->assets['doc-journal.css']);
    }

    public function testJournalWithoutEntriesSaysSo(): void
    {
        $definition = self::definition('economy.accounting', 'economy.accounting.docJournal');
        $data = self::printData('docJournalInvoice', $definition->id, 'en', static function (array $data): array {
            $data['journal'] = [];
            $data['totals']  = ['debit' => 0, 'credit' => 0, 'debitCur' => 0, 'creditCur' => 0];
            return $data;
        });

        $html = $this->render($definition, $data)->html;

        $this->assertStringContainsString('The document has no accounting entries.', $html);
        $this->assertStringNotContainsString('row-total', $html);
        $this->assertStringContainsString('Accounting unit', $html);
        $this->assertStringContainsString('Accounted by', $html);
    }

    public function testLogoGoesToHeaderAsDataUriAndToAssets(): void
    {
        $this->dsPath = sys_get_temp_dir() . '/shpd_printtpl_' . uniqid('', true);
        mkdir($this->dsPath . '/branding', 0755, true);
        file_put_contents($this->dsPath . '/branding/companyLogo.png', 'PNGDATA');

        $definition = self::definition('docs.invoicesOut', 'docs.invoicesOut.invoice');
        $document   = $this->render(
            $definition,
            self::printData('invoice', $definition->id, logo: 'logo.png'),
            new BrandingStorage($this->dsPath),
        );

        $this->assertStringContainsString(
            '<img class="logo" src="data:image/png;base64,' . base64_encode('PNGDATA') . '"',
            (string) $document->header,
        );
        $this->assertSame('PNGDATA', $document->assets['logo.png']);
    }

    // ── vzhled z nastavení (#90 D46) ────────────────────────────────────────

    public function testDefaultAppearanceIsNeutralAccentWithLogoOnTheLeft(): void
    {
        $definition = self::definition('docs.invoicesOut', 'docs.invoicesOut.invoice');
        $header     = (string) $this->render($definition, self::printData('invoice', $definition->id))->header;

        $this->assertStringContainsString('<div class="head-inner" style="border-bottom-color: #c8c8c8">', $header);
        $this->assertStringContainsString('<div class="head-title" style="border-color: #c8c8c8">', $header);
    }

    public function testAccentColorsHeaderOnlyAndPageStaysUncoloured(): void
    {
        $this->dsPath = sys_get_temp_dir() . '/shpd_printtpl_' . uniqid('', true);
        mkdir($this->dsPath . '/branding', 0755, true);
        file_put_contents($this->dsPath . '/branding/companyLogo.png', 'PNGDATA');

        $definition = self::definition('docs.invoicesOut', 'docs.invoicesOut.invoice');
        $document   = $this->render(
            $definition,
            self::printData('invoice', $definition->id, logo: 'logo.png', branding: ['accentColor' => '#0a5c8f']),
            new BrandingStorage($this->dsPath),
        );
        $header = (string) $document->header;

        // Pruh u titulku, linka pod záhlavím a podklad loga.
        $this->assertStringContainsString('<div class="head-title" style="border-color: #0a5c8f">', $header);
        $this->assertStringContainsString('style="border-bottom-color: #0a5c8f"', $header);
        $this->assertMatchesRegularExpression('#<img class="logo" [^>]*style="background-color: \#0a5c8f">#', $header);
        // Barvy je potřeba tisknout i bez volby „tisk pozadí“.
        $this->assertStringContainsString('-webkit-print-color-adjust: exact', $header);

        // Akcent patří jen záhlaví — stránka ani zápatí ho nenesou.
        $this->assertStringNotContainsString('#0a5c8f', $document->html);
        $this->assertStringNotContainsString('#0a5c8f', (string) $document->footer);
        $this->assertStringNotContainsString('#0a5c8f', $document->assets['doc-base.css']);
    }

    public function testLogoPlacementSwapsLogoAndTitle(): void
    {
        $definition = self::definition('docs.invoicesOut', 'docs.invoicesOut.invoice');

        $left = (string) $this->render($definition, self::printData('invoice', $definition->id))->header;
        $this->assertStringNotContainsString('class="head-inner head-inner--logo-right"', $left);

        $right = (string) $this->render(
            $definition,
            self::printData('invoice', $definition->id, branding: ['logoPlacement' => 'right']),
        )->header;
        $this->assertStringContainsString('class="head-inner head-inner--logo-right"', $right);
        $this->assertStringContainsString('Faktura – daňový doklad', $right);
    }

    public function testInternalJournalPrintSharesHeaderAppearance(): void
    {
        $definition = self::definition('economy.accounting', 'economy.accounting.docJournal');
        $header     = (string) $this->render($definition, self::printData(
            'docJournalInvoice',
            $definition->id,
            branding: ['accentColor' => '#0a5c8f', 'logoPlacement' => 'right'],
        ))->header;

        $this->assertStringContainsString('head-inner--logo-right', $header);
        $this->assertStringContainsString('border-color: #0a5c8f', $header);
    }

    // ── PDF přes render klienta ─────────────────────────────────────────────

    public function testPdfRequestCarriesHeaderFooterMarginsAndAssets(): void
    {
        $engine     = new CapturingRenderEngine(RenderResult::success('%PDF-1.7 fake'));
        $definition = self::definition('docs.invoicesOut', 'docs.invoicesOut.invoice');
        $data       = self::printData('invoice', $definition->id);
        $translator = (new PrintCatalogLoader(new PrintTemplatePaths(self::modules())))->translator($definition, 'cs');

        $pdf = $this->renderer($engine)->renderPdf($definition, $data, $translator);

        $this->assertSame('%PDF-1.7 fake', $pdf);
        $this->assertStringContainsString('<main class="doc">', (string) $engine->html);
        $this->assertSame(['doc-base.css'], array_keys($engine->assets));

        $options = $engine->options;
        $this->assertInstanceOf(PdfOptions::class, $options);
        $this->assertSame('A4', $options->paperFormat);
        $this->assertSame('portrait', $options->orientation);
        $this->assertSame('3.2cm', $options->marginTop);
        $this->assertSame('2cm', $options->marginBottom);
        $this->assertSame('1.6cm', $options->marginLeft, 'výchozí okraj profilu Report');
        $this->assertSame('1.6cm', $options->marginRight);
        $this->assertTrue($options->printBackground);
        $this->assertStringContainsString('IT-PRINT-INV', (string) $options->headerTemplate);
        $this->assertStringContainsString('pageNumber', (string) $options->footerTemplate);
    }

    public function testRenderFailureBecomesPrintRenderException(): void
    {
        $definition = self::definition('docs.invoicesOut', 'docs.invoicesOut.invoice');
        $data       = self::printData('invoice', $definition->id);
        $translator = (new PrintCatalogLoader(new PrintTemplatePaths(self::modules())))->translator($definition, 'cs');

        try {
            $this->renderer()->renderPdf($definition, $data, $translator);
            $this->fail('PrintRenderException expected');
        } catch (PrintRenderException $e) {
            $this->assertSame(RenderErrorKind::Unconfigured, $e->errorKind);
            $this->assertTrue($e->isServiceUnavailable());
        }

        $engine = new CapturingRenderEngine(RenderResult::failure(RenderErrorKind::EngineError, 'HTTP 500'));
        try {
            $this->renderer($engine)->renderPdf($definition, $data, $translator);
            $this->fail('PrintRenderException expected');
        } catch (PrintRenderException $e) {
            $this->assertSame(RenderErrorKind::EngineError, $e->errorKind);
            $this->assertFalse($e->isServiceUnavailable());
            $this->assertSame('HTTP 500', $e->getMessage());
        }
    }
}

class CapturingRenderEngine implements RenderEngineInterface
{
    public ?string $html = null;
    /** @var array<string, string> */
    public array $assets = [];
    public ?PdfOptions $options = null;

    public function __construct(private readonly RenderResult $result)
    {
    }

    public function renderHtml(string $html, array $assets, PdfOptions $options, int $timeoutSec): RenderResult
    {
        $this->html    = $html;
        $this->assets  = $assets;
        $this->options = $options;
        return $this->result;
    }

    public function convertOffice(string $fileName, string $content, int $timeoutSec): RenderResult
    {
        return $this->result;
    }

    public function embedFiles(string $pdfContent, array $attachments, int $timeoutSec): RenderResult
    {
        return $this->result;
    }

    public function health(): bool
    {
        return true;
    }
}
