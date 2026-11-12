<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat\Xml;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Core\Version;
use Shipard\Module\Economy\Vat\Xml\EpoXsdValidator;
use Shipard\Module\Economy\Vat\Xml\FilingPeriod;
use Shipard\Module\Economy\Vat\Xml\FilingXmlInput;
use Shipard\Module\Economy\Vat\Xml\FilingXmlValidator;
use Shipard\Module\Economy\Vat\Xml\Kh1XmlWriter;
use Shipard\Module\Economy\Vat\Xml\VatXmlMapping;

/**
 * Generátor XML kontrolního hlášení (issue #55, Fáze 3). Golden soubor
 * drží tvar, oficiální XSD z repozitáře přijatelnost.
 */
class Kh1XmlWriterTest extends TestCase
{
    private const CONFIG   = __DIR__ . '/../../../../../../modules/economy/vat/config/vat-xml-cz.jsonc';
    /** Číselník Země daňového portálu — název státu do věty P (#55 F3-3). */
    private const COUNTRIES = __DIR__ . '/../../../../../../modules/world/cz/config/epoCountries.jsonc';
    private const FIXTURES = __DIR__ . '/../../../../../Fixtures/vat-xml/synthetic';

    private const HEADER = [
        'typ_ds'    => 'P',
        'dic'       => '12345678',
        'zkrobchjm' => 'Ukázka s.r.o.',
        'c_ufo'     => '464',
        'c_pracufo' => '3305',
        'email'     => 'ucto@example.com',
    ];

    public function testFullStatementMatchesGoldenFile(): void
    {
        $path = self::FIXTURES . '/kh1-regular.xml';
        $this->assertFileExists($path);
        $this->assertSame(
            str_replace('@version@', Version::VERSION, (string) file_get_contents($path)),
            $this->write($this->input()),
        );
    }

    public function testGeneratedFileValidatesAgainstTheOfficialSchema(): void
    {
        $this->assertSame([], (new EpoXsdValidator())->validate($this->write($this->input()), 'cs'));
    }

    // ── Sekce a řádky ───────────────────────────────────────────────────────

    public function testSectionsKeepSchemaOrderAndRowOrder(): void
    {
        preg_match_all('/<(Veta[A-D][0-9]?)/', $this->write($this->input()), $matches);
        $this->assertSame(
            ['VetaD', 'VetaA1', 'VetaA2', 'VetaA4', 'VetaA5', 'VetaB2', 'VetaB3', 'VetaC'],
            $matches[1],
        );
    }

    public function testDetailRowsCarryPartnerIdentification(): void
    {
        $xml = $this->write($this->input());

        // Tuzemské DIČ jen číslicemi, EU rozdělené na kód státu a číslo.
        $this->assertStringContainsString('dic_odb="87654321"', $xml);
        $this->assertStringContainsString('k_stat="DE" vatid_dod="123456789"', $xml);
        $this->assertStringContainsString('c_evid_dd="FV-1"', $xml);
    }

    /** A.1 a B.1 vykazují DUZP, ostatní sekce DPPD (#55 X12). */
    public function testReverseChargeSectionUsesDuzpAttribute(): void
    {
        $xml = $this->write($this->input());
        $this->assertStringContainsString('duzp="10.04.2026"', $xml);
        $this->assertStringContainsString('dppd="12.04.2026"', $xml);
    }

    /** Agregáty A.5 a B.3 nesou jen pásma, žádný doklad. */
    public function testAggregateRowsCarryOnlyBands(): void
    {
        preg_match('/<VetaA5[^>]*>/', $this->write($this->input()), $m);
        $this->assertSame('<VetaA5 zakl_dane1="3000.00" dan1="630.00"/>', $m[0]);
    }

    /** Hlášení se podává na haléře — hodnoty se nezaokrouhlují. */
    public function testValuesKeepHellers(): void
    {
        $xml = $this->write($this->input([
            'rows' => [$this->row('A4', ['base1' => 1234.56, 'tax1' => 259.26])],
        ]));
        $this->assertStringContainsString('zakl_dane1="1234.56" dan1="259.26"', $xml);
    }

