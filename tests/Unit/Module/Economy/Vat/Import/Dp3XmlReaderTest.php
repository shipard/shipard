<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat\Import;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Vat\Import\Dp3XmlReader;
use Shipard\Module\Economy\Vat\Import\EpoXmlDocument;
use Shipard\Module\Economy\Vat\Import\EpoXmlReadException;
use Shipard\Module\Economy\Vat\Xml\VatXmlMapping;

/**
 * Čtečka přiznání z XML pro import starých podání (#55 D33): mapování
 * `vat-xml-cz.jsonc` obráceně, chybějící atribut = nula, neznámé atributy
 * hodnotových vět se hlásí, cizí písemnost a rozbité XML se odmítnou.
 */
class Dp3XmlReaderTest extends TestCase
{
    private const CONFIG    = __DIR__ . '/../../../../../../modules/economy/vat/config/vat-xml-cz.jsonc';
    private const COUNTRIES = __DIR__ . '/../../../../../../modules/world/cz/config/epoCountries.jsonc';
    private const FIXTURES  = __DIR__ . '/../../../../../Fixtures/vat-xml/synthetic';

    public function testRegularReturnRowsFollowMapping(): void
    {
        $data = $this->read($this->fixture('dp3-regular.xml'));

        $this->assertSame(['base' => 1000.0, 'taxFull' => 210.0, 'taxReduced' => 0.0], $data->rows[1]);
        $this->assertSame(['base' => 501.0, 'taxFull' => 105.0, 'taxReduced' => 0.0], $data->rows[40], 'ř. 40 má tři sloty');
        $this->assertSame(['base' => 0.0, 'taxFull' => 105.0, 'taxReduced' => 0.0], $data->rows[46]);
        $this->assertSame(210.0, $data->rows[62]['taxFull']);
        $this->assertSame(105.0, $data->rows[64]['taxFull']);
        $this->assertSame(['base' => 0.0, 'taxFull' => 0.0, 'taxReduced' => 0.0], $data->rows[2], 'chybějící atribut = nula');

        $this->assertSame(['base', 'full', 'reduced'], $data->slots[40]);
        $this->assertSame(['base'], $data->slots[20]);
        $this->assertSame(['full', 'reduced'], $data->slots[45]);

        $this->assertSame('B', $data->forma);
        $this->assertNull($data->dateFound);
        $this->assertSame('12345678', $data->vetaP['dic']);
        $this->assertSame('ČESKÁ REPUBLIKA', $data->vetaP['stat']);
        $this->assertSame('A', $data->vetaD['trans']);
        $this->assertSame([], $data->unmapped);
    }

    public function testSupplementaryReturnCarriesFormaAndDateFound(): void
    {
        $data = $this->read($this->fixture('dp3-supplementary.xml'));

        $this->assertSame('D', $data->forma);
        $this->assertSame('2026-07-28', $data->dateFound, 'd_zjist jako ISO datum');
        $this->assertSame(-500.0, $data->rows[1]['base']);
        $this->assertSame(-105.0, $data->rows[66]['taxFull']);
        $this->assertSame([], $data->unmapped, 'textová příloha (VetaR) není hodnotová věta');
    }

    public function testNumbersAreReadByValue(): void
    {
        $data = $this->read($this->dp3('<Veta1 obrat23="1000.00" dan23="+210" obrat5=" 12 "/>'));

        $this->assertSame(1000.0, $data->rows[1]['base']);
        $this->assertSame(210.0, $data->rows[1]['taxFull']);
        $this->assertSame(12.0, $data->rows[2]['base']);
    }

    public function testUnmappedNonZeroAttributesAreReported(): void
    {
        $data = $this->read($this->dp3(
            '<Veta1 obrat23="1" novy_radek="5" nulovy="0"/>'
            . '<Veta5 odp_uprav_kf="1" koef_p20_nov="80"/>'
            . '<VetaR kod_sekce="D" poradi="1" t_prilohy="text"/>',
        ));

        $this->assertSame(
            [['veta' => 'Veta1', 'attribute' => 'novy_radek', 'value' => '5']],
            $data->unmapped,
            'koeficient (percent.attr) i nula se nehlásí, VetaR není hodnotová věta',
        );
    }

