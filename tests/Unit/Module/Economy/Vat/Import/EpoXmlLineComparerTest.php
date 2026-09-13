<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat\Import;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Vat\Import\EpoXmlDocument;
use Shipard\Module\Economy\Vat\Import\EpoXmlLineComparer;
use Shipard\Module\Economy\Vat\Xml\VatXmlMapping;

/**
 * Porovnání řádků hlášení podle klíče z mapování (#55 D33): jiná hodnota
 * se hlásí u konkrétního řádku a pole, chybějící a přebývající řádky
 * zvlášť; pořadí řádků a zápis čísla rozdíl nedělají.
 */
class EpoXmlLineComparerTest extends TestCase
{
    private const CONFIG   = __DIR__ . '/../../../../../../modules/economy/vat/config/vat-xml-cz.jsonc';
    private const FIXTURES = __DIR__ . '/../../../../../Fixtures/vat-xml/synthetic';

    public function testIdenticalControlStatementsHaveNoDifferences(): void
    {
        $xml = $this->fixture('kh1-regular.xml');
        $this->assertSame([], $this->compare('cs', $xml, $xml));
    }

    public function testRowOrderAndNumberFormattingDoNotMatter(): void
    {
        $filed = $this->fixture('kh1-regular.xml');
        // Řádky A.4 a A.2 prohozené, částky bez desetinných míst.
        $composed = str_replace(
            ['zakl_dane1="50000.00"', 'dan1="210.00"'],
            ['zakl_dane1="50000"', 'dan1="210"'],
            $this->swapLines($filed, 'VetaA2', 'VetaA4'),
        );
        $this->assertSame([], $this->compare('cs', $filed, $composed));
    }

    public function testControlStatementDifferencesAreKeyed(): void
    {
        $filed    = $this->fixture('kh1-regular.xml');
        $composed = str_replace(
            [
                '<VetaA2 k_stat="DE" vatid_dod="123456789" c_evid_dd="DE-77" dppd="12.04.2026" zakl_dane1="1000.00" dan1="210.00"/>',
                '<VetaA4 dic_odb="11111111" c_evid_dd="FV-2" dppd="15.04.2026" zakl_dane1="20000.00" dan1="4200.00" kod_rezim_pl="0" zdph_44="N"/>',
                '<VetaA5 zakl_dane1="3000.00" dan1="630.00"/>',
                '<VetaB3 zakl_dane1="500.00" dan1="105.00"/>',
            ],
            [
                '<VetaA2 k_stat="DE" vatid_dod="123456789" c_evid_dd="DE-77" dppd="12.04.2026" zakl_dane1="1000.00" dan1="220.00"/>',
                '',
                '<VetaA5 zakl_dane1="3500.00" dan1="630.00"/>',
                '<VetaB3 zakl_dane1="500.00" dan1="105.00"/>'
                . '<VetaB2 dic_dod="33333333" c_evid_dd="DOD-10" dppd="19.04.2026" zakl_dane1="100.00" dan1="21.00" pomer="N" zdph_44="N"/>',
            ],
            $filed,
        );

        $differences = $this->compare('cs', $filed, $composed);
        $byKind = [];
        foreach ($differences as $difference) {
            $byKind[$difference['kind']][] = $difference;
        }

        $this->assertCount(2, $byKind['value']);
        $this->assertSame(
            ['section' => 'A2', 'key' => 'DE|123456789|DE-77', 'field' => 'dan1', 'composed' => '220', 'filed' => '210', 'kind' => 'value'],
            $byKind['value'][0],
        );
        $this->assertSame(
            ['section' => 'A5', 'key' => '', 'field' => 'zakl_dane1', 'composed' => '3500', 'filed' => '3000', 'kind' => 'value'],
            $byKind['value'][1],
            'souhrnná sekce bez klíče',
        );

        $this->assertCount(1, $byKind['missing']);
        $this->assertSame('A4', $byKind['missing'][0]['section']);
        $this->assertSame('11111111|FV-2', $byKind['missing'][0]['key']);
        $this->assertStringContainsString('zakl_dane1="20000"', (string) $byKind['missing'][0]['filed']);
        $this->assertNull($byKind['missing'][0]['composed']);

        $this->assertCount(1, $byKind['extra']);
        $this->assertSame('B2', $byKind['extra'][0]['section']);
        $this->assertSame('33333333|DOD-10', $byKind['extra'][0]['key']);
        $this->assertNull($byKind['extra'][0]['filed']);
    }

    public function testControlStatementKeyIncludesKodPredPl(): void
    {
        $filed    = $this->fixture('kh1-regular.xml');
        $composed = str_replace('kod_pred_pl="4"', 'kod_pred_pl="5"', $filed);

        $differences = $this->compare('cs', $filed, $composed);
        $this->assertCount(2, $differences, 'jiný kód = jiný řádek (chybí + přebývá)');
        $this->assertSame(['missing', 'extra'], array_column($differences, 'kind'));
        $this->assertSame('87654321|FV-1|4', $differences[0]['key']);
    }

    public function testRecapitulativeStatementDifference(): void
    {
        $filed    = $this->fixture('shv-regular.xml');
        $composed = str_replace('pln_hodnota="12346"', 'pln_hodnota="12350"', $filed);

        $this->assertSame(
            [['section' => 'R', 'key' => 'SK|2020123456|0', 'field' => 'pln_hodnota', 'composed' => '12350', 'filed' => '12346', 'kind' => 'value']],
            $this->compare('rs', $filed, $composed),
        );
        $this->assertSame([], $this->compare('rs', $filed, $filed));
    }

    public function testDuplicateKeysAreNotCollapsed(): void
    {
        $filed = $this->fixture('shv-regular.xml');
        $twice = str_replace(
            '<VetaR k_stat="SK" c_vat="2020123456" k_pln_eu="0" pln_pocet="3" pln_hodnota="12346"/>',
            '<VetaR k_stat="SK" c_vat="2020123456" k_pln_eu="0" pln_pocet="3" pln_hodnota="12346"/>'
            . '<VetaR k_stat="SK" c_vat="2020123456" k_pln_eu="0" pln_pocet="1" pln_hodnota="5"/>',
            $filed,
        );

        $differences = $this->compare('rs', $filed, $twice);
        $this->assertCount(1, $differences);
        $this->assertSame('extra', $differences[0]['kind']);
        $this->assertSame('SK|2020123456|0#2', $differences[0]['key']);
    }

    // ── pomocné ─────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function compare(string $type, string $filed, string $composed): array
    {
        $mapping = VatXmlMapping::fromArray(JsoncParser::parseFile(self::CONFIG), $type);
        return (new EpoXmlLineComparer($mapping))->compare(
            EpoXmlDocument::fromString($filed),
            EpoXmlDocument::fromString($composed),
        );
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }

    private function swapLines(string $xml, string $vetaA, string $vetaB): string
    {
        $lines = explode("\n", $xml);
        $a = $b = null;
        foreach ($lines as $i => $line) {
            if (str_contains($line, "<{$vetaA} ")) {
                $a = $i;
            }
            if (str_contains($line, "<{$vetaB} ")) {
                $b = $i;
            }
        }
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        [$lines[$a], $lines[$b]] = [$lines[$b], $lines[$a]];
        return implode("\n", $lines);
    }
}
