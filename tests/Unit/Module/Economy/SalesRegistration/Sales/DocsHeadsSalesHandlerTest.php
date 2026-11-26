<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Sales;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFacts;
use Shipard\Module\Economy\SalesRegistration\Evaluation\RegistrationFacts;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleQueue;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleState;
use Shipard\Module\Economy\SalesRegistration\Sales\DocsHeadsSalesHandler;
use Shipard\Module\Economy\SalesRegistration\Sales\SaleSync;
use Shipard\Module\Economy\SalesRegistration\Sales\SyncResult;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\Queue\MemorySaleStore;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\Queue\ScriptedSender;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;

/**
 * Handler dokladů evidence tržeb (#171 D6, D7, U12): kdy spustí rozdílový
 * výpočet, že chybu výpočtu spolkne a že doklad s tržbou nepustí do koše.
 */
class DocsHeadsSalesHandlerTest extends TestCase
{
    use SalesRegistrationConfig;

    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/salesreg-handler-' . bin2hex(random_bytes(4)) . '.log';
        ErrorLogger::setLogPath($this->logFile);
    }

    protected function tearDown(): void
    {
        ErrorLogger::setLogPath(null);
        @unlink($this->logFile);
    }

    private function sync(): FakeSaleSync
    {
        $type = SalesRegistrationTypes::fromConfig($this->salesRegistrationConfig())->get('cz.eet2');
        $sync = new FakeSaleSync();
        $sync->registrationList = [new RegistrationFacts(1, $type, '2027-01-01', null, 2, [2 => '181'])];
        $sync->registrationTypes = [1 => $type];
        $sync->docs[10] = DocFacts::fromRows(
            [
                'id' => 10, 'doc_type' => 'cashreg', 'docState' => 40, 'doc_number' => 'PR1270001',
                'issue_date' => '2027-02-10', 'payment_method' => 0, 'total_amount_dom' => '121.00',
                'home_currency' => 'czk', 'doc_state_changed_at' => '2027-02-10 09:30:00',
            ],
            [],
            ['code' => 'cashreg-P1'],
            'cz',
        );
        return $sync;
    }

    /** @param list<int> $docsWithSales */
    private function handler(?SaleSync $sync = null, array $docsWithSales = [], ?\Throwable $failure = null): DocsHeadsSalesHandler
    {
        $handler = new class extends DocsHeadsSalesHandler {
            public ?SaleSync $fake = null;
            /** @var list<int> */
            public array $docsWithSales = [];
            public ?\Throwable $failure = null;
            /** @var list<SyncResult> */
            public array $created = [];
            public int $syncs = 0;

            protected function sync(): SaleSync
            {
                $this->syncs++;
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                return $this->fake;
            }

            protected function hasSales(int $docId): bool
            {
                return in_array($docId, $this->docsWithSales, true);
            }

            protected function afterCreated(SyncResult $result): void
            {
                $this->created[] = $result;
            }
        };
        $handler->fake = $sync;
        $handler->docsWithSales = $docsWithSales;
        $handler->failure = $failure;
        $handler->setDb((new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor());
        $handler->setConfig($this->salesRegistrationConfig());
        return $handler;
    }

    public function testConfirmationCreatesTheSaleAndHandsItOver(): void
    {
        $sync = $this->sync();
        $handler = $this->handler($sync);

        $handler->onStateChanged('docs_core_heads', ['id' => 10], 10, 40);

        $this->assertCount(1, $sync->sales);
        $this->assertCount(1, $handler->created);
        $this->assertSame(1, $handler->created[0]->saleId);
        $log = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('sales registration: document created', $log);
        $this->assertStringContainsString('"serialNumber":"PR1270001"', $log);
    }

    /** Insert rovnou V pořádku („Vystavit a uzavřít“, pokyn přes API) dává starý stav 0. */
    public function testDocumentCreatedConfirmedIsHandledToo(): void
    {
        $sync = $this->sync();
        $handler = $this->handler($sync);

        $handler->onStateChanged('docs_core_heads', ['id' => 10], 0, 40);

        $this->assertCount(1, $sync->sales);
    }

    public function testOnlyConfirmationAndCancellationOfDocumentsTriggerTheSync(): void
    {
        $handler = $this->handler($this->sync());

        $handler->onStateChanged('docs_core_heads', ['id' => 10], 40, 80);
        $handler->onStateChanged('docs_core_heads', ['id' => 10], 10, 90);
        $handler->onStateChanged('docs_core_number_series', ['id' => 10], 10, 40);
        $handler->onStateChanged('docs_core_heads', [], 10, 40);
        $this->assertSame(0, $handler->syncs);

        $handler->onStateChanged('docs_core_heads', ['id' => 10], 40, 30);
        $this->assertSame(1, $handler->syncs);
    }

    public function testFailureIsLoggedAndNeverBreaksTheSaveOfTheDocument(): void
    {
        $handler = $this->handler(failure: new \RuntimeException('databáze spadla'));

        $handler->onStateChanged('docs_core_heads', ['id' => 10], 10, 40);

        $log = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('sale of document 10 not created', $log);
        $this->assertSame([], $handler->created);
    }

    public function testNothingToRegisterIsNotLogged(): void
    {
        $sync = $this->sync();
        unset($sync->docs[10]);
        $handler = $this->handler($sync);

        $handler->onStateChanged('docs_core_heads', ['id' => 10], 10, 40);

        $this->assertFileDoesNotExist($this->logFile);
    }

    // ── Okamžité odeslání (D7, U6) ──────────────────────────────────────────

    /** @return array{0: DocsHeadsSalesHandler, 1: MemorySaleStore, 2: ScriptedSender, 3: FakeSaleSync} */
    private function sendingHandler(bool $inTransaction = false): array
    {
        $sync = $this->sync();
        $store = new MemorySaleStore();
        $store->registrations[1] = ['id' => 1, 'type' => 'cz.eet2', 'docState' => 40];
        $sender = new ScriptedSender();
        $queue = new SaleQueue($store, $sender, $this->salesRegistrationConfig());

        $handler = new class extends DocsHeadsSalesHandler {
            public ?SaleSync $fake = null;
            public ?SaleQueue $fakeQueue = null;
            public ?MemorySaleStore $store = null;
            public bool $transaction = false;

            protected function sync(): SaleSync
            {
                return $this->fake;
            }

            protected function afterCreated(SyncResult $result): void
            {
                // Tržba z rozdílového výpočtu „je v databázi“ — fronta ji uvidí.
                $this->store->addSale((int) $result->saleId, $result->sale);
                parent::afterCreated($result);
            }

            protected function inTransaction(): bool
            {
                return $this->transaction;
            }

            protected function queue(): ?SaleQueue
            {
                return $this->fakeQueue;
            }
        };
        $handler->fake = $sync;
        $handler->fakeQueue = $queue;
        $handler->store = $store;
        $handler->transaction = $inTransaction;
        $handler->setDb((new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor());
        $handler->setConfig($this->salesRegistrationConfig());
        return [$handler, $store, $sender, $sync];
    }

    public function testNewSaleIsSentRightAway(): void
    {
        [$handler, $store, $sender] = $this->sendingHandler();

        $handler->onStateChanged('docs_core_heads', ['id' => 10], 10, 40);

        $this->assertCount(1, $sender->messages);
        $this->assertSame('PR1270001', $sender->messages[0]->serialNumber);
        $this->assertSame(SaleState::CONFIRMED, $store->sales[1]['state']);
    }

    /** Applier ukládá uvnitř transakce a doklad se ještě může vrátit — odeslání počká na cron. */
    public function testInsideAnOpenTransactionTheSaleIsLeftToTheQueue(): void
    {
        [$handler, $store, $sender, $sync] = $this->sendingHandler(inTransaction: true);

        $handler->onStateChanged('docs_core_heads', ['id' => 10], 0, 40);

        $this->assertCount(1, $sync->sales, 'tržba vznikla (vrátí se s dokladem)');
        $this->assertSame([], $sender->messages, 'nic neodešlo');
        $this->assertSame(SaleState::PENDING, $store->sales[1]['state']);
    }

    /** U6: po výpadku služby handler další tržby téže registrace minutu neposílá. */
    public function testOpenCircuitLeavesTheSaleToTheQueue(): void
    {
        [$handler, $store, $sender] = $this->sendingHandler();
        $store->addSale(50, ['registration' => 1]);
        $sender->script = [ScriptedSender::retryLater()];
        (new SaleQueue($store, $sender, $this->salesRegistrationConfig()))->attempt(50);

        $handler->onStateChanged('docs_core_heads', ['id' => 10], 10, 40);

        $this->assertCount(1, $sender->messages, 'jen pokus, který skončil výpadkem');
        $this->assertSame(SaleState::PENDING, $store->sales[1]['state']);
        $this->assertSame(0, $store->sales[1]['attempt_count']);
        $this->assertStringContainsString('sale left to the queue', (string) file_get_contents($this->logFile));
    }

    /** U12: doklad s tržbou nejde přesunout do koše — hláška ve formuláři (DomainException → 422). */
    public function testDocumentWithSaleCannotGoToTrash(): void
    {
        $handler = $this->handler(docsWithSales: [10]);
        $original = ['id' => 10, 'docState' => 40];

        $data = ['id' => 10, 'docState' => 90];
        try {
            $handler->onBeforeSave('docs_core_heads', $data, $original);
            $this->fail('přesun do koše měl skončit chybou');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Stornujte', $e->getMessage());
        }

        // Ostatní přechody a doklad bez tržby projdou.
        foreach ([80, 30, 40] as $state) {
            $data = ['id' => 10, 'docState' => $state];
            $handler->onBeforeSave('docs_core_heads', $data, $original);
        }
        $data = ['id' => 11, 'docState' => 90];
        $handler->onBeforeSave('docs_core_heads', $data, ['id' => 11, 'docState' => 10]);
        $data = ['id' => 10, 'doc_text' => 'bez změny stavu'];
        $handler->onBeforeSave('docs_core_heads', $data, $original);
        $data = ['docState' => 90];
        $handler->onBeforeSave('docs_core_heads', $data, null);
        $this->addToAssertionCount(1);
    }

    public function testDocumentWithSaleCannotBeDeleted(): void
    {
        $handler = $this->handler(docsWithSales: [10]);

        $handler->onBeforeDelete('docs_core_heads', ['id' => 11]);
        $handler->onBeforeDelete('docs_core_rows', ['id' => 10]);

        $this->expectException(\DomainException::class);
        $handler->onBeforeDelete('docs_core_heads', ['id' => 10]);
    }
}
