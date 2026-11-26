<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Checks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\SalesRegistration\Checks\DocUnregistrableCheck;
use Shipard\Module\Economy\SalesRegistration\Checks\SaleCheck;
use Shipard\Module\Economy\SalesRegistration\Checks\SaleFailedCheck;
use Shipard\Module\Economy\SalesRegistration\Checks\SaleOverdueCheck;
use Shipard\Module\Economy\SalesRegistration\Checks\SaleUnconfirmedCheck;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocEvaluation;
use Shipard\Module\Economy\SalesRegistration\Sales\SyncResult;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;

/**
 * Upozornění na tržby (#171 D13, D25, U5, U9): nepotvrzená déle než hodinu,
 * nezaevidovaná do 48 hodin, odmítnutá službou a doklad, který nejde
 * zaevidovat. `dropped` a `verified` upozornění nedávají.
 */
class SaleChecksTest extends TestCase
{
    use SalesRegistrationConfig;

    private const NOW = '2027-02-12 10:00:00';

    /**
     * Tržby v paměti: check si z nich skládá skupiny stejně jako SQL.
     *
     * @param class-string<SaleCheck> $class
     * @param list<array{registration: int, state: string, sold_at: string}> $sales
     */
    private function check(string $class, array $sales, string $language = 'cs'): SaleCheck
    {
        $config = $this->salesRegistrationConfig($language === 'cs' ? [] : [
            'economy.salesRegistration.texts' => self::repoJsonc('modules/economy/salesRegistration/config/texts.jsonc', 'en'),
        ]);
        $db = $this->createMock(DataSourceConnection::class);
        $check = match ($class) {
            SaleUnconfirmedCheck::class => new class($db, $config, $language) extends SaleUnconfirmedCheck {
                use FixedSales;
            },
            SaleOverdueCheck::class => new class($db, $config, $language) extends SaleOverdueCheck {
                use FixedSales;
            },
            default => new class($db, $config, $language) extends SaleFailedCheck {
                use FixedSales;
            },
        };
        $check->sales = $sales;
        $check->fixedNow = self::NOW;
        return $check;
    }

    /** @return array{registration: int, state: string, sold_at: string} */
    private static function sale(string $state, string $soldAt, int $registration = 1): array
    {
        return ['registration' => $registration, 'state' => $state, 'sold_at' => $soldAt];
    }

    /** @return array<string, int> findingKey → počet tržeb */
    private function counts(SaleCheck $check): array
    {
        $counts = [];
        foreach ($check->run() as $finding) {
            $counts[$finding->findingKey] = $finding->context['count'];
        }
        return $counts;
    }

    public function testUnconfirmedCoversOpenSalesBetweenOneHourAnd48Hours(): void
    {
        $check = $this->check(SaleUnconfirmedCheck::class, [
            self::sale('pending', '2027-02-12 09:30:00'),   // půl hodiny — ještě ne
            self::sale('pending', '2027-02-12 08:59:59'),   // přes hodinu
            self::sale('sending', '2027-02-11 20:00:00'),
            self::sale('failed', '2027-02-11 12:00:00'),
            self::sale('pending', '2027-02-10 09:00:00'),   // přes 48 h — přebírá overdue
            self::sale('confirmed', '2027-02-11 12:00:00'),
            self::sale('verified', '2027-02-11 12:00:00'),
            self::sale('dropped', '2027-02-11 12:00:00'),
            self::sale('pending', '2027-02-12 07:00:00', 2),
        ]);

        $this->assertSame(['1' => 3, '2' => 1], $this->counts($check), 'jeden nález na registraci');

        $finding = $check->run()[0];
        $this->assertSame('warning', $finding->severity);
        $this->assertSame('Tržby čekají na potvrzení: 3 (Registrace 1)', $finding->title);
        $this->assertStringContainsString('nejstarší od 11.02.2027 12:00', $finding->message);
        $this->assertSame(468, $finding->subjectTableId);
        $this->assertSame(1, $finding->subjectRowId);
        $this->assertSame('open_viewer', $finding->actions[0]['kind']);
        $this->assertSame('economy.salesRegistration.sales', $finding->actions[0]['target']['viewerId']);
        $this->assertSame('Otevřít evidenci tržeb', $finding->actions[0]['label']);
    }

