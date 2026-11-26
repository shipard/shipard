<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Evaluation;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocEvaluation;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFacts;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocSaleEvaluator;
use Shipard\Module\Economy\SalesRegistration\Evaluation\Money;
use Shipard\Module\Economy\SalesRegistration\Evaluation\RegistrationFacts;
use Shipard\Module\Economy\SalesRegistration\Evaluation\SerialNumber;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationType;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;

/**
 * Vyhodnocení dokladu pro evidenci tržeb (#171 D8a, D8d, D8e, D18, D19, D22,
 * U9, U11) nad skutečnými pravidly typu `cz.eet2`: co spadá a jakou částkou,
 * co nespadá a co spadá, ale tržbu založit nejde.
 */
class DocSaleEvaluatorTest extends TestCase
{
    use SalesRegistrationConfig;

    private function type(): SalesRegistrationType
    {
        $type = SalesRegistrationTypes::fromConfig($this->salesRegistrationConfig())->get('cz.eet2');
        $this->assertNotNull($type);
        return $type;
    }

    /** @param array<string, mixed> $override */
    private function registration(array $override = []): RegistrationFacts
    {
        return new RegistrationFacts(
            id: $override['id'] ?? 1,
            type: $override['type'] ?? $this->type(),
            validFrom: array_key_exists('validFrom', $override) ? $override['validFrom'] : '2027-01-01',
            validTo: $override['validTo'] ?? null,
            defaultUnit: array_key_exists('defaultUnit', $override) ? $override['defaultUnit'] : 2,
            units: $override['units'] ?? [2 => '181', 3 => '182'],
        );
    }

    /**
     * @param array<string, mixed> $head
     * @param list<array{0: ?string, 1: float|int|string}> $rows pohyb a částka řádku v domácí měně
     * @param array<string, mixed>|null $series
     */
    private function doc(array $head = [], array $rows = [], ?array $series = ['code' => 'cashreg-P1'], string $country = 'cz'): DocFacts
    {
        return DocFacts::fromRows(
            $head + [
                'id'                   => 10,
                'doc_type'             => 'cashreg',
                'docState'             => 40,
                'doc_number'           => 'PR1270001',
                'issue_date'           => '2027-02-10',
                'payment_method'       => 0,
                'cash_dir'             => 0,
                'total_amount_dom'     => '121.00',
                'home_currency'        => 'czk',
                'imported'             => 0,
                'source_kind'          => null,
                'doc_state_changed_at' => '2027-02-10 09:30:00',
            ],
            array_map(static fn(array $row): array => ['operation' => $row[0], 'vat_total_dom' => $row[1]], $rows),
            $series,
            $country,
        );
    }

    /** @param list<RegistrationFacts>|null $registrations */
    private function evaluate(DocFacts $doc, ?array $registrations = null, string $soldAt = '2027-02-10 09:30:00'): DocEvaluation
    {
        return (new DocSaleEvaluator())->evaluate(
            $doc,
            $registrations ?? [$this->registration()],
            new \DateTimeImmutable($soldAt, new \DateTimeZone('UTC')),
        );
    }

    // ── Co spadá a jakou částkou ────────────────────────────────────────────

    public function testCashRegisterReceiptInCashAndByCardFallsWithItsTotal(): void
    {
        foreach ([0 => 'hotově', 2 => 'kartou'] as $method => $label) {
            $result = $this->evaluate($this->doc(['payment_method' => $method]));

            $this->assertTrue($result->applicable, $label);
            $this->assertSame(12100, $result->cents);
            $this->assertSame(1, $result->registration?->id);
            $this->assertSame(2, $result->unit, 'výchozí jednotka registrace');
            $this->assertSame('181', $result->unitId);
            $this->assertSame('cashreg-P1', $result->cashRegisterId, 'označení pokladny = kód řady');
        }
    }

    public function testIssuedInvoiceInCashAndByCardFalls(): void
    {
        foreach ([0, 2] as $method) {
            $result = $this->evaluate($this->doc(
                ['doc_type' => 'invno', 'payment_method' => $method, 'total_amount_dom' => 2420],
                series: ['code' => 'invno'],
            ));
            $this->assertTrue($result->applicable);
            $this->assertSame(242000, $result->cents);
        }
    }