    /**
     * Povinné atributy, na které snapshot nemá sloupec, jdou z konstant
     * (X10) — zvláštní režimy a nedobytné pohledávky jsou mimo M1.
     */
    public function testRequiredAttributesOutsideTheSnapshotComeFromConstants(): void
    {
        $xml = $this->write($this->input());
        $this->assertStringContainsString('kod_rezim_pl="0" zdph_44="N"', $xml, 'A.4');
        $this->assertStringContainsString('pomer="N" zdph_44="N"', $xml, 'B.2');
    }

    /** Nulový základ v A.1 se musí vypsat — schéma ho vyžaduje. */
    public function testRequiredBandIsWrittenEvenWhenZero(): void
    {
        $xml = $this->write($this->input([
            'rows' => [$this->row('A1', ['base1' => 0.0, 'reverse_charge_code' => '4'])],
        ]));
        $this->assertStringContainsString('zakl_dane1="0.00"', $xml);
        $this->assertSame([], (new EpoXsdValidator())->validate($xml, 'cs'));
    }

    // ── Věta C ──────────────────────────────────────────────────────────────

    public function testControlTotalsComeFromReturnRowsOfTheSameSnapshot(): void
    {
        $xml = $this->write($this->input());

        // obrat23 = ř. 1, pln23 = ř. 40, pln_rez_pren = ř. 25,
        // celk_zd_a2 = Σ ř. 3, 4, 5, 6, 9, 12, 13.
        $this->assertStringContainsString('obrat23="23000.00"', $xml);
        $this->assertStringContainsString('pln23="15500.00"', $xml);
        $this->assertStringContainsString('pln_rez_pren="50000.00"', $xml);
        $this->assertStringContainsString('celk_zd_a2="1300.00"', $xml, 'ř. 3 + ř. 5');
    }

    public function testControlTotalsAreSkippedWithoutReturnBase(): void
    {
        $xml = $this->write($this->input(['returnBase' => []]));
        $this->assertStringNotContainsString('<VetaC', $xml);
    }

    // ── Věta D ──────────────────────────────────────────────────────────────

    public function testFormaAndDateFoundFollowTheKind(): void
    {
        $regular = $this->write($this->input());
        $this->assertStringContainsString('khdph_forma="B"', $regular);
        $this->assertStringNotContainsString('d_zjist', $regular);

        $subsequent = $this->write($this->input([
            'kind'      => 'subsequent',
            'previous'  => 'regular',
            'dateFound' => '2026-06-01',
        ]));
        $this->assertStringContainsString('khdph_forma="N"', $subsequent);
        $this->assertStringContainsString('d_zjist="01.06.2026"', $subsequent);

        $correctiveAfterSubsequent = $this->write($this->input([
            'kind'      => 'corrective',
            'previous'  => 'subsequent',
            'dateFound' => '2026-06-05',
        ]));
        $this->assertStringContainsString('khdph_forma="E"', $correctiveAfterSubsequent);
    }

    /** Věta D kontrolního hlášení nemá kód činnosti ani „vznikla povinnost". */
    public function testHeaderHasNoReturnOnlyFields(): void
    {
        $xml = $this->write($this->input());
        $this->assertStringNotContainsString('c_okec', $xml);
        $this->assertStringNotContainsString('trans=', $xml);
        $this->assertStringNotContainsString('typ_platce', $xml);
    }

    // ── Validace řádků (#55 X10) ────────────────────────────────────────────

    public function testMissingReverseChargeCodeIsRejected(): void
    {
        $errors = $this->validate($this->input([
            'rows' => [$this->row('A1', ['reverse_charge_code' => null])],
        ]));

        $this->assertCount(1, $errors);
        $this->assertSame('incomplete_row', $errors[0]->code);
        $this->assertStringContainsString('kód předmětu plnění', $errors[0]->message);
    }

    /** Kód předmětu plnění je řetězec až do XML (#89 D13) — `4a` projde i schématem portálu. */
    public function testSubjectWithLetterIsWrittenAsIsAndValidates(): void
    {
        $input = $this->input([
            'rows' => [
                $this->row('A1', ['reverse_charge_code' => '4a']),
                $this->row('B1', ['reverse_charge_code' => '14', 'doc_number' => 'DOD-7', 'tax1' => 210.0]),
            ],
        ]);

        $this->assertSame([], $this->validate($input));
        $xml = $this->write($input);
        $this->assertStringContainsString('kod_pred_pl="4a"', $xml);
        $this->assertStringContainsString('kod_pred_pl="14"', $xml);
        $this->assertSame([], (new EpoXsdValidator())->validate($xml, 'cs'));
    }