    public function testOverdueCoversOpenSalesOlderThan48Hours(): void
    {
        $check = $this->check(SaleOverdueCheck::class, [
            self::sale('pending', '2027-02-10 09:59:59'),
            self::sale('failed', '2027-02-09 12:00:00'),
            self::sale('pending', '2027-02-10 10:00:00'),   // přesně 48 h — ještě warning
            self::sale('confirmed', '2027-02-01 12:00:00'),
            self::sale('dropped', '2027-02-01 12:00:00'),
        ]);

        $this->assertSame(['1' => 2], $this->counts($check));
        $finding = $check->run()[0];
        $this->assertSame('error', $finding->severity);
        $this->assertSame('Tržby nezaevidované do 48 hodin: 2 (Registrace 1)', $finding->title);
        $this->assertStringContainsString('Odesílání pokračuje', $finding->message);
    }

    public function testFailedCoversEveryRejectedSaleWhateverItsAge(): void
    {
        $check = $this->check(SaleFailedCheck::class, [
            self::sale('failed', '2027-02-12 09:59:00'),
            self::sale('failed', '2027-01-01 12:00:00'),
            self::sale('pending', '2027-02-01 12:00:00'),
        ]);

        $this->assertSame(['1' => 2], $this->counts($check));
        $finding = $check->run()[0];
        $this->assertSame('error', $finding->severity);
        $this->assertStringContainsString('Odeslat znovu', $finding->message);
    }

    public function testNothingToReportMeansNoFinding(): void
    {
        foreach ([SaleUnconfirmedCheck::class, SaleOverdueCheck::class, SaleFailedCheck::class] as $class) {
            $this->assertSame([], $this->check($class, [
                self::sale('confirmed', '2027-02-01 12:00:00'),
                self::sale('verified', '2027-02-01 12:00:00'),
                self::sale('dropped', '2027-02-01 12:00:00'),
            ])->run(), $class);
        }
    }

    public function testEnglishTexts(): void
    {
        $finding = $this->check(SaleUnconfirmedCheck::class, [self::sale('pending', '2027-02-12 08:00:00')], 'en')->run()[0];

        $this->assertSame('Sales waiting for confirmation: 1 (Registrace 1)', $finding->title);
        $this->assertStringContainsString('the oldest since 2027-02-12 08:00', $finding->message);
    }

    // ── Doklad, který nejde zaevidovat (U9) ─────────────────────────────────

    /** @param list<SyncResult> $results */
    private function unregistrableCheck(array $results): DocUnregistrableCheck
    {
        $check = new class($this->createMock(DataSourceConnection::class), $this->salesRegistrationConfig(), 'cs') extends DocUnregistrableCheck {
            /** @var list<SyncResult> */
            public array $results = [];

            protected function unregistrable(): array
            {
                return $this->results;
            }

            protected function docNumber(int $docId): string
            {
                return 'PR' . $docId;
            }
        };
        $check->results = $results;
        return $check;
    }

