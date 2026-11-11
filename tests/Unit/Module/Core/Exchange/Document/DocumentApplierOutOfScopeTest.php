<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use PHPUnit\Framework\TestCase;

/**
 * Přijatý doklad **mimo české DPH** (#89 task A, D1–D3,
 * tasks/exchange-received-foreign-vat.md): dodavatel mimo tuzemsko účtuje
 * vlastní daň (`foreignVat`), nebo poskytuje službu se zvláštním místem
 * plnění (`specialPlace`). Doklad vznikne celý Bez DPH — bez kódů
 * a rekapitulace, daň dodavatele v cenách řádků stejným převodem jako
 * u neplátce (#97). Skutečný `vat-cz.jsonc`, skutečná taxonomie štítků
 * a skutečný `DocsHeadsDocument` v heads gatewayi; fiktivní dodavatel
 * (hotel v Německu) a částky.
 */
class DocumentApplierOutOfScopeTest extends TestCase
{
    use OutOfScopeHarness;

    /** Hlášky, které u dokladu mimo české DPH nedávají smysl. */
    private const SUPPRESSED = [
        'vat_code_unknown', 'vat_code_derived', 'vat_mode_derived', 'vat_mode_suspect',
        'recap_source_computed_fallback', 'supply_kind_derived',
    ];

    private const OUT_OF_SCOPE_BLOCK = ['status' => 'matched', 'matchedBy' => 'outOfScope'];

    // ── D2: zahraniční DPH ──────────────────────────────────────────────────

    public function testForeignVatPreviewIsWithoutVatWithSupplierTaxInRowPrices(): void
    {
        $result = $this->buildApplier()->preview($this->hotelPayload());

        $this->assertTrue($result->success);
        $resolve = $result->canonical['_resolve'];
        $this->assertSame(
            ['value' => 'none', 'source' => 'outOfScope', 'auto' => 'none', 'reason' => 'foreignVat'],
            $resolve['vat']['mode'],
        );
        $this->assertSame('intracom', $resolve['vat']['place']['value']);
        // Nabídka kódů zůstává — volbou kódu se uživatel vrátí k samovyměření.
        $this->assertNotSame([], $resolve['vatCodeOptions']);
        $this->assertSame(self::OUT_OF_SCOPE_BLOCK, $resolve['rows'][0]['vatCode']);
        $this->assertSame(self::OUT_OF_SCOPE_BLOCK, $resolve['rows'][1]['vatCode']);

        $computed = $resolve['computed'];
        $this->assertSame([], $computed['vatRecap']);
        $this->assertNull($computed['recapFallback']);
        $this->assertSame(249.70, $computed['totals']['totalAmount']);
        $this->assertSame(0.0, $computed['totals']['totalVat']);
        $this->assertSame(
            [
                ['index' => 0, 'unitPrice' => 107.0, 'totalPrice' => 214.00],
                ['index' => 1, 'unitPrice' => 17.85, 'totalPrice' => 35.70],
            ],
            $computed['rows'],
        );

        $issue = $this->issue($result->canonical, 'vat_foreign_tax');
        $this->assertNotNull($issue);
        $this->assertSame('info', $issue['severity']);
        $this->assertSame('vat.mode', $issue['path']);
        $codes = $this->issueCodes($result->canonical);
        foreach ([...self::SUPPRESSED, 'computed_total_mismatch', 'totals_mismatch', 'vat_special_place'] as $code) {
            $this->assertNotContains($code, $codes);
        }
        $this->assertSame(0, $resolve['summary']['errorCount']);
    }