    public function testMissingPartnerVatIdAndNumberAreReported(): void
    {
        $errors = $this->validate($this->input([
            'rows' => [$this->row('B2', ['partner_vat_id' => '', 'doc_number' => ''])],
        ]));

        $messages = implode(' | ', array_map(static fn (object $e): string => $e->message, $errors));
        $this->assertStringContainsString('DIČ protistrany', $messages);
        $this->assertStringContainsString('evidenční číslo dokladu', $messages);
    }

    public function testAggregateRowsHaveNothingRequired(): void
    {
        $this->assertSame([], $this->validate($this->input([
            'rows' => [$this->row('A5', ['doc_number' => '', 'partner_vat_id' => '', 'vat_dppd' => null])],
        ])));
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    private function mapping(): VatXmlMapping
    {
        return VatXmlMapping::fromArray(
            JsoncParser::parseFile(self::CONFIG),
            'cs',
            JsoncParser::parseFile(self::COUNTRIES),
        );
    }

    private function write(FilingXmlInput $input): string
    {
        return (new Kh1XmlWriter($this->mapping()))->write($input);
    }

    /** @return list<object> */
    private function validate(FilingXmlInput $input): array
    {
        return (new FilingXmlValidator($this->mapping()))->validate($input);
    }

    /** @param array<string, mixed> $override */
    private function input(array $override = []): FilingXmlInput
    {
        return new FilingXmlInput(
            reportType: 'cs',
            filingKind: $override['kind'] ?? 'regular',
            previousKind: $override['previous'] ?? null,
            header: $override['header'] ?? self::HEADER,
            period: FilingPeriod::fromRange('2026-04-01', '2026-04-30'),
            dateIssue: '2026-05-02',
            dateFiled: '2026-05-04',
            dateFound: $override['dateFound'] ?? null,
            controlRows: $override['rows'] ?? $this->defaultRows(),
            controlReturnBase: $override['returnBase'] ?? [
                '1' => 23000.0, '3' => 1000.0, '5' => 300.0, '25' => 50000.0, '40' => 15500.0,
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    private function defaultRows(): array
    {
        return [
            $this->row('A1', ['doc_number' => 'FV-1', 'partner_vat_id' => 'CZ87654321',
                'vat_dppd' => '2026-04-10', 'reverse_charge_code' => '4', 'base1' => 50000.0]),
            $this->row('A2', ['doc_number' => 'DE-77', 'partner_vat_id' => 'DE123456789',
                'vat_dppd' => '2026-04-12', 'base1' => 1000.0, 'tax1' => 210.0]),
            $this->row('A4', ['doc_number' => 'FV-2', 'partner_vat_id' => 'CZ11111111',
                'vat_dppd' => '2026-04-15', 'base1' => 20000.0, 'tax1' => 4200.0]),
            $this->row('A5', ['row_kind' => 'aggregate', 'doc_number' => '', 'partner_vat_id' => '',
                'vat_dppd' => null, 'base1' => 3000.0, 'tax1' => 630.0]),
            $this->row('B2', ['doc_number' => 'DOD-9', 'partner_vat_id' => 'CZ22222222',
                'vat_dppd' => '2026-04-18', 'base1' => 15000.0, 'tax1' => 3150.0]),
            $this->row('B3', ['row_kind' => 'aggregate', 'doc_number' => '', 'partner_vat_id' => '',
                'vat_dppd' => null, 'base1' => 500.0, 'tax1' => 105.0]),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function row(string $section, array $values = []): array
    {
        return $values + [
            'section'        => $section,
            'row_kind'       => 'detail',
            'doc_number'     => 'FV-1',
            'partner_vat_id' => 'CZ87654321',
            'vat_dppd'       => '2026-04-10',
            'reverse_charge_code' => null,
            'base1'          => 1000.0,
            'tax1'           => 210.0,
            'base2'          => 0.0,
            'tax2'           => 0.0,
            'base3'          => 0.0,
            'tax3'           => 0.0,
        ];
    }
}
