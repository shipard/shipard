<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accbal;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Accbal\LedgerGenerator;

/**
 * Kanonický klíč pohybu (#69 D13): SHA-1 n-tice zdroj + platební identita
 * řádku, normalizované jako klíč případu (D10). Pravidla odvození pohybu
 * (#69 D17 operace má přednost, D18 sign-pravidla jen ze seedu, D20
 * uzávěrkové období mimo ledger, D22 nejdelší shodný prefix vyhrává)
 * přes čistou `buildDesired()` nad poli.
 * Generování nad reálným deníkem kryje integrační LedgerGeneratorTest.
 */
class LedgerGeneratorTest extends TestCase
{
    private const RECEIVABLES       = 1;
    private const PAYABLES          = 2;
    private const ADVANCES_RECEIVED = 4;
    private const UNMATCHED         = 5;
    private const ACC_DATE    = '2026-06-10';

    /** Řádek nastavení ve tvaru balanceAccounts() (a_from/a_to = platnost řádku, b_* skupiny). */
    private static function rule(int $balance, string $prefix, int $accSide, int $amountsSign, int $balSide, bool $modifySign = false, array $over = []): array
    {
        static $id = 0;
        return array_merge([
            'id'             => ++$id,
            'balance'        => $balance,
            'account_number' => $prefix,
            'acc_side'       => $accSide,
            'amounts_sign'   => $amountsSign,
            'bal_side'       => $balSide,
            'modify_sign'    => $modifySign ? 1 : 0,
            'a_from'         => null,
            'a_to'           => null,
            'b_from'         => null,
            'b_to'           => null,
        ], $over);
    }

    /** Výchozí seed (sign-pravidla dobropisů v obou hlavních skupinách) + clearing. */
    private static function seedRules(): array
    {
        return [
            self::rule(self::RECEIVABLES, '311', 0, 1, 0),
            self::rule(self::RECEIVABLES, '311', 1, 1, 1),
            self::rule(self::RECEIVABLES, '321', 1, 2, 0, true),
            self::rule(self::RECEIVABLES, '321', 0, 2, 1, true),
            self::rule(self::PAYABLES, '321', 1, 1, 0),
            self::rule(self::PAYABLES, '321', 0, 1, 1),
            self::rule(self::PAYABLES, '311', 0, 2, 0, true),
            self::rule(self::PAYABLES, '311', 1, 2, 1, true),
            self::rule(self::UNMATCHED, '261200', 1, 1, 1),
            self::rule(self::UNMATCHED, '261300', 0, 1, 1),
        ];
    }

    /**
     * Legacy seed (importovaný DS): bez sign-pravidel a s částkami „Všechny"
     * (amounts_sign 0) — dobropis zůstává záporně ve své skupině.
     */
    private static function legacyRules(): array
    {
        $rules = array_filter(self::seedRules(), static fn(array $r) => $r['modify_sign'] === 0);
        return array_values(array_map(static fn(array $r) => ['amounts_sign' => 0] + $r, $rules));
    }

    /** Jednostranný řádek deníku: kladná částka na MD/DAL, záporná = záporná na téže straně. */
    private static function journal(string $account, int $side, float $amount, ?string $operation, array $over = []): array
    {
        static $id = 0;
        return array_merge([
            'id'                 => ++$id,
            'account_number'     => $account,
            'accounting_date'    => self::ACC_DATE,
            'money_dr'           => $side === 0 ? $amount : 0.0,
            'money_cr'           => $side === 1 ? $amount : 0.0,
            'money_dr_cur'       => $side === 0 ? $amount : 0.0,
            'money_cr_cur'       => $side === 1 ? $amount : 0.0,
            'operation'          => $operation,
            'partner'            => 42,
            'payment_reference'  => 'VS1',
            'specific_symbol'    => null,
            'currency'           => 'czk',
            'fiscal_year'        => 7,
            'fiscal_period_type' => 1,
            'is_error'           => 0,
        ], $over);
    }

