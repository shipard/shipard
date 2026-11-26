<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\ContactPayment;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentOutcome;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentPolicy;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentService;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocEvaluation;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFacts;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFactsLoader;
use Shipard\Module\Economy\SalesRegistration\Evaluation\RegistrationFacts;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleQueue;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleState;
use Shipard\Module\Economy\SalesRegistration\Sales\SaleSync;
use Shipard\Module\Economy\SalesRegistration\Sales\SyncResult;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\Queue\MemorySaleStore;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\Queue\ScriptedSender;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\Sales\FakeSaleSync;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;

/**
 * Kontaktní platba na dokladu V pořádku (#171 D8b, U15, U17, U21, U23):
 * příznak se zapíše bez přechodu stavu, hned vznikne tržba a zkusí se
 * odeslat; zrušení dá zápornou tržbu s časem zrušení. Co se zapsat nemá,
 * služba odmítne dřív, než cokoli zapíše.
 */
class ContactPaymentServiceTest extends TestCase
{
    use SalesRegistrationConfig;

    /** 12. 2. 2027 08:00 světového času = 09:00 v Praze. */
    private const NOW = '2027-02-12 08:00:00';

    private string $zone = '';
    private string $logFile = '';
    private FakeSaleSync $sync;
    private MemorySaleStore $store;
    private ScriptedSender $sender;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->logFile = sys_get_temp_dir() . '/salesreg-contact-' . bin2hex(random_bytes(4)) . '.log';
        ErrorLogger::setLogPath($this->logFile);

        $type = SalesRegistrationTypes::fromConfig($this->salesRegistrationConfig())->get('cz.eet2');
        $this->store = new MemorySaleStore();
        $this->store->registrations[1] = ['id' => 1, 'type' => 'cz.eet2', 'docState' => 40];
        $this->sender = new ScriptedSender();

        $store = $this->store;
        $this->sync = new class($store) extends FakeSaleSync {
            public ?\Throwable $failure = null;

            public function __construct(private readonly MemorySaleStore $queueStore)
            {
                parent::__construct();
            }

            public function sync(int $docId, ?\DateTimeImmutable $now = null, bool $dryRun = false): SyncResult
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                return parent::sync($docId, $now, $dryRun);
            }

