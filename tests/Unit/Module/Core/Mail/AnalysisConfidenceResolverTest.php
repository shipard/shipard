<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\AnalysisConfidenceResolver;

/**
 * Runtime mapování confidence → pásmo návrhu (tasks/mail-message-centric.md
 * D3): prahy profilu s fallbacky + strop D7 podle pokrytí řádků položkami.
 * Pásmo se počítá za běhu, nikam se nezapisuje — testuje se resolver přímo,
 * hlavně thresholds fallbacky a hranice stropu.
 */
class AnalysisConfidenceResolverTest extends TestCase
{
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
            $this->resolver()->capBand(AnalysisConfidenceResolver::BAND_READY, $canonical),
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
            $this->resolver()->capBand(AnalysisConfidenceResolver::BAND_READY, $canonical),
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
            $this->resolver()->capBand(AnalysisConfidenceResolver::BAND_READY, $canonical),
        );
    }

    public function testCapLeavesLowerBandsAlone(): void
    {
        $canonical = ['supplier' => ['country' => 'cz', 'companyId' => '12345678']];

        $this->assertSame(AnalysisConfidenceResolver::BAND_REVIEW, $this->resolver()->capBand(AnalysisConfidenceResolver::BAND_REVIEW, $canonical));
        $this->assertSame(AnalysisConfidenceResolver::BAND_LOW, $this->resolver()->capBand(AnalysisConfidenceResolver::BAND_LOW, $canonical));
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
            $this->resolver($db)->bandForAnalysis(0.85, 17, []),
        );

        // Řádek bez ourCode → strop D7 sráží ready na review.
        $this->assertSame(
            AnalysisConfidenceResolver::BAND_REVIEW,
            $this->resolver($db)->bandForAnalysis(0.85, 17, [
                'rows' => [['rowKind' => 'item', 'item' => ['name' => 'X']]],
            ]),
        );
    }

    public function testBandForAnalysisWithoutProfileFallsBackToDefaultProfile(): void
    {
        // Žádný default profil v DS → default thresholds; null confidence → 0.0 → low.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $this->assertSame(
            AnalysisConfidenceResolver::BAND_LOW,
            $this->resolver($db)->bandForAnalysis(null, null, []),
        );
        $this->assertSame(
            AnalysisConfidenceResolver::BAND_REVIEW,
            $this->resolver($db)->bandForAnalysis(0.7, null, []),
        );
    }
}
