<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Docs\Core\NumberSeriesForm;

/** Pole Kód řady ve formuláři číselné řady (#166 D17, U6). */
class NumberSeriesFormCodeTest extends TestCase
{
    use NumberSeriesDbTrait;

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode(['_meta' => ['language' => 'cs'], 'items' => ['docs.core.docTypes' => [
                'invno' => ['name' => 'Faktura vydaná', 'doc_number_pattern_default' => '%D%y%4'],
                'invni' => ['name' => 'Faktura přijatá', 'doc_number_pattern_default' => '%D%y%4'],
                'cash'  => ['name' => 'Pokladní doklad', 'series_binding' => 'cash_desk', 'doc_number_pattern_default' => '%D%C%y%5'],
            ]]]),
        );
    }

    protected function tearDown(): void
    {
        unlink($this->tmpDir . '/config/configuration/compiled.cs.json');
        rmdir($this->tmpDir . '/config/configuration');
        rmdir($this->tmpDir . '/config');
        rmdir($this->tmpDir);
    }

    /** Formulář nad zdrojem dat s výchozí řadou vydaných faktur a pokladnou HL. */
    private function form(): NumberSeriesForm
    {
        $dibi = $this->seriesDb(
            [['id' => 1, 'doc_type' => 'invno', 'code' => 'invno', 'name' => 'Faktury vydané', 'docState' => 40]],
            ['economy_codebooks_cash_desks' => [4 => 'HL']],
        );
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getDibiConnection')->willReturn($dibi);
        $db->method('fetchRow')->willReturnCallback(
            static fn (string $sql): ?array => str_contains($sql, 'economy_codebooks_cash_desks') ? ['code' => 'HL'] : null,
        );

        $form = new NumberSeriesForm('docs_core_number_series');
        $form->setDb($db);
        $form->setConfig(ConfigRuntime::load($this->tmpDir, 'cs'));
        return $form;
    }

    private function codeElement(NumberSeriesForm $form, array $data, bool $isNew): FormElement
    {
        $first = $form->buildFormDefinition($data, $isNew)->tabs[0]->sections[0];
        foreach ($first->columns as $column) {
            foreach ($column->elements as $i => $element) {
                if ($element->column === 'code') {
                    $this->assertSame('name', $column->elements[$i - 1]->column, 'kód je hned za názvem');
                    return $element;
                }
            }
        }
        $this->fail('Pole code v první sekci formuláře není');
    }

    public function testCodeFieldSitsNextToTheNameWithAHint(): void
    {
        $element = $this->codeElement($this->form(), [], true);

        $this->assertSame('input', $element->type);
        $this->assertFalse($element->required, 'prázdný kód doplní Document');
        $this->assertNotSame('', (string) $element->hint);
    }

    public function testCodeIsEditableInReadOnlyState(): void
    {
        $this->assertSame(['code'], $this->form()->getReadOnlyEditableColumns());
    }

    public function testChoosingDocTypeSuggestsTheCode(): void
    {
        $form = $this->form();

        $next = $form->recalculate('doc_type', ['doc_type' => 'invno'])->data;
        $this->assertSame('invno-2', $next['code'], 'typ už výchozí řadu má');

        $first = $form->recalculate('doc_type', ['doc_type' => 'invni'])->data;
        $this->assertSame('invni', $first['code'], 'první řada typu');
    }

    public function testChangingDocTypeReplacesThePreviousSuggestion(): void
    {
        $data = $this->form()->recalculate('doc_type', ['doc_type' => 'invni', 'code' => 'invno-2'])->data;

        $this->assertSame('invni', $data['code'], 'kód předchozího typu nezůstane');
    }

    public function testBoundTypeWaitsForTheEntity(): void
    {
        $form = $this->form();

        $data = $form->recalculate('doc_type', ['doc_type' => 'cash', 'code' => 'invno-2'])->data;
        $this->assertNull($data['code'], 'vázaný typ bez pokladny návrh nemá');

        $data = $form->recalculate('cash_desk', ['doc_type' => 'cash', 'cash_desk' => 4] + $data)->data;
        $this->assertSame('cash-HL', $data['code']);
        $this->assertSame('HL', $data['doc_number_code'], 'kód v čísle dokladu se předvyplňuje jako dřív');
    }

    public function testOtherColumnsDoNotTouchTheCode(): void
    {
        $data = $this->form()->recalculate('reset_scope', ['doc_type' => 'invno', 'code' => 'moje'])->data;

        $this->assertSame('moje', $data['code']);
    }

    public function testExistingSeriesKeepsItsCodeOnRecalculate(): void
    {
        $data = $this->form()->recalculate('doc_type', ['id' => 1, 'doc_type' => 'invno', 'code' => 'invno'])->data;

        $this->assertSame('invno', $data['code']);
    }

    public function testNewRecordDefaultsSuggestCodeOnlyWithDocType(): void
    {
        $form = $this->form();

        $data = [];
        $form->applyNewRecordDefaults($data);
        $this->assertArrayNotHasKey('code', $data, 'bez typu dokladu není z čeho kód odvodit');

        $data = ['doc_type' => 'invni'];
        $form->applyNewRecordDefaults($data);
        $this->assertSame('invni', $data['code']);

        $data = ['doc_type' => 'invni', 'code' => 'moje'];
        $form->applyNewRecordDefaults($data);
        $this->assertSame('moje', $data['code'], 'vyplněný kód se nepřepisuje');
    }
}
