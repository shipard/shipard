<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core\Corrective;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\DocumentValidator;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Docs\Core\Corrective\CorrectiveDocBuilder;
use Shipard\Module\Docs\Core\DocRowCalculator;

/**
 * Builder canonicalu opravného dokladu (#92 D6, tasks/corrective-doc-exchange.md
 * U6): převzaté údaje hlavičky, odkaz pinem, převrácené řádky — pro každý
 * cenový řádek `net_total` = −původní (DocRowCalculator), žádné `totals`
 * ani `vatRecap`; výsledek prochází schématem a DocumentValidator.
 */
class CorrectiveDocBuilderTest extends TestCase
{
    private const CUSTOMER = ['name' => 'Odběratel s.r.o.', 'country' => 'cz', 'companyId' => '12345678'];

    /** @return array<string, mixed> hlavička původní faktury jako z DB (join vat_reg_country) */
    private static function head(array $overrides = []): array
    {
        return array_merge([
            'id' => 77, 'doc_type' => 'invno', 'number_series' => 3, 'doc_number' => 'FV26-00123', 'sequence_number' => 123,
            'doc_text' => 'Konzultace březen', 'partner' => 5, 'partner_address' => 9,
            'issue_date' => new \DateTimeImmutable('2026-03-01'), 'due_date' => new \DateTimeImmutable('2026-03-15'),
            'accounting_date' => new \DateTimeImmutable('2026-03-01'), 'vat_duzp' => new \DateTimeImmutable('2026-03-10'),
            'vat_registration' => 1, 'vat_reg_country' => 'cz', 'vat_mode' => 1, 'vat_calc_source' => 0, 'vat_place' => 0,
            'vat_recap_source' => 1, 'cs_mode' => 2,
            'doc_currency' => 'czk', 'exchange_rate' => '1.000000', 'payment_method' => 1, 'bank_account' => 4,
            'payment_reference' => '2600123', 'specific_symbol' => '77', 'constant_symbol' => '0308',
            'cost_center' => 3, 'work_order' => null, 'asset' => null,
            'total_base' => '2000.00', 'total_vat' => '420.00', 'total_amount' => '2420.00',
            'notice' => 'interní', 'doc_notice' => 'Děkujeme.', 'docState' => 40,
            'corrective' => 0, 'corrected_doc' => null, 'corrected_doc_number' => null, 'corrected_doc_date' => null, 'correction_reason' => null,
        ], $overrides);
    }

    /** @return list<array<string, mixed>> řádky původní faktury jako z DB (join položky a jednotky) */
    private static function rows(): array
    {
        $base = ['doc_head' => 77, 'operation' => 'sale.services', 'item' => null, 'item_code' => null, 'item_name' => null,
                 'unit' => null, 'unit_code' => null, 'unit_shortcut' => null, 'discount_pct' => null, 'discount_amount' => null,
                 'vat_code' => 'cz-110', 'vat_pct' => '21.00', 'cost_center' => null, 'dimensions' => []];
        return [
            // z jednotkové ceny, s položkou, jednotkou a dimenzí řádku
            ['id' => 1, 'row_kind' => 1, 'order_pos' => 1, 'description' => 'Konzultace', 'quantity' => '2.0000',
             'unit_price' => '500.0000', 'total_price' => '1000.00', 'price_calc_mode' => 0,
             'item' => 18, 'item_code' => 'KONZ', 'item_name' => 'Konzultace IT', 'unit' => 4, 'unit_code' => 'hr', 'unit_shortcut' => 'h',
             'dimensions' => ['costCenter' => 'V01']] + $base,
            // z celkové ceny
            ['id' => 2, 'row_kind' => 1, 'order_pos' => 2, 'description' => 'Doprava', 'quantity' => '3.0000',
             'unit_price' => '300.0000', 'total_price' => '900.00', 'price_calc_mode' => 1] + $base,
            // sleva procentem
            ['id' => 3, 'row_kind' => 1, 'order_pos' => 3, 'description' => 'Licence', 'quantity' => '1.0000',
             'unit_price' => '1000.0000', 'total_price' => '1000.00', 'price_calc_mode' => 0, 'discount_pct' => '10.00'] + $base,
            // sleva částkou
            ['id' => 4, 'row_kind' => 1, 'order_pos' => 4, 'description' => 'Podpora', 'quantity' => '1.0000',
             'unit_price' => '1000.0000', 'total_price' => '1000.00', 'price_calc_mode' => 0, 'discount_amount' => '100.00'] + $base,
            // textový řádek
            ['id' => 5, 'row_kind' => 0, 'order_pos' => 5, 'description' => 'Fakturujeme vám práce za březen.', 'quantity' => '0.0000',
             'unit_price' => null, 'total_price' => null, 'price_calc_mode' => 0, 'vat_code' => null, 'vat_pct' => null, 'operation' => null] + $base,
            // bez množství, z celkové ceny — znaménko nese cena
            ['id' => 6, 'row_kind' => 1, 'order_pos' => 6, 'description' => 'Paušál', 'quantity' => '0.0000',
             'unit_price' => '0.0000', 'total_price' => '250.00', 'price_calc_mode' => 1] + $base,
            // bez množství, z jednotkové ceny
            ['id' => 7, 'row_kind' => 1, 'order_pos' => 7, 'description' => 'Záloha na materiál', 'quantity' => '0.0000',
             'unit_price' => '50.0000', 'total_price' => '0.00', 'price_calc_mode' => 0] + $base,
        ];
    }