    /** Doklad z návrhu vypadá jako ručně založený doklad plátce přepnutý na Bez DPH. */
    public function testForeignVatApplySavesWhatPreviewShows(): void
    {
        $applier = $this->buildApplier();
        $payload = $this->hotelPayload();
        $computed = $applier->preview($payload)->canonical['_resolve']['computed'];

        $applied = $applier->apply($payload);

        $this->assertTrue($applied->success, "{$applied->errorCode} {$applied->errorMessage}");
        $saved = $this->savedHeadsData;
        $this->assertIsArray($saved);
        $this->assertSame(0, (int) $saved['vat_mode']);
        // Místo plnění zůstává efektivní; registraci plátce doklad nese dál
        // (formulář ji při přepnutí na Bez DPH jen skryje).
        $this->assertSame(1, (int) $saved['vat_place']);
        $this->assertSame(5, (int) $saved['vat_registration']);
        $this->assertSame([], $saved['vatRecap']);
        $this->assertSame($computed['totals']['totalAmount'], (float) $saved['total_amount']);
        $this->assertSame(249.70, (float) $saved['total_amount']);
        foreach ([0 => 214.00, 1 => 35.70] as $i => $gross) {
            $row = $saved['rows'][$i];
            $this->assertSame($gross, (float) $row['total_price']);
            $this->assertSame(1, (int) $row['price_calc_mode']);
            $this->assertTrue(empty($row['vat_code']));
            $this->assertTrue(empty($row['vat_pct']));
            // Daň dodavatele je v základu řádku — tudy jde do nákladů i závazku.
            $this->assertSame($gross, (float) $row['vat_base']);
            $this->assertSame(0.0, (float) $row['vat_amount']);
        }
    }

    /** Účtenka v cenách s daní (fromTotal): ceny řádků se nemění. */
    public function testForeignVatKeepsPricesAlreadyIncludingVat(): void
    {
        $payload = $this->hotelPayload();
        $payload['vat']['mode'] = 'fromTotal';
        $payload['rows'][0]['unitPrice'] = 107.00;
        $payload['rows'][0]['totalPrice'] = 214.00;
        $payload['rows'][1]['unitPrice'] = 17.85;
        $payload['rows'][1]['totalPrice'] = 35.70;

        $result = $this->buildApplier()->preview($payload);

        $resolve = $result->canonical['_resolve'];
        $this->assertSame('outOfScope', $resolve['vat']['mode']['source']);
        $this->assertSame(214.00, $resolve['computed']['rows'][0]['totalPrice']);
        $this->assertSame(35.70, $resolve['computed']['rows'][1]['totalPrice']);
        $this->assertSame(249.70, $resolve['computed']['totals']['totalAmount']);
        $this->assertNotContains('computed_total_mismatch', $this->issueCodes($result->canonical));
    }

    /** Cena s daní od dodavatele (`computed.vatTotal`, ISDOC) má přednost před přepočtem sazbou. */
    public function testForeignVatTakesGrossPriceFromSupplierComputedTotal(): void
    {
        $payload = $this->hotelPayload();
        $payload['rows'][0]['computed'] = ['vatBase' => 200.00, 'vatAmount' => 14.01, 'vatTotal' => 214.01];
        unset($payload['vatRecap']);
        $payload['totals'] = ['totalAmount' => 249.71];

        $computed = $this->buildApplier()->preview($payload)->canonical['_resolve']['computed'];

        $this->assertSame(214.01, $computed['rows'][0]['totalPrice']);
        $this->assertSame(249.71, $computed['totals']['totalAmount']);
    }

    /** D5 z #97: řádky jedné sazby se dorovnají na rekapitulaci dodavatele (haléř na největší řádek). */
    public function testForeignVatAlignsRowsToSupplierRecap(): void
    {
        $payload = $this->hotelPayload();
        $row = $payload['rows'][1];
        $row['quantity'] = 1;
        $row['unitPrice'] = 33.33;
        $row['totalPrice'] = 33.33;
        $big = $row;
        $big['unitPrice'] = 66.67;
        $big['totalPrice'] = 66.67;
        // 33,33 × 1,19 = 39,66; 66,67 × 1,19 = 79,34; součet 158,66, dodavatel 158,67.
        $payload['rows'] = [$row, $big, $row];
        $payload['vatRecap'] = [
            ['vatCode' => null, 'vatPct' => 19, 'base' => 133.33, 'tax' => 25.34, 'total' => 158.67],
        ];
        $payload['totals'] = ['totalBase' => 133.33, 'totalVat' => 25.34, 'totalAmount' => 158.67, 'totalRounding' => 0];

        $result = $this->buildApplier()->preview($payload);

        $computed = $result->canonical['_resolve']['computed'];
        $this->assertSame([39.66, 79.35, 39.66], array_column($computed['rows'], 'totalPrice'));
        $this->assertSame(158.67, $computed['totals']['totalAmount']);
        $this->assertNotContains('computed_total_mismatch', $this->issueCodes($result->canonical));
    }