    public function testUnregistrableDocumentGetsOneFindingWithTheReason(): void
    {
        $findings = $this->unregistrableCheck([
            SyncResult::unregistrable(10, DocEvaluation::REASON_CASH_REGISTER_ID, 1),
            SyncResult::unregistrable(11, DocEvaluation::REASON_NO_UNIT, 1),
            SyncResult::unregistrable(12, DocEvaluation::REASON_MULTIPLE_REGISTRATIONS),
        ])->run();

        $this->assertCount(3, $findings);
        $this->assertSame(['10', '11', '12'], array_map(static fn(AlertFinding $f): string => $f->findingKey, $findings));
        $first = $findings[0];
        $this->assertSame('error', $first->severity);
        $this->assertSame('Doklad PR10 nejde zaevidovat jako tržbu', $first->title);
        $this->assertStringContainsString('Kód číselné řady nejde použít jako označení pokladního zařízení', $first->message);
        $this->assertStringContainsString('tržba založí sama s původním časem', $first->message);
        $this->assertSame(401, $first->subjectTableId);
        $this->assertSame(10, $first->subjectRowId);
        $this->assertSame(
            ['id' => 'open_document', 'label' => 'Otevřít doklad', 'kind' => 'open_form', 'primary' => true, 'target' => ['table' => 'docs_core_heads', 'mode' => 'edit', 'id' => 10]],
            $first->actions[0],
        );
        $this->assertStringContainsString('výchozí jednotku registrace', $findings[1]->message);
        $this->assertStringContainsString('víc platných registrací', $findings[2]->message);
    }

    public function testEveryReasonOfTheEvaluationHasItsOwnText(): void
    {
        $constants = (new \ReflectionClass(DocEvaluation::class))->getConstants();
        $unregistrable = [
            'REASON_MULTIPLE_REGISTRATIONS', 'REASON_REGISTRATION_GONE', 'REASON_CURRENCY', 'REASON_NO_UNIT',
            'REASON_FOREIGN_UNIT', 'REASON_UNIT_ID', 'REASON_CASH_REGISTER_ID', 'REASON_SERIAL_NUMBER', 'REASON_AMOUNT',
        ];
        foreach ($unregistrable as $name) {
            $reason = $constants[$name];
            $message = $this->unregistrableCheck([SyncResult::unregistrable(10, $reason)])->run()[0]->message;
            $this->assertStringNotContainsString('(' . $reason . ')', $message, "{$name}: chybí vlastní text důvodu");
        }
        $other = $this->unregistrableCheck([SyncResult::unregistrable(10, 'cosiNového')])->run()[0]->message;
        $this->assertStringContainsString('(cosiNového)', $other, 'neznámý důvod se aspoň vypíše');
    }

    public function testNumberOfFindingsIsCapped(): void
    {
        $results = [];
        for ($i = 1; $i <= DocUnregistrableCheck::MAX_FINDINGS + 10; $i++) {
            $results[] = SyncResult::unregistrable($i, DocEvaluation::REASON_NO_UNIT, 1);
        }

        $this->assertCount(DocUnregistrableCheck::MAX_FINDINGS, $this->unregistrableCheck($results)->run());
    }

    public function testSaleChecksAreDeclaredForTheSalesSection(): void
    {
        $raw = JsoncParser::parseFile(__DIR__ . '/../../../../../../modules/economy/salesRegistration/module.jsonc');
        $checks = [];
        foreach ($raw['alertChecks'] as $definition) {
            if (str_contains($definition['id'], '.cert_')) {
                continue;
            }
            $checks[$definition['id']] = [$definition['class'], $definition['severity']];
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_.]*$/', $definition['id']);
            $this->assertSame('sales', $definition['navSection'], $definition['id']);
            foreach (['name', 'name:cs', 'name:en', 'description', 'description:cs', 'description:en'] as $key) {
                $this->assertNotSame('', trim((string) ($definition[$key] ?? '')), "{$definition['id']}: {$key}");
            }
        }

        $this->assertSame([
            'economy.salesregistration.sale_unconfirmed'  => [SaleUnconfirmedCheck::class, 'warning'],
            'economy.salesregistration.sale_overdue'      => [SaleOverdueCheck::class, 'error'],
            'economy.salesregistration.sale_failed'       => [SaleFailedCheck::class, 'error'],
            'economy.salesregistration.doc_unregistrable' => [DocUnregistrableCheck::class, 'error'],
        ], $checks);
    }
}
