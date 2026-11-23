<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange;

use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\ApplyMode;
use Shipard\Module\Docs\Core\RoundingModes;
use Shipard\Tests\Integration\Exchange\RoundTrip\RoundTripEnvironment;
use Shipard\Tests\Integration\Exchange\RoundTrip\RoundTripFixtures as F;
use Shipard\Tests\Integration\Exchange\RoundTrip\SavedDocument;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Případy „payload → uložený doklad“ pro režim pokyn (#166 D24,
 * tasks/doc-apply-instruction-mode.md U8) — tvary, které export nevyrobí,
 * a proto je matice round-tripu ({@see DocumentRoundTripMatrixTest})
 * nepokryje: řádky s objektem `vat` bez kódu (import ze starého Shipardu),
 * místo plnění v rozporu s DIČ dodavatele, převzatá rekapitulace bez kódů,
 * ceny řádků s daní při `fromBase`. Druhá skupina (#166 D25–D27,
 * tasks/doc-totals-rounding-explicit.md U7): `totals` jako kontrolní součet
 * a výslovný mód zaokrouhlení — součty, které se od výpočtu liší, mód
 * v rozporu s automatikou, součty v cizí měně. Třetí skupina (#166 D30–D39,
 * tasks/doc-format-references.md U8): reference — nenalezená, nejednoznačná
 * a špatně zapsaná, strana jen údaji, signály návrhu v pokynu, řada
 * a registrace DPH referencí; kde to dává smysl, týž payload i v návrhu.
 * Čtvrtá skupina (#166 D40–D50, tasks/doc-defaults-shared.md U10): výchozí
 * hodnoty pokynu — minimální payload, hodnota z payloadu vyhrává, výslovné
 * `null`, importní mód, `_resolve.defaults`, jeden nález na pole.
 *
 * Payload vzniká z dokladu pořízeného cestou formuláře (koncept → export)
 * a úpravou toho, co případ zkoumá; po apply se čte uložený doklad
 * z databáze. Fixtury a úklid sdílí s maticí ({@see RoundTripEnvironment}).
 */
class DocumentInstructionPayloadTest extends IntegrationTestCase
{
    use RoundTripEnvironment;

    private int $numbers = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootRoundTripEnvironment();
    }

    protected function onTearDown(): void
    {
        $this->shutdownRoundTripEnvironment();
    }

    // ── Doklad Bez DPH: nic se neodvozuje ───────────────────────────────────

    public function testNoVatDocumentFromForeignSupplierInImportModeIsSavedAsWritten(): void
    {
        $f = $this->fixtures;
        $payload = $this->payload($f->receivedHead('bez DPH ze zahraničí', [
            'partner'  => $f->person('euSupplier'),
            'vat_mode' => 0,
        ]) + ['rows' => [$f->row(false, 'služby', 1, 300.00, null), $f->row(false, 'doprava', 1, 50.00, null)]]);
        // Tvar importu ze starého Shipardu: objekt `vat` bez kódu na každém řádku.
        $payload = self::withRowVat($payload, ['pct' => 0]);
        $this->assertSame('none', $payload['vat']['mode']);
        $this->assertSame('domestic', $payload['vat']['place']);

        $saved = $this->saved($this->applier->apply($this->options($payload, 40, import: true)));

        $this->assertSame(0, (int) $saved->head['vat_mode']);
        $this->assertSame(0, (int) $saved->head['vat_place'], 'místo plnění z payloadu, ne z DIČ dodavatele');
        $this->assertSame(40, (int) $saved->head['docState']);
        $this->assertSame([null, null], array_column($saved->rows, 'vat_code'));
        $this->assertSame([], $saved->recap);
    }

    public function testNoVatDocumentFromDomesticSupplierGetsNoCodeAndNoRecap(): void
    {
        $f = $this->fixtures;
        $payload = $this->payload($f->receivedHead('bez DPH od neplátce', [
            'partner'  => $f->person('nonPayer'),
            'vat_mode' => 0,
        ]) + ['rows' => [$f->row(false, 'služby', 2, 750.00, null)]]);
        $payload = self::withRowVat($payload, ['pct' => 0]);

        $result = $this->applier->apply($this->options($payload, 40));
        $saved = $this->saved($result);

        $this->assertSame(0, (int) $saved->head['vat_mode']);
        $this->assertSame([null], array_column($saved->rows, 'vat_code'), 'žádný odvozený kód „Osvobozeno“');
        $this->assertSame([], $saved->recap, 'žádný řádek rekapitulace navíc');
        $this->assertSame([], array_intersect(['vat_code_unknown', 'vat_code_derived'], self::codes($result)));
    }

    public function testDomesticPlaceAndCodesAreKeptForSupplierWithForeignVatId(): void
    {
        $f = $this->fixtures;
        $code = $f->vatCode('input', 'domestic', 'standard');
        $payload = $this->payload($f->receivedHead('tuzemské plnění od zahraničního dodavatele', [
            'partner' => $f->person('euSupplier'),
        ]) + ['rows' => [$f->row(false, 'služby', 1, 1000.00, $code)]]);
        $this->assertSame('domestic', $payload['vat']['place']);

        $result = $this->applier->apply($this->options($payload, 40));
        $saved = $this->saved($result);

        $this->assertSame(0, (int) $saved->head['vat_place']);
        $this->assertSame(1, (int) $saved->head['vat_mode']);
        $this->assertSame([$code], array_column($saved->rows, 'vat_code'));
        $this->assertSame([$code], array_column($saved->recap, 'vat_code'));
        $this->assertSame([], array_intersect(['vat_place_derived', 'vat_code_derived'], self::codes($result)));
    }

    // ── Řádek bez kódu DPH: výchozí kód, výslovné null rozhoduje DocDocument ─

    /**
     * #166 D43, D44: řádek bez kódu na dokladu s DPH dostane první kód
     * nabídky jako ve formuláři — náhled i uložení to řeknou v
     * `_resolve.defaults`, protože výchozí kód mění částky.
     */
    public function testRowWithoutCodeGetsTheDefaultCodeAndTheResponseSaysSo(): void
    {
        $code = $this->fixtures->vatCode('input', 'domestic', 'standard');
        $payload = $this->options($this->payloadWithoutRowCode(), 40);

        $preview = $this->applier->preview($payload);
        $this->assertTrue($preview->success);
        $this->assertSame([], self::findings($preview, 'vat_code_missing'));
        $this->assertSame($code, self::defaults($preview)['rows.0.vat.code'] ?? null);
        $this->assertArrayNotHasKey('code', $preview->canonical['rows'][0]['vat'], 'odpověď vrací payload, jak přišel');

        $result = $this->applier->apply($payload);
        $saved = $this->saved($result);

        $this->assertSame(40, (int) $saved->head['docState']);
        $this->assertSame([$code], array_column($saved->rows, 'vat_code'));
        $this->assertSame($code, self::defaults($result)['rows.0.vat.code'] ?? null);
        $this->assertSame([1000.0, 210.0, 1210.0], self::amounts($saved, 'total_base', 'total_vat', 'total_amount'));
    }

    /** #166 D50: výslovné `null` je „záměrně bez kódu“ — nedoplní se a potvrzení zastaví doklad. */
    public function testExplicitNullCodeIsRejectedOnConfirmationAndPreviewShowsItFirst(): void
    {
        $payload = $this->options($this->payloadWithNullRowCode(), 40);
        $before = $this->documentCount();

        $preview = $this->applier->preview($payload);
        $this->assertTrue($preview->success);
        $this->assertSame([['error', 'rows.0.vat.code']], self::findings($preview, 'vat_code_missing'));
        $this->assertArrayNotHasKey('rows.0.vat.code', self::defaults($preview));

        $result = $this->applier->apply($payload);
        $this->assertFalse($result->success);
        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'rows.0.vat.code']], self::findings($result, 'vat_code_missing'));
        $this->assertSame($before, $this->documentCount(), 'nic se neuložilo');
    }

    public function testExplicitNullCodeIsSavedAsDraftWithWarning(): void
    {
        $result = $this->applier->apply($this->options(self::withoutChecksum($this->payloadWithNullRowCode()), 10));
        $saved = $this->saved($result);

        $this->assertSame(10, (int) $saved->head['docState']);
        $this->assertSame([null], array_column($saved->rows, 'vat_code'));
        $this->assertSame([['warning', 'rows.0.vat.code']], self::findings($result, 'vat_code_missing'));
    }

    /** #166 D27: se součty dokladu (daň 210) řádek záměrně bez kódu neprojde ani jako koncept — daň na dokladu není. */
    public function testNullCodeAndDeclaredTotalsFailsTheChecksumEvenAsDraft(): void
    {
        $payload = $this->options($this->payloadWithNullRowCode(), 10);
        $before = $this->documentCount();

        $result = $this->applier->apply($payload);

        $this->assertFalse($result->success);
        $this->assertSame(['totals_check_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame(
            [['error', 'totals.totalVat'], ['error', 'totals.totalAmount']],
            self::findings($result, 'totals_check_failed'),
        );
        $this->assertSame($before, $this->documentCount(), 'nic se neuložilo');
    }

    /**
     * D22: importní mód výjimku z validace nemá — a obsah řádku nedoplňuje
     * (#166 D49): historický řádek bez kódu by jinak tiše dostal základní sazbu.
     */
    public function testRowWithoutCodeIsRejectedOnConfirmationEvenInImportMode(): void
    {
        $payload = $this->options($this->payloadWithoutRowCode(), 40, import: true);
        $before = $this->documentCount();

        $result = $this->applier->apply($payload);

        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'rows.0.vat.code']], self::findings($result, 'vat_code_missing'));
        $this->assertArrayNotHasKey('rows.0.vat.code', self::defaults($result));
        $this->assertSame($before, $this->documentCount());
    }

    public function testCorrectiveDocumentWithoutReasonIsRejectedInImportMode(): void
    {
        $f = $this->fixtures;
        $code = $f->vatCode('output', 'domestic', 'standard');
        $originalId = $f->saveDocument(
            $f->issuedHead('původní doklad k opravě') + ['rows' => [$f->row(true, 'práce', 1, 1000.00, $code)]],
        );
        $payload = $this->payload($f->issuedHead('opravný doklad bez důvodu', [
            'corrective'        => 1,
            'corrected_doc'     => $originalId,
            'correction_reason' => F::PREFIX . ' sleva',
        ]) + ['rows' => [$f->row(true, 'sleva', -1, 200.00, $code)]]);
        $payload['correction']['reason'] = null;
        $before = $this->documentCount();

        $result = $this->applier->apply($this->options($payload, 40, import: true));

        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'correction.reason']], self::findings($result, 'correction_reason_required'));
        $this->assertSame($before, $this->documentCount());
    }

    // ── Převzatá rekapitulace je záměr ──────────────────────────────────────

    public function testDeclaredRecapWithoutCodeIsAnError(): void
    {
        $payload = $this->declaredRecapPayload();
        unset($payload['vatRecap'][0]['vatCode']);
        $before = $this->documentCount();

        $result = $this->applier->apply($this->options($payload, 40));

        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'vatRecap.0.vatCode']], self::findings($result, 'vat_recap_code_missing'));
        $this->assertSame($before, $this->documentCount(), 'žádný tichý přepočet z řádků');
    }

    public function testDeclaredRecapWithCodesIsTakenOverEvenWhenRowsDifferByAHeller(): void
    {
        $payload = $this->declaredRecapPayload();
        $this->assertSame('declared', $payload['vat']['recapSource']);

        $saved = $this->saved($this->applier->apply($this->options($payload, 40)));

        $this->assertSame(1, (int) $saved->head['vat_recap_source']);
        $this->assertCount(1, $saved->recap);
        // Řádky dávají daň 21,00; dodavatel vykázal 20,99 — platí rekapitulace.
        $this->assertEqualsWithDelta(20.99, (float) $saved->recap[0]['tax'], 0.001);
        $this->assertEqualsWithDelta(120.98, (float) $saved->head['total_amount'], 0.001);
    }

    public function testUnknownVatCodeIsAnError(): void
    {
        $f = $this->fixtures;
        $payload = $this->payload($f->receivedHead('neznámý kód') + ['rows' => [
            $f->row(false, 'služby', 1, 1000.00, $f->vatCode('input', 'domestic', 'standard')),
        ]]);
        $payload['rows'][0]['vat']['code'] = 'cz-999';
        $before = $this->documentCount();

        $result = $this->applier->apply($this->options($payload, 40));

        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'rows.0.vat.code']], self::findings($result, 'vat_code_unknown'));
        $this->assertSame($before, $this->documentCount());
    }

    // ── Režim výpočtu DPH je záměr ──────────────────────────────────────────

    public function testGrossRowPricesWithFromBaseKeepTheDeclaredMode(): void
    {
        $payload = self::withoutChecksum($this->grossRowsPayload());

        $result = $this->applier->apply($this->options($payload, 10));
        $saved = $this->saved($result);

        $this->assertSame(1, (int) $saved->head['vat_mode'], 'režim zůstává Ze základu');
        // 121 Kč je základ: daň se počítá z něj, žádná korekce ze součtů.
        $this->assertEqualsWithDelta(121.00, (float) $saved->head['total_base'], 0.001);
        $this->assertEqualsWithDelta(146.41, (float) $saved->head['total_amount'], 0.001);
        $this->assertSame([], array_intersect(['vat_mode_derived', 'vat_mode_suspect', 'totals_mismatch'], self::codes($result)));
    }

    /**
     * #166 D27: týž payload se součty dokladu (100 + 21 = 121). Pokyn režim
     * neopraví, ale kontrolní součet rozpor odhalí — doklad se neuloží.
     */
    public function testGrossRowPricesWithFromBaseAreCaughtByTheChecksum(): void
    {
        $payload = $this->options($this->grossRowsPayload(), 10);
        $before = $this->documentCount();

        $preview = $this->applier->preview($payload);
        $result = $this->applier->apply($payload);

        $expected = [['error', 'totals.totalBase'], ['error', 'totals.totalVat'], ['error', 'totals.totalAmount']];
        $this->assertSame($expected, self::findings($preview, 'totals_check_failed'), 'náhled hlásí totéž předem');
        $this->assertSame(['totals_check_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame($expected, self::findings($result, 'totals_check_failed'));
        $this->assertSame([], array_intersect(['vat_mode_derived', 'computed_total_mismatch'], self::codes($result)));
        $this->assertSame($before, $this->documentCount(), 'nic se neuložilo');
    }

    /** Týž payload v režimu návrh se chová jako dnes — režim se odvodí ze součtů. */
    public function testGrossRowPricesWithFromBaseAreCorrectedInProposalMode(): void
    {
        $payload = $this->options($this->grossRowsPayload(), 10);

        $preview = $this->applier->preview($payload, ApplyMode::Proposal);

        $this->assertContains('vat_mode_derived', self::codes($preview));
        $this->assertSame('fromTotal', $preview->canonical['_resolve']['vat']['mode']['value']);
    }

    // ── `totals` jako kontrolní součet, výslovný mód zaokrouhlení ───────────

    public function testTotalAmountOffByOneFailsTheChecksumAndSavesNothing(): void
    {
        $payload = $this->options($this->plainReceivedPayload(), 40);
        $payload['totals']['totalAmount'] = 1211.00;
        $before = $this->documentCount();

        $preview = $this->applier->preview($payload);
        $result = $this->applier->apply($payload);

        $this->assertSame([['error', 'totals.totalAmount']], self::findings($preview, 'totals_check_failed'), 'náhled hlásí totéž předem');
        $this->assertFalse($result->success);
        $this->assertSame(['totals_check_failed', 422], [$result->errorCode, $result->statusCode]);
        $finding = self::issue($result, 'totals_check_failed');
        $this->assertSame(
            ['error', 'totals.totalAmount', 1211.00, 1210.00],
            [$finding['severity'], $finding['path'], $finding['declared'], $finding['computed']],
        );
        $this->assertCount(1, self::findings($result, 'totals_check_failed'), 'ostatní pole sedí');
        $this->assertSame($before, $this->documentCount(), 'nic se neuložilo');
    }

    public function testDifferenceOfOneCentPassesTheChecksum(): void
    {
        $payload = $this->options($this->plainReceivedPayload(), 40);
        $payload['totals']['totalAmount'] = 1210.01;
        $payload['totals']['totalVat'] = 209.99;

        $result = $this->applier->apply($payload);
        $saved = $this->saved($result);

        $this->assertSame([1210.00, 210.00], self::amounts($saved, 'total_amount', 'total_vat'));
        $this->assertSame([], self::findings($result, 'totals_check_failed'));
    }

    /** Směrový mód z částek uhodnout nejde: 121,12 → nahoru 122,00, matematicky by vyšlo 121,00. */
    public function testCorrectTotalsWithUnitUpAreSavedWithThatMode(): void
    {
        $f = $this->fixtures;
        $payload = $this->payload($f->issuedHead('ruční zaokrouhlení nahoru', [
            'total_rounding_auto' => 0,
            'total_rounding_mode' => RoundingModes::UP_UNIT,
        ]) + ['rows' => [$f->row(true, 'práce', 1, 100.10, $f->vatCode('output', 'domestic', 'standard'))]]);
        $this->assertSame('unitUp', $payload['totals']['totalRoundingMode']);
        $this->assertSame([false, 122.00, 0.88], [
            $payload['totals']['totalRoundingAuto'], $payload['totals']['totalAmount'], $payload['totals']['totalRounding'],
        ]);

        $saved = $this->saved($this->applier->apply($this->options($payload, 10)));
        $this->assertSame(RoundingModes::UP_UNIT, (int) $saved->head['total_rounding_mode']);
        $this->assertSame([122.00, 0.88], self::amounts($saved, 'total_amount', 'total_rounding'));

        // Bez módu je pokyn na haléře — mód neodhaduje, rozpor odhalí kontrolní součet.
        unset($payload['totals']['totalRoundingMode']);
        $result = $this->applier->apply($this->options($payload, 10));
        $this->assertSame('totals_check_failed', $result->errorCode);
        $this->assertSame(
            [['error', 'totals.totalAmount'], ['error', 'totals.totalRounding']],
            self::findings($result, 'totals_check_failed'),
        );
    }

    /** #166 D26: automatika zapnutá a uvedený jiný mód → platí automatika, pokyn varuje. */
    public function testModeDifferentFromAutomaticIsIgnoredWithWarning(): void
    {
        $f = $this->fixtures;
        // Úhrada převodem: automatika dává „na haléře“.
        $payload = $this->payload($f->issuedHead('automatika a jiný mód', ['total_rounding_auto' => 1]) + ['rows' => [
            $f->row(true, 'práce', 1, 100.10, $f->vatCode('output', 'domestic', 'standard')),
        ]]);
        $this->assertTrue($payload['totals']['totalRoundingAuto']);
        $this->assertArrayNotHasKey('totalRoundingMode', $payload['totals'], 'při automatice export mód nenese');
        $payload = $this->options($payload, 10);
        $payload['totals']['totalRoundingMode'] = 'unitUp';

        $preview = $this->applier->preview($payload);
        $result = $this->applier->apply($payload);
        $saved = $this->saved($result);

        $expected = [['warning', 'totals.totalRoundingMode']];
        $this->assertSame($expected, self::findings($preview, 'total_rounding_mode_ignored'));
        $this->assertSame($expected, self::findings($result, 'total_rounding_mode_ignored'));
        $this->assertSame([1, RoundingModes::ON_CENT], [(int) $saved->head['total_rounding_auto'], (int) $saved->head['total_rounding_mode']]);
        $this->assertSame([121.12], self::amounts($saved, 'total_amount'));

        // Mód shodný s automatikou varování nedá.
        $payload['totals']['totalRoundingMode'] = 'cent';
        $this->assertSame([], self::findings($this->applier->apply($payload), 'total_rounding_mode_ignored'));
    }

    /** Součty jsou v měně dokladu; částka v domácí měně kontrolou neprojde. */
    public function testTotalsInForeignCurrencyAreCheckedInDocumentCurrency(): void
    {
        $f = $this->fixtures;
        $payload = $this->options($this->payload($f->issuedHead('součty v cizí měně', [
            'doc_currency'  => $f->homeCurrency() === 'eur' ? 'usd' : 'eur',
            'exchange_rate' => 24.565,
        ]) + ['rows' => [$f->row(true, 'práce', 4, 125.25, $f->vatCode('output', 'domestic', 'standard'))]]), 10);
        // 4 × 125,25 = 501,00 + 21 % = 606,21 v měně dokladu.
        $this->assertSame(606.21, $payload['totals']['totalAmount']);

        $saved = $this->saved($this->applier->apply($payload));
        $this->assertSame([606.21, 14891.55], self::amounts($saved, 'total_amount', 'total_amount_dom'));

        $payload['totals']['totalAmount'] = 14891.55;
        $result = $this->applier->apply($payload);
        $this->assertSame('totals_check_failed', $result->errorCode);
        $finding = self::issue($result, 'totals_check_failed');
        $this->assertSame([14891.55, 606.21], [$finding['declared'], $finding['computed']]);
    }

    /** #166 D28: týž chybný payload v návrhu se uloží s varováním jako dřív — kontrolní součet se nevyhodnocuje. */
    public function testWrongTotalsAreOnlyAWarningInProposalMode(): void
    {
        $payload = $this->options($this->plainReceivedPayload(), 10);
        $payload['totals']['totalAmount'] = 1215.00;

        $this->assertSame('totals_check_failed', $this->applier->apply($payload)->errorCode, 'pokyn tentýž payload odmítne');

        $preview = $this->applier->preview($payload, ApplyMode::Proposal);
        $result = $this->applier->apply($payload, ApplyMode::Proposal);
        $saved = $this->saved($result);

        $this->assertSame([['warning', 'totals.totalAmount']], self::findings($preview, 'computed_total_mismatch'));
        $this->assertSame([1210.00], self::amounts($saved, 'total_amount'));
        $this->assertContains('totals_mismatch', self::codes($result));
        $this->assertSame([], array_intersect(['totals_check_failed'], [...self::codes($preview), ...self::codes($result)]));
    }

    public function testImportNumberInProposalModeIsAnError(): void
    {
        $f = $this->fixtures;
        $payload = $this->payload($f->receivedHead('import v návrhu') + ['rows' => [
            $f->row(false, 'služby', 1, 1000.00, $f->vatCode('input', 'domestic', 'standard')),
        ]]);
        $before = $this->documentCount();

        $result = $this->applier->apply($this->options($payload, 40, import: true), ApplyMode::Proposal);

        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'applyOptions.importNumber']], self::findings($result, 'import_requires_instruction'));
        $this->assertSame($before, $this->documentCount());
    }

    // ── Reference (#166 D30–D39) ────────────────────────────────────────────

    /** Export píše každý odkaz referencí přirozeným klíčem — a pokyn ho tak zase najde. */
    public function testExportedReferencesAreFoundAgain(): void
    {
        $f = $this->fixtures;
        $payload = $this->referencePayload();

        $this->assertSame('@IT-RT-P2', $payload['supplier']['ref']);
        $this->assertSame('@' . F::PREFIX . '-NAKUP', $payload['rows'][0]['item']['ref']);
        $this->assertStringStartsWith('@', (string) $payload['numberSeries']);
        $this->assertStringStartsWith('@', (string) $payload['vat']['registration']);
        $this->assertArrayNotHasKey('numberSeriesCode', $payload['applyOptions']);

        $saved = $this->saved($this->applier->apply($this->options($payload, 10)));

        $this->assertSame($f->person('supplier'), (int) $saved->head['partner']);
        $this->assertSame($f->item(false), (int) $saved->rows[0]['item']);
        $this->assertSame($f->seriesId('invni'), (int) $saved->head['number_series']);
        $this->assertSame($f->vatRegistrationId(), (int) $saved->head['vat_registration']);
    }

    /** D35: osoba přes IČO, položka jen referencí kódem (bez objektu). */
    public function testPersonByCompanyIdAndItemByBareReference(): void
    {
        $f = $this->fixtures;
        $payload = $this->referencePayload();
        $payload['supplier'] = '@company_id:88200019';
        $payload['rows'][0]['item'] = '@' . F::PREFIX . '-NAKUP';

        $saved = $this->saved($this->applier->apply($this->options($payload, 10)));

        $this->assertSame($f->person('supplier'), (int) $saved->head['partner']);
        $this->assertSame($f->item(false), (int) $saved->rows[0]['item']);
        $this->assertSame('služby', mb_substr((string) $saved->rows[0]['description'], -6), 'text řádku z pole description');
    }

    public function testUnknownReferenceIsAnErrorAndNothingIsSaved(): void
    {
        $payload = $this->referencePayload();
        $before = $this->documentCount();

        foreach ([
            'supplier'    => static function (array $p): array { $p['supplier'] = '@IT-RT-NENI'; return $p; },
            'rows.0.item' => static function (array $p): array { $p['rows'][0]['item'] = ['ref' => '@IT-RT-NENI', 'name' => 'x']; return $p; },
            'numberSeries' => static function (array $p): array { $p['numberSeries'] = '@it-rt-neni'; return $p; },
        ] as $path => $change) {
            $result = $this->applier->apply($this->options($change($payload), 10));

            $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode], $path);
            $this->assertSame([['error', $path]], self::findings($result, 'reference_not_found'), $path);
            $this->assertSame($before, $this->documentCount(), $path);
        }
    }

    /** D35: dvě osoby se stejným IČO — nikdy první nalezená. */
    public function testAmbiguousCompanyIdIsAnError(): void
    {
        $payload = $this->referencePayload();
        $this->db->insertRow('base_persons_persons', [
            'person_id'    => F::PREFIX . '-P9',
            'person_type'  => 2,
            'full_name'    => F::PREFIX . ' Dvojník s.r.o.',
            'last_name'    => F::PREFIX . ' Dvojník s.r.o.',
            'first_name'   => '',
            'company_id'   => '88200019',
            'docState'     => 40,
            'docStateMain' => 3,
        ]);
        $payload['supplier'] = ['ref' => '@company_id:88200019'] + $payload['supplier'];
        $before = $this->documentCount();

        $result = $this->applier->apply($this->options($payload, 10));

        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame([['error', 'supplier']], self::findings($result, 'reference_ambiguous'));
        $this->assertSame($before, $this->documentCount());
    }

    /** D31: strana jen údaji není hledání podle jména ani tiché „bez partnera“. */
    public function testPartyByDataOnlyIsReferenceMissing(): void
    {
        $payload = $this->referencePayload();
        unset($payload['supplier']['ref']);
        $before = $this->documentCount();

        $result = $this->applier->apply($this->options($payload, 10));

        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame([['error', 'supplier']], self::findings($result, 'reference_missing'));
        $this->assertSame($before, $this->documentCount());

        // Návrh podle údajů hledá jako dřív (IČO dodavatele je v adresáři).
        $proposal = $this->saved($this->applier->apply($this->options($payload, 10), ApplyMode::Proposal));
        $this->assertSame($this->fixtures->person('supplier'), (int) $proposal->head['partner']);
    }

    /** D9: číselný řetězec není id. */
    public function testNumericStringIsAnInvalidReference(): void
    {
        $payload = $this->referencePayload();
        $payload['supplier'] = (string) $this->fixtures->person('supplier');

        $result = $this->applier->apply($this->options($payload, 10));

        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame([['error', 'supplier']], self::findings($result, 'reference_invalid'));
    }

    /** D31, D39: účet — nenalezená reference je chyba; holé číslo je signál, v návrhu jen varování. */
    public function testRowAccountReferenceAndSignal(): void
    {
        $payload = $this->referencePayload();

        $payload['rows'][0]['account'] = '@999999999';
        $result = $this->applier->apply($this->options($payload, 10));
        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame([['error', 'rows.0.account']], self::findings($result, 'reference_not_found'));

        $payload['rows'][0]['account'] = '999999999';
        $result = $this->applier->apply($this->options($payload, 10));
        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame([['error', 'rows.0.account']], self::findings($result, 'signal_not_allowed'));

        $proposal = $this->applier->apply($this->options($payload, 10), ApplyMode::Proposal);
        $this->saved($proposal);
        $this->assertSame([['warning', 'rows.0.account']], self::findings($proposal, 'account_not_found'));
    }

    /** D37: jednotka je holý systémový kód; neznámá je v pokynu chyba. */
    public function testUnknownUnitIsAnError(): void
    {
        $payload = $this->referencePayload();
        $payload['rows'][0]['unit'] = 'sáh';
        $before = $this->documentCount();

        $result = $this->applier->apply($this->options($payload, 10));

        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame([['error', 'rows.0.unit']], self::findings($result, 'unit_not_found'));
        $this->assertSame($before, $this->documentCount());
    }

    /** D39: `item.ourCode` je signál návrhu — pokyn ho odmítá, týž payload v návrhu projde. */
    public function testOurCodeIsRejectedInInstructionAndReadInProposal(): void
    {
        $f = $this->fixtures;
        $payload = $this->referencePayload();
        $payload['rows'][0]['item'] = ['ourCode' => F::PREFIX . '-NAKUP', 'name' => 'nákup služeb'];
        $before = $this->documentCount();

        $result = $this->applier->apply($this->options($payload, 10));
        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame([['error', 'rows.0.item.ourCode']], self::findings($result, 'signal_not_allowed'));
        $this->assertSame($before, $this->documentCount());

        $saved = $this->saved($this->applier->apply($this->options($payload, 10), ApplyMode::Proposal));
        $this->assertSame($f->item(false), (int) $saved->rows[0]['item']);
    }

    /** D33: návrh s `ref` hledání podle údajů přeskočí — údaje jiné osoby nic nezmění. */
    public function testProposalWithReferenceSkipsLookup(): void
    {
        $f = $this->fixtures;
        $payload = $this->referencePayload();
        $payload['supplier'] = ['ref' => '@IT-RT-P2', 'name' => 'Někdo úplně jiný a.s.', 'companyId' => '88300013', 'country' => 'cz'];
        $nonPayer = $f->person('nonPayer');

        $saved = $this->saved($this->applier->apply($this->options($payload, 10), ApplyMode::Proposal));

        $this->assertSame($f->person('supplier'), (int) $saved->head['partner']);
        $this->assertNotSame($nonPayer, (int) $saved->head['partner'], 'IČO v údajích patří jiné osobě — nehledá se');
    }

    /** D30: řada referencí kódem; u pokladního dokladu ji určuje pokladna a cizí řada je chyba. */
    public function testNumberSeriesByKeyAndConflictOnCashDocument(): void
    {
        $f = $this->fixtures;
        $invno = $f->seriesId('invno');
        $invnoRef = '@' . $this->db->fetchRow('SELECT [code] FROM [docs_core_number_series] WHERE [id] = %i', $invno)['code'];

        $issued = $this->payload($f->issuedHead('řada referencí') + ['rows' => [
            $f->row(true, 'práce', 1, 500.00, $f->vatCode('output', 'domestic', 'standard')),
        ]]);
        $this->assertSame($invnoRef, $issued['numberSeries'], 'export řadu vyváží referencí kódem');
        $saved = $this->saved($this->applier->apply($this->options($issued, 10)));
        $this->assertSame($invno, (int) $saved->head['number_series']);

        $desk = $f->cashDeskId();
        $cash = $this->payload([
            'doc_type' => 'cash', 'cash_dir' => 1, 'cash_desk' => $desk, 'number_series' => $f->seriesId('cash', $desk),
            'partner' => $f->person('customer'), 'vat_registration' => $f->vatRegistrationId(), 'vat_mode' => 1,
            'doc_currency' => $f->homeCurrency(), 'payment_method' => 0, 'issue_date' => F::DATE, 'accounting_date' => F::DATE,
            'doc_text' => F::PREFIX . ' pokladní příjem s cizí řadou',
            'rows' => [$f->row(true, 'práce', 1, 500.00, $f->vatCode('output', 'domestic', 'standard'))],
        ]);
        $this->assertArrayNotHasKey('numberSeries', $cash, 'řadu pokladního dokladu určuje pokladna — export ji nenese');
        $cash['numberSeries'] = $invnoRef;
        $before = $this->documentCount();

        $result = $this->applier->apply($this->options($cash, 10));

        $this->assertSame(['number_series_conflict', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame($before, $this->documentCount());
    }

    /** D36: registrace DPH referencí; bez pole výchozí registrace zdroje dat. */
    public function testVatRegistrationByKeyAndDefault(): void
    {
        $f = $this->fixtures;
        $payload = $this->referencePayload();

        $withRef = $this->saved($this->applier->apply($this->options($payload, 10)));
        $this->assertSame($f->vatRegistrationId(), (int) $withRef->head['vat_registration']);

        unset($payload['vat']['registration']);
        $default = $this->saved($this->applier->apply($this->options($payload, 10)));
        $this->assertSame($f->vatRegistrationId(), (int) $default->head['vat_registration'], 'bez pole výchozí registrace');

        $payload['vat']['registration'] = '@it-rt-neni';
        $result = $this->applier->apply($this->options($payload, 10));
        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame([['error', 'vat.registration']], self::findings($result, 'reference_not_found'));

        $payload['vat'] = ['registrationCountry' => $f->country()] + $payload['vat'];
        unset($payload['vat']['registration']);
        $result = $this->applier->apply($this->options($payload, 10));
        $this->assertSame([['error', 'vat.registrationCountry']], self::findings($result, 'signal_not_allowed'));
    }

    // ── Partner účetního a pokladního dokladu (D30, D32) ────────────────────

    /** D30: `partner` na faktuře je chyba — partnerem faktury je strana. Nic se neuloží. */
    public function testPartnerFieldOnInvoiceIsAnError(): void
    {
        $payload = $this->referencePayload();
        $payload['partner'] = $payload['supplier']['ref'];
        $before = $this->documentCount();

        $result = $this->applier->apply($this->options($payload, 10));

        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'partner']], self::findings($result, 'partner_not_supported'));
        $this->assertSame($before, $this->documentCount());
    }

    /** D30: partner hlavičky i řádku účetního dokladu polem; nenalezená reference je chyba a nic se neuloží. */
    public function testAccountingDocumentPartnerFieldsAndUnknownReference(): void
    {
        $f = $this->fixtures;
        $payload = $this->accountingPayload();
        $this->assertArrayNotHasKey('partner', $payload, 'doklad bez partnera pole nenese');

        $payload['partner'] = $f->person('supplier');
        $payload['rows'][1]['partner'] = ['ref' => $f->person('customer')];
        $saved = $this->saved($this->applier->apply($this->options($payload, 10)));
        $this->assertSame($f->person('supplier'), (int) $saved->head['partner']);
        $this->assertNull($saved->rows[0]['partner']);
        $this->assertSame($f->person('customer'), (int) $saved->rows[1]['partner']);

        $payload['rows'][1]['partner'] = '@it-rt-neni';
        $before = $this->documentCount();
        $result = $this->applier->apply($this->options($payload, 10));
        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'rows.1.partner']], self::findings($result, 'reference_not_found'));
        $this->assertSame($before, $this->documentCount());
    }

    /** D32: pokyn `_resolve` nečte — payload projde s jedním info nálezem a volba partnera se neuplatní. */
    public function testResolveBlockIsIgnoredInInstruction(): void
    {
        $f = $this->fixtures;
        $payload = $this->accountingPayload();
        $payload['_resolve'] = [
            'partner' => ['userAction' => 'useExisting:' . $f->person('supplier')],
            'rows'    => [1 => ['partner' => ['userAction' => 'useExisting:' . $f->person('customer')]]],
            'vat'     => ['mode' => ['userAction' => 'useValue:fromTotal']],
        ];

        $result = $this->applier->apply($this->options($payload, 10));
        $saved = $this->saved($result);

        $this->assertSame([['info', '_resolve']], self::findings($result, 'resolve_ignored'));
        $this->assertNotContains('vat_pin_ignored', self::codes($result));
        $this->assertNull($saved->head['partner']);
        $this->assertNull($saved->rows[1]['partner']);

        // Náhled hlásí totéž; payload bez bloku nález nemá.
        $this->assertSame([['info', '_resolve']], self::findings($this->applier->preview($this->options($payload, 10)), 'resolve_ignored'));
        unset($payload['_resolve']);
        $this->assertSame([], self::findings($this->applier->apply($this->options($payload, 10)), 'resolve_ignored'));
    }

    /** U3: v návrhu volby partnera fungují dál; uvedené pole má přednost. */
    public function testPartnerPinsStillWorkInProposalAndFieldWins(): void
    {
        $f = $this->fixtures;
        $payload = $this->accountingPayload();
        $payload['_resolve'] = [
            'partner' => ['userAction' => 'useExisting:' . $f->person('supplier')],
            'rows'    => [1 => ['partner' => ['userAction' => 'useExisting:' . $f->person('supplier')]]],
        ];

        $pinned = $this->saved($this->applier->apply($this->options($payload, 10), ApplyMode::Proposal));
        $this->assertSame($f->person('supplier'), (int) $pinned->head['partner']);
        $this->assertSame($f->person('supplier'), (int) $pinned->rows[1]['partner']);

        $payload['rows'][1]['partner'] = $f->person('customer');
        $withField = $this->saved($this->applier->apply($this->options($payload, 10), ApplyMode::Proposal));
        $this->assertSame($f->person('supplier'), (int) $withField->head['partner'], 'hlavička dál z volby');
        $this->assertSame($f->person('customer'), (int) $withField->rows[1]['partner'], 'pole má přednost před volbou');
    }

    // ── Výchozí hodnoty pokynu (#166 D40–D45, D48–D50) ──────────────────────

    /** D44: odpověď říká, co pokyn doplnil — stejně v náhledu i po uložení; doklad to nese. */
    public function testDefaultsAreListedInPreviewAndAfterSave(): void
    {
        $f = $this->fixtures;
        $payload = $this->options($this->minimalIssuedPayload(), 40);

        $preview = self::defaults($this->applier->preview($payload));
        $result = $this->applier->apply($payload);
        $saved = $this->saved($result);

        $this->assertSame($preview, self::defaults($result), 'náhled a uložení doplní totéž');
        $this->assertSame(
            [
                'currency', 'payment.method', 'vat.mode', 'dates.issueDate', 'vat.registration', 'payment.ownBankAccount',
                'rows.0.operation', 'rows.0.description', 'rows.0.unitPrice', 'rows.0.unit', 'rows.0.quantity', 'rows.0.vat.code',
            ],
            array_keys($preview),
        );
        $this->assertSame(date('Y-m-d'), $preview['dates.issueDate']);
        $this->assertSame($f->vatRegistrationId(), $preview['vat.registration']);
        $this->assertSame(1250.0, (float) $preview['rows.0.unitPrice']);

        $this->assertSame(40, (int) $saved->head['docState']);
        $this->assertSame($f->vatRegistrationId(), (int) $saved->head['vat_registration']);
        $this->assertNotNull($saved->head['bank_account'], 'výchozí vlastní účet — bez něj fakturu nejde potvrdit');
        $this->assertSame(1, (int) $saved->head['total_rounding_auto']);
        $this->assertSame('sale.services', $saved->rows[0]['operation']);
        $this->assertSame([1250.0, 262.5, 1512.5], self::amounts($saved, 'total_base', 'total_vat', 'total_amount'));
        // Odpověď vrací payload, jak přišel — bez doplněných hodnot.
        $this->assertArrayNotHasKey('dates', $result->canonical);
        $this->assertSame(['ref' => '@' . $f->itemCode($f->pricedItem())], $result->canonical['rows'][0]['item']);
    }

    /** D40: hodnota z payloadu vždy vyhrává — výchozí hodnota ji nepřepíše a ve výčtu není. */
    public function testPayloadValuesWinOverDefaults(): void
    {
        $payload = $this->minimalIssuedPayload();
        $payload['dates'] = ['issueDate' => F::DATE];
        $payload['payment'] = ['method' => 'card'];
        $payload['vat'] = ['mode' => 'fromTotal'];
        $payload['rows'][0] += ['description' => F::PREFIX . ' vlastní text', 'unitPrice' => 990.0, 'quantity' => 3];

        $result = $this->applier->apply($this->options($payload, 10));
        $saved = $this->saved($result);

        $this->assertSame(F::DATE, substr((string) $saved->head['issue_date'], 0, 10));
        $this->assertSame([2, 2], [(int) $saved->head['payment_method'], (int) $saved->head['vat_mode']]);
        $this->assertSame(F::PREFIX . ' vlastní text', $saved->rows[0]['description']);
        $this->assertSame([990.0, 3.0], [(float) $saved->rows[0]['unit_price'], (float) $saved->rows[0]['quantity']]);
        $this->assertSame(
            [],
            array_intersect(
                ['dates.issueDate', 'payment.method', 'vat.mode', 'rows.0.description', 'rows.0.unitPrice', 'rows.0.quantity'],
                array_keys(self::defaults($result)),
            ),
        );
    }

    /** D50: výslovné `null` = záměrně prázdné — vlastní účet ani jednotka z karty se nedoplní. */
    public function testExplicitNullKeepsFieldsEmpty(): void
    {
        $f = $this->fixtures;
        $payload = [
            'format' => 'shpd.docs.document', 'formatVersion' => '1.0', 'docType' => 'invoiceReceived',
            'supplier' => '@' . $f->personNumber($f->person('supplier')),
            'payment'  => ['ownBankAccount' => null],
            'totals'   => ['totalRoundingAuto' => null],
            'rows'     => [['item' => '@' . $f->itemCode($f->pricedItem()), 'unit' => null, 'unitPrice' => null]],
        ];

        $result = $this->applier->apply($this->options($payload, 10));
        $saved = $this->saved($result);

        $this->assertNull($saved->head['bank_account']);
        $this->assertNull($saved->rows[0]['unit']);
        $this->assertSame(0.0, (float) $saved->rows[0]['unit_price'], 'bez ceny z karty');
        $defaults = self::defaults($result);
        $this->assertArrayNotHasKey('payment.ownBankAccount', $defaults);
        $this->assertArrayNotHasKey('rows.0.unit', $defaults);
        $this->assertArrayNotHasKey('rows.0.unitPrice', $defaults);
        $this->assertArrayHasKey('rows.0.description', $defaults, 'co payload neuvádí, se doplní dál');
    }

    /** D50: `totals.totalRoundingAuto` — chybí = zapnuto jako ve formuláři, `null` = vypnuto. */
    public function testNullTotalRoundingAutoTurnsAutomaticOff(): void
    {
        $on = $this->saved($this->applier->apply($this->options($this->minimalIssuedPayload(), 10)));
        $payload = $this->minimalIssuedPayload();
        $payload['totals'] = ['totalRoundingAuto' => null];
        $off = $this->saved($this->applier->apply($this->options($payload, 10)));

        $this->assertSame([1, 0], [(int) $on->head['total_rounding_auto'], (int) $off->head['total_rounding_auto']]);
    }

    /** D42, D43: řádek bez položky dostane pohyb, množství 1 a kód DPH — z karty nemá co převzít. */
    public function testRowWithoutItemGetsOperationQuantityAndCode(): void
    {
        $payload = $this->minimalIssuedPayload();
        $payload['rows'] = [['description' => F::PREFIX . ' práce', 'unitPrice' => 800.0]];

        $result = $this->applier->apply($this->options($payload, 40));
        $saved = $this->saved($result);

        $this->assertSame(
            ['rows.0.operation', 'rows.0.quantity', 'rows.0.vat.code'],
            array_values(array_filter(array_keys(self::defaults($result)), static fn (string $p): bool => str_starts_with($p, 'rows.'))),
        );
        $this->assertSame(1.0, (float) $saved->rows[0]['quantity']);
        $this->assertSame($this->fixtures->vatCode('output', 'domestic', 'standard', date: date('Y-m-d')), $saved->rows[0]['vat_code']);
        $this->assertSame([800.0, 168.0, 968.0], self::amounts($saved, 'total_base', 'total_vat', 'total_amount'));
    }

    /** D48: import historie datum vystavení nést musí — chybějící je chyba, a to jediná na to pole. */
    public function testImportWithoutIssueDateIsASingleError(): void
    {
        $payload = $this->options($this->minimalIssuedPayload(), 10, import: true);
        $before = $this->documentCount();

        $preview = $this->applier->preview($payload);
        $this->assertSame([['error', 'dates.issueDate']], self::findings($preview, 'required'));
        $this->assertArrayNotHasKey('dates.issueDate', self::defaults($preview));

        $result = $this->applier->apply($payload);
        $this->assertFalse($result->success);
        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'dates.issueDate']], self::findings($result, 'required'), 'bez odvozeného nálezu na účetním datu');
        $this->assertSame($before, $this->documentCount());
    }

    /**
     * D49: importní mód obsah řádku nedoplňuje — historický řádek by jinak
     * tiše dostal cenu z dnešní karty položky a základní sazbu. Pohyb ano.
     */
    public function testImportDoesNotFillRowContent(): void
    {
        $payload = $this->minimalIssuedPayload();
        $payload['dates'] = ['issueDate' => F::DATE];

        $result = $this->applier->apply($this->options($payload, 10, import: true));
        $saved = $this->saved($result);
        $row = $saved->rows[0];

        $this->assertSame(
            ['rows.0.operation'],
            array_values(array_filter(array_keys(self::defaults($result)), static fn (string $p): bool => str_starts_with($p, 'rows.'))),
        );
        $this->assertSame('sale.services', $row['operation']);
        $this->assertNull($row['vat_code']);
        $this->assertNull($row['unit']);
        $this->assertNull($row['quantity']);
        $this->assertSame(0.0, (float) $row['total_price']);
        // Hlavička výchozí hodnoty v importu dostává (D48).
        $this->assertNotNull($saved->head['bank_account']);
        $this->assertNotNull($saved->head['vat_registration']);
    }

    /**
     * D45, U7: povinnost hlídá doklad pro cílový stav — pokyn bez partnera
     * jde uložit jako koncept, potvrzení zastaví jediný nález na pole.
     */
    public function testMissingPartnerIsOneFindingOnConfirmationAndNoneForDraft(): void
    {
        $f = $this->fixtures;
        $payload = [
            'format' => 'shpd.docs.document', 'formatVersion' => '1.0', 'docType' => 'invoiceIssued',
            'docText' => F::PREFIX . ' bez partnera',
            'rows'    => [['item' => '@' . $f->itemCode($f->pricedItem())]],
        ];

        $preview = $this->applier->preview($this->options($payload, 40));
        $this->assertSame([['error', 'customer']], self::findings($preview, 'required'));

        $confirm = $this->applier->apply($this->options($payload, 40));
        $this->assertSame(['validation_failed', 422], [$confirm->errorCode, $confirm->statusCode]);
        $this->assertSame([['error', 'customer']], self::findings($confirm, 'required'));

        $draft = $this->saved($this->applier->apply($this->options($payload, 10)));
        $this->assertSame(10, (int) $draft->head['docState']);
        $this->assertNull($draft->head['partner']);
    }

    // ── Idempotence pokynu — source.ref (#166 D46) ──────────────────────────

    /** Dvakrát týž pokyn = jeden doklad; druhý pokyn s jiným obsahem doklad nezmění. */
    public function testSameInstructionTwiceGivesOneDocument(): void
    {
        $payload = $this->options($this->sourceRefPayload('dvojí pokyn'), 40);

        $first = $this->applier->apply($payload);
        $saved = $this->saved($first);
        $this->assertNotSame('alreadyApplied', $first->canonical['_resolve']['summary']['status']);
        $this->assertSame(['api', $payload['source']['ref']], [$saved->head['source_kind'], $saved->head['source_ref']]);
        $count = $this->documentCount();

        $second = $this->applier->apply($payload);
        $this->assertTrue($second->success, (string) $second->errorMessage);
        $this->assertSame([200, $first->savedId], [$second->statusCode, $second->savedId]);
        $this->assertSame($first->savedId, $second->canonical['savedDocId']);
        $this->assertSame('alreadyApplied', $second->canonical['_resolve']['summary']['status']);
        $issue = self::issue($second, 'already_applied');
        $this->assertSame([$first->savedId, $saved->head['doc_number']], [$issue['docId'], $issue['docNumber']]);
        $this->assertSame($count, $this->documentCount());

        // Jiný obsah se stejným klíčem: obsah se neporovnává, doklad zůstává.
        $changed = $payload;
        $changed['rows'][0]['quantity'] = 7;
        $changed['docText'] = F::PREFIX . ' dvojí pokyn — jiný obsah';
        $third = $this->applier->apply($changed);
        $this->assertSame([$first->savedId, 'alreadyApplied'], [$third->savedId, $third->canonical['_resolve']['summary']['status']]);
        $this->assertSame($count, $this->documentCount());
        $after = SavedDocument::load($this->db, (int) $first->savedId);
        $this->assertSame($saved->head['doc_text'], $after->head['doc_text']);
        $this->assertSame((float) $saved->head['total_amount'], (float) $after->head['total_amount']);
        $this->assertSame((float) $saved->rows[0]['quantity'], (float) $after->rows[0]['quantity']);

        // U6: export nese původ — jeho apply na témže zdroji vrátí původní doklad.
        $exported = $this->export((int) $first->savedId);
        $this->assertSame(['api', $payload['source']['ref']], [$exported['source']['kind'], $exported['source']['ref']]);
        $this->assertSame($first->savedId, $this->applier->apply($exported)->savedId);
        $this->assertSame($count, $this->documentCount());
    }

    /** U4: náhled existující doklad hlásí a zbytek běží jako obvykle. */
    public function testPreviewReportsTheExistingDocument(): void
    {
        $payload = $this->options($this->sourceRefPayload('náhled'), 10);

        $before = $this->applier->preview($payload);
        $this->assertSame([], self::findings($before, 'already_applied'));

        $saved = $this->saved($this->applier->apply($payload));
        $preview = $this->applier->preview($payload);

        $this->assertTrue($preview->success);
        $this->assertSame([['info', 'source.ref']], self::findings($preview, 'already_applied'));
        $issue = self::issue($preview, 'already_applied');
        $this->assertSame((int) $saved->head['id'], $issue['docId']);
        $this->assertNull($issue['docNumber'], 'koncept číslo nemá');
        $this->assertSame(self::defaults($before), self::defaults($preview), 'zbytek náhledu se nemění');
        $this->assertArrayHasKey('computed', $preview->canonical['_resolve']);
    }

    /** D46: doklad v koši nový pokyn neblokuje — vznikne nový. */
    public function testDocumentInTrashDoesNotBlockANewOne(): void
    {
        $payload = $this->options($this->sourceRefPayload('koš'), 10);
        $first = $this->saved($this->applier->apply($payload));
        $firstId = (int) $first->head['id'];

        $this->db->execute('UPDATE [docs_core_heads] SET [docState] = 90 WHERE [id] = %i', $firstId);
        $this->assertSame([], self::findings($this->applier->preview($payload), 'already_applied'));

        $second = $this->applier->apply($payload);
        $secondId = (int) $this->saved($second)->head['id'];
        $this->assertNotSame($firstId, $secondId);
        $this->assertNotSame('alreadyApplied', $second->canonical['_resolve']['summary']['status']);

        // Další pokyn vrací živý doklad, ne ten v koši.
        $this->assertSame($secondId, $this->applier->apply($payload)->savedId);
        // Po obnově z koše žijí dva — vrací se nejnovější.
        $this->db->execute('UPDATE [docs_core_heads] SET [docState] = 10 WHERE [id] = %i', $firstId);
        $this->assertSame($secondId, $this->applier->apply($payload)->savedId);
    }

    /** U2: stejný identifikátor pro doklad jiného typu je chyba volajícího. */
    public function testSameRefForAnotherDocTypeIsAConflict(): void
    {
        $issued = $this->options($this->sourceRefPayload('jiný typ'), 10);
        $this->saved($this->applier->apply($issued));
        $count = $this->documentCount();

        $received = $this->options($this->referencePayload(), 10);
        $received['source'] = $issued['source'];

        $preview = $this->applier->preview($received);
        $this->assertSame([['error', 'source.ref']], self::findings($preview, 'source_ref_conflict'));

        $result = $this->applier->apply($received);
        $this->assertFalse($result->success);
        $this->assertSame(['source_ref_conflict', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'source.ref']], self::findings($result, 'source_ref_conflict'));
        // referencePayload() si založil vlastní koncept cestou formuláře — ten v počtu je.
        $this->assertSame($count + 1, $this->documentCount());
    }

    /** U5: přegenerování se stejným klíčem nahradí cílový koncept; klíč jde z payloadu. */
    public function testReplaceConceptWithTheSameRef(): void
    {
        $payload = $this->options($this->sourceRefPayload('přegenerování'), 10);
        $targetId = (int) $this->saved($this->applier->apply($payload))->head['id'];
        $count = $this->documentCount();

        $replace = $payload;
        $replace['applyOptions']['replaceConcept'] = $targetId;
        $replace['rows'][0]['quantity'] = 3;
        $this->assertSame([], self::findings($this->applier->preview($replace), 'already_applied'), 'cílový koncept se z dohledání vylučuje');
        $result = $this->applier->apply($replace);
        $saved = $this->saved($result);

        $this->assertSame($targetId, (int) $saved->head['id']);
        $this->assertNotSame('alreadyApplied', $result->canonical['_resolve']['summary']['status']);
        $this->assertSame(3.0, (float) $saved->rows[0]['quantity']);
        $this->assertSame($payload['source']['ref'], $saved->head['source_ref']);
        $this->assertSame($count, $this->documentCount());

        // Payload bez klíče: sloupce původu se vrátí na hodnotu čerstvého dokladu.
        unset($replace['source']);
        $cleared = $this->saved($this->applier->apply($replace));
        $this->assertSame($targetId, (int) $cleared->head['id']);
        $this->assertSame([null, null], [$cleared->head['source_kind'], $cleared->head['source_ref']]);
    }

    /** U5: importní mód beze změny — opakovaný běh importu vrátí existující doklad. */
    public function testImportTwiceReturnsTheExistingDocument(): void
    {
        $payload = $this->sourceRefPayload('import');
        $payload['dates'] = ['issueDate' => F::DATE];
        $payload['rows'][0] += ['description' => 'IT-RT služba', 'quantity' => 1, 'unitPrice' => 1000.00, 'vat' => ['code' => $this->fixtures->vatCode('output', 'domestic', 'standard')]];
        $payload = $this->options($payload, 40, import: true);

        $first = $this->applier->apply($payload);
        $saved = $this->saved($first);
        $this->assertSame($payload['applyOptions']['importNumber']['docNumber'], $saved->head['doc_number']);
        $count = $this->documentCount();

        // Tentýž payload včetně čísla — bez klíče by narazil na existující číslo dokladu.
        $second = $this->applier->apply($payload);
        $this->assertTrue($second->success, (string) $second->errorMessage);
        $this->assertSame([$first->savedId, 'alreadyApplied'], [$second->savedId, $second->canonical['_resolve']['summary']['status']]);
        $this->assertSame($saved->head['doc_number'], self::issue($second, 'already_applied')['docNumber']);
        $this->assertSame($count, $this->documentCount());
    }

    /** U1: identifikátor bez druhu zdroje je chyba validace, nic se neuloží. */
    public function testRefWithoutKindIsAnError(): void
    {
        $payload = $this->options($this->sourceRefPayload('bez druhu'), 10);
        unset($payload['source']['kind']);
        $before = $this->documentCount();

        $this->assertSame([['error', 'source.ref']], self::findings($this->applier->preview($payload), 'source_kind_missing'));
        $result = $this->applier->apply($payload);
        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'source.ref']], self::findings($result, 'source_kind_missing'));
        $this->assertSame($before, $this->documentCount());
    }

    // ── Payloady ────────────────────────────────────────────────────────────

    /**
     * Minimální pokyn s klíčem idempotence (`source.kind` + `source.ref`);
     * text nese prefix fixtur kvůli počítání a úklidu. @return array<string, mixed>
     */
    private function sourceRefPayload(string $label): array
    {
        return [
            'source'  => ['kind' => 'api', 'ref' => F::PREFIX . '-' . substr(uniqid(), -8) . '-' . (++$this->numbers)],
            'docText' => F::PREFIX . ' idempotence — ' . $label,
        ] + $this->minimalIssuedPayload();
    }

    /**
     * Minimální pokyn vydané faktury: typ dokladu, odběratel a položka —
     * všechno ostatní jsou výchozí hodnoty. @return array<string, mixed>
     */
    private function minimalIssuedPayload(): array
    {
        $f = $this->fixtures;
        return [
            'format' => 'shpd.docs.document', 'formatVersion' => '1.0', 'docType' => 'invoiceIssued',
            'customer' => '@' . $f->personNumber($f->person('customer')),
            'rows'     => [['item' => '@' . $f->itemCode($f->pricedItem())]],
        ];
    }


    /** Účetní doklad se dvěma kontačními řádky, bez partnera — export. @return array<string, mixed> */
    private function accountingPayload(): array
    {
        $f = $this->fixtures;
        return $this->payload([
            'doc_type' => 'cmnbkp', 'number_series' => $f->seriesId('cmnbkp'),
            'issue_date' => F::DATE, 'accounting_date' => F::DATE, 'doc_currency' => $f->homeCurrency(),
            'doc_text' => F::PREFIX . ' účetní doklad — partner',
            'rows' => [
                ['row_kind' => 1, 'operation' => 'acc.record', 'account' => $f->accountId('518'), 'acc_side' => 0,
                 'total_price' => 800.00, 'price_calc_mode' => 1, 'description' => F::PREFIX . ' náklad'],
                ['row_kind' => 1, 'operation' => 'acc.record', 'account' => $f->accountId('321'), 'acc_side' => 1,
                 'total_price' => 800.00, 'price_calc_mode' => 1, 'description' => F::PREFIX . ' závazek',
                 'payment_reference' => '2026000088'],
            ],
        ]);
    }

    /** Přijatá faktura s DPH a položkou — export s referencemi, bez kontrolního součtu. @return array<string, mixed> */
    private function referencePayload(): array
    {
        $f = $this->fixtures;
        return self::withoutChecksum($this->payload($f->receivedHead('reference') + ['rows' => [
            $f->row(false, 'služby', 1, 1000.00, $f->vatCode('input', 'domestic', 'standard')),
        ]]));
    }

    /**
     * Canonical dokladu pořízeného cestou formuláře jako koncept; bez
     * `applyOptions.importNumber` (export konceptu nese zástupné číslo)
     * a bez cílového stavu — ty doplní {@see options()}.
     *
     * @param array<string, mixed> $formData
     * @return array<string, mixed>
     */
    private function payload(array $formData): array
    {
        $canonical = $this->export($this->fixtures->saveDocument($formData, 10));
        unset($canonical['applyOptions']['importNumber'], $canonical['applyOptions']['targetDocState'], $canonical['_resolve']);
        return $canonical;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function options(array $payload, int $targetDocState, bool $import = false): array
    {
        $payload['applyOptions']['targetDocState'] = $targetDocState;
        if ($import) {
            $payload['applyOptions']['importNumber'] = [
                'docNumber'      => F::PREFIX . '-PL-' . (++$this->numbers) . '-' . substr(uniqid(), -6),
                'sequenceNumber' => null,
            ];
        }
        return $payload;
    }

    /**
     * Každému položkovému řádku nastaví objekt `vat` (tvar, který export nevyrobí).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $vat
     * @return array<string, mixed>
     */
    private static function withRowVat(array $payload, array $vat): array
    {
        foreach ($payload['rows'] as $i => $row) {
            $payload['rows'][$i]['vat'] = $vat;
        }
        return $payload;
    }

    /**
     * Payload bez číselných `totals` — bez kontrolního součtu (#166 D27).
     * Příznak automatiky a mód zaokrouhlení zůstávají.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function withoutChecksum(array $payload): array
    {
        unset(
            $payload['totals']['totalBase'],
            $payload['totals']['totalVat'],
            $payload['totals']['totalAmount'],
            $payload['totals']['totalRounding'],
        );
        if (($payload['totals'] ?? null) === []) {
            unset($payload['totals']);
        }
        return $payload;
    }

    /** Přijatá faktura s DPH: 1 000,00 + 21 % = 1 210,00, součty z exportu. @return array<string, mixed> */
    private function plainReceivedPayload(): array
    {
        $f = $this->fixtures;
        $payload = $this->payload($f->receivedHead('kontrolní součet') + ['rows' => [
            $f->row(false, 'služby', 1, 1000.00, $f->vatCode('input', 'domestic', 'standard')),
        ]]);
        $this->assertSame(
            ['totalBase' => 1000.0, 'totalVat' => 210.0, 'totalAmount' => 1210.0, 'totalRounding' => 0.0],
            array_intersect_key($payload['totals'], array_flip(['totalBase', 'totalVat', 'totalAmount', 'totalRounding'])),
        );
        return $payload;
    }

    /** Přijatá faktura s DPH, řádek se sazbou a bez kódu. @return array<string, mixed> */
    private function payloadWithoutRowCode(): array
    {
        $f = $this->fixtures;
        $payload = $this->payload($f->receivedHead('řádek bez kódu DPH') + ['rows' => [
            $f->row(false, 'služby', 1, 1000.00, $f->vatCode('input', 'domestic', 'standard')),
        ]]);
        return self::withRowVat($payload, ['pct' => 21]);
    }

    /** Tentýž doklad, řádek výslovně bez kódu (`"vat": {"code": null}`, #166 D50). @return array<string, mixed> */
    private function payloadWithNullRowCode(): array
    {
        return self::withRowVat($this->payloadWithoutRowCode(), ['code' => null]);
    }

    /** Převzatá rekapitulace s haléřovým rozdílem proti řádkům. @return array<string, mixed> */
    private function declaredRecapPayload(): array
    {
        $f = $this->fixtures;
        $code = $f->vatCode('input', 'domestic', 'standard');
        return $this->payload($f->receivedHead('převzatá rekapitulace', ['vat_recap_source' => 1]) + [
            'rows'     => [$f->row(false, 'služby', 3, 33.33, $code)],
            'vatRecap' => [['vat_code' => $code, 'base' => 99.99, 'tax' => 20.99, 'total' => 120.98]],
        ]);
    }

    /** Řádek v ceně s daní při `fromBase`: součet řádků = částka k úhradě. @return array<string, mixed> */
    private function grossRowsPayload(): array
    {
        $f = $this->fixtures;
        $payload = $this->payload($f->receivedHead('ceny s daní při fromBase') + ['rows' => [
            $f->row(false, 'služby', 1, 121.00, $f->vatCode('input', 'domestic', 'standard')),
        ]]);
        $this->assertSame('fromBase', $payload['vat']['mode']);
        $payload['totals'] = ['totalBase' => 100.00, 'totalVat' => 21.00, 'totalAmount' => 121.00, 'totalRounding' => 0.0];
        unset($payload['vatRecap']);
        return $payload;
    }

    // ── Čtení výsledku ──────────────────────────────────────────────────────

    private function saved(ApplyResult $result): SavedDocument
    {
        $this->assertTrue(
            $result->success,
            "Apply selhal: {$result->errorCode} — {$result->errorMessage}\n"
                . json_encode($result->canonical['_resolve']['issues'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
        return SavedDocument::load($this->db, (int) $result->savedId);
    }

    /** Částky hlavičky zaokrouhlené na haléře. @return list<float> */
    private static function amounts(SavedDocument $saved, string ...$columns): array
    {
        return array_map(static fn (string $column): float => round((float) $saved->head[$column], 2), $columns);
    }

    /** První nález s kódem. @return array<string, mixed> */
    private static function issue(ApplyResult $result, string $code): array
    {
        foreach ((array) ($result->canonical['_resolve']['issues'] ?? []) as $issue) {
            if (is_array($issue) && ($issue['code'] ?? null) === $code) {
                return $issue;
            }
        }
        self::fail("Nález {$code} v odpovědi není.");
    }

    /** Počet dokladů testu (texty nesou prefix fixtur). */
    private function documentCount(): int
    {
        return (int) ($this->db->fetchRow(
            'SELECT COUNT(*) AS [n] FROM [docs_core_heads] WHERE [doc_text] LIKE %s',
            F::PREFIX . '%',
        )['n'] ?? 0);
    }

    /** @return list<string> */
    private static function codes(ApplyResult $result): array
    {
        return array_values(array_map(
            static fn (array $issue): string => (string) ($issue['code'] ?? ''),
            (array) ($result->canonical['_resolve']['issues'] ?? []),
        ));
    }

    /** Pole, která pokyn doplnil sám (`_resolve.defaults`). @return array<string, mixed> cesta → hodnota */
    private static function defaults(ApplyResult $result): array
    {
        $defaults = $result->canonical['_resolve']['defaults'] ?? null;
        self::assertIsArray($defaults, 'odpověď pokynu nese _resolve.defaults');
        return array_column($defaults, 'value', 'path');
    }

    /** @return list<array{0: string, 1: string}> dvojice [severity, path] nálezů s kódem */
    private static function findings(ApplyResult $result, string $code): array
    {
        $out = [];
        foreach ((array) ($result->canonical['_resolve']['issues'] ?? []) as $issue) {
            if (is_array($issue) && ($issue['code'] ?? null) === $code) {
                $out[] = [(string) $issue['severity'], (string) $issue['path']];
            }
        }
        return $out;
    }
}