    /** Převod cen běží v měně dokladu; kurz a domácí měnu dál počítá doklad. */
    public function testForeignVatInForeignCurrencyConvertsInDocumentCurrency(): void
    {
        $payload = $this->hotelPayload();
        $payload['currency'] = 'EUR';
        $payload['exchangeRate'] = 25.0;
        $applier = $this->buildApplier();

        $applied = $applier->apply($payload);

        $this->assertTrue($applied->success, "{$applied->errorCode} {$applied->errorMessage}");
        $saved = $this->savedHeadsData;
        $this->assertSame('eur', $saved['doc_currency']);
        $this->assertSame(25.0, (float) $saved['exchange_rate']);
        $this->assertSame(249.70, (float) $saved['total_amount']);
        $this->assertSame(214.00, (float) $saved['rows'][0]['total_price']);
    }

    /**
     * Daň dodavatele jen v rekapitulaci / `computed` řádků: kontext se nesmí
     * vzít z cache jiného dokladu, který se liší jen těmito poli.
     */
    public function testContextCacheDistinguishesSupplierTaxOutsideRowRates(): void
    {
        $withTax = $this->hotelPayload();
        foreach ($withTax['rows'] as &$row) {
            $row['vat']['pct'] = null;
        }
        unset($row);
        $withoutTax = $withTax;
        $withoutTax['vatRecap'] = [
            ['vatCode' => null, 'vatPct' => 0, 'base' => 230.00, 'tax' => 0, 'total' => 230.00],
        ];
        $withoutTax['totals'] = ['totalBase' => 230.00, 'totalVat' => 0, 'totalAmount' => 230.00, 'totalRounding' => 0];
        $applier = $this->buildApplier();

        $first = $applier->preview($withTax)->canonical['_resolve'];
        $second = $applier->preview($withoutTax)->canonical['_resolve'];

        $this->assertSame('outOfScope', $first['vat']['mode']['source']);
        $this->assertNotSame('outOfScope', $second['vat']['mode']['source']);
        $this->assertSame('cz-217', $second['rows'][0]['vatCode']['createPayload']['code']);
    }

    // ── D3: zvláštní místo plnění ───────────────────────────────────────────

    public function testSpecialPlaceWithoutSupplierTaxIsWithoutVatWithWarning(): void
    {
        $applier = $this->buildApplier();
        $payload = $this->specialPlacePayload();

        $result = $applier->preview($payload);

        $resolve = $result->canonical['_resolve'];
        $this->assertSame(
            ['value' => 'none', 'source' => 'outOfScope', 'auto' => 'none', 'reason' => 'specialPlace'],
            $resolve['vat']['mode'],
        );
        $this->assertSame(self::OUT_OF_SCOPE_BLOCK + ['specialSource' => 'tag'], $resolve['rows'][0]['vatCode']);
        $this->assertNotSame([], $resolve['vatCodeOptions']);
        // Ceny beze změny — doklad žádnou daň nenese.
        $this->assertSame([200.00, 30.00], array_column($resolve['computed']['rows'], 'totalPrice'));
        $this->assertSame(230.00, $resolve['computed']['totals']['totalAmount']);

        $issue = $this->issue($result->canonical, 'vat_special_place');
        $this->assertNotNull($issue);
        $this->assertSame('warning', $issue['severity']);
        $this->assertSame('vat.mode', $issue['path']);
        $this->assertStringContainsString('vyber kód DPH u řádku', $issue['message']);
        $this->assertStringNotContainsString('položky', $issue['message']);
        $codes = $this->issueCodes($result->canonical);
        foreach ([...self::SUPPRESSED, 'vat_foreign_tax', 'computed_total_mismatch'] as $code) {
            $this->assertNotContains($code, $codes);
        }
    }

    /** Varování apply neblokuje — jednoklik (režim safe) doklad vystaví. */
    public function testSpecialPlaceWarningDoesNotBlockSafeApply(): void
    {
        $applier = $this->buildApplier();
        $payload = $this->specialPlacePayload();
        $payload['applyOptions'] = ['autoCreateMode' => 'safe', 'targetDocState' => 10];

        $applied = $applier->apply($payload);

        $this->assertTrue($applied->success, "{$applied->errorCode} {$applied->errorMessage}");
        $this->assertContains('vat_special_place', $this->issueCodes($applied->canonical));
        $this->assertSame(0, (int) $this->savedHeadsData['vat_mode']);
        $this->assertSame(230.00, (float) $this->savedHeadsData['total_amount']);
        $this->assertTrue(empty($this->savedHeadsData['rows'][0]['vat_code']));
    }

