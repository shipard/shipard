<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\AnalysisConfidenceResolver;

/**
 * Runtime mapování confidence → pásmo návrhu (tasks/mail-message-centric.md
 * D3): prahy profilu s fallbacky + strop D7 podle pokrytí řádků položkami,
 * D15 podle nálezů hodnot a D17 u zvláštního místa plnění (#89).
 * Pásmo se počítá za běhu, nikam se nezapisuje — testuje se resolver přímo,
 * hlavně thresholds fallbacky a hranice stropu.
 */
class AnalysisConfidenceResolverTest extends TestCase
{
    /** Výřez taxonomie `core.exchange.contentTags` — jen atribut, který strop čte. */
    private const TAGS = [
        'travel.accommodation' => ['crossBorderSupply' => 'special'],
        'premises.rent'        => ['crossBorderSupply' => 'special'],
        'it.software'          => ['crossBorderSupply' => 'services'],
    ];

    private function resolver(?DataSourceConnection $db = null): AnalysisConfidenceResolver
    {
        return new AnalysisConfidenceResolver(
            $db ?? $this->createMock(DataSourceConnection::class),
        );
    }

    // ── thresholds ──────────────────────────────────────────────────────────

    public function testThresholdsForNullProfileReturnsDefaults(): void
    {
        $this->assertSame(
            AnalysisConfidenceResolver::DEFAULT_THRESHOLDS,
            $this->resolver()->thresholdsForProfile(null),
        );
    }