    /** @return list<array{balance: int, bal_side: int, amount: float, account: string}> */
    private function desired(array $rules, array $rows): array
    {
        $generator = new LedgerGenerator($this->createMock(\Dibi\Connection::class), null, 'czk');
        $out = [];
        foreach ($generator->buildDesired('doc', 5, $rules, $rows, 'czk') as $move) {
            $out[] = [
                'balance'  => $move['balance'],
                'bal_side' => $move['bal_side'],
                'amount'   => $move['amount'],
                'account'  => $move['account_number'],
            ];
        }
        return $out;
    }

    // ── D17: operace má přednost ─────────────────────────────────────────────

    public function testReceivableOperationKeepsNegativePaymentInReceivables(): void
    {
        // Oprava salda / zápočet ze starého systému: 311 DAL záporně s operací.
        // Sign-pravidlo (311 záporně → Závazky ×−1) se nepoužije.
        $moves = $this->desired(self::seedRules(), [self::journal('311100', 1, -100.0, 'acc.balanceReceivable')]);

        $this->assertSame([['balance' => self::RECEIVABLES, 'bal_side' => 1, 'amount' => -100.0, 'account' => '311100']], $moves);
    }

    public function testPayableOperationKeepsSignInPayables(): void
    {
        $moves = $this->desired(self::seedRules(), [self::journal('321100', 1, -300.0, 'acc.balancePayable')]);

        $this->assertSame([['balance' => self::PAYABLES, 'bal_side' => 0, 'amount' => -300.0, 'account' => '321100']], $moves, 'předpis závazku záporně, žádné ×−1 do Pohledávek');
    }

    public function testOperationRowOnRequestSideIsRequest(): void
    {
        $moves = $this->desired(self::seedRules(), [self::journal('311100', 0, 250.0, 'acc.balanceReceivable')]);

        $this->assertSame([['balance' => self::RECEIVABLES, 'bal_side' => 0, 'amount' => 250.0, 'account' => '311100']], $moves);
    }

    public function testFxOperationBooksOnlyTheBalanceAccountLine(): void
    {
        // Kurzová ztráta pohledávky: 563 MD / 311 DAL, obě linky nesou operaci.
        $moves = $this->desired(self::seedRules(), [
            self::journal('563000', 0, 4.0, 'acc.fxLossReceivable'),
            self::journal('311100', 1, 4.0, 'acc.fxLossReceivable'),
        ]);

        $this->assertSame([['balance' => self::RECEIVABLES, 'bal_side' => 1, 'amount' => 4.0, 'account' => '311100']], $moves, '563 není v žádné skupině → přeskočen');
    }

    public function testSideOperationWithoutGroupOnThatSideIsSkipped(): void
    {
        // acc.balancePayable na 311: Závazky mají 311 jen jako sign-pravidlo
        // (modify_sign) → pro operaci žádná skupina, řádek nejde ani přes nastavení.
        $this->assertSame([], $this->desired(self::seedRules(), [self::journal('311100', 0, -100.0, 'acc.balancePayable')]));
        $this->assertSame([], $this->desired(self::legacyRules(), [self::journal('311100', 0, -100.0, 'acc.balancePayable')]));
    }

    // ── payment.* = vždy úhrada, znaménko podle strany ───────────────────────

    public function testPaymentOnOppositeSideIsNegativePayment(): void
    {
        // Vratka přeplatku zákazníkovi: payment.receivable / payment.out na 311 MD.
        $moves = $this->desired(self::seedRules(), [self::journal('311100', 0, 100.0, 'payment.receivable')]);
        $this->assertSame([['balance' => self::RECEIVABLES, 'bal_side' => 1, 'amount' => -100.0, 'account' => '311100']], $moves);

        $moves = $this->desired(self::seedRules(), [self::journal('321100', 1, 300.0, 'payment.out')]);
        $this->assertSame([['balance' => self::PAYABLES, 'bal_side' => 1, 'amount' => -300.0, 'account' => '321100']], $moves, 'dodavatel vrací přeplatek: 321 DAL = opačná strana proti úhradě závazku');
    }

