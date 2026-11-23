<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core\Defaults;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\Defaults\DocRowDefaults;
use Shipard\Module\Docs\Core\DocHeadVatContext;

/**
 * Výchozí hodnoty řádku dokladu na jednom místě (#166 D40, D42, D43, D49,
 * D50): pohyb podle typu položky, obsah z karty položky, množství 1 a první
 * kód DPH nabídky — jen tam, kde klíč v datech chybí.
 */
class DocRowDefaultsTest extends TestCase
{
    use DefaultsFixtures;

    /** Položky: 1 služba s cenou a jednotkou, 2 zásoba bez ceny, 3 ostatní. */
    private const ITEMS = [
        1 => ['name' => 'Správa IT', 'sales_price_no_vat' => '5000.00', 'unit' => 8, 'item_type' => 0],
        2 => ['name' => 'Kabel', 'sales_price_no_vat' => null, 'unit' => null, 'item_type' => 1],
        3 => ['name' => 'Poštovné', 'sales_price_no_vat' => '0.00', 'unit' => 2, 'item_type' => 3],
    ];

    private function defaults(): DocRowDefaults
    {
        return new DocRowDefaults($this->db(items: self::ITEMS), $this->realConfig());
    }

    /** Kontext hlavičky z dat — stejnou cestou jako pokyn (hlavička ještě není uložená). */
    private function head(string $docType, int $vatMode = 1, int $cashDir = 0, ?int $registration = 7): array
    {
        return DocHeadVatContext::fromHead([
            'doc_type'         => $docType,
            'cash_dir'         => $cashDir,
            'vat_mode'         => $vatMode,
            'vat_place'        => 0,
            'issue_date'       => '2026-06-10',
            'vat_registration' => $registration,
        ], $this->db(), $this->realConfig());
    }

    // ── Pohyb ────────────────────────────────────────────────────────────────

    public function testOperationFollowsItemTypeMap(): void
    {
        $defaults = $this->defaults();

        $this->assertSame('sale.services', $defaults->operationFor($this->head('invno'), 0));
        $this->assertSame('sale.goods', $defaults->operationFor($this->head('invno'), 1));
        $this->assertSame('purchase.services', $defaults->operationFor($this->head('invni'), 0));
        $this->assertSame('purchase.other', $defaults->operationFor($this->head('invni'), 3));
    }

    /** Typ položky mimo mapu: výchozí pohyb mapy typu dokladu. */
    public function testItemTypeOutsideTheMapTakesMapDefault(): void
    {
        $defaults = $this->defaults();

        $this->assertSame('sale.services', $defaults->operationFor($this->head('invno'), 3), 'invno nemá sale.other');
        $this->assertSame('sale.goods', $defaults->operationFor($this->head('cashreg'), 3));
    }

    /**
     * Řádek bez položky: první pohyb nabídky, ne výchozí pohyb mapy —
     * *Účetní položka* přijaté faktury bez položky nejde potvrdit.
     */
    public function testRowWithoutItemTakesFirstOfferedOperation(): void
    {
        $defaults = $this->defaults();

        $this->assertSame('purchase.goods', $defaults->operationFor($this->head('invni'), null));
        $this->assertSame('sale.services', $defaults->operationFor($this->head('invno'), null));
        $this->assertSame('sale.services', $defaults->operationFor($this->head('cashreg'), null));
    }

    /** Typ dokladu bez záznamu v mapě: první pohyb nabídky (podle směru pokladního dokladu). */
    public function testDocTypeWithoutMapTakesFirstOfferedOperation(): void
    {
        $defaults = $this->defaults();

        $this->assertSame('sale.services', $defaults->operationFor($this->head('invpo'), 1));
        $this->assertSame('purchase.goods', $defaults->operationFor($this->head('invpi'), 0));
        $this->assertSame('sale.services', $defaults->operationFor($this->head('cash', cashDir: 1), null));
        $this->assertSame('purchase.goods', $defaults->operationFor($this->head('cash', cashDir: 2), null));
        $this->assertSame('acc.record', $defaults->operationFor($this->head('cmnbkp', vatMode: 0), null));
    }

    public function testOperationWithoutHeadIsNull(): void
    {
        $this->assertNull($this->defaults()->operationFor(null, 0));
    }

    public function testAutomaticOperationIsEmptyMappedOrFirstOffered(): void
    {
        $defaults = $this->defaults();
        $head = $this->head('invni');

        $this->assertTrue($defaults->isAutomaticOperation($head, ''));
        $this->assertTrue($defaults->isAutomaticOperation($head, 'acc.entry'), 'výchozí pohyb mapy');
        $this->assertTrue($defaults->isAutomaticOperation($head, 'purchase.services'), 'hodnota mapy');
        $this->assertTrue($defaults->isAutomaticOperation($head, 'purchase.goods'), 'první v nabídce');
        $this->assertFalse($defaults->isAutomaticOperation($head, 'purchase.advanceDeduction'), 'ručně zvolený zvláštní pohyb');
        $this->assertFalse($defaults->isAutomaticOperation($head, 'purchase.asset'));
    }