    /** D18: hotovost zaokrouhlená na koruny jde zaokrouhleně — částka dokladu zaokrouhlení obsahuje. */
    public function testRoundedCashTotalIsTakenFromTheDocumentTotal(): void
    {
        $result = $this->evaluate($this->doc(['total_amount_dom' => '100.00'], [['sale.goods', '99.60']]));

        $this->assertSame(10000, $result->cents);
    }

    /** D18: doklad v cizí měně jde částkou v domácí měně (kurz dokladu). */
    public function testForeignCurrencyDocumentUsesTheDomesticAmount(): void
    {
        $result = $this->evaluate($this->doc([
            'doc_type' => 'invno', 'payment_method' => 2, 'doc_currency' => 'eur',
            'total_amount' => '100.00', 'total_amount_dom' => '2515.50', 'home_currency' => 'czk',
        ], series: ['code' => 'invno']));

        $this->assertTrue($result->applicable);
        $this->assertSame(251550, $result->cents);
    }

    public function testCashReceiptSumsOnlyRowsThatAreSales(): void
    {
        $doc = $this->doc(
            ['doc_type' => 'cash', 'cash_dir' => 1, 'total_amount_dom' => '1800.00'],
            [
                ['sale.services', '500.00'],
                ['payment.receivable', '300.00'],
                ['advance.received', '200.00'],
                ['transfer.in', '700.00'],
                ['acc.entry', '100.00'],
            ],
            ['code' => 'cash-P1'],
        );

        $result = $this->evaluate($doc);

        $this->assertTrue($result->applicable);
        $this->assertSame(100000, $result->cents, 'převod a účetní zápis do evidence nejdou');
    }

    public function testCashReceiptWithOnlySaleRowsUsesTheRoundedTotal(): void
    {
        $doc = $this->doc(
            ['doc_type' => 'cash', 'cash_dir' => 1, 'total_amount_dom' => '500.00'],
            [['sale.goods', '300.40'], ['sale.services', '199.40'], [null, 0]],
            ['code' => 'cash-P1'],
        );

        $this->assertSame(50000, $this->evaluate($doc)->cents, 'všechny nenulové řádky vyhovují → částka dokladu se zaokrouhlením');
    }

    // ── Kontaktní platba (D8b, U14, U15) ────────────────────────────────────

    /** @param array<string, mixed> $head */
    private function invoice(array $head = []): DocFacts
    {
        return $this->doc(
            $head + ['doc_type' => 'invno', 'payment_method' => 1, 'total_amount_dom' => '2420.00', 'contact_payment' => 1],
            series: ['code' => 'invno'],
        );
    }

    public function testInvoiceByTransferFallsOnlyWithTheContactPaymentFlag(): void
    {
        $without = $this->evaluate($this->invoice(['contact_payment' => 0]));
        $this->assertFalse($without->applicable);
        $this->assertSame(DocEvaluation::REASON_NO_RULE, $without->reason);

        $with = $this->evaluate($this->invoice(['contact_payment_at' => '2027-02-10 10:15:00']));
        $this->assertTrue($with->applicable);
        $this->assertSame(242000, $with->cents, 'vždy celá částka dokladu');
        $this->assertSame(DocEvaluation::SOURCE_CONTACT_PAYMENT, $with->source);
        $this->assertSame('invno', $with->cashRegisterId);
    }

    public function testProformaFallsWithTheFlagByTransferAndByCard(): void
    {
        foreach ([1, 2] as $method) {
            $result = $this->evaluate($this->invoice(['doc_type' => 'invpo', 'payment_method' => $method]));
            $this->assertTrue($result->applicable, (string) $method);
            $this->assertSame(DocEvaluation::SOURCE_CONTACT_PAYMENT, $result->source);
        }
        // Bez příznaku zálohová faktura nespadá nikdy — pravidlo pro ni není.
        $this->assertSame(
            DocEvaluation::REASON_NO_RULE,
            $this->evaluate($this->invoice(['doc_type' => 'invpo', 'payment_method' => 2, 'contact_payment' => 0]))->reason,
        );
    }

