<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Compare;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Compare\Projection;
use Shipard\Module\Core\Exchange\Compare\ProjectionException;

class ProjectionTest extends TestCase
{
    public static function projectionPath(): string
    {
        return dirname(__DIR__, 6) . '/tests/corpus/projection.jsonc';
    }

    /** @return array<string, mixed> */
    public static function corpusDoc(): array
    {
        $path = dirname(__DIR__, 6) . '/tests/corpus/cz/expected/vat-payer/0101-domestic-goods.json';
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testLoadsCorpusProjection(): void
    {
        $p = Projection::load(self::projectionPath());
        $this->assertSame(['customer'], $p->nullableNodes());
        $this->assertTrue($p->isNullableNode('customer'));
        $this->assertFalse($p->isNullableNode('supplier'));
        $this->assertSame('format', array_key_first($p->document()));
        $this->assertArrayHasKey('amount', $p->shapes());
    }

    public function testProjectOrdersKeysFillsNullsAndDropsExtras(): void
    {
        $p = Projection::load(self::projectionPath());
        $doc = [
            'rows' => [['unit' => 'ks', 'quantity' => 2, 'extra' => 1, 'item' => ['name' => 'X']]],
            'docNumber' => 'A-1',
            'format' => 'shpd.docs.document',
            'source' => ['kind' => 'aiExtraction'],
            'supplier' => ['name' => 'Dod', 'address' => ['city' => 'Zlín']],
            'totals' => ['totalAmount' => 10],
        ];
        $out = $p->project($doc);

        $this->assertSame(array_keys($p->document()), array_keys($out));
        $this->assertArrayNotHasKey('source', $out);
        $this->assertNull($out['customer'], 'nullable uzel zůstává null');
        $this->assertSame(['issueDate' => null, 'dueDate' => null, 'taxPointDate' => null, 'periodFrom' => null, 'periodTo' => null], $out['dates'], 'chybějící objekt = strom null listů');
        $this->assertNull($out['_resolve']['contentTag']['rowExceptions']);
        $this->assertSame('A-1', $out['docNumber']);
        $this->assertSame(['rowKind', 'item', 'unit', 'quantity', 'unitPrice', 'totalPrice', 'priceCalcMode', 'discountPct', 'discountAmount', 'vat'], array_keys($out['rows'][0]));
        $this->assertSame(['supplierCode' => null, 'ean' => null, 'name' => 'X', 'description' => null], $out['rows'][0]['item']);
        $this->assertArrayNotHasKey('extra', $out['rows'][0]);
        $this->assertSame(['pct' => null, 'supplyKind' => null, 'reverseChargeCode' => null], $out['rows'][0]['vat']);
        $this->assertSame('Zlín', $out['supplier']['address']['city']);
        $this->assertNull($out['supplier']['address']['zip']);
        $this->assertSame(['name' => null, 'email' => null, 'phone' => null, 'web' => null], $out['supplier']['contact']);
        $this->assertNull($out['vatRecap']);
        $this->assertSame(['totalBase' => null, 'totalVat' => null, 'totalAmount' => 10, 'totalRounding' => null], $out['totals']);
    }

    public function testProjectKeepsCorpusExpectationUnchanged(): void
    {
        $p = Projection::load(self::projectionPath());
        $doc = self::corpusDoc();
        $this->assertSame($doc, $p->project($doc));
    }

    public function testProjectKeepsMismatchedTypesForComparator(): void
    {
        $p = Projection::load(self::projectionPath());
        $out = $p->project(['supplier' => 'text', 'rows' => 'x', 'dates' => ['issueDate' => ['a' => 1]]]);
        $this->assertSame('text', $out['supplier']);
        $this->assertSame('x', $out['rows']);
        $this->assertSame(['a' => 1], $out['dates']['issueDate']);
    }

    public function testShapeOfResolvesByCountryFromParent(): void
    {
        $p = Projection::load(self::projectionPath());
        [$name] = $p->shapeOf('zip', ['country' => 'CZ ']);
        $this->assertSame('digits5', $name);
        [$name] = $p->shapeOf('zip', ['country' => 'de']);
        $this->assertSame('text', $name);
        [$name] = $p->shapeOf('houseNumber', ['country' => 'us']);
        $this->assertSame('absent', $name);
        [$name, $shape] = $p->shapeOf('amount', null);
        $this->assertSame('amount', $name);
        $this->assertSame(['check' => 'amount'], $shape);
    }

    public function testMapVisitsLeavesWithShapeParentAndPath(): void
    {
        $p = Projection::load(self::projectionPath());
        $projected = $p->project(self::corpusDoc());
        $seen = [];
        $p->map($projected, static function (?string $shape, mixed $value, ?array $parent, string $path) use (&$seen): mixed {
            $seen[$path] = [$shape, $parent['country'] ?? null];
            return $value;
        });
        $this->assertSame(['zip', 'cz'], $seen['supplier.address.zip'], 'jméno tvaru z projekce, byCountry rozpouští až normalizace');
        $this->assertSame(['amount', null], $seen['rows[1].unitPrice']);
        $this->assertSame([null, null], $seen['vat.reverseCharge']);
        $this->assertSame('index', $seen['_resolve.contentTag.rowExceptions[0].rowIndex'][0]);
    }

    public function testNodePath(): void
    {
        $this->assertSame('rows[].vat.pct', Projection::nodePath('rows[12].vat.pct'));
        $this->assertSame('customer', Projection::nodePath('customer'));
    }

    public function testRejectsMalformedProjection(): void
    {
        $base = ['format' => Projection::FORMAT, 'formatVersion' => '1', 'document' => [], '$defs' => [], 'shapes' => []];

        $this->expectException(ProjectionException::class);
        Projection::fromArray(['format' => 'x'] + $base);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badShapes(): array
    {
        return [
            'dva klíče' => [['x' => ['regex' => 'a', 'const' => 1]]],
            'tilda' => [['x' => ['regex' => '^a~b$']]],
            'neznámá kontrola' => [['x' => ['check' => 'money']]],
            'byCountry bez *' => [['x' => ['byCountry' => ['cz' => 'y']], 'y' => ['regex' => 'a']]],
            'byCountry na byCountry' => [['x' => ['byCountry' => ['*' => 'y']], 'y' => ['byCountry' => ['*' => 'x']]]],
            'neznámý druh' => [['x' => ['pattern' => 'a']]],
        ];
    }

    /**
     * @param array<string, mixed> $shapes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badShapes')]
    public function testRejectsBadShapes(array $shapes): void
    {
        $this->expectException(ProjectionException::class);
        Projection::fromArray(['format' => Projection::FORMAT, 'formatVersion' => '1', 'document' => [], '$defs' => [], 'shapes' => $shapes]);
    }

    public function testUnknownRefFails(): void
    {
        $p = Projection::fromArray(['format' => Projection::FORMAT, 'formatVersion' => '1', 'document' => ['a' => ['$ref' => 'Nope']], '$defs' => [], 'shapes' => []]);
        $this->expectException(ProjectionException::class);
        $p->project(['a' => []]);
    }
}