    public function testPaymentOnPaymentSideIsPositivePayment(): void
    {
        $moves = $this->desired(self::seedRules(), [
            self::journal('311100', 1, 100.0, 'payment.in'),
            self::journal('261200', 1, 50.0, 'payment.in', ['payment_reference' => 'VS2']),
        ]);

        $this->assertSame([
            ['balance' => self::RECEIVABLES, 'bal_side' => 1, 'amount' => 100.0, 'account' => '311100'],
            ['balance' => self::UNMATCHED, 'bal_side' => 1, 'amount' => 50.0, 'account' => '261200'],
        ], $moves, 'clearing skupina má jen řádky úhrady — strana úhrady z nich');
    }

    public function testPaymentIgnoresCreditNoteRulesOfOtherGroup(): void
    {
        // 311 DAL záporně s payment.*: Závazky mají 311 jen přes modify_sign →
        // skupina účtu jsou Pohledávky, DAL = strana úhrady → + × (−500) = −500.
        $moves = $this->desired(self::seedRules(), [self::journal('311100', 1, -500.0, 'payment.in')]);

        $this->assertSame([['balance' => self::RECEIVABLES, 'bal_side' => 1, 'amount' => -500.0, 'account' => '311100']], $moves);
    }

    // ── D18: bez operace platí nastavení vč. sign-pravidel ──────────────────

    public function testNegativeAmountWithoutOperationFollowsSettings(): void
    {
        $creditNote = [self::journal('311100', 0, -100.0, 'sale.services')];

        $this->assertSame(
            [['balance' => self::PAYABLES, 'bal_side' => 0, 'amount' => 100.0, 'account' => '311100']],
            $this->desired(self::seedRules(), $creditNote),
            'výchozí seed: dobropis pohledávky = závazek ×−1',
        );
        $this->assertSame(
            [['balance' => self::RECEIVABLES, 'bal_side' => 0, 'amount' => -100.0, 'account' => '311100']],
            $this->desired(self::legacyRules(), $creditNote),
            'legacy: dobropis zůstává záporně v Pohledávkách',
        );
    }

    public function testMirroredCreditNoteRuleForPayables(): void
    {
        $received = [self::journal('321100', 1, -80.0, 'purchase.services')];

        $this->assertSame(
            [['balance' => self::RECEIVABLES, 'bal_side' => 0, 'amount' => 80.0, 'account' => '321100']],
            $this->desired(self::seedRules(), $received),
            'výchozí seed: dobropis závazku = pohledávka ×−1',
        );
        $this->assertSame(
            [['balance' => self::PAYABLES, 'bal_side' => 0, 'amount' => -80.0, 'account' => '321100']],
            $this->desired(self::legacyRules(), $received),
            'legacy: dobropis závazku zůstává záporně v Závazcích',
        );
    }

    // ── platnost k účetnímu datu ─────────────────────────────────────────────

    public function testExpiredRuleIsNotUsedForOperationNorSettings(): void
    {
        $rules = [
            self::rule(self::RECEIVABLES, '311', 0, 1, 0, false, ['a_to' => '2026-05-31']),
            self::rule(self::RECEIVABLES, '311', 1, 1, 1, false, ['a_to' => '2026-05-31']),
        ];

        $this->assertSame([], $this->desired($rules, [self::journal('311100', 1, -100.0, 'acc.balanceReceivable')]), 'krok operace respektuje valid_to');
        $this->assertSame([], $this->desired($rules, [self::journal('311100', 0, 100.0, 'sale.services')]), 'nastavení respektuje valid_to');
        $this->assertCount(1, $this->desired($rules, [self::journal('311100', 0, 100.0, 'sale.services', ['accounting_date' => '2026-05-31'])]), 'poslední den platnosti ještě platí');
    }

    public function testRuleValiditySwitchesLegacyToDefaultAtYearBoundary(): void
    {
        // Ruční přepnutí legacy DS (docs/accbal.md §3.2): řádky „Všechny" končí
        // 31. 12., od 1. 1. platí řádky „Kladné" + sign-pravidlo dobropisu.
        $rules = array_map(static fn(array $r) => ['a_to' => '2026-12-31'] + $r, self::legacyRules());
        $rules[] = self::rule(self::RECEIVABLES, '311', 0, 1, 0, false, ['a_from' => '2027-01-01']);
        $rules[] = self::rule(self::PAYABLES, '311', 0, 2, 0, true, ['a_from' => '2027-01-01']);
        $creditNote = static fn(string $date) => [self::journal('311100', 0, -100.0, 'sale.services', ['accounting_date' => $date])];

        $this->assertSame(
            [['balance' => self::RECEIVABLES, 'bal_side' => 0, 'amount' => -100.0, 'account' => '311100']],
            $this->desired($rules, $creditNote('2026-12-31')),
        );
        $this->assertSame(
            [['balance' => self::PAYABLES, 'bal_side' => 0, 'amount' => 100.0, 'account' => '311100']],
            $this->desired($rules, $creditNote('2027-01-01')),
            'od nového roku právě jeden pohyb — staré řádky „Všechny" už neplatí',
        );
    }