    /** @return array<string, mixed> řádek canonicalu → vstup DocRowCalculator::computePrice */
    private static function calcRow(array $c): array
    {
        return [
            'row_kind'        => ['text' => 0, 'item' => 1, 'section' => 2][$c['rowKind']],
            'quantity'        => $c['quantity'] ?? 0,
            'price_calc_mode' => ($c['priceCalcMode'] ?? 'fromUnitPrice') === 'fromTotal' ? 1 : 0,
            'unit_price'      => $c['unitPrice'] ?? null,
            'total_price'     => $c['totalPrice'] ?? null,
            'discount_pct'    => $c['discountPct'] ?? null,
            'discount_amount' => $c['discountAmount'] ?? null,
        ];
    }

    private function assertValidCanonical(array $canonical): void
    {
        $schemaIssues = (new SchemaValidator(SchemaLoader::default()))->validate($canonical, DocumentApplier::FORMAT_ID, DocumentApplier::FORMAT_VERSION);
        $this->assertSame([], $schemaIssues, json_encode($schemaIssues, JSON_UNESCAPED_UNICODE));
        $errors = array_filter((new DocumentValidator())->validate($canonical), static fn (array $i): bool => $i['severity'] === 'error');
        $this->assertSame([], array_values($errors), json_encode($errors, JSON_UNESCAPED_UNICODE));
    }

    public function testHeaderTakesOverOriginalAndLeavesOutNumbersTextsAndTotals(): void
    {
        $c = CorrectiveDocBuilder::build(self::head(), self::rows(), self::CUSTOMER, ['costCenter' => 'S01'], '2026-04-20');
        $this->assertValidCanonical($c);

        $this->assertSame('invoiceIssued', $c['docType']);
        $this->assertSame('supplier', $c['selfParty']);
        $this->assertSame(self::CUSTOMER, $c['customer']);
        $this->assertSame(['issueDate' => '2026-04-20'], $c['dates'], 'jen datum vystavení, ostatní doplní dokument');
        $this->assertSame('CZK', $c['currency']);
        $this->assertSame(1.0, $c['exchangeRate']);
        $this->assertSame(['mode' => 'fromBase', 'place' => 'domestic', 'registrationCountry' => 'cz', 'calcSource' => 'header'], $c['vat']);
        $this->assertSame(['method' => 'bankTransfer'], $c['payment']);
        $this->assertSame(['costCenter' => 'S01'], $c['dimensions']);
        $this->assertSame(['kind' => 'correctiveDoc'], $c['source']);
        $this->assertSame(
            ['originalDocNumber' => 'FV26-00123', 'originalTaxPointDate' => '2026-03-10', 'reason' => null],
            $c['correction'],
            'důvod prázdný — doplní uživatel',
        );
        $this->assertSame(['userAction' => 'useExisting:77'], $c['_resolve']['correction']);
        $this->assertSame(['userAction' => 'useExisting:5'], $c['_resolve']['customer']);
        $this->assertSame([['index' => 0, 'item' => ['userAction' => 'useExisting:18']]], $c['_resolve']['rows']);
        $this->assertSame(['targetDocState' => 10, 'numberSeriesId' => 3, 'importOwnBankAccount' => 4], $c['applyOptions']);

        foreach (['totals', 'vatRecap', 'docText', 'notes', 'docNumber', 'attachments'] as $key) {
            $this->assertArrayNotHasKey($key, $c, $key);
        }
        $this->assertArrayNotHasKey('recapSource', $c['vat'], 'rekapitulace se počítá znovu');
        $this->assertArrayNotHasKey('controlStatementMode', $c['vat'], 'ruční zařazení do KH se nepřebírá');
        $this->assertArrayNotHasKey('paymentReference', $c['payment'], 'symboly se nepřebírají');
    }

