<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Compare;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Compare\Projection;
use Shipard\Module\Core\Exchange\Compare\ValueShapes;

class ValueShapesTest extends TestCase
{
    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: mixed, 3: mixed}> */
    public static function values(): array
    {
        $re = ['regex' => '^.*$'];
        return [
            'země malými' => ['country', $re, ' CZ', 'cz'],
            'měna velkými' => ['currency', $re, 'czk', 'CZK'],
            'DIČ bez mezer' => ['vatId', $re, 'cz 4307 5894', 'CZ43075894'],
            'IBAN bez mezer' => ['iban', $re, 'cz68 0100 0000 0001 0196 8643', 'CZ6801000000000101968643'],
            'BIC velkými' => ['bic', $re, 'kombczpp ', 'KOMBCZPP'],
            'e-mail malými' => ['email', $re, 'Odbyt@Drevomat-Hana.example', 'odbyt@drevomat-hana.example'],
            'telefon s mezerami' => ['phone', $re, '+420 000 201 304', '+420000201304'],
            'telefon bez plus' => ['phone', $re, '000-201-304', '000201304'],
            'web se schématem' => ['web', $re, 'https://WWW.Drevomat-hana.example/', 'www.drevomat-hana.example'],
            'web s cestou' => ['web', $re, 'http://x.example/shop/', 'x.example/shop'],
            'PSČ s mezerou' => ['digits5', $re, '779 00', '77900'],
            'IČO s mezerou' => ['digits8', $re, '430 75 894', '43075894'],
            'symbol' => ['symbol', $re, ' 20261180 ', '20261180'],
            'reference bez mezer' => ['reference', $re, ' INV-2026 -0388 ', 'INV-2026-0388'],
            'účet s nulami' => ['accountNumber', $re, '000019-0002000145399/0800', '19-2000145399/0800'],
            'účet bez předčíslí' => ['accountNumber', $re, '0101968643 / 0100', '101968643/0100'],
            'účet s nulovým předčíslím' => ['accountNumber', $re, '0-123/0100', '123/0100'],
            'zahraniční účet' => ['accountNumber', $re, '12 34 56', '123456'],
            'text trim' => ['text', $re, "  Spárovka smrková \n", 'Spárovka smrková'],
            'datum' => ['date', $re, '2026-08-25 ', '2026-08-25'],
            'const nemění' => ['selfParty', ['const' => 'customer'], ' customer', ' customer'],
            'částka na setiny' => ['amount', ['check' => 'amount'], '486.504', 486.5],
            'částka int' => ['amount', ['check' => 'amount'], 2340, 2340.0],
            'číslo' => ['number', ['check' => 'number'], '12.5', 12.5],
            'index' => ['index', ['check' => 'index'], '3', 3],
            'nečíslo zůstává' => ['amount', ['check' => 'amount'], 'abc', 'abc'],
            'null' => ['country', $re, null, null],
            'bool' => ['x', $re, true, true],
            'neznámý tvar trim' => ['whatever', $re, ' x ', 'x'],
        ];
    }

    #[DataProvider('values')]
    public function testNormalizeValue(string $name, array $shape, mixed $value, mixed $expected): void
    {
        $this->assertSame($expected, ValueShapes::normalizeValue($name, $shape, $value));
    }

    public function testNormalizeWalksProjectionWithByCountry(): void
    {
        $p = Projection::load(ProjectionTest::projectionPath());
        $doc = ProjectionTest::corpusDoc();
        $doc['supplier']['address']['zip'] = '779 00';
        $doc['supplier']['address']['country'] = 'CZ';
        $doc['supplier']['vatId'] = 'cz43075894';
        $doc['currency'] = 'czk';
        $doc['rows'][0]['unitPrice'] = '486.50';
        $doc['customer']['address']['zip'] = '760 01';
        $doc['customer']['country'] = 'us';          // byCountry bere country z téhož objektu — adresa má svůj
        $doc['customer']['address']['country'] = 'us';

        $out = ValueShapes::normalize($p, $p->project($doc));

        $this->assertSame('77900', $out['supplier']['address']['zip']);
        $this->assertSame('cz', $out['supplier']['address']['country']);
        $this->assertSame('CZ43075894', $out['supplier']['vatId']);
        $this->assertSame('CZK', $out['currency']);
        $this->assertSame(486.5, $out['rows'][0]['unitPrice']);
        $this->assertSame('760 01', $out['customer']['address']['zip'], 'mimo cz/sk zůstává PSČ jak na dokladu');
        $this->assertSame(12.5, $out['rows'][0]['quantity']);
        $this->assertSame(3, $out['_resolve']['contentTag']['rowExceptions'][0]['rowIndex']);
        $this->assertFalse($out['vat']['reverseCharge']);
    }
}
