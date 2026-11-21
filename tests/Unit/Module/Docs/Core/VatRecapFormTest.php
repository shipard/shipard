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
            ['vat_rate_date', 'vat_code', 'reverse_charge_code', 'base', 'tax', 'total'],
            array_keys($els),
            'formulář obsahuje jen to, co je vstupem — sazbu, _dom a sum_* dopočítá uložení (#159 D1)',
        );
        $this->assertTrue($els['vat_rate_date']->hidden, 'datum řádku jen při zdroji „vlastní datum na řádcích“');
        $labels = array_column($els['vat_code']->options, 'label', 'value');
        $this->assertStringEndsWith(' — 21 %', $labels['cz-110'], 'popisek kódu nese procento');
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

    /** Sazba není vstup (#159 D1, D8): hodnotu z dat přepíše sazba kódu k datu sazby. */
    public function testRateIsAlwaysDerivedFromCodeAndRateDate(): void
    {
        $form = $this->form($this->receivedInvoiceHead());
        $data = ['doc_head' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 20.0];
        $form->applyNewRecordDefaults($data);
        $this->assertSame(21.0, $data['vat_pct']);

        // Jinou sazbu dodavatele nese datum sazby dokladu, ne přepsané číslo.
        $form = $this->form(['vat_rate_source' => 1, 'vat_rate_date' => '2012-12-31'] + $this->receivedInvoiceHead());
        $data = ['doc_head' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21.0];
        $form->applyNewRecordDefaults($data);
        $this->assertSame(20.0, $data['vat_pct']);
    }

    public function testLineRateDateFieldAndOptionsWithRowsSource(): void
    {
        $form = $this->form(['vat_rate_source' => 2] + $this->receivedInvoiceHead());
        $els = $this->elements($form->buildFormDefinition(
            ['doc_head' => 1, 'vat_code' => 'cz-110', 'vat_rate_date' => '2012-11-20'],
            false,
        ));

        $this->assertFalse($els['vat_rate_date']->hidden);
        $this->assertSame('reload', $els['vat_rate_date']->triggers);
        $labels = array_column($els['vat_code']->options, 'label', 'value');
        $this->assertStringEndsWith(' — 20 %', $labels['cz-110'], 'nabídka k datu řádku');

        $result = $form->recalculate('vat_rate_date', [
            'doc_head' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21.0, 'vat_rate_date' => '2012-11-20',
        ]);
        $this->assertSame(20.0, $result->data['vat_pct'], 'změna data řádku přepočte sazbu');
    }

    /** Opravný doklad (#92 D4): sazba kódu k datu plnění původního dokladu, ne k DUZP opravy. */
    public function testCorrectiveDocumentPrefillsRateAtOriginalTaxPointDate(): void
    {
        $form = $this->form(['corrective' => 1, 'corrected_doc_date' => '2012-06-01'] + $this->receivedInvoiceHead());
        $result = $form->recalculate('vat_code', ['doc_head' => 1, 'vat_code' => 'cz-110']);
        $this->assertSame(20.0, $result->data['vat_pct'], 'cz-110 mělo v roce 2012 sazbu 20 %');

        $form = $this->form(['corrective' => 1, 'corrected_doc_date' => null] + $this->receivedInvoiceHead());
        $result = $form->recalculate('vat_code', ['doc_head' => 1, 'vat_code' => 'cz-110']);
        $this->assertSame(21.0, $result->data['vat_pct'], 'bez data původního plnění sazba k DUZP');

        $form = $this->form(['corrective' => 0, 'corrected_doc_date' => '2012-06-01'] + $this->receivedInvoiceHead());
        $result = $form->recalculate('vat_code', ['doc_head' => 1, 'vat_code' => 'cz-110']);
        $this->assertSame(21.0, $result->data['vat_pct'], 'běžný doklad beze změny');
    }

    public function testChangingCodePrefillsItsRate(): void
    {
        $form = $this->form($this->receivedInvoiceHead());
        $result = $form->recalculate('vat_code', [
            'doc_head' => 1, 'vat_code' => 'cz-111', 'vat_pct' => 21.0,
        ]);

        $this->assertSame(12.0, $result->data['vat_pct'], 'snížená sazba cz-111 k roku 2026');
    }


    // ── Předmět plnění tuzemského přenesení (#89 D14) ─────────────────────

    public function testSubjectFieldFollowsReverseChargeCode(): void
    {
        $form = $this->form($this->receivedInvoiceHead());

        $els = $this->elements($form->buildFormDefinition(['doc_head' => 1, 'vat_code' => 'cz-115'], false));
        $this->assertFalse($els['reverse_charge_code']->hidden);
        $this->assertTrue($els['reverse_charge_code']->required);
        $this->assertContains('4a', array_column($els['reverse_charge_code']->options, 'value'));

        $els = $this->elements($form->buildFormDefinition(['doc_head' => 1, 'vat_code' => 'cz-110'], false));
        $this->assertTrue($els['reverse_charge_code']->hidden);
        $this->assertFalse($els['reverse_charge_code']->required);

        $result = $form->recalculate('vat_code', [
            'doc_head' => 1, 'vat_code' => 'cz-110', 'reverse_charge_code' => '4',
        ]);
        $this->assertNull($result->data['reverse_charge_code']);
    }

    /** „Mimo DPH“ do rekapitulace nepatří (#164 U6) — formulář řádku rekapitulace ho nenabízí. */
    public function testOutOfScopeCodeIsNotOfferedForRecapLine(): void
    {
        $form = $this->form($this->receivedInvoiceHead());
        $def = $form->buildFormDefinition(['doc_head' => 1], true);
        $codes = array_column($this->elements($def)['vat_code']->options, 'value');

        $this->assertNotContains('cz-000', $codes);
        $this->assertContains('cz-110', $codes);

        $data = ['doc_head' => 1];
        $form->applyNewRecordDefaults($data);
        $this->assertSame($codes[0], $data['vat_code']);
    }
}
