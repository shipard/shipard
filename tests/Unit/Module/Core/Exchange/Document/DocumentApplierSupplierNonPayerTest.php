<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Shipard\Module\Core\Exchange\Document\ApplyMode;
use PHPUnit\Framework\TestCase;

/**
 * Přijatý doklad **od neplátce DPH v tuzemsku** a kód „Mimo DPH“ v applieru
 * (#164 D5, U10, U11, tasks/vat-codebook-revision.md):
 *
 *  - doklad od neplátce (`vat.mode` none, řádky bez sazby) vznikne celý
 *    Bez DPH — bez kódů a rekapitulace, ceny řádků beze změny. Nahrazuje
 *    zrušený kód „Bez daně“ s nulovou rekapitulací (#103 F3 nález B);
 *  - řádek s 0 % na dokladu plátce se odvodí jako `cz-114` „Osvobozeno“;
 *  - `cz-000` se neodvozuje — volí ho uživatel, nebo přijde v payloadu.
 *
 * Skutečný `vat-cz.jsonc` a skutečný `DocsHeadsDocument` v heads gatewayi
 * (lešení {@see OutOfScopeHarness}); fiktivní dodavatel a částky.
 */
class DocumentApplierSupplierNonPayerTest extends TestCase
{
    use OutOfScopeHarness;

    private const OUT_OF_SCOPE_BLOCK = ['status' => 'matched', 'matchedBy' => 'outOfScope'];

    /**
     * Faktura od tuzemského neplátce: režim Bez DPH, řádek bez kódu a sazby,
     * bez rekapitulace a bez DIČ dodavatele.
     *
     * @return array<string, mixed>
     */
    private function nonPayerPayload(): array
    {
        $payload = $this->happyPayload();
        $payload['supplier']['vatId'] = null;
        $payload['vat'] = ['mode' => 'none', 'place' => 'domestic', 'registrationCountry' => null];
        $payload['rows'][0]['unitPrice'] = 1250.0;
        $payload['rows'][0]['totalPrice'] = 12500.0;
        $payload['rows'][0]['vat'] = ['code' => null, 'pct' => null, 'supplyKind' => null, 'reverseChargeCode' => null];
        unset($payload['rows'][0]['computed']);
        $payload['vatRecap'] = [];
        $payload['totals'] = ['totalBase' => null, 'totalVat' => null, 'totalAmount' => 12500.0, 'totalRounding' => 0];
        return $payload;
    }

    /** @return array<string, mixed> `_resolve.vat.mode` dokladu od neplátce */
    private function nonPayerMode(string $auto = 'none'): array
    {
        return ['value' => 'none', 'source' => 'outOfScope', 'auto' => $auto, 'reason' => 'supplierNonPayer'];
    }

    // ── D5a: doklad od neplátce v tuzemsku ──────────────────────────────────

    public function testDocumentFromNonPayerPreviewIsWithoutVatCodesAndRecap(): void
    {
        $result = $this->buildApplier()->preview($this->nonPayerPayload(), ApplyMode::Proposal);

        $this->assertTrue($result->success);
        $resolve = $result->canonical['_resolve'];
        $this->assertSame($this->nonPayerMode(), $resolve['vat']['mode']);
        $this->assertSame('domestic', $resolve['vat']['place']['value']);
        // Nabídka kódů zůstává — volbou kódu se uživatel vrátí k dokladu s DPH.
        $this->assertNotSame([], $resolve['vatCodeOptions']);
        $this->assertSame(self::OUT_OF_SCOPE_BLOCK, $resolve['rows'][0]['vatCode']);

        $computed = $resolve['computed'];
        $this->assertSame([], $computed['vatRecap']);
        $this->assertSame(12500.0, $computed['totals']['totalAmount']);
        $this->assertSame(0.0, $computed['totals']['totalVat']);
        // Neplátce daň neúčtuje — ceny řádků se nepřevádějí.
        $this->assertSame([['index' => 0, 'unitPrice' => 1250.0, 'totalPrice' => 12500.0]], $computed['rows']);

        $issue = $this->issue($result->canonical, 'vat_supplier_non_payer');
        $this->assertNotNull($issue);
        $this->assertSame('info', $issue['severity']);
        $this->assertSame('vat.mode', $issue['path']);
        $codes = $this->issueCodes($result->canonical);
        foreach (['vat_code_unknown', 'vat_code_derived', 'vat_mode_derived', 'vat_mode_suspect',
            'recap_source_computed_fallback', 'vat_foreign_tax', 'vat_non_payer'] as $code) {
            $this->assertNotContains($code, $codes);
        }
        $this->assertSame(0, $resolve['summary']['errorCount']);
    }