    // ── D20: uzávěrkové období mimo ledger ──────────────────────────────────

    public function testClosingPeriodRowsAreSkippedOpeningRowsStay(): void
    {
        $closing = [self::journal('311100', 1, 7600000.0, null, ['partner' => null, 'payment_reference' => null, 'fiscal_period_type' => 2])];
        $this->assertSame([], $this->desired(self::seedRules(), $closing));

        $opening = [self::journal('311100', 0, 1000.0, null, ['fiscal_period_type' => 0])];
        $this->assertSame([['balance' => self::RECEIVABLES, 'bal_side' => 0, 'amount' => 1000.0, 'account' => '311100']], $this->desired(self::seedRules(), $opening));

        $noMonth = [self::journal('311100', 0, 1000.0, null, ['fiscal_period_type' => null])];
        $this->assertCount(1, $this->desired(self::seedRules(), $noMonth), 'řádek bez měsíce (NULL) se derivuje');
    }

    public function testErrorRowProducesNothing(): void
    {
        $this->assertSame([], $this->desired(self::seedRules(), [self::journal('311100', 0, 100.0, 'acc.balanceReceivable', ['is_error' => 1])]));
    }

    public function testSameIdentityRowsAggregateAcrossRules(): void
    {
        $moves = $this->desired(self::seedRules(), [
            self::journal('311100', 0, 100.0, 'sale.services'),
            self::journal('311100', 0, 50.0, 'sale.goods'),
        ]);

        $this->assertSame([['balance' => self::RECEIVABLES, 'bal_side' => 0, 'amount' => 150.0, 'account' => '311100']], $moves);
    }

    // ── D22: nejdelší shodný prefix vyhrává ─────────────────────────────────

    /**
     * Seed + Závazky 325 + podúčet 325201 přesunutý do Přijatých záloh
     * (nastavení per DS, docs/accbal.md §3.2) — obě strany.
     */
    private static function depositRules(): array
    {
        return [
            ...self::seedRules(),
            self::rule(self::PAYABLES, '325', 1, 1, 0),
            self::rule(self::PAYABLES, '325', 0, 1, 1),
            self::rule(self::ADVANCES_RECEIVED, '324', 1, 1, 0),
            self::rule(self::ADVANCES_RECEIVED, '324', 0, 1, 1),
            self::rule(self::ADVANCES_RECEIVED, '325201', 1, 1, 0),
            self::rule(self::ADVANCES_RECEIVED, '325201', 0, 1, 1),
        ];
    }

    public function testLongestPrefixWinsForSettingsRows(): void
    {
        $rules = self::depositRules();

        $this->assertSame(
            [['balance' => self::ADVANCES_RECEIVED, 'bal_side' => 0, 'amount' => 1000.0, 'account' => '325201']],
            $this->desired($rules, [self::journal('325201', 1, 1000.0, 'acc.item')]),
            'jediný pohyb v Přijatých zálohách — kratší 325 v Závazcích je vyloučen',
        );
        $this->assertSame(
            [['balance' => self::PAYABLES, 'bal_side' => 0, 'amount' => 1000.0, 'account' => '325101']],
            $this->desired($rules, [self::journal('325101', 1, 1000.0, 'acc.item')]),
            'jiný podúčet dál padá do Závazků',
        );
        $this->assertSame(
            [['balance' => self::ADVANCES_RECEIVED, 'bal_side' => 1, 'amount' => 1000.0, 'account' => '325201']],
            $this->desired($rules, [self::journal('325201', 0, 1000.0, null)]),
            'vyúčtování zálohy na MD = úhrada v Přijatých zálohách',
        );
    }

