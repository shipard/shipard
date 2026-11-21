<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Docs\Core\DocDocument;
use Shipard\Module\Docs\Core\VatLineScope;
use Shipard\Module\World\Vat\VatRateResolver;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

/**
 * Tři stavy položkového řádku dokladu s DPH (#164 D1–D3, D7, U5–U8) nad
 * skutečným číselníkem `world.vat.cz`:
 *
 *  - kód v rekapitulaci (i osvobozený — základ a nulová daň);
 *  - `cz-000` „Mimo DPH“ — mimo rekapitulaci, v částce dokladu vždy,
 *    i u převzaté rekapitulace;
 *  - bez kódu — „nevyplněno“: varování v konceptu a v importním módu, chyba
 *    při potvrzení.
 */
class DocDocumentOutOfScopeRowsTest extends TestCase
{
    private const VAT_CZ_PATH = __DIR__ . '/../../../../../modules/world/vat/config/vat-cz.jsonc';

    private string $tmpDir;

    /** @var array<int, array<string, mixed>> Řádky, které vrací mock DB. */
    private array $dbRows = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_out_of_scope_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
        $this->writeConfig([]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/config/configuration/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir . '/config/configuration');
        rmdir($this->tmpDir . '/config');
        rmdir($this->tmpDir);
    }

    /** @param array<string, mixed> $extraItems další cfgItems vedle číselníku DPH */
    private function writeConfig(array $extraItems): void
    {
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode([
                '_meta' => ['language' => 'cs'],
                'items' => ['world.vat.cz' => JsoncParser::parseFile(self::VAT_CZ_PATH)] + $extraItems,
            ]),
        );
    }

    private function doc(bool $withCountry = true): TestableDocsHeadsDocument
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn($withCountry ? new Row(['country' => 'cz', 'id' => 1]) : null);
        $db->method('fetchAll')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'docs_core_vat_recap')
                ? []
                : array_map(static fn (array $r) => new Row($r), $this->dbRows),
        );

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig(ConfigRuntime::load($this->tmpDir, 'cs'));
        return $doc;
    }

    /** @return array<string, array<string, mixed>> */
    private function vatCodes(): array
    {
        return (new VatRateResolver(ConfigRuntime::load($this->tmpDir, 'cs')))
            ->getVatCodes('cz', direction: null, place: null, includeHidden: true);
    }

    /** @return array<string, mixed> */
    private function row(?string $code, float $price, array $extra = []): array
    {
        return $extra + [
            'row_kind'        => 1,
            'quantity'        => 1,
            'unit_price'      => $price,
            'price_calc_mode' => 0,
            'vat_code'        => $code,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function head(array $rows, array $extra = []): array
    {
        return $extra + [
            'vat_mode'            => 1,
            'vat_registration'    => 1,
            'vat_duzp'            => '2026-05-06',
            'issue_date'          => '2026-05-06',
            'exchange_rate'       => 1.0,
            'total_rounding_mode' => 0,
            'rows'                => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{column: string, code: string, severity: string}>
     */
    private function findings(TestableDocsHeadsDocument $doc, array $data, string $code): array
    {
        $result = $doc->validate($data);
        $out = [];
        foreach (['error' => $result->getErrors(), 'warning' => $result->getWarnings()] as $severity => $items) {
            foreach ($items as $item) {
                $item = $item->toArray();
                if ($item['code'] === $code) {
                    $out[] = ['column' => $item['column'], 'code' => $item['code'], 'severity' => $severity];
                }
            }
        }
        return $out;
    }

    // ── Přepočítaná rekapitulace ────────────────────────────────────────────

    /** @return array<string, array{0: int, 1: float, 2: float, 3: float, 4: float}> */
    public static function priceModes(): array
    {
        // vat_mode, cena zdaněného řádku, základ, daň, celkem zdaněné skupiny
        return [
            'zdola (ceny bez DPH)' => [1, 100.00, 100.00, 21.00, 121.00],
            'shora (ceny s DPH)'   => [2, 121.00, 100.00, 21.00, 121.00],
        ];
    }

    #[DataProvider('priceModes')]
    public function testOutOfScopeRowIsOutsideRecapButInDocumentAmount(
        int $vatMode,
        float $taxedPrice,
        float $base,
        float $tax,
        float $total,
    ): void {
        $data = $this->head(
            [$this->row('cz-110', $taxedPrice), $this->row('cz-000', 50.00)],
            ['vat_mode' => $vatMode],
        );
        $computed = $this->doc()->computeAmounts($data);

        $this->assertCount(1, $computed['recap'], 'rekapitulace jen ze zdaněného řádku');
        $this->assertSame('cz-110', $computed['recap'][0]['vat_code']);
        $this->assertSame($base, $computed['recap'][0]['base']);
        $this->assertSame($tax, $computed['recap'][0]['tax']);

        // Částka dokladu = oba řádky; „Mimo DPH“ je v základu, daň nemá.
        $this->assertSame($base + 50.00, $data['total_base']);
        $this->assertSame($tax, $data['total_vat']);
        $this->assertSame($total + 50.00, $data['total_amount']);
        $this->assertSame($base + 50.00, $data['total_base_dom']);
        $this->assertSame($total + 50.00, $data['total_amount_dom']);

        // U5: řádek „Mimo DPH“ se počítá jako dnes řádek bez kódu.
        $outOfScope = $computed['rows'][1];
        $this->assertSame(0.0, (float) $outOfScope['vat_pct']);
        $this->assertSame(50.00, $outOfScope['vat_base']);
        $this->assertSame(0.0, $outOfScope['vat_amount']);
        $this->assertSame(50.00, $outOfScope['vat_total']);
        $this->assertSame(50.00, $outOfScope['vat_total_dom']);
    }

    public function testOutOfScopeRowIsNotGroupedByRowMethodEither(): void
    {
        // vat_calc_source 1 = historická metoda „z řádků“ — druhé seskupení.
        $data = $this->head(
            [$this->row('cz-110', 100.00), $this->row('cz-000', 50.00)],
            ['vat_calc_source' => 1],
        );
        $computed = $this->doc()->computeAmounts($data);

        $this->assertSame(['cz-110'], array_column($computed['recap'], 'vat_code'));
        $this->assertSame(171.00, $data['total_amount']);
    }

    public function testDocumentWithOnlyOutOfScopeRowsHasNoRecap(): void
    {
        $data = $this->head([$this->row('cz-000', 50.00), $this->row('cz-000', -20.00)]);
        $computed = $this->doc()->computeAmounts($data);

        $this->assertSame([], $computed['recap']);
        $this->assertSame([], $data['vatRecap']);
        $this->assertSame(30.00, $data['total_base']);
        $this->assertSame(0.0, $data['total_vat']);
        $this->assertSame(30.00, $data['total_amount']);

        // Stejné pravidlo bez výpočtu — čte ho zámek období DPH.
        $this->assertFalse(DocDocument::willHaveVatRecap($data, false, $this->vatCodes()));
        $data['rows'][] = $this->row('cz-110', 100.00);
        $this->assertTrue(DocDocument::willHaveVatRecap($data, false, $this->vatCodes()));
    }

    /** @return array<string, array{0: string}> */
    public static function exemptInputCodes(): array
    {
        return ['tuzemsko' => ['cz-114'], 'EU' => ['cz-214'], 'třetí země' => ['cz-414']];
    }

    /** Osvobozené plnění na vstupu (#164 D4) je v rekapitulaci: základ a nulová daň. */
    #[DataProvider('exemptInputCodes')]
    public function testExemptInputCodeIsInRecapWithBaseAndZeroTax(string $code): void
    {
        $data = $this->head([$this->row($code, 800.00)]);
        $computed = $this->doc()->computeAmounts($data);

        $this->assertCount(1, $computed['recap']);
        $line = $computed['recap'][0];
        $this->assertSame($code, $line['vat_code']);
        $this->assertSame(800.00, $line['base']);
        $this->assertSame(0.0, $line['tax']);
        $this->assertSame(800.00, $line['total']);
        $this->assertSame(800.00, $data['total_amount']);
        $this->assertTrue(VatLineScope::inRecap($data['rows'][0], $this->vatCodes()));
    }

    // ── Převzatá rekapitulace (D7) ──────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $recap
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function declared(array $recap, array $extra = []): array
    {
        $data = $this->head([], $extra + ['id' => 42, 'vat_mode' => 2, 'vat_recap_source' => 1, 'vatRecap' => $recap]);
        unset($data['rows']); // řádky spravuje sub-formulář — čtou se z DB
        return $data;
    }

    public function testOutOfScopeRowCountsInDocumentAmountEvenWithDeclaredRecap(): void
    {
        $this->dbRows = [
            $this->row('cz-110', 121.00, ['id' => 1]),
            $this->row('cz-000', 50.00, ['id' => 2]),
        ];
        $data = $this->declared([
            ['vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 100.00, 'tax' => 21.00, 'total' => 121.00],
        ]);
        $doc = $this->doc();
        $computed = $doc->computeAmounts($data);

        $this->assertTrue($computed['recapDeclared']);
        $this->assertSame(['cz-110'], array_column($computed['recap'], 'vat_code'));
        $this->assertSame(150.00, $data['total_base']);
        $this->assertSame(21.00, $data['total_vat']);
        $this->assertSame(171.00, $data['total_amount']);
        $this->assertSame(150.00, $data['total_base_dom']);
        $this->assertSame(171.00, $data['total_amount_dom']);

        // Řádek „Mimo DPH“ do žádné skupiny nepatří — kontrola převzaté
        // rekapitulace ho nevidí a nic nehlásí.
        $this->assertSame([], $this->findings($doc, $data, 'rows_recap_mismatch'));
        $this->assertSame([], $this->findings($doc, $data, VatLineScope::RECAP_OUT_OF_SCOPE));
    }

    /** Odpočet nezdaněné zálohy: záporný řádek „Mimo DPH“ částku dokladu sníží. */
    public function testNegativeOutOfScopeRowLowersAmountOfDocumentWithDeclaredRecap(): void
    {
        $this->dbRows = [
            $this->row('cz-110', 121.00, ['id' => 1]),
            $this->row('cz-000', -30.00, ['id' => 2]),
        ];
        $data = $this->declared([
            ['vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 100.00, 'tax' => 21.00, 'total' => 121.00],
        ]);
        $this->doc()->computeAmounts($data);

        $this->assertSame(70.00, $data['total_base']);
        $this->assertSame(91.00, $data['total_amount']);
    }

    public function testOutOfScopeRowCountsInDomesticAmountsOfForeignCurrencyDocument(): void
    {
        $this->dbRows = [
            $this->row('cz-110', 121.00, ['id' => 1]),
            $this->row('cz-000', -30.00, ['id' => 2]),
        ];
        $data = $this->declared(
            [['vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 100.00, 'tax' => 21.00, 'total' => 121.00]],
            ['exchange_rate' => 25.0],
        );
        $this->doc()->computeAmounts($data);

        $this->assertSame(91.00, $data['total_amount']);
        $this->assertSame(1750.00, $data['total_base_dom'], '(100 − 30) × 25');
        $this->assertSame(525.00, $data['total_vat_dom']);
        $this->assertSame(2275.00, $data['total_amount_dom']);
    }

    /** Pro řádek bez kódu a řádek s běžným kódem mimo rekapitulaci platí dnešní pravidlo (U7). */
    public function testOtherRowsOutsideDeclaredRecapStayOutOfTotals(): void
    {
        $this->dbRows = [
            $this->row('cz-110', 121.00, ['id' => 1]),
            $this->row(null, 40.00, ['id' => 2]),
            $this->row('cz-111', 112.00, ['id' => 3]),
        ];
        $data = $this->declared([
            ['vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 100.00, 'tax' => 21.00, 'total' => 121.00],
        ]);
        $this->doc()->computeAmounts($data);

        $this->assertSame(100.00, $data['total_base']);
        $this->assertSame(121.00, $data['total_amount']);
    }

    public function testOutOfScopeCodeInDeclaredRecapIsAlwaysError(): void
    {
        $this->dbRows = [$this->row('cz-000', 50.00, ['id' => 1])];
        $recap = [['vat_code' => 'cz-000', 'vat_pct' => 0.0, 'base' => 50.00, 'tax' => 0.0, 'total' => 50.00]];
        $expected = [['column' => 'recap', 'code' => VatLineScope::RECAP_OUT_OF_SCOPE, 'severity' => 'error']];
        $doc = $this->doc();

        $draft = $this->declared($recap, ['docState' => 10]);
        $this->assertSame($expected, $this->findings($doc, $draft, VatLineScope::RECAP_OUT_OF_SCOPE), 'Koncept');

        $import = $this->declared($recap, ['docState' => 40, '_importNumber' => ['number' => 'X1']]);
        $this->assertSame($expected, $this->findings($doc, $import, VatLineScope::RECAP_OUT_OF_SCOPE), 'importní mód');
    }

    // ── Řádek bez kódu = nevyplněno (D2, D3, U8) ────────────────────────────

    public function testRowWithoutCodeWarnsInDraftAndFailsOnConfirmation(): void
    {
        $rows = [$this->row('cz-110', 100.00), $this->row(null, 50.00), $this->row('cz-000', 10.00)];
        $doc = $this->doc();

        $this->assertSame(
            [['column' => 'rows.1.vat_code', 'code' => 'vat_code_missing', 'severity' => 'warning']],
            $this->findings($doc, $this->head($rows, ['docState' => 10]), 'vat_code_missing'),
            'Koncept se uloží s varováním',
        );
        $this->assertSame(
            [['column' => 'rows.1.vat_code', 'code' => 'vat_code_missing', 'severity' => 'error']],
            $this->findings($doc, $this->head($rows, ['docState' => 40]), 'vat_code_missing'),
            'potvrzení skončí chybou na řádku',
        );
        // V opravě (stav 80) se doklad bez kódu uložit smí — jinak by starý
        // doklad nešel otevřít k opravě.
        $this->assertSame(
            'warning',
            $this->findings($doc, $this->head($rows, ['docState' => 80]), 'vat_code_missing')[0]['severity'],
        );
    }

    public function testRowWithoutCodeOnlyWarnsInImportMode(): void
    {
        $data = $this->head(
            [$this->row(null, 50.00)],
            ['docState' => 40, '_importNumber' => ['number' => 'X1']],
        );

        $this->assertSame(
            [['column' => 'rows.0.vat_code', 'code' => 'vat_code_missing', 'severity' => 'warning']],
            $this->findings($this->doc(), $data, 'vat_code_missing'),
        );
    }

    public function testDocumentWithoutVatCarriesNoCodes(): void
    {
        // D3: na dokladu Bez DPH jsou řádky bez kódu správně.
        $data = $this->head([$this->row(null, 50.00)], ['docState' => 40, 'vat_mode' => 0]);

        $this->assertSame([], $this->findings($this->doc(), $data, 'vat_code_missing'));
    }

    public function testMissingCodeIsNotReportedWithoutRegistrationCountry(): void
    {
        // Bez země registrace se nekontroluje — jako ostatní kontroly DPH.
        $data = $this->head([$this->row(null, 50.00)], ['docState' => 40]);

        $this->assertSame([], $this->findings($this->doc(withCountry: false), $data, 'vat_code_missing'));
    }

    public function testTextAndContationRowsNeedNoCode(): void
    {
        // Kontačnímu řádku (operace s vlajkou rowSide) formulář pole Kód DPH
        // nenabízí; textový řádek kód nenese nikdy.
        $this->writeConfig(['docs.core.rowOperations' => [
            'acc.record' => ['rowSide' => 1],
            'acc.fx'     => ['rowSide' => 0],
            'sale'       => [],
        ]]);
        $data = $this->head([
            $this->row(null, 0.0, ['row_kind' => 0]),
            $this->row(null, 50.00, ['operation' => 'acc.record']),
            $this->row(null, 50.00, ['operation' => 'acc.fx']),
            $this->row(null, 50.00, ['operation' => 'sale']),
        ], ['docState' => 10]);

        $this->assertSame(
            [['column' => 'rows.3.vat_code', 'code' => 'vat_code_missing', 'severity' => 'warning']],
            $this->findings($this->doc(), $data, 'vat_code_missing'),
        );
    }

    public function testRowsFromDatabaseAreCheckedOnHeaderOnlySave(): void
    {
        // Uložení hlavičky bez řádků v payloadu (přechod stavu) čte řádky z DB.
        $this->dbRows = [$this->row('cz-110', 100.00, ['id' => 1]), $this->row(null, 50.00, ['id' => 2])];
        $data = $this->head([], ['id' => 42, 'docState' => 40]);
        unset($data['rows']);

        $this->assertSame(
            [['column' => 'rows.1.vat_code', 'code' => 'vat_code_missing', 'severity' => 'error']],
            $this->findings($this->doc(), $data, 'vat_code_missing'),
        );
    }

    // ── Pomocník pravidla (U1) ──────────────────────────────────────────────

    public function testVatLineScopeDistinguishesThreeStates(): void
    {
        $codes = $this->vatCodes();
        $taxed = ['row_kind' => 1, 'vat_code' => 'cz-110'];
        $outOfScope = ['row_kind' => 1, 'vat_code' => 'cz-000'];
        $empty = ['row_kind' => 1, 'vat_code' => null];
        $text = ['row_kind' => 0, 'vat_code' => null];

        $this->assertTrue(VatLineScope::inRecap($taxed, $codes));
        $this->assertFalse(VatLineScope::isOutOfScope($taxed, $codes));
        $this->assertFalse(VatLineScope::lacksCode($taxed));

        $this->assertFalse(VatLineScope::inRecap($outOfScope, $codes));
        $this->assertTrue(VatLineScope::isOutOfScope($outOfScope, $codes));
        $this->assertFalse(VatLineScope::lacksCode($outOfScope), '„Mimo DPH“ je vyplněný kód');

        $this->assertFalse(VatLineScope::inRecap($empty, $codes));
        $this->assertFalse(VatLineScope::isOutOfScope($empty, $codes));
        $this->assertTrue(VatLineScope::lacksCode($empty));
        $this->assertTrue(VatLineScope::lacksCode(['vat_code' => '']), 'row_kind výchozí 1');

        foreach ([VatLineScope::inRecap($text, $codes), VatLineScope::isOutOfScope($text, $codes), VatLineScope::lacksCode($text)] as $state) {
            $this->assertFalse($state, 'textový řádek nemá žádný ze stavů');
        }

        // Bez definic kódů se kategorie nepozná — řádek s kódem je řádek
        // rekapitulace, jako před zavedením kódu „Mimo DPH“.
        $this->assertTrue(VatLineScope::inRecap($outOfScope, null));
        $this->assertFalse(VatLineScope::isOutOfScope($outOfScope, null));
    }
}