    /** U14: brána, zápočet a dobírka příznak nenesou — ani kdyby v datech zůstal. */
    public function testFlagIsIgnoredWhereTheTypeDataDoNotAllowIt(): void
    {
        foreach ([3 => 'dobírkou', 4 => 'zápočtem', 5 => 'platební bránou'] as $method => $label) {
            foreach (['invno', 'invpo'] as $docType) {
                $result = $this->evaluate($this->invoice(['doc_type' => $docType, 'payment_method' => $method]));
                $this->assertFalse($result->applicable, "{$docType} {$label}");
                $this->assertSame(DocEvaluation::REASON_NO_RULE, $result->reason, "{$docType} {$label}");
            }
        }
        foreach (['cashreg', 'cash', 'invni', 'cmnbkp'] as $docType) {
            $this->assertNotSame(
                DocEvaluation::SOURCE_CONTACT_PAYMENT,
                $this->evaluate($this->invoice(['doc_type' => $docType]))->source,
                $docType,
            );
        }
    }

    public function testRuleHasPriorityOverTheFlag(): void
    {
        // Faktura Kartou spadá pravidlem; příznak na tom nic nemění.
        $result = $this->evaluate($this->invoice(['payment_method' => 2]));

        $this->assertTrue($result->applicable);
        $this->assertSame(DocEvaluation::SOURCE_RULE, $result->source);
    }

    /** U15: rozhoduje čas platby, ne datum vystavení dokladu. */
    public function testDocumentIssuedBeforeTheRegistrationFallsWhenPaidWithinIt(): void
    {
        $doc = $this->invoice(['issue_date' => '2026-12-15', 'contact_payment_at' => '2027-01-05 14:30:00']);

        $result = $this->evaluate($doc, soldAt: '2026-12-15 08:00:00');

        $this->assertTrue($result->applicable, 'přechod dokladu byl před registrací, platba v ní');
        $this->assertSame(242000, $result->cents);
    }

    public function testPaymentOutsideTheRegistrationDoesNotFall(): void
    {
        // Zaplaceno ještě před začátkem evidence — i když doklad přešel až v ní.
        $before = $this->evaluate(
            $this->invoice(['contact_payment_at' => '2026-12-31 23:50:00']),
            soldAt: '2027-01-02 09:00:00',
        );
        $this->assertFalse($before->applicable);
        $this->assertFalse($before->unregistrable);
        $this->assertSame(DocEvaluation::REASON_PAYMENT_OUTSIDE_REGISTRATION, $before->reason);

        $after = $this->evaluate(
            $this->invoice(['contact_payment_at' => '2027-07-01 10:00:00']),
            [$this->registration(['validTo' => '2027-06-30'])],
        );
        $this->assertSame(DocEvaluation::REASON_PAYMENT_OUTSIDE_REGISTRATION, $after->reason);
    }

    /** U16: příznak bez času bere čas přechodu dokladu. */
    public function testFlagWithoutTimeUsesTheTransitionTime(): void
    {
        $this->assertTrue($this->evaluate($this->invoice(), soldAt: '2027-01-05 10:00:00')->applicable);
        $this->assertSame(
            DocEvaluation::REASON_PAYMENT_OUTSIDE_REGISTRATION,
            $this->evaluate($this->invoice(), soldAt: '2026-12-20 10:00:00')->reason,
        );
    }

    /** U23: čas platby je už v zóně místa tržby — datum se z něj bere, jak je. */
    public function testPaymentTimeIsNotShiftedByTheServerZone(): void
    {
        // 1. 1. 2027 00:10 v Praze; ve světovém čase by to byl ještě 31. prosinec.
        $this->assertTrue($this->evaluate($this->invoice(['contact_payment_at' => '2027-01-01 00:10:00']))->applicable);
    }

    /** Faktura s odpočtem zálohy: částka dokladu je částka k úhradě. */
    public function testInvoiceWithAdvanceDeductionFallsWithTheAmountToPay(): void
    {
        $result = $this->evaluate($this->invoice(['total_amount_dom' => '1420.00']));

        $this->assertTrue($result->applicable);
        $this->assertSame(142000, $result->cents);
    }