    public function testDocumentFromNonPayerIsSavedWithoutVat(): void
    {
        $applied = $this->buildApplier()->apply($this->nonPayerPayload(), ApplyMode::Proposal);

        $this->assertTrue($applied->success, "{$applied->errorCode} {$applied->errorMessage}");
        $saved = $this->savedHeadsData;
        $this->assertIsArray($saved);
        $this->assertSame(0, (int) $saved['vat_mode']);
        $this->assertSame(0, (int) $saved['vat_place']);
        $this->assertSame([], $saved['vatRecap']);
        $this->assertSame(12500.0, (float) $saved['total_amount']);
        $row = $saved['rows'][0];
        $this->assertTrue(empty($row['vat_code']), 'řádek dokladu od neplátce kód nenese');
        $this->assertTrue(empty($row['vat_pct']));
        $this->assertSame(12500.0, (float) $row['total_price']);
        $this->assertSame(0, (int) $row['price_calc_mode'], 'cena řádku se nepřevádí');
        $this->assertSame(12500.0, (float) $row['vat_base']);
        $this->assertSame(0.0, (float) $row['vat_amount']);
    }

    /** Režim Bez DPH zvolený v náhledu u dokladu bez daně: totéž, hodnota „Automaticky“ zůstává vidět. */
    public function testModePinNoneMakesDomesticDocumentWithoutTaxANonPayerDocument(): void
    {
        $payload = $this->nonPayerPayload();
        $payload['vat']['mode'] = 'fromBase';
        $applier = $this->buildApplier();

        // Bez volby: doklad s DPH, řádek bez sazby nejde odvodit.
        $plain = $applier->preview($payload, ApplyMode::Proposal)->canonical;
        $this->assertNotSame('outOfScope', $plain['_resolve']['vat']['mode']['source']);
        $this->assertContains('vat_code_unknown', $this->issueCodes($plain));

        $pinned = $applier->preview($this->withPins($payload, ['vat.mode' => 'useValue:none']), ApplyMode::Proposal)->canonical;
        $this->assertSame($this->nonPayerMode('fromBase'), $pinned['_resolve']['vat']['mode']);
        $this->assertSame(self::OUT_OF_SCOPE_BLOCK, $pinned['_resolve']['rows'][0]['vatCode']);
        $this->assertNotContains('vat_code_unknown', $this->issueCodes($pinned));
        $this->assertContains('vat_supplier_non_payer', $this->issueCodes($pinned));

        // Volba Bez DPH má přednost před volbou kódu řádku.
        $both = $applier->preview($this->withPins($payload, [
            'vat.mode'        => 'useValue:none',
            'rows[0].vatCode' => 'useCode:cz-110',
        ]), ApplyMode::Proposal)->canonical;
        $this->assertSame('supplierNonPayer', $both['_resolve']['vat']['mode']['reason']);
        $this->assertContains('vat_pin_ignored', $this->issueCodes($both));
    }

    // ── Co dokladem od neplátce není ────────────────────────────────────────

    /** Volbou kódu na řádku se uživatel vrací k dokladu s DPH. */
    public function testRowCodePinCancelsNonPayerDocument(): void
    {
        $payload = $this->withPins($this->nonPayerPayload(), ['rows[0].vatCode' => 'useCode:cz-110']);

        $resolve = $this->buildApplier()->preview($payload, ApplyMode::Proposal)->canonical['_resolve'];

        $this->assertNotSame('outOfScope', $resolve['vat']['mode']['source']);
        $this->assertSame('user', $resolve['rows'][0]['vatCode']['matchedBy']);
        $this->assertSame('cz-110', $resolve['rows'][0]['vatCode']['createPayload']['code']);
    }

