<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accbal;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Accounting\JournalLineRequest;
use Shipard\Core\Accounting\JournalLineView;
use Shipard\Core\Accounting\JournalSourceContext;
use Shipard\Module\Economy\Accbal\CaseClosureContributor;
use Shipard\Module\Economy\Accbal\CaseResidual;

/**
 * CaseClosureContributor::closures — čistá funkce nad poli (#79 D3b/D3c):
 * spouštěcí řádek (operace, maska kategorie úhrady, opačná strana,
 * partner + VS, kladná částka), klíč případu vč. období a normalizace,
 * částka do výše rezidua a postupné spotřebování víc řádky téhož klíče,
 * domácí částka kurzem předpisů s dorovnáním haléřů, pár požadavků
 * (kategorie uzavření na předpisovou stranu, účet prvního předpisu na
 * stranu úhrady) s identitou řádku. Agregát případu dodává callback.
 */
class CaseClosureContributorTest extends TestCase
{
    private const PROFORMAS = 6;
    private const FY = 5;

    /** @var array<string, CaseResidual|null> "balance|fy|partner|vs|ss|cur" → agregát */
    private array $cases = [];
    /** @var list<array{0: int, 1: array<string, mixed>}> dotazy na agregát */
    private array $asked = [];

    private function context(string $currency = 'czk'): JournalSourceContext
    {
        return new JournalSourceContext('bankTransaction', 77, '2026-06-10', self::FY, $currency);
    }

    /** @return list<array{balance: int, name: string, request_side: int, payment_mask: string, closing_category: string}> */
    private function targets(): array
    {
        return [[
            'balance'          => self::PROFORMAS,
            'name'             => 'Zálohové faktury vydané',
            'request_side'     => 0,
            'payment_mask'     => '324',
            'closing_category' => 'offbalance.contra',
        ]];
    }

    private static function line(
        int $side, string $account, ?string $operation, float $amount,
        ?int $partner = 42, ?string $vs = 'PRO-1', ?string $ss = null, ?float $dom = null,
    ): JournalLineView {
        return new JournalLineView($side, $account, $operation, $partner, $vs, $ss, $dom ?? $amount, $amount);
    }

    private static function proforma(float $requested, float $requestedHc, float $paid = 0.0, float $paidHc = 0.0): CaseResidual
    {
        return new CaseResidual($requested, $requestedHc, $paid, $paidHc, '756100', '756100');
    }

    private function seedCase(CaseResidual $case, int $partner = 42, string $vs = 'PRO-1', ?string $ss = null, string $cur = 'czk', int $fy = self::FY): void
    {
        $this->cases[implode('|', [self::PROFORMAS, $fy, $partner, $vs, $ss ?? '', $cur])] = $case;
    }

    /** @param list<JournalLineView> $lines @return list<JournalLineRequest> */
    private function closures(array $lines, ?string $currency = 'czk'): array
    {
        $this->asked = [];
        return CaseClosureContributor::closures(
            $this->context($currency), $lines, $this->targets(),
            function (int $balance, array $key): ?CaseResidual {
                $this->asked[] = [$balance, $key];
                $id = implode('|', [$balance, $key['fiscal_year'], $key['partner'], $key['payment_reference'], $key['specific_symbol'] ?? '', $key['currency']]);
                return $this->cases[$id] ?? null;
            },
        );
    }

    /** @param list<JournalLineRequest> $requests @return list<array{0: int, 1: ?string, 2: ?string, 3: float, 4: float}> */
    private static function shape(array $requests): array
    {
        return array_map(static fn(JournalLineRequest $r) => [$r->side, $r->category, $r->accountNumber, $r->moneyDom, $r->moneyCur], $requests);
    }

    // ── Uzavírací pár ────────────────────────────────────────────────────────

    public function testFullPaymentClosesCaseWithBalancedPair(): void
    {
        $this->seedCase(self::proforma(12100.0, 12100.0));

        $requests = $this->closures([self::line(1, '324100', 'payment.in', 12100.0)]);

        $this->assertSame([
            [0, 'offbalance.contra', null, 12100.0, 12100.0],
            [1, null, '756100', 12100.0, 12100.0],
        ], self::shape($requests));
        foreach ($requests as $r) {
            $this->assertSame(42, $r->partner);
            $this->assertSame('PRO-1', $r->paymentReference);
            $this->assertNull($r->specificSymbol);
            $this->assertSame('Uzavření zálohové faktury vydané PRO-1', $r->text);
        }
    }

    public function testPartialPaymentClosesOnlyPaidAmount(): void
    {
        $this->seedCase(self::proforma(12100.0, 12100.0));

        $requests = $this->closures([self::line(1, '324100', 'payment.in', 5000.0)]);

        $this->assertSame([
            [0, 'offbalance.contra', null, 5000.0, 5000.0],
            [1, null, '756100', 5000.0, 5000.0],
        ], self::shape($requests));
    }

