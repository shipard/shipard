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

    // ── Řádek bez kódu DPH: rozhoduje DocDocument ───────────────────────────

    public function testRowWithoutCodeIsRejectedOnConfirmationAndPreviewShowsItFirst(): void
    {
        $payload = $this->options($this->payloadWithoutRowCode(), 40);
        $before = $this->documentCount();

        $preview = $this->applier->preview($payload);
        $this->assertTrue($preview->success);
        $this->assertSame([['error', 'rows.0.vat.code']], self::findings($preview, 'vat_code_missing'));

        $result = $this->applier->apply($payload);
        $this->assertFalse($result->success);
        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'rows.0.vat.code']], self::findings($result, 'vat_code_missing'));
        $this->assertSame($before, $this->documentCount(), 'nic se neuložilo');
    }

    public function testRowWithoutCodeIsSavedAsDraftWithWarning(): void
    {
        $result = $this->applier->apply($this->options(self::withoutChecksum($this->payloadWithoutRowCode()), 10));
        $saved = $this->saved($result);

        $this->assertSame(10, (int) $saved->head['docState']);
        $this->assertSame([null], array_column($saved->rows, 'vat_code'));
        $this->assertSame([['warning', 'rows.0.vat.code']], self::findings($result, 'vat_code_missing'));
    }

    /** #166 D27: se součty dokladu (daň 210) řádek bez kódu neprojde ani jako koncept — daň na dokladu není. */
    public function testRowWithoutCodeAndDeclaredTotalsFailsTheChecksumEvenAsDraft(): void
    {
        $payload = $this->options($this->payloadWithoutRowCode(), 10);
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

    /** D22: importní mód výjimku z validace nemá. */
    public function testRowWithoutCodeIsRejectedOnConfirmationEvenInImportMode(): void
    {
        $payload = $this->options($this->payloadWithoutRowCode(), 40, import: true);
        $before = $this->documentCount();

        $result = $this->applier->apply($payload);

        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $this->assertSame([['error', 'rows.0.vat.code']], self::findings($result, 'vat_code_missing'));
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

    // ── Payloady ────────────────────────────────────────────────────────────

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