    public function testModePinWithVatCancelsNonPayerDocument(): void
    {
        $payload = $this->withPins($this->nonPayerPayload(), ['vat.mode' => 'useValue:fromBase']);

        $canonical = $this->buildApplier()->preview($payload, ApplyMode::Proposal)->canonical;

        $this->assertSame('user', $canonical['_resolve']['vat']['mode']['source']);
        $this->assertNotContains('vat_supplier_non_payer', $this->issueCodes($canonical));
    }

    /** Kód z canonicalu nebo z historie řádků potvrdil člověk — doklad jde dál jako dřív. */
    public function testKeptInputCodeCancelsNonPayerDocument(): void
    {
        $payload = $this->nonPayerPayload();
        $payload['rows'][0]['vat']['code'] = 'cz-110';

        $resolve = $this->buildApplier()->preview($payload, ApplyMode::Proposal)->canonical['_resolve'];

        $this->assertNotSame('outOfScope', $resolve['vat']['mode']['source']);
        $this->assertSame('cz-110', $resolve['rows'][0]['vatCode']['createPayload']['code']);
    }

    /** Deklarované `none` u dokladu, jehož řádky sazbu mají, není doklad od neplátce. */
    public function testDeclaredNoneWithRowRatesIsNotANonPayerDocument(): void
    {
        $payload = $this->nonPayerPayload();
        $payload['rows'][0]['vat']['pct'] = 21;

        $canonical = $this->buildApplier()->preview($payload, ApplyMode::Proposal)->canonical;

        $this->assertNotSame('outOfScope', $canonical['_resolve']['vat']['mode']['source']);
        $this->assertSame('cz-110', $canonical['_resolve']['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertNotContains('vat_supplier_non_payer', $this->issueCodes($canonical));
    }

    /** Tuzemské přenesení daňové povinnosti: Bez DPH se dál přepíná na „Ze základu“. */
    public function testDomesticReverseChargeIsNotANonPayerDocument(): void
    {
        $byHead = $this->nonPayerPayload();
        $byHead['vat']['reverseCharge'] = true;
        $byRow = $this->nonPayerPayload();
        $byRow['vat']['reverseCharge'] = true;
        $byRow['rows'][0]['vat']['reverseChargeCode'] = '4';

        foreach ([$byHead, $byRow] as $payload) {
            $canonical = $this->buildApplier()->preview($payload, ApplyMode::Proposal)->canonical;
            $mode = $canonical['_resolve']['vat']['mode'];

            $this->assertSame('fromBase', $mode['value']);
            $this->assertSame('derived', $mode['source']);
            $this->assertSame('cz-115', $canonical['_resolve']['rows'][0]['vatCode']['createPayload']['code']);
            $this->assertNotContains('vat_supplier_non_payer', $this->issueCodes($canonical));
        }
    }

    /** Import (starý Shipard, datové sady) přenáší hotové doklady — beze změny. */
    public function testImportModeIsNeverANonPayerDocument(): void
    {
        $payload = $this->nonPayerPayload();
        $payload['applyOptions'] = ['importNumber' => ['docNumber' => '2024-0042', 'sequenceNumber' => 42]];

        $canonical = $this->buildApplier()->preview($payload, ApplyMode::Instruction)->canonical;

        $this->assertNotSame('outOfScope', $canonical['_resolve']['vat']['mode']['source']);
        $this->assertNotContains('vat_supplier_non_payer', $this->issueCodes($canonical));
    }

    // ── D5b: 0 % v tuzemsku na dokladu plátce ───────────────────────────────

    public function testZeroRateRowOnPayerDocumentDerivesExemptCode(): void
    {
        $payload = $this->happyPayload();
        $payload['rows'][1] = [
            'rowKind' => 'item', 'orderPos' => 2,
            'item' => ['ourCode' => null, 'supplierCode' => null, 'name' => 'Pojištění zásilky', 'description' => null],
            'unit' => 'ks', 'quantity' => 1, 'unitPrice' => 300.0, 'totalPrice' => 300.0,
            'priceCalcMode' => 'fromUnitPrice',
            'vat' => ['code' => null, 'pct' => 0, 'supplyKind' => null, 'reverseChargeCode' => null],
        ];

        $canonical = $this->buildApplier()->preview($payload, ApplyMode::Proposal)->canonical;

        $vatCode = $canonical['_resolve']['rows'][1]['vatCode'];
        $this->assertSame('derived', $vatCode['matchedBy']);
        $this->assertSame('cz-114', $vatCode['createPayload']['code']);
        $this->assertSame(0.0, $vatCode['createPayload']['pct']);
        $this->assertNotSame('outOfScope', $canonical['_resolve']['vat']['mode']['source']);
        $this->assertNotContains('vat_code_unknown', $this->issueCodes($canonical));
    }

    // ── D5c: „Mimo DPH“ volí uživatel, nebo přijde v payloadu ───────────────

    public function testOutOfScopeCodeCanBeChosenInPreviewForEveryPlace(): void
    {
        foreach (['domestic', 'intracom', 'thirdCountry'] as $place) {
            $payload = $this->withPins($this->happyPayload(), [
                'vat.place'       => "useValue:{$place}",
                'rows[0].vatCode' => 'useCode:cz-000',
            ]);

            $canonical = $this->buildApplier()->preview($payload, ApplyMode::Proposal)->canonical;
            $resolve = $canonical['_resolve'];

            $this->assertSame($place, $resolve['vat']['place']['value']);
            $options = array_column($resolve['vatCodeOptions'], 'code');
            $this->assertSame('cz-000', end($options), "{$place}: „Mimo DPH“ je v nabídce poslední");
            $this->assertSame('user', $resolve['rows'][0]['vatCode']['matchedBy'], $place);
            $this->assertSame('cz-000', $resolve['rows'][0]['vatCode']['createPayload']['code'], $place);
            $codes = $this->issueCodes($canonical);
            $this->assertNotContains('vat_code_pin_invalid', $codes, $place);
            $this->assertNotContains('vat_code_pin_conflict', $codes, "{$place}: „Mimo DPH“ se signály v rozporu není");
        }
    }

    public function testOutOfScopeCodeFromPayloadIsKeptAndStaysOutOfRecap(): void
    {
        // Faktura plátce s odpočtem nezdaněné zálohy: řádek „Mimo DPH“
        // přichází v payloadu, částku dokladu sníží, v rekapitulaci není.
        $payload = $this->happyPayload();
        $payload['rows'][1] = [
            'rowKind' => 'item', 'orderPos' => 2,
            'item' => ['ourCode' => null, 'supplierCode' => null, 'name' => 'Odpočet zálohy', 'description' => null],
            'unit' => 'ks', 'quantity' => 1, 'unitPrice' => -2500.0, 'totalPrice' => -2500.0,
            'priceCalcMode' => 'fromUnitPrice',
            'vat' => ['code' => 'cz-000', 'pct' => 0, 'supplyKind' => null, 'reverseChargeCode' => null],
        ];
        $payload['totals'] = ['totalBase' => 7830.58, 'totalVat' => 2169.42, 'totalAmount' => 10000.0, 'totalRounding' => 0.0];
        $applier = $this->buildApplier();

        $canonical = $applier->preview($payload, ApplyMode::Proposal)->canonical;
        $vatCode = $canonical['_resolve']['rows'][1]['vatCode'];
        $this->assertSame('matched', $vatCode['status']);
        $this->assertSame('cz-000', $vatCode['createPayload']['code']);
        $codes = $this->issueCodes($canonical);
        foreach (['vat_code_unknown', 'vat_code_derived', 'vat_code_pin_conflict'] as $code) {
            $this->assertNotContains($code, $codes);
        }
        $this->assertSame(['cz-110'], array_column($canonical['_resolve']['computed']['vatRecap'], 'vatCode'));
        $this->assertSame(10000.0, $canonical['_resolve']['computed']['totals']['totalAmount']);

        $applied = $applier->apply($payload, ApplyMode::Proposal);
        $this->assertTrue($applied->success, "{$applied->errorCode} {$applied->errorMessage}");
        $saved = $this->savedHeadsData;
        $this->assertSame('cz-000', $saved['rows'][1]['vat_code']);
        $this->assertSame(['cz-110'], array_column($saved['vatRecap'], 'vat_code'));
        $this->assertSame(10000.0, (float) $saved['total_amount']);
        $this->assertSame(7830.58, (float) $saved['total_base']);
    }
}