    public function testSecondPaymentClosesRemainderAndSurplusIsIgnored(): void
    {
        // Jiný zdroj už uzavřel 5 000; tahle platba 10 000 uzavře zbytek 7 100.
        $this->seedCase(self::proforma(12100.0, 12100.0, 5000.0, 5000.0));

        $requests = $this->closures([self::line(1, '324100', 'payment.in', 10000.0)]);

        $this->assertSame([
            [0, 'offbalance.contra', null, 7100.0, 7100.0],
            [1, null, '756100', 7100.0, 7100.0],
        ], self::shape($requests));
    }

    public function testOverpaymentClosesOnlyResidual(): void
    {
        $this->seedCase(self::proforma(12100.0, 12100.0));

        $requests = $this->closures([self::line(1, '324100', 'payment.in', 15000.0)]);

        $this->assertSame([[0, 'offbalance.contra', null, 12100.0, 12100.0], [1, null, '756100', 12100.0, 12100.0]], self::shape($requests));
    }

    public function testClosedOrUnknownCaseYieldsNothing(): void
    {
        $this->seedCase(self::proforma(12100.0, 12100.0, 12100.0, 12100.0));
        $this->assertSame([], $this->closures([self::line(1, '324100', 'payment.in', 100.0)]), 'uzavřený případ');

        $this->cases = [];
        $this->assertSame([], $this->closures([self::line(1, '324100', 'payment.in', 100.0)]), 'klíč bez pohybů');
        $this->assertCount(1, $this->asked, 'agregát se ptá jen jednou');
    }

    public function testCaseWithoutRequestYieldsNothing(): void
    {
        $this->cases[implode('|', [self::PROFORMAS, self::FY, 42, 'PRO-1', '', 'czk'])]
            = new CaseResidual(0.0, 0.0, 100.0, 100.0, null, '756100');

        $this->assertSame([], $this->closures([self::line(1, '324100', 'payment.in', 100.0)]));
    }

    // ── Víc řádků téhož klíče v jednom zdroji ────────────────────────────────

    public function testLinesOfSameKeyConsumeResidualInOrder(): void
    {
        $this->seedCase(self::proforma(12100.0, 12100.0));

        $requests = $this->closures([
            self::line(1, '324100', 'advance.received', 8000.0),
            self::line(1, '324100', 'advance.received', 8000.0),
            self::line(1, '324100', 'advance.received', 8000.0),
        ]);

        $this->assertSame([
            [0, 'offbalance.contra', null, 8000.0, 8000.0], [1, null, '756100', 8000.0, 8000.0],
            [0, 'offbalance.contra', null, 4100.0, 4100.0], [1, null, '756100', 4100.0, 4100.0],
        ], self::shape($requests), 'třetí řádek už reziduum nenajde');
    }

    // ── Cizí měna (D3c) ──────────────────────────────────────────────────────

    public function testForeignCurrencyUsesRequestRateAndLastClosureSettlesRemainder(): void
    {
        // Proforma 1 000 EUR kurzem 25,123 → 25 123 Kč. Tři platby 333 + 333
        // + 334 EUR vlastními kurzy: první dvě poměrem (8 365,96), poslední
        // dorovná na Σ 25 123 (8 391,08) — Σ dom uzavření = Σ dom předpisu.
        $this->seedCase(self::proforma(1000.0, 25123.0), cur: 'eur');
        $first = $this->closures([self::line(1, '324100', 'payment.in', 333.0, dom: 8325.0)], 'eur');
        $this->assertSame([[0, 'offbalance.contra', null, 8365.96, 333.0], [1, null, '756100', 8365.96, 333.0]], self::shape($first));

        $this->seedCase(self::proforma(1000.0, 25123.0, 333.0, 8365.96), cur: 'eur');
        $second = $this->closures([self::line(1, '324100', 'payment.in', 333.0, dom: 8391.6)], 'eur');
        $this->assertSame([[0, 'offbalance.contra', null, 8365.96, 333.0], [1, null, '756100', 8365.96, 333.0]], self::shape($second));

        $this->seedCase(self::proforma(1000.0, 25123.0, 666.0, 16731.92), cur: 'eur');
        $last = $this->closures([self::line(1, '324100', 'payment.in', 334.0, dom: 8283.2)], 'eur');
        $this->assertSame([[0, 'offbalance.contra', null, 8391.08, 334.0], [1, null, '756100', 8391.08, 334.0]], self::shape($last));
        $this->assertEqualsWithDelta(25123.0, 8365.96 + 8365.96 + 8391.08, 0.001);
    }

    public function testForeignCurrencyRemainderSettledWithinOneSource(): void
    {
        $this->seedCase(self::proforma(1000.0, 25123.0), cur: 'eur');

        $requests = $this->closures([
            self::line(1, '324100', 'advance.received', 333.0, dom: 8325.0),
            self::line(1, '324100', 'advance.received', 667.0, dom: 16675.0),
        ], 'eur');

        $this->assertSame([
            [0, 'offbalance.contra', null, 8365.96, 333.0], [1, null, '756100', 8365.96, 333.0],
            [0, 'offbalance.contra', null, 16757.04, 667.0], [1, null, '756100', 16757.04, 667.0],
        ], self::shape($requests));
    }

    // ── Klíč případu ─────────────────────────────────────────────────────────

