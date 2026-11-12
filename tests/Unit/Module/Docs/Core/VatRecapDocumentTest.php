<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\VatRecapDocument;

/**
 * Řádek převzaté rekapitulace DPH: nový bez pořadí se zařadí na konec
 * (stejná úmluva jako u řádků dokladu). Přepočet hlavičky po uložení dělá
 * `DocHeadRecomputer` a ověřuje ho integrační proklik nad dev DS — tady
 * jde jen o pořadí.
 */
class VatRecapDocumentTest extends TestCase
{
    private function docWithMax(mixed $max, bool $expectQuery = true): VatRecapDocument
    {
        $db = $this->createMock(Connection::class);
        if ($expectQuery) {
            $db->expects($this->once())->method('fetchSingle')->willReturnCallback(
                function (mixed ...$args) use ($max): mixed {
                    $this->assertStringContainsString('MAX([order_pos])', $args[0]);
                    $this->assertStringContainsString('docs_core_vat_recap', $args[0]);
                    $this->assertSame(7, $args[1]);
                    return $max;
                },
            );
        } else {
            $db->expects($this->never())->method('fetchSingle');
        }
        $doc = new VatRecapDocument();
        $doc->setDb($db);
        return $doc;
    }

    public function testNewLineWithoutOrderGetsMaxPlusOne(): void
    {
        $doc = $this->docWithMax(2);
        $data = ['doc_head' => 7, 'vat_code' => 'cz-110', 'vat_pct' => 21.0];
        $doc->beforeSave($data, null);
        $this->assertSame(3, $data['order_pos']);
    }

    public function testFirstLineOfEmptyDocumentGetsOne(): void
    {
        $doc = $this->docWithMax(null);
        $data = ['doc_head' => 7];
        $doc->beforeSave($data, null);
        $this->assertSame(1, $data['order_pos']);
    }

    public function testExplicitOrderIsKept(): void
    {
        $doc = $this->docWithMax(null, expectQuery: false);
        $data = ['doc_head' => 7, 'order_pos' => 5];
        $doc->beforeSave($data, null);
        $this->assertSame(5, $data['order_pos']);
    }

    public function testUpdateDoesNotTouchOrder(): void
    {
        $doc = $this->docWithMax(null, expectQuery: false);
        $data = ['id' => 3, 'doc_head' => 7, 'base' => 100.0];
        $doc->beforeSave($data, ['id' => 3, 'doc_head' => 7, 'order_pos' => 2]);
        $this->assertArrayNotHasKey('order_pos', $data);
    }


    // ── Předmět plnění tuzemského přenesení (#89 D7) ───────────────────────

    private function docWithVatHead(): VatRecapDocument
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new \Dibi\Row([
            'vat_registration' => 1, 'vat_mode' => 2, 'vat_duzp' => '2026-05-06', 'issue_date' => '2026-05-06',
            'corrective' => 0, 'corrected_doc_date' => null,
        ]));
        $db->method('fetchSingle')->willReturnCallback(
            static fn (mixed ...$args): mixed => str_contains((string) $args[0], 'vat_registrations') ? 'cz' : 1,
        );
        $doc = new VatRecapDocument();
        $doc->setDb($db);
        $doc->setConfig(\Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory::fromItems([
            'world.vat.cz' => \Shipard\Core\Utils\JsoncParser::parseFile(
                dirname(__DIR__, 5) . '/modules/world/vat/config/vat-cz.jsonc',
            ),
        ]));
        return $doc;
    }

    public function testSubjectStaysOnReverseChargeLineAndItsPairOnly(): void
    {
        $doc = $this->docWithVatHead();

        $primary = ['doc_head' => 7, 'order_pos' => 1, 'vat_code' => 'cz-115', 'reverse_charge_code' => '4'];
        $doc->beforeSave($primary, null);
        $this->assertSame('4', $primary['reverse_charge_code']);

        $pair = ['doc_head' => 7, 'order_pos' => 2, 'vat_code' => 'cz-203', 'is_reverse_pair' => 1, 'reverse_charge_code' => '4'];
        $doc->beforeSave($pair, null);
        $this->assertSame('4', $pair['reverse_charge_code'], 'oddaňovací pár nese předmět své skupiny');

        $other = ['doc_head' => 7, 'order_pos' => 3, 'vat_code' => 'cz-110', 'reverse_charge_code' => '4'];
        $doc->beforeSave($other, null);
        $this->assertNull($other['reverse_charge_code']);
    }

    public function testLineValidationRejectsUnknownSubject(): void
    {
        $doc = $this->docWithVatHead();
        $errors = static function (array $line) use ($doc): array {
            $line += ['doc_head' => 7];
            return array_map(static fn ($e): array => $e->toArray(), $doc->validate($line)->getErrors());
        };

        $this->assertSame([], $errors(['vat_code' => 'cz-115', 'reverse_charge_code' => '4a']));
        $this->assertSame([], $errors(['vat_code' => 'cz-115']), 'chybějící předmět hlídá potvrzení dokladu');

        $unknown = $errors(['vat_code' => 'cz-115', 'reverse_charge_code' => '99']);
        $this->assertCount(1, $unknown);
        $this->assertSame('reverse_charge_code', $unknown[0]['column']);
        $this->assertSame('invalid_value', $unknown[0]['code']);
    }
}