    public function testControlStatementIsRejectedByReturnReader(): void
    {
        $this->expectException(EpoXmlReadException::class);
        $this->expectExceptionMessage('DPHKH1');
        try {
            $this->read($this->fixture('kh1-regular.xml'));
        } catch (EpoXmlReadException $e) {
            $this->assertSame(EpoXmlReadException::REASON_TYPE_MISMATCH, $e->reason);
            throw $e;
        }
    }

    /** @return list<array{string, string}> */
    public static function unreadableProvider(): array
    {
        return [
            'text'          => ['tohle není XML', 'Start tag expected'],
            'empty'         => ['', 'prázdný'],
            'dtd'           => ['<!DOCTYPE Pisemnost [<!ENTITY x "y">]><Pisemnost><DPHDP3/></Pisemnost>', 'DTD'],
            'foreign root'  => ['<Invoice/>', 'kořenový element'],
            'no document'   => ['<Pisemnost/>', 'žádný dokument'],
            'two documents' => ['<Pisemnost><DPHDP3/><DPHKH1/></Pisemnost>', 'víc než jeden'],
        ];
    }

    #[DataProvider('unreadableProvider')]
    public function testUnreadableInputIsRejected(string $xml, string $message): void
    {
        try {
            EpoXmlDocument::fromString($xml);
            $this->fail('očekávána výjimka');
        } catch (EpoXmlReadException $e) {
            $this->assertSame(EpoXmlReadException::REASON_UNREADABLE, $e->reason);
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testDeclaredLegacyEncodingIsRewrittenToUtf8(): void
    {
        $xml = '<?xml version="1.0" encoding="windows-1250"?>'
            . '<Pisemnost><DPHDP3><VetaP zkrobchjm="Žluťoučký kůň"/></DPHDP3></Pisemnost>';
        $document = EpoXmlDocument::fromString($xml);
        $this->assertSame('Žluťoučký kůň', $document->attribute('VetaP', 'zkrobchjm'));
    }

    public function testValueHelpers(): void
    {
        $this->assertSame('2019-09-02', EpoXmlDocument::isoDate('2.9.2019'));
        $this->assertSame('2019-09-02', EpoXmlDocument::isoDate('2019-09-02'));
        $this->assertNull(EpoXmlDocument::isoDate('x'));
        $this->assertSame('210', EpoXmlDocument::normalizeValue('210.00'));
        $this->assertSame('0', EpoXmlDocument::normalizeValue('-0'));
        $this->assertSame('2026-05-04', EpoXmlDocument::normalizeValue('4.5.2026'));
        $this->assertSame('FV-1', EpoXmlDocument::normalizeValue(' FV-1 '));
        $this->assertSame(0.0, EpoXmlDocument::normalizeNumber('abc'));
    }

    public function testCountryCodeIsReverseOfCountryName(): void
    {
        $mapping = $this->mapping();
        $this->assertSame('cz', $mapping->countryCode('ČESKÁ REPUBLIKA'));
        $this->assertSame('de', $mapping->countryCode('německo'));
        $this->assertNull($mapping->countryCode('Atlantida'));
        $this->assertNull($mapping->countryCode(null));
    }

    // ── pomocné ─────────────────────────────────────────────────────────────

    private function read(string $xml): \Shipard\Module\Economy\Vat\Import\Dp3XmlData
    {
        return (new Dp3XmlReader($this->mapping()))->read(EpoXmlDocument::fromString($xml));
    }

    private function mapping(): VatXmlMapping
    {
        return VatXmlMapping::fromArray(
            JsoncParser::parseFile(self::CONFIG),
            'return',
            JsoncParser::parseFile(self::COUNTRIES),
        );
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }

    private function dp3(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Pisemnost nazevSW="x" verzeSW="1"><DPHDP3 verzePis="01.02">'
            . '<VetaD dokument="DP3" k_uladis="DPH" dapdph_forma="B"/><VetaP dic="1"/>' . $body
            . '</DPHDP3></Pisemnost>';
    }
}