    public function testPinnedRegistrationKeepsTheContactPaymentDocument(): void
    {
        $evaluator = new DocSaleEvaluator();
        $registrations = [$this->registration(['validTo' => '2027-03-31'])];
        $soldAt = new \DateTimeImmutable('2027-06-01 10:00:00', new \DateTimeZone('UTC'));

        // Oprava po skončení registrace: doklad zůstává u registrace své tržby.
        $kept = $evaluator->evaluate($this->invoice(['contact_payment_at' => '2027-02-10 10:00:00']), $registrations, $soldAt, 1);
        $this->assertTrue($kept->applicable);
        $this->assertSame(DocEvaluation::SOURCE_CONTACT_PAYMENT, $kept->source);

        // Příznak zrušený → doklad už nespadá, rozdíl vyrovná záporná tržba.
        $cancelled = $evaluator->evaluate($this->invoice(['contact_payment' => 0]), $registrations, $soldAt, 1);
        $this->assertFalse($cancelled->applicable);
        $this->assertFalse($cancelled->unregistrable);
    }

    public function testImportedAndUnconfirmedDocumentIgnoresTheFlag(): void
    {
        $this->assertSame(DocEvaluation::REASON_IMPORTED, $this->evaluate($this->invoice(['imported' => 1]))->reason);
        $this->assertSame(DocEvaluation::REASON_STATE, $this->evaluate($this->invoice(['docState' => 10]))->reason);
        $this->assertSame(DocEvaluation::REASON_CANCELLED, $this->evaluate($this->invoice(['docState' => 30]))->reason);
    }

    public function testWithContactPaymentEvaluatesTheDocumentAsIfFlagged(): void
    {
        $doc = $this->invoice(['contact_payment' => 0])->withContactPayment('2027-02-10T10:15');

        $this->assertTrue($doc->contactPayment);
        $this->assertSame('2027-02-10 10:15', $doc->contactPaymentAt);
        $this->assertTrue($this->evaluate($doc)->applicable);
    }

    // ── Co nespadá ──────────────────────────────────────────────────────────

    public function testOtherPaymentMethodsDoNotFall(): void
    {
        foreach ([1 => 'převodem', 3 => 'dobírkou', 4 => 'zápočtem', 5 => 'platební bránou'] as $method => $label) {
            foreach (['cashreg', 'invno'] as $docType) {
                $result = $this->evaluate($this->doc(['doc_type' => $docType, 'payment_method' => $method]));
                $this->assertFalse($result->applicable, "{$docType} {$label}");
                $this->assertFalse($result->unregistrable, "{$docType} {$label}");
                $this->assertSame(DocEvaluation::REASON_NO_RULE, $result->reason);
            }
        }
    }

    public function testCashExpenseAndNonSaleRowsDoNotFall(): void
    {
        $expense = $this->doc(['doc_type' => 'cash', 'cash_dir' => 2], [['purchase.goods', '500.00']], ['code' => 'cash-P1']);
        $this->assertSame(DocEvaluation::REASON_NO_RULE, $this->evaluate($expense)->reason);

        $transfer = $this->doc(
            ['doc_type' => 'cash', 'cash_dir' => 1, 'total_amount_dom' => '800.00'],
            [['transfer.in', '700.00'], ['acc.entry', '100.00']],
            ['code' => 'cash-P1'],
        );
        $result = $this->evaluate($transfer);
        $this->assertFalse($result->applicable);
        $this->assertFalse($result->unregistrable);
        $this->assertSame(DocEvaluation::REASON_NO_MATCHING_ROWS, $result->reason);
    }

    public function testOtherDocumentTypesDoNotFall(): void
    {
        foreach (['invni', 'invpo', 'invpi', 'cmnbkp'] as $docType) {
            $this->assertSame(DocEvaluation::REASON_NO_RULE, $this->evaluate($this->doc(['doc_type' => $docType]))->reason, $docType);
        }
    }

    public function testOnlyConfirmedDocumentsAreEvaluatedAndCancelledIsZero(): void
    {
        foreach ([10 => DocEvaluation::REASON_STATE, 80 => DocEvaluation::REASON_STATE, 90 => DocEvaluation::REASON_STATE, 30 => DocEvaluation::REASON_CANCELLED] as $state => $reason) {
            $result = $this->evaluate($this->doc(['docState' => $state]));
            $this->assertFalse($result->applicable, (string) $state);
            $this->assertFalse($result->unregistrable, (string) $state);
            $this->assertSame($reason, $result->reason, (string) $state);
            $this->assertSame(0, $result->cents);
        }
    }