            protected function insertSale(array $data): int
            {
                // Tržba z rozdílového výpočtu „je v databázi“ — fronta ji uvidí.
                $id = parent::insertSale($data);
                $this->queueStore->addSale($id, $data);
                return $id;
            }
        };
        $this->sync->registrationList = [new RegistrationFacts(1, $type, '2027-01-01', null, 2, [2 => '181'])];
        $this->sync->registrationTypes = [1 => $type];
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
        ErrorLogger::setLogPath(null);
        @unlink($this->logFile);
    }

    /** @param array<string, mixed> $head */
    private function invoice(array $head = []): DocFacts
    {
        return DocFacts::fromRows(
            $head + [
                'id' => 10, 'doc_type' => 'invno', 'docState' => 40, 'doc_number' => 'FV1270001',
                'issue_date' => '2027-01-20', 'payment_method' => 1, 'total_amount_dom' => '2420.00',
                'home_currency' => 'czk', 'imported' => 0, 'doc_state_changed_at' => '2027-01-20 09:00:00',
                'contact_payment' => 0,
            ],
            [],
            ['code' => 'invno'],
            'cz',
        );
    }

    private function service(bool $withQueue = true): ContactPaymentService
    {
        $db = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
        $queue = $withQueue ? new SaleQueue($this->store, $this->sender, $this->salesRegistrationConfig()) : null;

        $service = new class($db, new DocFactsLoader($db, null, null), $this->sync, $queue) extends ContactPaymentService {
            public ?FakeSaleSync $fake = null;
            /** @var list<array{flag: bool, paidAt: ?string, stamp: string}> */
            public array $writes = [];
            public bool $writable = true;

            protected function loadDoc(int $docId): ?DocFacts
            {
                return $this->fake->docs[$docId] ?? null;
            }

            protected function policy(): ContactPaymentPolicy
            {
                return new ContactPaymentPolicy($this->fake->registrationList);
            }

            protected function write(int $docId, bool $flag, ?string $paidAt, string $stamp): bool
            {
                if (!$this->writable) {
                    return false;
                }
                $this->writes[] = ['flag' => $flag, 'paidAt' => $paidAt, 'stamp' => $stamp];
                $doc = $this->fake->docs[$docId];
                $this->fake->docs[$docId] = DocFacts::fromRows(
                    [
                        'id' => $doc->id, 'doc_type' => $doc->docType, 'docState' => $doc->docState,
                        'doc_number' => $doc->docNumber, 'issue_date' => $doc->issueDate,
                        'payment_method' => $doc->paymentMethod, 'total_amount_dom' => $doc->totalCents / 100,
                        'home_currency' => $doc->homeCurrency, 'doc_state_changed_at' => $doc->stateChangedAt,
                        'contact_payment' => $flag ? 1 : 0, 'contact_payment_at' => $paidAt,
                        'contact_payment_changed_at' => $stamp,
                    ],
                    [],
                    ['code' => $doc->seriesCode],
                    $doc->country,
                );
                return true;
            }
        };
        $service->fake = $this->sync;
        return $service;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
    }

    public function testSettingCreatesTheSaleWithThePaymentTimeAndSendsIt(): void
    {
        $this->sync->docs[10] = $this->invoice();
        $service = $this->service();

        $outcome = $service->set(10, '2027-02-12 08:50:00', $this->now()); // 08:50 v Praze

        $this->assertTrue($outcome->isWritten());
        $this->assertSame(
            [['flag' => true, 'paidAt' => '2027-02-12 08:50:00', 'stamp' => self::NOW]],
            $service->writes,
            'čas platby, jak byl zadán; čas změny v zóně serveru',
        );
        $this->assertSame(SyncResult::CREATED, $outcome->sync?->status);
        $this->assertSame('2027-02-12 07:50:00', $this->sync->sales[0]['sold_at'], 'čas platby převedený do zóny serveru');
        $this->assertSame('2420.00', $this->sync->sales[0]['amount']);
        $this->assertSame(SaleState::CONFIRMED, $outcome->attempt?->state);
        $this->assertCount(1, $this->sender->messages, 'tržba se hned zkusí odeslat');
    }

    public function testCancellingCreatesANegativeSaleAtTheTimeOfCancellation(): void
    {
        $this->sync->docs[10] = $this->invoice();
        $service = $this->service();
        $service->set(10, '2027-02-12 08:50:00', $this->now());

        $later = new \DateTimeImmutable('2027-02-12 10:30:00', new \DateTimeZone('UTC'));
        $outcome = $service->cancel(10, $later);

        $this->assertTrue($outcome->isWritten());
        $this->assertSame(['flag' => false, 'paidAt' => null, 'stamp' => '2027-02-12 10:30:00'], $service->writes[1]);
        $this->assertSame(['2420.00', '-2420.00'], array_column($this->sync->sales, 'amount'), 'součet tržeb dokladu je nula');
        $this->assertSame('2027-02-12 10:30:00', $this->sync->sales[1]['sold_at']);
        $this->assertSame('FV1270001-2', $this->sync->sales[1]['serial_number']);
        $this->assertSame(SaleState::CONFIRMED, $outcome->attempt?->state);
    }

    /** U15: doklad vystavený před registrací, platba v době její platnosti. */
    public function testDocumentIssuedBeforeTheRegistrationIsAccepted(): void
    {
        $this->sync->docs[10] = $this->invoice(['issue_date' => '2026-12-10', 'doc_state_changed_at' => '2026-12-10 09:00:00']);

        $outcome = $this->service()->set(10, '2027-02-11 15:00:00', $this->now());

        $this->assertTrue($outcome->isWritten());
        $this->assertSame(SyncResult::CREATED, $outcome->sync?->status);
    }

    public function testNothingIsWrittenWhenTheDocumentWouldNotFall(): void
    {
        $this->sync->docs[10] = $this->invoice();
        $service = $this->service();

        // Platba ještě před začátkem evidence.
        $outcome = $service->set(10, '2026-12-30 10:00:00', $this->now());

        $this->assertSame(ContactPaymentOutcome::REFUSAL_NOT_APPLICABLE, $outcome->refusal);
        $this->assertSame(DocEvaluation::REASON_PAYMENT_OUTSIDE_REGISTRATION, $outcome->evaluationReason);
        $this->assertSame([], $service->writes);
        $this->assertSame([], $this->sync->sales);
    }

    public function testFutureAndUnreadableTimeAreRefused(): void
    {
        $this->sync->docs[10] = $this->invoice();
        $service = $this->service();

        $this->assertSame(ContactPaymentOutcome::REFUSAL_FUTURE, $service->set(10, '2027-02-12 09:30:00', $this->now())->refusal, '09:30 v Praze je za půl hodiny');
        $this->assertSame(ContactPaymentOutcome::REFUSAL_TIME, $service->set(10, 'zítra', $this->now())->refusal);
        $this->assertSame([], $service->writes);

        // Pár minut napřed (hodiny prohlížeče) projde, zapíše se „teď“.
        $this->assertTrue($service->set(10, '2027-02-12 09:03:00', $this->now())->isWritten());
        $this->assertSame('2027-02-12 09:00:00', $service->writes[0]['paidAt']);
    }

    public function testDocumentOutsideTheConditionsIsRefused(): void
    {
        $service = $this->service();
        $cases = [
            'neexistuje'            => null,
            'koncept'               => $this->invoice(['docState' => 10]),
            'příznak už nastavený'  => $this->invoice(['contact_payment' => 1]),
            'import'                => $this->invoice(['imported' => 1]),
        ];
        foreach ($cases as $label => $doc) {
            $this->sync->docs = $doc !== null ? [10 => $doc] : [];
            $this->assertSame(ContactPaymentOutcome::REFUSAL_CONFLICT, $service->set(10, '2027-02-12 08:50:00', $this->now())->refusal, $label);
        }
        foreach ([[1, 'cashreg'], [5, 'invno'], [2, 'invno']] as [$method, $docType]) {
            $this->sync->docs[10] = $this->invoice(['doc_type' => $docType, 'payment_method' => $method]);
            $this->assertSame(ContactPaymentOutcome::REFUSAL_NOT_ALLOWED, $service->set(10, '2027-02-12 08:50:00', $this->now())->refusal, "{$docType} × {$method}");
        }

        $this->sync->docs[10] = $this->invoice();
        $this->assertSame(ContactPaymentOutcome::REFUSAL_CONFLICT, $service->cancel(10, $this->now())->refusal, 'zrušit jde jen nastavený příznak');
        $this->assertSame([], $service->writes);
    }

    /** Dvojí kliknutí nebo souběh s přechodem dokladu: podmíněný zápis nic nezapsal. */
    public function testLostConditionalWriteCreatesNoSale(): void
    {
        $this->sync->docs[10] = $this->invoice();
        $service = $this->service();
        $service->writable = false;

        $this->assertSame(ContactPaymentOutcome::REFUSAL_CONFLICT, $service->set(10, '2027-02-12 08:50:00', $this->now())->refusal);
        $this->assertSame([], $this->sync->sales);
    }

    /** U9: chybí nastavení — příznak se zapíše, tržbu po opravě dorovná záchranná síť. */
    public function testUnregistrableDocumentKeepsTheFlagAndSaysWhy(): void
    {
        $type = $this->sync->registrationTypes[1];
        $this->sync->registrationList = [new RegistrationFacts(1, $type, '2027-01-01', null, null, [])];
        $this->sync->docs[10] = $this->invoice();
        $service = $this->service();

        $outcome = $service->set(10, '2027-02-12 08:50:00', $this->now());

        $this->assertTrue($outcome->isWritten());
        $this->assertCount(1, $service->writes);
        $this->assertSame(SyncResult::UNREGISTRABLE, $outcome->sync?->status);
        $this->assertSame(DocEvaluation::REASON_NO_UNIT, $outcome->sync?->reason);
        $this->assertNull($outcome->attempt);
    }

    /** U21: spadne-li založení tržby, příznak zůstává a tržbu dorovná záchranná síť. */
    public function testFailureAfterTheWriteIsLeftToTheSafetyNet(): void
    {
        $this->sync->docs[10] = $this->invoice();
        $service = $this->service();
        $this->sync->failure = new \RuntimeException('databáze spadla');

        $outcome = $service->set(10, '2027-02-12 08:50:00', $this->now());

        $this->assertTrue($outcome->isWritten());
        $this->assertNull($outcome->sync);
        $this->assertStringContainsString('not created after contact payment change', (string) file_get_contents($this->logFile));

        // Záchranná síť: týž výpočet nad zapsaným dokladem, stejný čas tržby.
        $this->sync->failure = null;
        $this->sync->sync(10, new \DateTimeImmutable('2027-02-12 08:05:00'));
        $this->assertSame('2027-02-12 07:50:00', $this->sync->sales[0]['sold_at']);
    }

    /** U6: jistič — po výpadku služby se nová tržba hned neposílá. */
    public function testServiceOutageLeavesTheSaleToTheQueue(): void
    {
        $this->sync->docs[10] = $this->invoice();
        $this->sender->script = [ScriptedSender::retryLater(), ScriptedSender::confirmed()];
        $this->store->addSale(90, ['state' => SaleState::PENDING]);
        (new SaleQueue($this->store, $this->sender, $this->salesRegistrationConfig()))->attempt(90);

        // Jistič měří skutečný čas — registrace proto platí už teď a platba je „před minutou“.
        $this->sync->registrationList = [new RegistrationFacts(1, $this->sync->registrationTypes[1], '2026-01-01', null, 2, [2 => '181'])];
        $paidAt = (new \DateTimeImmutable('-1 minute', new \DateTimeZone('Europe/Prague')))->format('Y-m-d H:i:s');
        $outcome = $this->service()->set(10, $paidAt);

        $this->assertTrue($outcome->isWritten());
        $this->assertSame(SyncResult::CREATED, $outcome->sync?->status);
        $this->assertNull($outcome->attempt, 'tržba zůstala frontě');
        $this->assertCount(1, $this->sender->messages, 'druhá zpráva neodešla');
    }

    public function testWithoutAQueueTheSaleWaitsForCron(): void
    {
        $this->sync->docs[10] = $this->invoice();

        $outcome = $this->service(withQueue: false)->set(10, '2027-02-12 08:50:00', $this->now());

        $this->assertSame(SyncResult::CREATED, $outcome->sync?->status);
        $this->assertNull($outcome->attempt);
    }
}