    public function testLongestPrefixWinsForOperationsWithSide(): void
    {
        $rules = self::depositRules();

        $this->assertSame(
            [['balance' => self::ADVANCES_RECEIVED, 'bal_side' => 1, 'amount' => 1000.0, 'account' => '325201']],
            $this->desired($rules, [self::journal('325201', 0, 1000.0, 'payment.payable')]),
            'payment.* → úhrada ve skupině nejdelšího prefixu, + na její straně úhrady',
        );
        $this->assertSame(
            [['balance' => self::PAYABLES, 'bal_side' => 1, 'amount' => 1000.0, 'account' => '325101']],
            $this->desired($rules, [self::journal('325101', 0, 1000.0, 'payment.payable')]),
        );
        $this->assertSame(
            [['balance' => self::ADVANCES_RECEIVED, 'bal_side' => 0, 'amount' => 1000.0, 'account' => '325201']],
            $this->desired($rules, [self::journal('325201', 1, 1000.0, 'acc.balancePayable')]),
            'operace se stranou → skupina předpisu nejdelšího prefixu',
        );
    }

    public function testSameLengthPrefixesInTwoGroupsBothProduce(): void
    {
        $rules = [
            self::rule(self::PAYABLES, '325', 1, 1, 0),
            self::rule(self::ADVANCES_RECEIVED, '325', 1, 1, 0),
        ];

        $this->assertSame([
            ['balance' => self::PAYABLES, 'bal_side' => 0, 'amount' => 10.0, 'account' => '325201'],
            ['balance' => self::ADVANCES_RECEIVED, 'bal_side' => 0, 'amount' => 10.0, 'account' => '325201'],
        ], $this->desired($rules, [self::journal('325201', 1, 10.0, null)]), 'týž účet vědomě ve dvou skupinách — regrese');
    }

    public function testPrecedenceIsPerSide(): void
    {
        // Přijaté zálohy mají jen předpis 325201 DAL; MD stranu dál drží 325 v Závazcích.
        $rules = [
            self::rule(self::PAYABLES, '325', 1, 1, 0),
            self::rule(self::PAYABLES, '325', 0, 1, 1),
            self::rule(self::ADVANCES_RECEIVED, '325201', 1, 1, 0),
        ];

        $this->assertSame(
            [['balance' => self::ADVANCES_RECEIVED, 'bal_side' => 0, 'amount' => 10.0, 'account' => '325201']],
            $this->desired($rules, [self::journal('325201', 1, 10.0, null)]),
        );
        $this->assertSame(
            [['balance' => self::PAYABLES, 'bal_side' => 1, 'amount' => 10.0, 'account' => '325201']],
            $this->desired($rules, [self::journal('325201', 0, 10.0, null)]),
            '325201 DAL v jiné skupině nevyřadí 325 MD',
        );
    }

    public function testLongerPrefixExcludesCreditNoteRuleOfShorterPrefix(): void
    {
        // Sign-pravidla se účastní přednosti stejně: 325201 Kladné v Přijatých
        // zálohách vyřadí i 325 Záporné ×−1 v Závazcích → záporný řádek na
        // 325201 nevyhoví ničemu.
        $rules = [
            self::rule(self::PAYABLES, '325', 1, 2, 0, true),
            self::rule(self::ADVANCES_RECEIVED, '325201', 1, 1, 0),
        ];

        $this->assertSame([], $this->desired($rules, [self::journal('325201', 1, -10.0, null)]));
        $this->assertSame(
            [['balance' => self::PAYABLES, 'bal_side' => 0, 'amount' => 10.0, 'account' => '325101']],
            $this->desired($rules, [self::journal('325101', 1, -10.0, null)]),
        );
    }