    public function testKeyCarriesFiscalYearNormalizedSymbolsAndCurrency(): void
    {
        $this->seedCase(self::proforma(100.0, 100.0), ss: '77', cur: 'czk');

        $requests = $this->closures([self::line(1, '324100', 'payment.in', 100.0, vs: ' PRO-1 ', ss: ' 77 ')], 'CZK');

        $this->assertCount(2, $requests);
        $this->assertSame([self::PROFORMAS, [
            'fiscal_year'       => self::FY,
            'partner'           => 42,
            'payment_reference' => 'PRO-1',
            'specific_symbol'   => '77',
            'currency'          => 'czk',
        ]], $this->asked[0]);
        $this->assertSame('PRO-1', $requests[0]->paymentReference, 'identita požadavku normalizovaná');
        $this->assertSame('77', $requests[0]->specificSymbol);
    }

    public function testOtherFiscalYearIsMiss(): void
    {
        $this->seedCase(self::proforma(100.0, 100.0), fy: self::FY + 1);

        $this->assertSame([], $this->closures([self::line(1, '324100', 'payment.in', 100.0)]));
    }

    public function testEmptySpecificSymbolMatchesOnlyEmpty(): void
    {
        $this->seedCase(self::proforma(100.0, 100.0), ss: '77');

        $this->assertSame([], $this->closures([self::line(1, '324100', 'payment.in', 100.0)]), 'prázdný SS nesedí na 77');
        $this->assertCount(2, $this->closures([self::line(1, '324100', 'payment.in', 100.0, ss: '77')]));
    }

    // ── Co nespouští ─────────────────────────────────────────────────────────

    public function testNonTriggerOperationsDoNothing(): void
    {
        $this->seedCase(self::proforma(12100.0, 12100.0));

        foreach (['sale.advanceVat', 'sale.advanceDeduction', 'acc.entry', 'transfer.in', null] as $operation) {
            $this->assertSame([], $this->closures([self::line(1, '324100', $operation, 12100.0)]), (string) $operation);
        }
    }

    public function testRequestSideAccountMaskAmountPartnerAndVsAreRequired(): void
    {
        $this->seedCase(self::proforma(12100.0, 12100.0));

        $this->assertSame([], $this->closures([self::line(0, '324100', 'payment.out', 12100.0)]), 'stejná strana jako předpis (vratka) nespouští');
        $this->assertSame([], $this->closures([self::line(1, '311100', 'payment.in', 12100.0)]), 'účet mimo masku úhrady');
        $this->assertSame([], $this->closures([self::line(1, '324100', 'payment.in', 0.0)]), 'nulová částka');
        $this->assertSame([], $this->closures([self::line(1, '324100', 'payment.in', -100.0)]), 'záporná částka');
        $this->assertSame([], $this->closures([self::line(1, '324100', 'payment.in', 100.0, partner: null)]), 'bez partnera');
        $this->assertSame([], $this->closures([self::line(1, '324100', 'payment.in', 100.0, vs: '  ')]), 'bez VS');
        $this->assertSame([], $this->asked, 'bez spouštěcího řádku se agregát nečte');
    }

    public function testNoTargetsYieldsNothing(): void
    {
        $this->assertSame([], CaseClosureContributor::closures(
            $this->context(), [self::line(1, '324100', 'payment.in', 100.0)], [],
            static fn(): ?CaseResidual => self::proforma(100.0, 100.0),
        ));
    }

    // ── CaseResidual ─────────────────────────────────────────────────────────

    public function testCaseResidualAggregatesRows(): void
    {
        $case = CaseResidual::fromRows([
            ['bal_side' => 1, 'account_number' => '756100', 'amount' => 100.0, 'amount_hc' => 2500.0],
            ['bal_side' => 0, 'account_number' => '756100', 'amount' => 1000.0, 'amount_hc' => 25123.0],
            ['bal_side' => 0, 'account_number' => '756200', 'amount' => 10.0, 'amount_hc' => 250.0],
        ]);

        $this->assertEqualsWithDelta(1010.0, $case->requested, 0.001);
        $this->assertEqualsWithDelta(25373.0, $case->requestedHc, 0.001);
        $this->assertEqualsWithDelta(100.0, $case->paid, 0.001);
        $this->assertEqualsWithDelta(910.0, $case->residual(), 0.001);
        $this->assertEqualsWithDelta(22873.0, $case->residualHc(), 0.001);
        $this->assertEqualsWithDelta(25373.0 / 1010.0, $case->requestRate(), 1e-9);
        $this->assertSame('756100', $case->firstRequestAccount, 'první předpis, ne první řádek');
        $this->assertSame('756100', $case->firstAccount);
    }

    public function testCaseResidualWithoutRequestHasRateOne(): void
    {
        $case = CaseResidual::fromRows([['bal_side' => 1, 'account_number' => '324100', 'amount' => 100.0, 'amount_hc' => 100.0]]);

        $this->assertNull($case->firstRequestAccount);
        $this->assertSame('324100', $case->firstAccount);
        $this->assertSame(1.0, $case->requestRate());
        $this->assertEqualsWithDelta(-100.0, $case->residual(), 0.001);
    }
}
