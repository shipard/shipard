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
use Shipard\Module\Economy\Vat\Xml\ShvXmlWriter;
use Shipard\Module\Economy\Vat\Xml\VatXmlMapping;

/**
 * Generátor XML souhrnného hlášení (issue #55, Fáze 3, X13/X14).
 */
class ShvXmlWriterTest extends TestCase
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
    ];

    public function testStatementMatchesGoldenFile(): void
    {
        $path = self::FIXTURES . '/shv-regular.xml';
        $this->assertFileExists($path);
        $this->assertSame(
            str_replace('@version@', Version::VERSION, (string) file_get_contents($path)),
            $this->write($this->input()),
        );
    }

    public function testGeneratedFileValidatesAgainstTheOfficialSchema(): void
    {
        $this->assertSame([], (new EpoXsdValidator())->validate($this->write($this->input()), 'rs'));
    }

    /** Kód státu a číslo registrace jdou do XML zvlášť (#55 X13). */
    public function testCustomerVatIdIsSplitIntoCountryAndNumber(): void
    {
        $xml = $this->write($this->input());
        $this->assertStringContainsString('k_stat="SK" c_vat="2020123456"', $xml);
        $this->assertStringContainsString('k_stat="DE" c_vat="123456789"', $xml);
    }

    /** Kód plnění je přímo `kod` snapshotu: 0 zboží, 3 služby. */
    public function testSupplyCodeGoesThroughUnchanged(): void
    {
        $xml = $this->write($this->input());
        $this->assertStringContainsString('k_pln_eu="0"', $xml, 'nula je hodnota, ne prázdno');
        $this->assertStringContainsString('k_pln_eu="3"', $xml);
    }

    /** Třístranný obchod prostřední osobou (kód 2, #164 D8) projde zapisovačem i XSD. */
    public function testTriangularTradeCodeIsWrittenAndValid(): void
    {
        $input = $this->input(['rows' => [
            ['kod' => 2, 'partner_vat_id' => 'DE123456789', 'count' => 1,
             'value' => 4000.0, 'value_filed' => 4000.0],
        ]]);
        $xml = $this->write($input);

        $this->assertStringContainsString('k_pln_eu="2"', $xml);
        $this->assertSame([], (new EpoXsdValidator())->validate($xml, 'rs'));
        $this->assertSame([], $this->validate($input));
    }

    /** Hodnota plnění je v celých Kč — zaokrouhlení nahoru udělal snapshot. */
    public function testValueIsWrittenInWholeCrowns(): void
    {
        $xml = $this->write($this->input());
        $this->assertStringContainsString('pln_pocet="3" pln_hodnota="12346"', $xml);
    }

    public function testFormaIsRegularOrSubsequentOnly(): void
    {
        $this->assertStringContainsString('shvies_forma="R"', $this->write($this->input()));
        $this->assertStringContainsString(
            'shvies_forma="N"',
            $this->write($this->input(['kind' => 'subsequent', 'previous' => 'regular'])),
        );
    }

    /** Souhrnné hlášení opravné nezná — mapování na něj kód formy nemá. */
    public function testCorrectiveKindIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->write($this->input(['kind' => 'corrective']));
    }

    /**
     * Věta D souhrnného hlášení nezná datum zjištění ani částečné období
     * a legacy atributy (`poc_radku`, `suma_pln`) se nevyplňují.
     */
    public function testHeaderCarriesOnlyWhatTheFormUses(): void
    {
        $xml = $this->write($this->input(['kind' => 'subsequent', 'previous' => 'regular']));

        $this->assertStringNotContainsString('d_zjist', $xml);
        $this->assertStringNotContainsString('poc_radku', $xml);
        $this->assertStringNotContainsString('suma_pln', $xml);
        $this->assertStringNotContainsString('pln_poc_celk', $xml);
    }

    /** Storno řádky Shipard negeneruje — následné hlášení je plný obsah (X14). */
    public function testNoCancellationRowsAreGenerated(): void
    {
        $xml = $this->write($this->input(['kind' => 'subsequent', 'previous' => 'regular']));
        $this->assertStringNotContainsString('<VetaS', $xml);
        $this->assertStringNotContainsString('k_storno', $xml);
    }

    public function testVatIdWithoutCountryPrefixIsRejected(): void
    {
        $errors = $this->validate($this->input([
            'rows' => [['kod' => 0, 'partner_vat_id' => '2020123456', 'count' => 1, 'value_filed' => 100.0]],
        ]));

        $this->assertCount(1, $errors);
        $this->assertSame('incomplete_row', $errors[0]->code);
        $this->assertStringContainsString('kód státu', $errors[0]->message);
    }

    public function testValidStatementHasNoErrors(): void
    {
        $this->assertSame([], $this->validate($this->input()));
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    private function mapping(): VatXmlMapping
    {
        return VatXmlMapping::fromArray(
            JsoncParser::parseFile(self::CONFIG),
            'rs',
            JsoncParser::parseFile(self::COUNTRIES),
        );
    }

    private function write(FilingXmlInput $input): string
    {
        return (new ShvXmlWriter($this->mapping()))->write($input);
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
            reportType: 'rs',
            filingKind: $override['kind'] ?? 'regular',
            previousKind: $override['previous'] ?? null,
            header: self::HEADER,
            period: FilingPeriod::fromRange('2026-04-01', '2026-04-30'),
            dateIssue: '2026-05-02',
            dateFiled: '2026-05-04',
            recapRows: $override['rows'] ?? [
                ['kod' => 0, 'partner_vat_id' => 'SK2020123456', 'count' => 3,
                 'value' => 12345.60, 'value_filed' => 12346.0],
                ['kod' => 3, 'partner_vat_id' => 'DE123456789', 'count' => 1,
                 'value' => 500.0, 'value_filed' => 500.0],
            ],
        );
    }
}