    /** Veto štítku platí i proti `reverseCharge: true` — doklad bez daně je mimo české DPH. */
    public function testSpecialPlaceAppliesEvenWithReverseChargeFlag(): void
    {
        $payload = $this->specialPlacePayload();
        $payload['vat']['reverseCharge'] = true;

        $resolve = $this->buildApplier()->preview($payload)->canonical['_resolve'];

        $this->assertSame('specialPlace', $resolve['vat']['mode']['reason']);
    }

    // ── Tabulka situací: kdy doklad mimo české DPH není ─────────────────────

    /** Smíšený doklad (D5): přenesení daňové povinnosti a zároveň sazba dodavatele — jako dřív samovyměření. */
    public function testReverseChargeWithSupplierRatesStaysSelfAssessed(): void
    {
        $payload = $this->hotelPayload();
        $payload['vat']['reverseCharge'] = true;

        $result = $this->buildApplier()->preview($payload);

        $resolve = $result->canonical['_resolve'];
        $this->assertNotSame('outOfScope', $resolve['vat']['mode']['source']);
        $this->assertSame('cz-217', $resolve['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertNotContains('vat_foreign_tax', $this->issueCodes($result->canonical));
    }

    /** Volba kódu na jediném řádku vrací celý doklad k dnešnímu chování. */
    public function testRowCodePinCancelsOutOfScope(): void
    {
        foreach ([$this->hotelPayload(), $this->specialPlacePayload()] as $payload) {
            $payload = $this->withPins($payload, ['rows[0].vatCode' => 'useCode:cz-217']);

            $result = $this->buildApplier()->preview($payload);

            $resolve = $result->canonical['_resolve'];
            $this->assertNotSame('outOfScope', $resolve['vat']['mode']['source']);
            $this->assertSame('user', $resolve['rows'][0]['vatCode']['matchedBy']);
            $this->assertSame('cz-217', $resolve['rows'][0]['vatCode']['createPayload']['code']);
            // Druhý řádek jde derivací jako dřív — chyba čeká na volbu.
            $this->assertSame('notFound', $resolve['rows'][1]['vatCode']['status']);
            $codes = $this->issueCodes($result->canonical);
            $this->assertContains('vat_code_unknown', $codes);
            $this->assertNotContains('vat_foreign_tax', $codes);
            $this->assertNotContains('vat_special_place', $codes);
        }
    }

    /** Volba režimu s DPH: doklad není mimo české DPH, řádky se rozhodují jako dřív. */
    public function testModePinWithVatCancelsOutOfScope(): void
    {
        $payload = $this->withPins($this->hotelPayload(), ['vat.mode' => 'useValue:fromBase']);

        $result = $this->buildApplier()->preview($payload);

        $resolve = $result->canonical['_resolve'];
        $this->assertSame('user', $resolve['vat']['mode']['source']);
        $issue = $this->issue($result->canonical, 'vat_code_unknown');
        $this->assertNotNull($issue);
        $this->assertStringContainsString('zahraniční DPH', $issue['message']);
        // Rada vede k volbám v náhledu, ne k ručnímu zakládání.
        $this->assertStringContainsString('zvol režim Bez DPH', $issue['message']);
    }

    /** Pořadí v kontextu: místo plnění z volby = tuzemsko → režim mimo DPH se neuplatní. */
    public function testPlacePinDomesticCancelsOutOfScope(): void
    {
        $payload = $this->hotelPayload();
        $payload['rows'][0]['vat']['pct'] = 21;
        $payload = $this->withPins($payload, ['vat.place' => 'useValue:domestic']);

        $resolve = $this->buildApplier()->preview($payload)->canonical['_resolve'];

        $this->assertSame('domestic', $resolve['vat']['place']['value']);
        $this->assertNotSame('outOfScope', $resolve['vat']['mode']['source']);
        $this->assertSame('cz-110', $resolve['rows'][0]['vatCode']['createPayload']['code']);
    }

    /** Kód z historie řádků (v souladu se signály) potvrdil člověk — doklad jde dál se samovyměřením. */
    public function testKeptInputCodeCancelsOutOfScope(): void
    {
        $foreignVat = $this->hotelPayload();
        unset($foreignVat['vat']['reverseCharge']);
        $foreignVat['rows'][0]['vat']['code'] = 'cz-217';
        $special = $this->specialPlacePayload();
        $special['rows'][0]['vat']['code'] = 'cz-217';

        foreach ([$foreignVat, $special] as $payload) {
            $resolve = $this->buildApplier()->preview($payload)->canonical['_resolve'];

            $this->assertNotSame('outOfScope', $resolve['vat']['mode']['source']);
            $this->assertSame('cz-217', $resolve['rows'][0]['vatCode']['createPayload']['code']);
            $this->assertSame('cfgItem', $resolve['rows'][0]['vatCode']['matchedBy']);
        }
    }

    /** Smíšený doklad (D5): jen část řádků má zvláštní místo plnění — veto zůstává chybou řádku. */
    public function testPartlySpecialRowsStayAsBefore(): void
    {
        $payload = $this->specialPlacePayload(rowExceptions: [['rowIndex' => 1, 'tag' => 'it.software']]);

        $result = $this->buildApplier()->preview($payload);

        $resolve = $result->canonical['_resolve'];
        $this->assertNotSame('outOfScope', $resolve['vat']['mode']['source']);
        $this->assertSame('notFound', $resolve['rows'][0]['vatCode']['status']);
        $this->assertSame('cz-217', $resolve['rows'][1]['vatCode']['createPayload']['code']);
        $issue = $this->issue($result->canonical, 'vat_code_unknown');
        $this->assertNotNull($issue);
        $this->assertStringContainsString('zvláštním pravidlem', $issue['message']);
        $this->assertNotContains('vat_special_place', $this->issueCodes($result->canonical));
    }

    /** Služba z EU bez daně a bez štítku special: samovyměření jako dřív. */
    public function testOrdinaryEuServiceStaysSelfAssessed(): void
    {
        $result = $this->buildApplier()->preview($this->specialPlacePayload(tag: 'it.software'));

        $resolve = $result->canonical['_resolve'];
        $this->assertNotSame('outOfScope', $resolve['vat']['mode']['source']);
        $this->assertSame('cz-217', $resolve['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertSame([], array_intersect(['vat_special_place', 'vat_foreign_tax'], $this->issueCodes($result->canonical)));
    }

    /** Tuzemský doklad se štítkem special i s daní dodavatele: beze změny. */
    public function testDomesticDocumentIsNeverOutOfScope(): void
    {
        $payload = $this->happyPayload();
        $payload['_resolve']['contentTag'] = ['tag' => 'travel.accommodation', 'tagSource' => 'llm', 'rowExceptions' => []];

        $resolve = $this->buildApplier()->preview($payload)->canonical['_resolve'];

        $this->assertSame('fromBase', $resolve['vat']['mode']['value']);
        $this->assertNotSame('outOfScope', $resolve['vat']['mode']['source']);
        $this->assertSame('cz-110', $resolve['rows'][0]['vatCode']['createPayload']['code']);
    }

    /** Import (starý Shipard, datové sady) přenáší hotové doklady — beze změny. */
    public function testImportModeIsNeverOutOfScope(): void
    {
        $payload = $this->hotelPayload();
        $payload['applyOptions'] = ['importNumber' => ['docNumber' => '2024-0042', 'sequenceNumber' => 42]];

        $result = $this->buildApplier()->preview($payload);

        $this->assertNotSame('outOfScope', $result->canonical['_resolve']['vat']['mode']['source']);
        $this->assertNotContains('vat_foreign_tax', $this->issueCodes($result->canonical));
    }

    /** Neplátce se nepohnul: zdroj bez registrace k datu zůstává ve větvi #97. */
    public function testNonPayerKeepsItsOwnBranch(): void
    {
        $result = $this->buildApplier($this->dbWithoutVatRegistration())->preview($this->hotelPayload());

        $resolve = $result->canonical['_resolve'];
        $this->assertSame(['value' => 'none', 'source' => 'nonPayer', 'auto' => 'none'], $resolve['vat']['mode']);
        $this->assertArrayNotHasKey('vatCodeOptions', $resolve);
        $this->assertArrayNotHasKey('vatCode', $resolve['rows'][0]);
        $codes = $this->issueCodes($result->canonical);
        $this->assertContains('vat_non_payer', $codes);
        $this->assertNotContains('vat_foreign_tax', $codes);
        $this->assertSame(249.70, $resolve['computed']['totals']['totalAmount']);
    }

    // ── D19–D21: zvláštní místo plnění ze štítků položky pokrytého řádku ────

    /** D20: `special` z položky se chová jako štítek řádku — doklad je mimo české DPH. */
    public function testSpecialPlaceFromItemTagsIsWithoutVatWithWarning(): void
    {
        $result = $this->buildApplier($this->db(self::TAGGED_ITEMS))->preview($this->coveredPayload(['CEST', 'MYTO']));

        $resolve = $result->canonical['_resolve'];
        $this->assertSame(
            ['value' => 'none', 'source' => 'outOfScope', 'auto' => 'none', 'reason' => 'specialPlace'],
            $resolve['vat']['mode'],
        );
        $this->assertSame(self::OUT_OF_SCOPE_BLOCK + ['specialSource' => 'item'], $resolve['rows'][0]['vatCode']);
        $this->assertSame(self::OUT_OF_SCOPE_BLOCK + ['specialSource' => 'item'], $resolve['rows'][1]['vatCode']);

        $issue = $this->issue($result->canonical, 'vat_special_place');
        $this->assertNotNull($issue);
        $this->assertSame('warning', $issue['severity']);
        $this->assertStringContainsString('ze štítků položek „CEST“, „MYTO“', $issue['message']);
        $codes = $this->issueCodes($result->canonical);
        foreach ([...self::SUPPRESSED, 'vat_special_place_ambiguous'] as $code) {
            $this->assertNotContains($code, $codes);
        }
        // Štítky položek jedním dotazem na doklad.
        $this->assertSame(1, $this->itemTagQueries);
    }

    public function testSpecialPlaceFromItemTagsAppliesWithoutVat(): void
    {
        $payload = $this->coveredPayload();
        $payload['applyOptions'] = ['autoCreateMode' => 'safe', 'targetDocState' => 10];

        $applied = $this->buildApplier($this->db(self::TAGGED_ITEMS))->apply($payload);

        $this->assertTrue($applied->success, "{$applied->errorCode} {$applied->errorMessage}");
        $this->assertContains('vat_special_place', $this->issueCodes($applied->canonical));
        $this->assertSame(0, (int) $this->savedHeadsData['vat_mode']);
        $this->assertTrue(empty($this->savedHeadsData['rows'][0]['vat_code']));
        $this->assertSame(1, $this->itemTagQueries);
    }

    /** Řádek se štítkem z bloku a řádek pokrytý položkou — obojí `special`. */
    public function testSpecialPlaceCombinesBlockTagAndItemTags(): void
    {
        $payload = $this->coveredPayload(['CEST', 'CEST']);
        unset($payload['rows'][0]['item']['ourCode']);
        $payload['_resolve']['contentTag'] = [
            'tag'           => null,
            'rowExceptions' => [['rowIndex' => 0, 'tag' => 'travel.accommodation']],
        ];

        $result = $this->buildApplier($this->db(self::TAGGED_ITEMS))->preview($payload);

        $resolve = $result->canonical['_resolve'];
        $this->assertSame('specialPlace', $resolve['vat']['mode']['reason'] ?? null);
        $this->assertSame('tag', $resolve['rows'][0]['vatCode']['specialSource']);
        $this->assertSame('item', $resolve['rows'][1]['vatCode']['specialSource']);
        $this->assertStringContainsString('ze štítků položky „CEST“', $this->issue($result->canonical, 'vat_special_place')['message']);
    }

    /** D20: veto ze štítků položky u smíšeného dokladu — řádek zůstává bez kódu jako dřív. */
    public function testPartlySpecialItemRowsKeepVeto(): void
    {
        $result = $this->buildApplier($this->db(self::TAGGED_ITEMS))->preview($this->coveredPayload(['CEST', 'SOFT']));

        $resolve = $result->canonical['_resolve'];
        $this->assertNotSame('outOfScope', $resolve['vat']['mode']['source']);
        $this->assertSame('notFound', $resolve['rows'][0]['vatCode']['status']);
        $this->assertSame('item', $resolve['rows'][0]['vatCode']['specialSource']);
        $this->assertSame('cz-217', $resolve['rows'][1]['vatCode']['createPayload']['code']);
        $issue = $this->issue($result->canonical, 'vat_code_unknown');
        $this->assertNotNull($issue);
        $this->assertSame('rows.0.vat.code', $issue['path']);
        $this->assertStringContainsString('zvláštním pravidlem', $issue['message']);
        $this->assertStringContainsString('ze štítků položky „CEST“', $issue['message']);
        $this->assertSame([], array_intersect(
            ['vat_special_place', 'vat_special_place_ambiguous'],
            $this->issueCodes($result->canonical),
        ));
    }

    /** D21: nejednoznačná položka — samovyměření jako dřív, varování na řádku s odvozeným kódem. */
    public function testAmbiguousItemWarnsOnRowWithDerivedCode(): void
    {
        $result = $this->buildApplier($this->db(self::TAGGED_ITEMS))->preview($this->coveredPayload(['OSTAT', 'SOFT']));

        $resolve = $result->canonical['_resolve'];
        $this->assertNotSame('outOfScope', $resolve['vat']['mode']['source']);
        $this->assertSame('cz-217', $resolve['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertSame('derived', $resolve['rows'][0]['vatCode']['matchedBy']);
        $this->assertArrayNotHasKey('specialSource', $resolve['rows'][0]['vatCode']);

        $warnings = array_values(array_filter(
            $result->canonical['_resolve']['issues'],
            static fn (array $issue): bool => $issue['code'] === 'vat_special_place_ambiguous',
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame('warning', $warnings[0]['severity']);
        $this->assertSame('rows.0.vat.code', $warnings[0]['path']);
        $this->assertStringContainsString('Položka „OSTAT“', $warnings[0]['message']);
        $this->assertStringContainsString('přepni režim DPH na Bez DPH', $warnings[0]['message']);
        $this->assertNotContains('vat_special_place', $this->issueCodes($result->canonical));
    }

    /** D21: varování apply neblokuje a doklad vznikne se samovyměřením. */
    public function testAmbiguousItemAppliesSelfAssessed(): void
    {
        $payload = $this->coveredPayload(['OSTAT', 'OSTAT']);
        $payload['applyOptions'] = ['autoCreateMode' => 'safe', 'targetDocState' => 10];

        $applied = $this->buildApplier($this->db(self::TAGGED_ITEMS))->apply($payload);

        $this->assertTrue($applied->success, "{$applied->errorCode} {$applied->errorMessage}");
        $this->assertContains('vat_special_place_ambiguous', $this->issueCodes($applied->canonical));
        $this->assertSame(1, (int) $this->savedHeadsData['vat_mode']);
        $this->assertSame('cz-217', $this->savedHeadsData['rows'][0]['vat_code']);
    }

    /** D21: kód ponechaný z historie potvrdil člověk — bez varování. */
    public function testAmbiguousItemWithKeptCodeDoesNotWarn(): void
    {
        $payload = $this->coveredPayload(['OSTAT', 'OSTAT']);
        $payload['rows'][0]['vat']['code'] = 'cz-217';
        $payload['rows'][1]['vat']['code'] = 'cz-217';

        $result = $this->buildApplier($this->db(self::TAGGED_ITEMS))->preview($payload);

        $resolve = $result->canonical['_resolve'];
        $this->assertSame('cz-217', $resolve['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertSame('cfgItem', $resolve['rows'][0]['vatCode']['matchedBy']);
        $this->assertNotContains('vat_special_place_ambiguous', $this->issueCodes($result->canonical));
    }

    /** D21: volba uživatele (kód řádku, režim s DPH, režim Bez DPH) varování nedostane. */
    public function testAmbiguousItemWithUserChoiceDoesNotWarn(): void
    {
        $payload = $this->coveredPayload(['OSTAT', 'OSTAT']);

        $rowPin = $this->buildApplier($this->db(self::TAGGED_ITEMS))
            ->preview($this->withPins($payload, ['rows[0].vatCode' => 'useCode:cz-217']));
        $warned = array_column(array_filter(
            $rowPin->canonical['_resolve']['issues'],
            static fn (array $issue): bool => $issue['code'] === 'vat_special_place_ambiguous',
        ), 'path');
        $this->assertSame(['rows.1.vat.code'], array_values($warned));

        foreach (['fromBase', 'none'] as $mode) {
            $result = $this->buildApplier($this->db(self::TAGGED_ITEMS))
                ->preview($this->withPins($payload, ['vat.mode' => "useValue:{$mode}"]));
            $this->assertNotContains('vat_special_place_ambiguous', $this->issueCodes($result->canonical), $mode);
        }
    }

    /** Položka bez `special` štítku, bez štítků nebo neznámá: beze změny. */
    public function testItemWithoutSpecialTagChangesNothing(): void
    {
        foreach ([['SOFT', 'SOFT'], ['NEZNAMA', 'NEZNAMA']] as $codes) {
            $result = $this->buildApplier($this->db(self::TAGGED_ITEMS))->preview($this->coveredPayload($codes));

            $resolve = $result->canonical['_resolve'];
            $this->assertNotSame('outOfScope', $resolve['vat']['mode']['source']);
            $this->assertSame('cz-217', $resolve['rows'][0]['vatCode']['createPayload']['code']);
            $this->assertSame([], array_intersect(
                ['vat_special_place', 'vat_special_place_ambiguous', 'vat_code_unknown'],
                $this->issueCodes($result->canonical),
            ));
        }
    }

    /** Volba položky v náhledu: na dokladu skončí jiná položka — z původní se neklasifikuje. */
    public function testRowWithUserItemChoiceIsNotClassifiedByItem(): void
    {
        $payload = $this->coveredPayload(['CEST', 'CEST']);
        $payload['_resolve']['rows'][0]['item']['userAction'] = 'useExisting:18';

        $result = $this->buildApplier($this->db(self::TAGGED_ITEMS))->preview($payload);

        $resolve = $result->canonical['_resolve'];
        $this->assertNotSame('outOfScope', $resolve['vat']['mode']['source']);
        $this->assertSame('cz-217', $resolve['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertSame('notFound', $resolve['rows'][1]['vatCode']['status']);
    }

    /** Tuzemsko a doklad s daní dodavatele se nepohnuly — ani varování, ani dotaz na štítky. */
    public function testDomesticAndSupplierVatDocumentsStayUntouched(): void
    {
        $domestic = $this->happyPayload();
        $domestic['rows'][0]['item']['ourCode'] = 'OSTAT';
        $resolve = $this->buildApplier($this->db(self::TAGGED_ITEMS))->preview($domestic)->canonical['_resolve'];
        $this->assertSame('fromBase', $resolve['vat']['mode']['value']);
        $this->assertSame('cz-110', $resolve['rows'][0]['vatCode']['createPayload']['code']);
        $this->assertSame([], array_intersect(
            ['vat_special_place', 'vat_special_place_ambiguous'],
            array_column($resolve['issues'], 'code'),
        ));
        $this->assertSame(0, $this->itemTagQueries);

        $foreignVat = $this->hotelPayload();
        $foreignVat['rows'][0]['item']['ourCode'] = 'OSTAT';
        $foreignVat['rows'][1]['item']['ourCode'] = 'CEST';
        $result = $this->buildApplier($this->db(self::TAGGED_ITEMS))->preview($foreignVat);
        $this->assertSame('foreignVat', $result->canonical['_resolve']['vat']['mode']['reason'] ?? null);
        $this->assertSame([], array_intersect(
            ['vat_special_place', 'vat_special_place_ambiguous'],
            $this->issueCodes($result->canonical),
        ));

        // Daň dodavatele i příznak přenesení (smíšený doklad, D5): samovyměření bez varování.
        $mixed = $foreignVat;
        $mixed['vat']['reverseCharge'] = true;
        $result = $this->buildApplier($this->db(self::TAGGED_ITEMS))->preview($mixed);
        $this->assertNotSame('outOfScope', $result->canonical['_resolve']['vat']['mode']['source']);
        $this->assertNotContains('vat_special_place_ambiguous', $this->issueCodes($result->canonical));
    }
}
