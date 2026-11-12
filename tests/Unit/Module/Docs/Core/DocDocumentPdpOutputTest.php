<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

/**
 * W4 z tasks/accounting-vat-analytics.md — PDP výstup (cz-150 a sourozenci)
 * nad REÁLNÝM vat-cz.jsonc, ne nad minimální testovací kopií. U přenesení
 * daňové povinnosti na výstupu daň odvádí zákazník: faktura je jen základ,
 * rekapitulace bez daně, total_vat = 0, total_amount = základ, žádný
 * oddaňovací pár.
 */
class DocDocumentPdpOutputTest extends TestCase
{
    private const VAT_CZ_PATH = __DIR__ . '/../../../../../modules/world/vat/config/vat-cz.jsonc';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_pdp_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }

    private function buildDocWithRealConfig(): TestableDocsHeadsDocument
    {
        $vatCz = JsoncParser::parseFile(self::VAT_CZ_PATH);
        $data = [
            '_meta' => ['language' => 'cs'],
            'items' => ['world.vat.cz' => $vatCz],
        ];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode($data),
        );

        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['country' => 'cz']));

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig(ConfigRuntime::load($this->tmpDir, 'cs'));
        return $doc;
    }

    /**
     * @return array<string, array{string, float}> code => [code, pct k 2026]
     */
    public static function pdpOutputCodes(): array
    {
        return [
            'cz-150 (základní PDP4)'       => ['cz-150', 21.0],
            'cz-151 (snížená PDP4)'        => ['cz-151', 12.0],
            'cz-152 (základní PDP5)'       => ['cz-152', 21.0],
            'cz-350 (první snížená PDP4)'  => ['cz-350', 15.0],
        ];
    }

    #[DataProvider('pdpOutputCodes')]
    public function testPdpOutputCodeProducesNoTax(string $code, float $pct): void
    {
        $doc = $this->buildDocWithRealConfig();
        $duzp = $code === 'cz-350' ? '2023-06-15' : '2026-06-10';
        $data = [
            'rows' => [
                // Kódy cz-152 / cz-117 zatím existují (ruší je až 5/7) — s předmětem 5.
                ['row_kind' => 1, 'vat_code' => $code, 'vat_pct' => $pct, 'total_price' => 1000.0,
                    'reverse_charge_code' => $code === 'cz-152' ? '5' : '4'],
            ],
            'vat_registration' => 1,
            'vat_duzp'         => $duzp,
            'exchange_rate'    => 1.0,
        ];
        $recap = $doc->buildVatRecapitulationPub($data);

        $this->assertCount(1, $recap, "Kód {$code} nesmí generovat oddaňovací pár");
        $this->assertSame($code, $recap[0]['vat_code']);
        $this->assertSame(
            $code === 'cz-152' ? '5' : '4',
            $recap[0]['reverse_charge_code'],
            "Kód {$code}: rekapitulace nese předmět plnění řádku",
        );
        $this->assertSame(1000.0, $recap[0]['base']);
        $this->assertSame(0.0, $recap[0]['tax'], "Kód {$code}: daň odvádí zákazník, recap musí mít tax = 0");
        $this->assertSame(1000.0, $recap[0]['total']);
        $this->assertSame(1, $recap[0]['sum_base']);
        $this->assertSame(0, $recap[0]['sum_tax'], "Kód {$code} musí mít sumTax = 0");
        $this->assertSame(1, $recap[0]['sum_total']);

        $doc->sumTotalsPub($data, $recap);

        $this->assertSame(1000.0, $data['total_base']);
        $this->assertSame(0.0, $data['total_vat'], "Kód {$code}: total_vat musí být 0");
        $this->assertSame(1000.0, $data['total_amount'], "Kód {$code}: total_amount = jen základ");
    }
}
