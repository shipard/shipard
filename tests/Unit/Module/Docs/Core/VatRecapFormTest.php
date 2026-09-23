<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Docs\Core\VatRecapForm;

/**
 * Sub-formulář řádku převzaté rekapitulace DPH: nabídka kódů podle země,
 * směru a místa plnění hlavičky (sdílený `DocHeadVatContext` s řádkem
 * dokladu) a sazba jako **předvolba** — hodnota z dokladu je fakt, takže
 * ji formulář nesmí přepsat.
 */
class VatRecapFormTest extends TestCase
{
    private const VAT_CZ_PATH = __DIR__ . '/../../../../../modules/world/vat/config/vat-cz.jsonc';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_recapform_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode([
                '_meta' => ['language' => 'cs'],
                'items' => [
                    'world.vat.cz' => JsoncParser::parseFile(self::VAT_CZ_PATH),
                    'docs.core.docTypes' => JsoncParser::parseFile(
                        __DIR__ . '/../../../../../modules/docs/core/config/docTypes.jsonc',
                    ),
                    'core.system.formDefaults' => ['generalTabLabel' => 'Obecné'],
                ],
            ]),
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/config/configuration/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->tmpDir . '/config/configuration');
        @rmdir($this->tmpDir . '/config');
        @rmdir($this->tmpDir);
    }

    /** @param array<string, mixed> $head */
    private function form(array $head): VatRecapForm
    {
        $form = new VatRecapForm('docs_core_vat_recap');
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static function (mixed ...$args) use ($head): ?array {
                $sql = (string) ($args[0] ?? '');
                if (str_contains($sql, 'vat_registrations')) {
                    return ['country' => 'cz'];
                }
                return $head;
            },
        );
        $form->setDb($db);
        $form->setConfig(ConfigRuntime::load($this->tmpDir, 'cs'));
        return $form;
    }

    /** @return array<string, FormElement> */
    private function elements(FormDefinition $def): array
    {
        $out = [];
        foreach ($def->tabs as $tab) {
            foreach ($tab->sections as $section) {
                foreach ($section->columns as $col) {
                    foreach ($col->elements as $el) {
                        if ($el->column !== null) {
                            $out[$el->column] = $el;
                        }
                    }
                }
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function receivedInvoiceHead(): array
    {
        return [
            'vat_registration' => 1,
            'doc_type' => 'invni',
            'cash_dir' => 0,
            'vat_place' => 0,
            'vat_duzp' => '2026-05-06',
            'vat_mode' => 2,
            'doc_currency' => 'czk',
            'home_currency' => 'czk',
            'exchange_rate' => 1.0,
        ];
    }

    public function testFormHasAmountFieldsAndVatCodeOptions(): void
    {
        $form = $this->form($this->receivedInvoiceHead());
        $def = $form->buildFormDefinition(['doc_head' => 1], true);
        $els = $this->elements($def);

        $this->assertSame(
            ['vat_code', 'vat_pct', 'base', 'tax', 'total'],
            array_keys($els),
            'formulář obsahuje jen to, co je vstupem — _dom a sum_* dopočítá uložení',
        );
        $this->assertSame('select', $els['vat_code']->type);
        $this->assertNotEmpty($els['vat_code']->options);
        $codes = array_column($els['vat_code']->options, 'value');
        $this->assertContains('cz-110', $codes, 'vstupní tuzemské kódy pro přijatou fakturu');
    }

    public function testNewLineDefaultsRateFromCodebook(): void
    {
        $form = $this->form($this->receivedInvoiceHead());
        $data = ['doc_head' => 1];
        $form->applyNewRecordDefaults($data);

        $this->assertNotEmpty($data['vat_code']);
        $this->assertSame(21.0, $data['vat_pct'], 'sazba kódu k DUZP');
    }

    /** Nedaňový doklad DUZP nemá (#79 D1) — sazba se bere k datu vystavení. */
    public function testWithoutDuzpRateFallsBackToIssueDate(): void
    {
        $form = $this->form(['vat_duzp' => null, 'issue_date' => '2026-05-06'] + $this->receivedInvoiceHead());
        $data = ['doc_head' => 1];
        $form->applyNewRecordDefaults($data);

        $this->assertSame(21.0, $data['vat_pct'], 'sazba k datu vystavení, když DUZP chybí');
    }

    /** Bez DUZP i data vystavení (hlavička před uložením) zůstává sazba na uživateli. */
    public function testWithoutAnyRateDateNoRateIsDerived(): void
    {
        $form = $this->form(['vat_duzp' => null, 'issue_date' => null] + $this->receivedInvoiceHead());
        $data = ['doc_head' => 1];
        $form->applyNewRecordDefaults($data);

        $this->assertArrayNotHasKey('vat_pct', $data);
    }

    public function testRateFromDocumentIsNotOverwritten(): void
    {
        $form = $this->form($this->receivedInvoiceHead());
        // Historická sazba opsaná z dokladu — defaulty ji nesmí přepsat.
        $data = ['doc_head' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 20.0];
        $form->applyNewRecordDefaults($data);

        $this->assertSame(20.0, $data['vat_pct']);
    }

    public function testChangingCodePrefillsItsRate(): void
    {
        $form = $this->form($this->receivedInvoiceHead());
        $result = $form->recalculate('vat_code', [
            'doc_head' => 1, 'vat_code' => 'cz-111', 'vat_pct' => 21.0,
        ]);

        $this->assertSame(12.0, $result->data['vat_pct'], 'snížená sazba cz-111 k roku 2026');
    }
}
