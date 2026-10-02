<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Prints;

use Shipard\Api\PrintDefinitionLoader;
use Shipard\Core\Config\RenderConfig;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintFormat;
use Shipard\Core\Prints\PrintRunner;
use Shipard\Core\Prints\PrintRunnerFactory;
use Shipard\Core\Render\RenderClient;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Celá cesta tisku do PDF proti živému Gotenbergu: fixture doklad →
 * builder → Twig → render služba. Mimo běžný běh — vedle
 * SHIPARD_INTEGRATION_DS_PATH potřebuje i SHIPARD_INTEGRATION_GOTENBERG_URL:
 *
 *   SHIPARD_INTEGRATION_DS_PATH=… SHIPARD_INTEGRATION_GOTENBERG_URL=http://127.0.0.1:3000 \
 *     vendor/bin/phpunit --testsuite Integration --filter PrintPdf
 */
class PrintPdfTest extends IntegrationTestCase
{
    use PrintFixtureDocuments;

    private PrintRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $url = getenv('SHIPARD_INTEGRATION_GOTENBERG_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped(
                'Set SHIPARD_INTEGRATION_GOTENBERG_URL to a running Gotenberg instance to run this test.',
            );
        }
        RenderClient::resetWarningForTesting();

        $modules = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->runner = PrintRunnerFactory::create(
            PrintDefinitionLoader::load($this->dsConfig, $modules, 'cs'),
            $this->dsConfig,
            $this->db,
            $modules,
            new RenderClient(RenderConfig::fromArray(['url' => $url])),
        );

        $this->prepareFixtureDocuments();
    }

    protected function onTearDown(): void
    {
        $this->deleteFixtureDocuments();
    }

    public function testInvoicePdf(): void
    {
        $expected = $this->expected('invoice');
        $headId   = $this->insertInvoice($expected, $this->anyUnit()[0]);

        $output = $this->runner->run('docs.invoicesOut.invoice', $headId, PrintFormat::Pdf, 'cs');

        $this->assertSame(PrintFormat::Pdf, $output->format);
        $this->assertStringStartsWith('%PDF', (string) $output->pdfContent);
        $this->assertSame('faktura-it-print-inv.pdf', $output->printData->fileName);

        $text = $this->pdfText((string) $output->pdfContent);
        $this->assertStringContainsString('IT-PRINT-INV', $text);
        $this->assertStringContainsString('Faktura – daňový doklad', $text);
        $this->assertStringContainsString('Tiskárna Vzorová s.r.o.', $text);
        $this->assertStringContainsString('Strana 1 / 1', $text);
    }

    public function testProformaPdf(): void
    {
        $headId = $this->insertProforma($this->expected('proforma'));

        $output = $this->runner->run('docs.proformasOut.proforma', $headId, PrintFormat::Pdf, 'cs');

        $this->assertStringStartsWith('%PDF', (string) $output->pdfContent);
        $text = $this->pdfText((string) $output->pdfContent);
        $this->assertStringContainsString('IT-PRINT-PRO', $text);
        $this->assertStringContainsString('Nejedná se o daňový doklad.', $text);
    }

    private function pdfText(string $pdf): string
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            $this->markTestSkipped('pdftotext (poppler-utils) is not installed.');
        }
        $file = tempnam(sys_get_temp_dir(), 'shpd_printpdf_');
        file_put_contents($file, $pdf);
        try {
            // Nezlomitelné mezery z filtrů → obyčejné, ať se text dá hledat.
            return str_replace("\u{00A0}", ' ', (string) shell_exec('pdftotext -layout ' . escapeshellarg($file) . ' -'));
        } finally {
            @unlink($file);
        }
    }
}