    // ── Řádek ────────────────────────────────────────────────────────────────

    public function testItemRowGetsOperationContentQuantityAndVatCode(): void
    {
        $row = ['row_kind' => 1, 'item' => 1];
        $filled = $this->defaults()->apply($row, $this->head('invno'));

        $this->assertSame(['operation', 'description', 'unit_price', 'unit', 'quantity', 'vat_code'], $filled);
        $this->assertSame('sale.services', $row['operation']);
        $this->assertSame('Správa IT', $row['description']);
        $this->assertSame(5000.0, $row['unit_price']);
        $this->assertSame(8, $row['unit']);
        $this->assertSame(1, $row['quantity']);
        $this->assertSame('cz-120', $row['vat_code'], 'první kód nabídky tuzemsko / výstup');
    }

    /** Položka bez ceny a jednotky: doplní se jen text; nulová cena se nepočítá. */
    public function testItemWithoutPriceAndUnitFillsOnlyDescription(): void
    {
        $row = ['item' => 2];
        $filled = $this->defaults()->apply($row, $this->head('invno'));

        $this->assertSame(['operation', 'description', 'quantity', 'vat_code'], $filled);
        $this->assertArrayNotHasKey('unit_price', $row);
        $this->assertArrayNotHasKey('unit', $row);

        $row = ['item' => 3];
        $this->defaults()->apply($row, $this->head('invno'));
        $this->assertArrayNotHasKey('unit_price', $row, 'cena 0 na kartě = nevyplněná');
    }

    /** Řádek bez položky: pohyb, množství a kód DPH. */
    public function testRowWithoutItemGetsOperationQuantityAndVatCode(): void
    {
        $row = ['description' => 'Práce'];
        $filled = $this->defaults()->apply($row, $this->head('invni'));

        $this->assertSame(['operation', 'quantity', 'vat_code'], $filled);
        $this->assertSame('purchase.goods', $row['operation'], 'bez položky první pohyb nabídky');
        $this->assertSame('Práce', $row['description']);
    }

    /** D50: přítomný klíč vyhrává — hodnota i výslovné `null`. */
    public function testPresentKeysWinIncludingNull(): void
    {
        $row = [
            'item' => 1, 'operation' => 'sale.goods', 'description' => 'Vlastní text',
            'unit_price' => 4200, 'unit' => null, 'quantity' => 3, 'vat_code' => null,
        ];
        $before = $row;
        $filled = $this->defaults()->apply($row, $this->head('invno'));

        $this->assertSame([], $filled);
        $this->assertSame($before, $row);
    }

    public function testHeadWithoutVatGetsNoVatCode(): void
    {
        $row = ['item' => 1];
        $filled = $this->defaults()->apply($row, $this->head('invno', vatMode: 0));

        $this->assertNotContains('vat_code', $filled);
        $this->assertArrayNotHasKey('vat_code', $row);
    }

    /** Bez registrace není země číselníku — nabídka je prázdná a řádek zůstane bez kódu. */
    public function testHeadWithoutRegistrationGetsNoVatCode(): void
    {
        $row = ['item' => 1];
        $this->defaults()->apply($row, $this->head('invno', registration: null));

        $this->assertArrayNotHasKey('vat_code', $row);
    }

    /** Importní mód (D49): obsah řádku se nedoplňuje, pohyb ano. */
    public function testWithoutContentOnlyOperationIsFilled(): void
    {
        $row = ['item' => 1];
        $filled = $this->defaults()->apply($row, $this->head('invno'), content: false);

        $this->assertSame(['operation'], $filled);
        $this->assertSame(['item' => 1, 'operation' => 'sale.services'], $row);
    }

    public function testTextRowAndContationRowGetNothing(): void
    {
        $text = ['row_kind' => 0];
        $this->assertSame([], $this->defaults()->apply($text, $this->head('invno')));
        $this->assertSame(['row_kind' => 0], $text);

        $contation = ['acc_side' => 0, 'account' => 12];
        $this->assertSame([], $this->defaults()->apply($contation, $this->head('cmnbkp', vatMode: 0)));
    }

    /** Pohyb se stranou na řádku (účetní doklad): doplní se pohyb a dál nic. */
    public function testRowSideOperationStopsAfterOperation(): void
    {
        $row = [];
        $filled = $this->defaults()->apply($row, $this->head('cmnbkp', vatMode: 0));

        $this->assertSame(['operation'], $filled);
        $this->assertSame(['operation' => 'acc.record'], $row);
    }
}