    public function testEveryPricedRowFlipsNetTotal(): void
    {
        $rows = self::rows();
        $c = CorrectiveDocBuilder::build(self::head(), $rows, self::CUSTOMER, [], '2026-04-20');

        $this->assertCount(count($rows), $c['rows']);
        foreach ($rows as $i => $original) {
            $flipped = DocRowCalculator::computePrice(self::calcRow($c['rows'][$i]));
            $source = DocRowCalculator::computePrice($original);
            $this->assertSame(
                $source['net_total'] === 0.0 ? 0.0 : -$source['net_total'],
                $flipped['net_total'],
                "řádek {$original['order_pos']}: net_total = −původní",
            );
            $this->assertSame($i + 1, $c['rows'][$i]['orderPos']);
        }
    }

    public function testRowShapes(): void
    {
        $c = CorrectiveDocBuilder::build(self::head(), self::rows(), self::CUSTOMER, [], '2026-04-20');
        [$unit, $total, $pct, $amount, $text, $noQtyTotal, $noQtyUnit] = $c['rows'];

        // z jednotkové ceny: záporné množství, stejná jednotková cena, položka, jednotka, kód DPH bez sazby, dimenze
        $this->assertSame(-2.0, $unit['quantity']);
        $this->assertSame(500.0, $unit['unitPrice']);
        $this->assertSame('fromUnitPrice', $unit['priceCalcMode']);
        $this->assertArrayNotHasKey('totalPrice', $unit);
        $this->assertSame(['ourCode' => 'KONZ', 'name' => 'Konzultace IT'], $unit['item']);
        $this->assertSame('hr', $unit['unit'], 'systémový kód jednotky má přednost před zkratkou');
        $this->assertSame(['code' => 'cz-110'], $unit['vat'], 'sazbu určí applier k datu původního plnění');
        $this->assertSame('sale.services', $unit['operation']);
        $this->assertSame('Konzultace', $unit['description']);
        $this->assertSame(['costCenter' => 'V01'], $unit['dimensions']);

        // z celkové ceny: záporné množství i celková cena, jednotková kladná
        $this->assertSame(-3.0, $total['quantity']);
        $this->assertSame(-900.0, $total['totalPrice']);
        $this->assertSame(300.0, $total['unitPrice']);
        $this->assertSame('fromTotal', $total['priceCalcMode']);

        // sleva procentem beze změny, sleva částkou s opačným znaménkem
        $this->assertSame(10.0, $pct['discountPct']);
        $this->assertArrayNotHasKey('discountAmount', $pct);
        $this->assertSame(-100.0, $amount['discountAmount']);
        $this->assertArrayNotHasKey('discountPct', $amount);

        // textový řádek beze změny a bez ceny
        $this->assertSame(['rowKind' => 'text', 'orderPos' => 5, 'description' => 'Fakturujeme vám práce za březen.'], $text);

        // bez množství: znaménko nese cena
        $this->assertSame(0.0, $noQtyTotal['quantity']);
        $this->assertSame(-250.0, $noQtyTotal['totalPrice']);
        $this->assertSame(0.0, $noQtyUnit['quantity']);
        $this->assertSame(-50.0, $noQtyUnit['unitPrice']);
    }

    public function testForeignCurrencyKeepsOriginalRate(): void
    {
        $c = CorrectiveDocBuilder::build(self::head(['doc_currency' => 'eur', 'exchange_rate' => '24.500000']), self::rows(), self::CUSTOMER, [], '2026-04-20');
        $this->assertValidCanonical($c);

        $this->assertSame('EUR', $c['currency']);
        $this->assertSame(24.5, $c['exchangeRate'], 'kurz původního dokladu, ne z lístku (D4)');
    }

    public function testOptionalHeaderPartsAreLeftOutWhenMissing(): void
    {
        $head = self::head(['partner' => null, 'bank_account' => null, 'vat_registration' => null, 'vat_reg_country' => null, 'vat_mode' => 0, 'exchange_rate' => null, 'vat_duzp' => null]);
        $c = CorrectiveDocBuilder::build($head, [self::rows()[0]], null, [], '2026-04-20');

        $this->assertArrayNotHasKey('customer', $c, 'bez partnera zastaví validátor applieru, builder nic nevymýšlí');
        $this->assertArrayNotHasKey('customer', $c['_resolve']);
        $this->assertArrayNotHasKey('exchangeRate', $c);
        $this->assertArrayNotHasKey('dimensions', $c);
        $this->assertSame(['mode' => 'none', 'place' => 'domestic', 'calcSource' => 'header'], $c['vat']);
        $this->assertSame(['targetDocState' => 10, 'numberSeriesId' => 3], $c['applyOptions']);
        $this->assertSame('2026-03-01', $c['correction']['originalTaxPointDate'], 'bez DUZP datum vystavení');
    }
}