    public function testThresholdsForProfileReadsJson(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'confidence_thresholds' => '{"ready": 0.95, "review": 0.5}',
        ]);

        $this->assertSame(
            ['ready' => 0.95, 'review' => 0.5],
            $this->resolver($db)->thresholdsForProfile(17),
        );
    }

    public function testThresholdsForProfileFallsBackOnCorruptedJson(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['confidence_thresholds' => 'not json']);

        $this->assertSame(
            AnalysisConfidenceResolver::DEFAULT_THRESHOLDS,
            $this->resolver($db)->thresholdsForProfile(17),
        );
    }

    public function testThresholdsForDefaultProfileWithoutProfileUsesDefaults(): void
    {
        // DS bez AI profilu (ISDOC import musí fungovat i tam)
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $this->assertSame(
            AnalysisConfidenceResolver::DEFAULT_THRESHOLDS,
            $this->resolver($db)->thresholdsForDefaultProfile(),
        );
    }

    public function testThresholdsForDefaultProfileResolvesProfileRow(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 17],
            ['confidence_thresholds' => '{"ready": 0.8, "review": 0.4}'],
        );

        $this->assertSame(
            ['ready' => 0.8, 'review' => 0.4],
            $this->resolver($db)->thresholdsForDefaultProfile(),
        );
    }

    // ── mapování confidence na pásmo ────────────────────────────────────────

    public function testBandFor(): void
    {
        $resolver = $this->resolver();
        $thresholds = AnalysisConfidenceResolver::DEFAULT_THRESHOLDS;

        $this->assertSame(AnalysisConfidenceResolver::BAND_READY, $resolver->bandFor(1.0, $thresholds));
        $this->assertSame(AnalysisConfidenceResolver::BAND_READY, $resolver->bandFor(0.9, $thresholds));
        $this->assertSame(AnalysisConfidenceResolver::BAND_REVIEW, $resolver->bandFor(0.7, $thresholds));
        $this->assertSame(AnalysisConfidenceResolver::BAND_REVIEW, $resolver->bandFor(0.6, $thresholds));
        $this->assertSame(AnalysisConfidenceResolver::BAND_LOW, $resolver->bandFor(0.3, $thresholds));
    }

    // ── strop D7 ────────────────────────────────────────────────────────────

    public function testCapDowngradesReadyWhenItemRowWithoutOurCode(): void
    {
        $canonical = ['rows' => [
            ['rowKind' => 'item', 'item' => ['name' => 'X']],
        ]];

        $this->assertSame(
            AnalysisConfidenceResolver::BAND_REVIEW,
            $this->resolver()->capBand(
                AnalysisConfidenceResolver::BAND_READY,
                $canonical,
                self::TAGS,
            ),
        );
    }

    public function testCapKeepsReadyWhenAllItemRowsHaveOurCode(): void
    {
        $canonical = ['rows' => [
            ['rowKind' => 'item', 'item' => ['ourCode' => 'ABC', 'name' => 'X']],
        ]];

        $this->assertSame(
            AnalysisConfidenceResolver::BAND_READY,
            $this->resolver()->capBand(
                AnalysisConfidenceResolver::BAND_READY,
                $canonical,
                self::TAGS,
            ),
        );
    }

    public function testCapIgnoresAccountingRows(): void
    {
        $canonical = ['rows' => [
            ['operation' => 'acc.record', 'accSide' => 'debit', 'totalPrice' => 100.0],
        ]];

        $this->assertSame(
            AnalysisConfidenceResolver::BAND_READY,
            $this->resolver()->capBand(
                AnalysisConfidenceResolver::BAND_READY,
                $canonical,
                self::TAGS,
            ),
        );
    }

    public function testCapLeavesNonReadyBandAlone(): void
    {
        $this->assertSame(
            AnalysisConfidenceResolver::BAND_LOW,
            $this->resolver()->capBand(
                AnalysisConfidenceResolver::BAND_LOW,
                ['rows' => [['rowKind' => 'item', 'item' => []]]],
                self::TAGS,
            ),
        );
    }

    public function testCapDowngradesReadyWhenRowEnrichedWithLowConfidence(): void
    {
        // Dominance návrh (historyDominantItem / low) vyplní ourCode, ale
        // řádek se jako pokrytý nepočítá — návrh zůstává review
        // (tasks/enrichment-dominant-item.md, D5).
        $canonical = [
            'rows' => [
                ['rowKind' => 'item', 'item' => ['ourCode' => 'MAT']],
            ],
            '_resolve' => ['rows' => [
                ['index' => 0, 'enrichment' => [
                    'matchedBy'  => 'historyDominantItem',
                    'confidence' => 'low',
                    'suggested'  => ['ourCode' => 'MAT'],
                ]],
            ]],
        ];

        $this->assertSame(
            AnalysisConfidenceResolver::BAND_REVIEW,
            $this->resolver()->capBand(
                AnalysisConfidenceResolver::BAND_READY,
                $canonical,
                self::TAGS,
            ),
        );
    }

    public function testCapKeepsReadyForTextMatchesAndSkippedRows(): void
    {
        // Textové matche (high/medium) i řádky s ourCode od AI
        // (skipped: hasOurCode, confidence: null) stropu nepodléhají.
        $canonical = [
            'rows' => [
                ['rowKind' => 'item', 'item' => ['ourCode' => 'NET500']],
                ['rowKind' => 'item', 'item' => ['ourCode' => 'RENT-A']],
                ['rowKind' => 'item', 'item' => ['ourCode' => 'X1']],
            ],
            '_resolve' => ['rows' => [
                ['index' => 0, 'enrichment' => [
                    'matchedBy'  => 'historyExactRaw',
                    'confidence' => 'high',
                    'suggested'  => ['ourCode' => 'NET500'],
                ]],
                ['index' => 1, 'enrichment' => [
                    'matchedBy'  => 'historyFuzzy',
                    'confidence' => 'medium',
                    'suggested'  => ['ourCode' => 'RENT-A'],
                ]],
                ['index' => 2, 'enrichment' => [
                    'matchedBy'  => null,
                    'confidence' => null,
                    'skipped'    => 'hasOurCode',
                    'suggested'  => [],
                ]],
            ]],
        ];

        $this->assertSame(
            AnalysisConfidenceResolver::BAND_READY,
            $this->resolver()->capBand(
                AnalysisConfidenceResolver::BAND_READY,
                $canonical,
                self::TAGS,
            ),
        );
    }

    public function testCapDowngradesReadyForContentTagRows(): void
    {
        // Obsahová eskalace (matchedBy contentTag) stropuje vždy (D14,
        // tasks/content-tag-enrichment.md) — návrh potvrzuje člověk,
        // i když má confidence medium.
        $canonical = [
            'rows' => [
                ['rowKind' => 'item', 'item' => ['ourCode' => 'FUEL']],
            ],
            '_resolve' => ['rows' => [
                ['index' => 0, 'enrichment' => [
                    'matchedBy'  => 'contentTag',
                    'confidence' => 'medium',
                    'tag'        => 'vehicle.fuel',
                    'tagSource'  => 'rule',
                    'suggested'  => ['ourCode' => 'FUEL'],
                ]],
            ]],
        ];

        $this->assertSame(
            AnalysisConfidenceResolver::BAND_REVIEW,
            $this->resolver()->capBand(
                AnalysisConfidenceResolver::BAND_READY,
                $canonical,
                self::TAGS,
            ),
        );
    }

    // ── strop D15: nálezy hodnot (#118) ─────────────────────────────────────

    public function testCapDowngradesReadyOnValueFinding(): void
    {
        // IČO s neplatným kontrolním součtem → nález validátoru hodnot → review.
        $canonical = ['supplier' => ['country' => 'cz', 'companyId' => '12345678']];

        $this->assertSame(
            AnalysisConfidenceResolver::BAND_REVIEW,
            $this->resolver()->capBand(AnalysisConfidenceResolver::BAND_READY, $canonical, self::TAGS),
        );
    }

    public function testCapDowngradesReadyOnValueRepair(): void
    {
        // Opravený IBAN je validní, ale auditní blok dává bank_account_repaired → review.
        $canonical = [
            'supplier' => ['country' => 'cz', 'bankAccount' => ['accountNumber' => '8360405366/0600', 'iban' => 'CZ0606000000008360405366']],
            '_resolve' => ['valueRepairs' => [[
                'path' => 'supplier.bankAccount.iban', 'from' => 'CZ0606000000000836040536',
                'to' => 'CZ0606000000008360405366', 'basis' => 'accountNumber',
            ]]],
        ];

        $this->assertSame(
            AnalysisConfidenceResolver::BAND_REVIEW,
            $this->resolver()->capBand(AnalysisConfidenceResolver::BAND_READY, $canonical, self::TAGS),
        );
    }

    public function testCapKeepsReadyForPrintShapesWithoutFinding(): void
    {
        // Persistovaná analýza z doby před normalizací: mezery nejsou nález.
        $canonical = ['supplier' => [
            'country' => 'CZ', 'companyId' => '526 541 50', 'vatId' => 'cz 5265 4150',
            'address' => ['zip' => '779 00'],
            'bankAccount' => ['accountNumber' => '2308417557/2010', 'iban' => 'CZ85 2010 0000 0023 0841 7557'],
        ]];

        $this->assertSame(
            AnalysisConfidenceResolver::BAND_READY,
            $this->resolver()->capBand(AnalysisConfidenceResolver::BAND_READY, $canonical, self::TAGS),
        );
    }

    public function testCapLeavesLowerBandsAlone(): void
    {
        $canonical = ['supplier' => ['country' => 'cz', 'companyId' => '12345678']];

        $this->assertSame(AnalysisConfidenceResolver::BAND_REVIEW, $this->resolver()->capBand(AnalysisConfidenceResolver::BAND_REVIEW, $canonical, self::TAGS));
        $this->assertSame(AnalysisConfidenceResolver::BAND_LOW, $this->resolver()->capBand(AnalysisConfidenceResolver::BAND_LOW, $canonical, self::TAGS));
    }

    // ── strop D17: zvláštní místo plnění (#89) ──────────────────────────────

    /**
     * Návrh, který by bez stropu D17 byl `ready`: řádky pokryté položkami,
     * žádný nález hodnot. Ubytování v Německu bez vyčíslené daně; dodavatel
     * bez DIČ (místo plnění říká `vat.place`).
     *
     * @return array<string, mixed>
     */
    private function specialPlaceCanonical(): array
    {
        return [
            'selfParty' => 'customer',
            'supplier'  => ['name' => 'Hotel Beispiel GmbH', 'country' => 'de'],
            'customer'  => null,
            'vat'       => ['mode' => 'fromBase', 'place' => 'intracom'],
            'rows'      => [
                ['rowKind' => 'item', 'item' => ['ourCode' => 'UBYT'], 'totalPrice' => 200.0, 'vat' => ['pct' => 0]],
                ['rowKind' => 'item', 'item' => ['ourCode' => 'UBYT'], 'totalPrice' => 30.0, 'vat' => ['pct' => 0]],
            ],
            'vatRecap'  => [['vatPct' => 0, 'base' => 230.0, 'tax' => 0, 'total' => 230.0]],
            '_resolve'  => ['contentTag' => ['tag' => 'travel.accommodation', 'tagSource' => 'rule', 'rowExceptions' => []]],
        ];
    }

    public function testCapDowngradesReadyForSpecialPlaceWithoutSupplierTax(): void
    {
        $canonical = $this->specialPlaceCanonical();

        $this->assertSame(
            AnalysisConfidenceResolver::BAND_REVIEW,
            $this->resolver()->capBand(AnalysisConfidenceResolver::BAND_READY, $canonical, self::TAGS),
        );
        // Bez taxonomie strop D17 neplatí — a jiný důvod stropu canonical nemá.
        $this->assertSame(
            AnalysisConfidenceResolver::BAND_READY,
            $this->resolver()->capBand(AnalysisConfidenceResolver::BAND_READY, $canonical, []),
        );
    }

    public function testCapKeepsReadyForForeignVat(): void
    {
        // Zahraniční DPH (#89 D2) je údaj z faktury, ne odhad — pásmo nemění.
        $canonical = $this->specialPlaceCanonical();
        $canonical['rows'][0]['vat']['pct'] = 7;
        $canonical['vatRecap'] = [['vatPct' => 7, 'base' => 230.0, 'tax' => 16.1, 'total' => 246.1]];

        $this->assertSame(
            AnalysisConfidenceResolver::BAND_READY,
            $this->resolver()->capBand(AnalysisConfidenceResolver::BAND_READY, $canonical, self::TAGS),
        );
    }

    public function testCapKeepsReadyForDomesticNonPayerWithSpecialTag(): void
    {
        // Nájem od tuzemského neplátce: štítek special, daň nenese — běžná
        // měsíční faktura do kontroly nesmí, s odběratelem i bez něj.
        $canonical = $this->specialPlaceCanonical();
        $canonical['supplier'] = ['name' => 'Pronajímatel', 'country' => 'cz'];
        $canonical['vat'] = ['mode' => 'none', 'place' => 'domestic'];
        $canonical['_resolve']['contentTag']['tag'] = 'premises.rent';

        foreach ([null, ['name' => 'Odběratel s.r.o.', 'country' => 'cz', 'vatId' => 'CZ12345678']] as $customer) {
            $canonical['customer'] = $customer;
            $this->assertSame(
                AnalysisConfidenceResolver::BAND_READY,
                $this->resolver()->capBand(AnalysisConfidenceResolver::BAND_READY, $canonical, self::TAGS),
            );
        }
    }

    public function testCapKeepsReadyWhenOnlyPartOfRowsIsSpecial(): void
    {
        $canonical = $this->specialPlaceCanonical();
        $canonical['_resolve']['contentTag']['rowExceptions'] = [['rowIndex' => 1, 'tag' => 'it.software']];

        $this->assertSame(
            AnalysisConfidenceResolver::BAND_READY,
            $this->resolver()->capBand(AnalysisConfidenceResolver::BAND_READY, $canonical, self::TAGS),
        );
    }

    public function testSpecialPlaceCapReadsSupplierOriginFromAnySignal(): void
    {
        // Hrubý signál „dodavatel není tuzemský“ — testuje se podmínka sama,
        // DIČ tu neprochází validací hodnot (strop D15).
        $base = $this->specialPlaceCanonical();
        $base['vat'] = ['mode' => 'fromBase', 'place' => 'domestic'];
        $base['supplier'] = ['name' => 'Dodavatel'];
        $caps = static fn (array $canonical): bool => AnalysisConfidenceResolver::capsSpecialPlace($canonical, self::TAGS);
        $customerCz = ['name' => 'Odběratel s.r.o.', 'country' => 'cz'];

        // Dodavatel bez DIČ a bez země signál nedá.
        $this->assertFalse($caps($base));

        // `vat.place` mimo tuzemsko stačí.
        $this->assertTrue($caps(['vat' => ['place' => 'thirdCountry']] + $base));

        // Prefix DIČ dodavatele proti DIČ odběratele.
        $withVatIds = $base;
        $withVatIds['supplier']['vatId'] = 'DE 123 456 789';
        $withVatIds['customer'] = ['vatId' => 'cz12345678'];
        $this->assertTrue($caps($withVatIds));
        $withVatIds['supplier']['vatId'] = 'CZ87654321';
        $this->assertFalse($caps($withVatIds));

        // Prefix DIČ dodavatele proti zemi odběratele (DIČ odběratele návrh často nenese).
        $prefixVsCountry = $base;
        $prefixVsCountry['supplier']['vatId'] = 'ATU12345678';
        $prefixVsCountry['customer'] = $customerCz;
        $this->assertTrue($caps($prefixVsCountry));
        $prefixVsCountry['supplier']['vatId'] = 'CZ87654321';
        $this->assertFalse($caps($prefixVsCountry));

        // Země dodavatele proti zemi odběratele (i z adresy).
        $countries = $base;
        $countries['supplier']['address'] = ['country' => 'SK'];
        $countries['customer'] = $customerCz;
        $this->assertTrue($caps($countries));
        $countries['supplier']['address']['country'] = 'CZ';
        $this->assertFalse($caps($countries));

        // Odběratel bez údajů: rozhoduje písmenný prefix DIČ dodavatele —
        // applier z něj místo plnění odvozuje proti zemi naší registrace.
        $noCustomer = $base;
        $noCustomer['supplier'] = ['name' => 'Dodavatel', 'country' => 'de', 'vatId' => 'DE123456789'];
        $this->assertTrue($caps($noCustomer));
        $noCustomer['supplier'] = ['name' => 'Dodavatel', 'country' => 'de'];
        $this->assertFalse($caps($noCustomer));
        $noCustomer['supplier'] = ['name' => 'Dodavatel', 'vatId' => '12345678'];
        $this->assertFalse($caps($noCustomer));
    }

    public function testSpecialPlaceCapOnlyForReceivedDocuments(): void
    {
        // Applier o dokladu mimo české DPH rozhoduje jen u přijatého dokladu.
        $canonical = ['selfParty' => 'supplier'] + $this->specialPlaceCanonical();

        $this->assertFalse(AnalysisConfidenceResolver::capsSpecialPlace($canonical, self::TAGS));
    }

    public function testContentTagsFromConfig(): void
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'core.exchange.contentTags' ? self::TAGS : null,
        );

        $this->assertSame(self::TAGS, AnalysisConfidenceResolver::contentTagsFrom($config));
        $this->assertSame([], AnalysisConfidenceResolver::contentTagsFrom(null));
        $this->assertSame([], AnalysisConfidenceResolver::contentTagsFrom($this->createMock(ConfigRuntime::class)));
    }

    // ── bandForAnalysis (convenience) ───────────────────────────────────────

    public function testBandForAnalysisUsesProfileThresholdsAndCap(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'confidence_thresholds' => '{"ready": 0.8, "review": 0.4}',
        ]);

        // 0.85 ≥ profile ready 0.8 → ready; canonical bez řádků strop nesráží.
        $this->assertSame(
            AnalysisConfidenceResolver::BAND_READY,
            $this->resolver($db)->bandForAnalysis(0.85, 17, [], self::TAGS),
        );

        // Řádek bez ourCode → strop D7 sráží ready na review.
        $this->assertSame(
            AnalysisConfidenceResolver::BAND_REVIEW,
            $this->resolver($db)->bandForAnalysis(0.85, 17, [
                'rows' => [['rowKind' => 'item', 'item' => ['name' => 'X']]],
            ], self::TAGS),
        );
    }

    public function testBandForAnalysisWithoutProfileFallsBackToDefaultProfile(): void
    {
        // Žádný default profil v DS → default thresholds; null confidence → 0.0 → low.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $this->assertSame(
            AnalysisConfidenceResolver::BAND_LOW,
            $this->resolver($db)->bandForAnalysis(null, null, [], self::TAGS),
        );
        $this->assertSame(
            AnalysisConfidenceResolver::BAND_REVIEW,
            $this->resolver($db)->bandForAnalysis(0.7, null, [], self::TAGS),
        );
    }
}