    public function testFutureLongerPrefixDoesNotExcludeYet(): void
    {
        // Platnost před předností: podúčet přesunutý od nového roku letošní pohyby nemění.
        $rules = [
            self::rule(self::PAYABLES, '325', 1, 1, 0),
            self::rule(self::ADVANCES_RECEIVED, '325201', 1, 1, 0, false, ['a_from' => '2027-01-01']),
        ];

        $this->assertSame(
            [['balance' => self::PAYABLES, 'bal_side' => 0, 'amount' => 10.0, 'account' => '325201']],
            $this->desired($rules, [self::journal('325201', 1, 10.0, null)]),
        );
        $this->assertSame(
            [['balance' => self::ADVANCES_RECEIVED, 'bal_side' => 0, 'amount' => 10.0, 'account' => '325201']],
            $this->desired($rules, [self::journal('325201', 1, 10.0, null, ['accounting_date' => '2027-01-01'])]),
        );
    }

    public function testPrefixIsTrimmedForMatchAndLength(): void
    {
        $rules = [
            self::rule(self::PAYABLES, '325', 1, 1, 0),
            self::rule(self::ADVANCES_RECEIVED, ' 325201 ', 1, 1, 0),
        ];

        $this->assertSame(
            [['balance' => self::ADVANCES_RECEIVED, 'bal_side' => 0, 'amount' => 10.0, 'account' => '325201']],
            $this->desired($rules, [self::journal('325201', 1, 10.0, null)]),
        );
    }

    // ── movementKey ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function identity(array $over = []): array
    {
        return array_merge([
            'source_kind'       => 'doc',
            'source_id'         => 5,
            'balance'           => 3,
            'bal_side'          => 0,
            'account_number'    => '311100',
            'partner'           => 42,
            'payment_reference' => 'VS1',
            'specific_symbol'   => null,
            'currency'          => 'czk',
        ], $over);
    }

    public function testKeyIsSha1OfCanonicalForm(): void
    {
        $key = LedgerGenerator::movementKey(self::identity());

        $this->assertSame(sha1('doc|5|3|0|311100|42|VS1||czk'), $key);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $key);
    }

    public function testNullAndEmptySymbolsShareKey(): void
    {
        $base = LedgerGenerator::movementKey(self::identity());

        $this->assertSame($base, LedgerGenerator::movementKey(self::identity(['specific_symbol' => ''])));
        $this->assertSame($base, LedgerGenerator::movementKey(self::identity(['specific_symbol' => '   '])));
        $this->assertSame($base, LedgerGenerator::movementKey(self::identity(['payment_reference' => ' VS1 '])));
        $this->assertSame($base, LedgerGenerator::movementKey(self::identity(['currency' => 'CZK'])));
        $this->assertSame($base, LedgerGenerator::movementKey(self::identity(['partner' => '42'])));
    }

    public function testMissingPartnerIsEmptyNotZero(): void
    {
        $null  = LedgerGenerator::movementKey(self::identity(['partner' => null]));
        $empty = LedgerGenerator::movementKey(self::identity(['partner' => '']));
        $zero  = LedgerGenerator::movementKey(self::identity(['partner' => 0]));

        $this->assertSame($null, $empty);
        $this->assertNotSame($null, $zero, 'partner 0 je hodnota, NULL je absence');
        $this->assertSame(sha1('doc|5|3|0|311100||VS1||czk'), $null);
    }

    public function testEveryKeyColumnDistinguishes(): void
    {
        $base = LedgerGenerator::movementKey(self::identity());
        $variants = [
            'source_kind'       => 'bankTransaction',
            'source_id'         => 6,
            'balance'           => 4,
            'bal_side'          => 1,
            'account_number'    => '311200',
            'partner'           => 43,
            'payment_reference' => 'VS2',
            'specific_symbol'   => 'SS',
            'currency'          => 'eur',
        ];
        foreach ($variants as $col => $value) {
            $this->assertNotSame($base, LedgerGenerator::movementKey(self::identity([$col => $value])), "sloupec {$col} musí klíč rozlišit");
        }
    }

    public function testNonKeyColumnsDoNotAffectKey(): void
    {
        $base = LedgerGenerator::movementKey(self::identity());
        $noise = self::identity([
            'id'              => 99,
            'journal_row'     => 123,
            'constant_symbol' => '0308',
            'due_date'        => '2026-07-10',
            'amount'          => 1210.0,
            'text'            => 'x',
            'fiscal_year'     => 7,
        ]);

        $this->assertSame($base, LedgerGenerator::movementKey($noise));
    }
}