    /** D8e, U11: import nespouští evidenci — trvalý příznak i druh zdroje import.*. */
    public function testImportedDocumentNeverFalls(): void
    {
        $this->assertSame(DocEvaluation::REASON_IMPORTED, $this->evaluate($this->doc(['imported' => 1]))->reason);
        $this->assertSame(DocEvaluation::REASON_IMPORTED, $this->evaluate($this->doc(['source_kind' => 'import.oldShipard']))->reason);

        // Doklad založený pokynem přes API je skutečná tržba.
        $this->assertTrue($this->evaluate($this->doc(['source_kind' => 'api']))->applicable);
        $this->assertTrue($this->evaluate($this->doc(['source_kind' => 'workOrder']))->applicable);
    }

    /** D8d: doklad vystavený před začátkem platnosti registrace nevstoupí ani opravou. */
    public function testDocumentIssuedBeforeTheRegistrationNeverFalls(): void
    {
        $doc = $this->doc(['issue_date' => '2026-12-30']);

        // Oprava v lednu: čas tržby už v platnosti registrace je, datum vystavení ne.
        $result = $this->evaluate($doc, soldAt: '2027-01-05 10:00:00');

        $this->assertFalse($result->applicable);
        $this->assertFalse($result->unregistrable);
        $this->assertSame(DocEvaluation::REASON_BEFORE_REGISTRATION, $result->reason);

        $this->assertTrue($this->evaluate($this->doc(['issue_date' => '2027-01-01']), soldAt: '2027-01-05 10:00:00')->applicable);
    }

    public function testZeroTotalDoesNotFall(): void
    {
        $this->assertSame(DocEvaluation::REASON_ZERO_AMOUNT, $this->evaluate($this->doc(['total_amount_dom' => 0]))->reason);
    }

    // ── Výběr registrace (D22) ──────────────────────────────────────────────

    public function testRegistrationIsChosenByCountryAndValidityAtSaleTime(): void
    {
        $this->assertSame(
            DocEvaluation::REASON_NO_REGISTRATION,
            $this->evaluate($this->doc(country: 'sk'))->reason,
            'slovenská registrace DPH dokladu — česká evidence se netýká',
        );
        $this->assertSame(
            DocEvaluation::REASON_NO_REGISTRATION,
            $this->evaluate($this->doc(), [$this->registration(['validTo' => '2027-01-31'])])->reason,
            'registrace k času tržby už neplatí',
        );
        $this->assertSame(DocEvaluation::REASON_NO_REGISTRATION, $this->evaluate($this->doc(), [])->reason);

        $old = $this->registration(['id' => 1, 'validFrom' => '2027-01-01', 'validTo' => '2027-01-31']);
        $new = $this->registration(['id' => 2, 'validFrom' => '2027-02-01', 'defaultUnit' => 3]);
        $result = $this->evaluate($this->doc(), [$old, $new]);
        $this->assertSame(2, $result->registration?->id, 'navazující registrace — platí jen jedna');
        $this->assertSame('182', $result->unitId);
    }

    /** Čas tržby se k platnosti registrace vztahuje v zóně místa tržby (U10). */
    public function testValidityUsesTheSaleTimeZoneOfTheType(): void
    {
        $registration = $this->registration(['validFrom' => '2027-02-11']);
        $doc = $this->doc(['issue_date' => '2027-02-11']);

        // 23:30 UTC = 00:30 následujícího dne v Praze.
        $this->assertTrue($this->evaluate($doc, [$registration], '2027-02-10 23:30:00')->applicable);
        $this->assertFalse($this->evaluate($doc, [$registration], '2027-02-10 22:30:00')->applicable);
    }

    public function testMoreCandidatesMeanTheSaleCannotBeCreated(): void
    {
        $result = $this->evaluate($this->doc(), [$this->registration(['id' => 1]), $this->registration(['id' => 2])]);

        $this->assertFalse($result->applicable);
        $this->assertTrue($result->unregistrable);
        $this->assertSame(DocEvaluation::REASON_MULTIPLE_REGISTRATIONS, $result->reason);

        // Doklad, který do evidence nespadá, kvůli dvěma registracím upozornění nedá.
        $bank = $this->evaluate($this->doc(['payment_method' => 1]), [$this->registration(['id' => 1]), $this->registration(['id' => 2])]);
        $this->assertFalse($bank->unregistrable);
    }

    // ── Spadá, ale nejde založit (U9) ───────────────────────────────────────

    public function testUnitComesFromTheSeriesOtherwiseFromTheRegistration(): void
    {
        $fromSeries = $this->evaluate($this->doc(series: ['code' => 'cashreg-P1', 'sales_registration_unit' => 3]));
        $this->assertSame(3, $fromSeries->unit);
        $this->assertSame('182', $fromSeries->unitId);

        $missing = $this->evaluate($this->doc(), [$this->registration(['defaultUnit' => null])]);
        $this->assertTrue($missing->unregistrable);
        $this->assertSame(DocEvaluation::REASON_NO_UNIT, $missing->reason);
        $this->assertSame(1, $missing->registration?->id);

        $foreign = $this->evaluate($this->doc(series: ['code' => 'cashreg-P1', 'sales_registration_unit' => 99]));
        $this->assertSame(DocEvaluation::REASON_FOREIGN_UNIT, $foreign->reason, 'jednotka řady patří jiné registraci');

        $badId = $this->evaluate($this->doc(), [$this->registration(['units' => [2 => '0181']])]);
        $this->assertSame(DocEvaluation::REASON_UNIT_ID, $badId->reason);
    }

    public function testSeriesCodeMustPassTheCashRegisterMask(): void
    {
        foreach ([['code' => 'prodejky-hlavni-provozovna'], ['code' => 'řada'], ['code' => ''], null] as $series) {
            $result = $this->evaluate($this->doc(series: $series));
            $this->assertTrue($result->unregistrable, var_export($series, true));
            $this->assertSame(DocEvaluation::REASON_CASH_REGISTER_ID, $result->reason);
        }
    }

    public function testHomeCurrencyMustBeTheCurrencyOfTheType(): void
    {
        $result = $this->evaluate($this->doc(['home_currency' => 'eur']));

        $this->assertTrue($result->unregistrable);
        $this->assertSame(DocEvaluation::REASON_CURRENCY, $result->reason);
    }

    // ── Pořadové číslo a částka tržby (D19) ─────────────────────────────────

    public function testSerialNumberIsTheDocumentNumberWithSuffixFromTheSecondSale(): void
    {
        $this->assertSame('PR1270001', SerialNumber::of('PR1270001', 1));
        $this->assertSame('PR1270001-2', SerialNumber::of(' PR1270001 ', 2));
        $this->assertSame('PR1270001-3', SerialNumber::of('PR1270001', 3));
    }

    public function testSaleProblemChecksSerialNumberAndAmountMasks(): void
    {
        $evaluator = new DocSaleEvaluator();
        $type = $this->type();

        $this->assertNull($evaluator->saleProblem($type, 'PR1270001-2', -12100));
        $this->assertSame(DocEvaluation::REASON_SERIAL_NUMBER, $evaluator->saleProblem($type, str_repeat('9', 24) . '-2', 100));
        $this->assertSame(DocEvaluation::REASON_SERIAL_NUMBER, $evaluator->saleProblem($type, '!0000000123', 100), 'zástupné číslo konceptu');
        $this->assertSame(DocEvaluation::REASON_SERIAL_NUMBER, $evaluator->saleProblem($type, '', 100));
        $this->assertSame(DocEvaluation::REASON_AMOUNT, $evaluator->saleProblem($type, 'PR1', 10_000_000_000));
    }

    public function testMoneyConvertsBetweenCentsAndDecimalText(): void
    {
        $this->assertSame(12100, Money::cents('121.00'));
        $this->assertSame(12100, Money::cents(121));
        $this->assertSame(-5, Money::cents(-0.05));
        $this->assertSame(1999, Money::cents(19.99));
        $this->assertSame(0, Money::cents(null));
        $this->assertSame('121.00', Money::decimal(12100));
        $this->assertSame('-0.05', Money::decimal(-5));
        $this->assertSame('-121.40', Money::decimal(-12140));
        $this->assertSame('0.00', Money::decimal(0));
    }
}
