<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Command;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Economy\SalesRegistration\Command\SalesRegistrationReconcileCommand;
use Shipard\Module\Economy\SalesRegistration\Command\SalesRegistrationRunCommand;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFactsLoader;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleQueue;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleState;
use Shipard\Module\Economy\SalesRegistration\Sales\ReconcileScanner;
use Shipard\Module\Economy\SalesRegistration\Sales\SyncResult;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\Queue\MemorySaleStore;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\Queue\ScriptedSender;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\Sales\FakeSaleSync;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\TempDataSource;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Příkazy cronu evidence tržeb (#171 D7): `sales-registration-run`
 * a `sales-registration-reconcile` — výpis, limity a že neodeslaná tržba
 * není chyba příkazu.
 */
class SalesRegistrationCommandsTest extends TestCase
{
    use SalesRegistrationConfig;
    use TempDataSource;

    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/salesreg-cmd-' . bin2hex(random_bytes(4)) . '.log';
        ErrorLogger::setLogPath($this->logFile);
    }

    protected function tearDown(): void
    {
        ErrorLogger::setLogPath(null);
        @unlink($this->logFile);
        $this->removeDataSourceDir();
    }

    public function testRunSendsDueSalesAndReportsTheCounts(): void
    {
        $store = new MemorySaleStore();
        $store->registrations[1] = ['id' => 1, 'type' => 'cz.eet2', 'docState' => 40];
        $past = (new \DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s');
        foreach ([1, 2, 3] as $id) {
            $store->addSale($id, ['next_attempt' => $past]);
        }
        $sender = new ScriptedSender();
        $sender->script = [ScriptedSender::confirmed(), ScriptedSender::rejectedByService(), ScriptedSender::dropped()];
        $queue = new SaleQueue($store, $sender, $this->salesRegistrationConfig());

        $tester = new CommandTester(new SalesRegistrationRunCommand($this->dataSourceConfig(), null, $queue));
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit, 'neodeslaná tržba není chyba příkazu');
        $this->assertStringContainsString(
            'Processed 3: 1 confirmed, 0 verified, 1 dropped, 1 failed, 0 to retry; 0 skipped after an outage, 0 requeued from stale sending',
            $tester->getDisplay(),
        );
        $this->assertSame(SaleState::FAILED, $store->sales[2]['state']);
    }

    public function testRunRespectsTheLimitAndRejectsNonsense(): void
    {
        $store = new MemorySaleStore();
        $store->registrations[1] = ['id' => 1, 'type' => 'cz.eet2', 'docState' => 40];
        $past = (new \DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s');
        foreach ([1, 2, 3] as $id) {
            $store->addSale($id, ['next_attempt' => $past]);
        }
        $queue = new SaleQueue($store, new ScriptedSender(), $this->salesRegistrationConfig());
        $tester = new CommandTester(new SalesRegistrationRunCommand($this->dataSourceConfig(), null, $queue));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--limit' => '2']));
        $this->assertStringContainsString('Processed 2:', $tester->getDisplay());

        $this->assertSame(Command::FAILURE, $tester->execute(['--limit' => '0']));
        $this->assertSame((string) SaleQueue::DEFAULT_LIMIT, (new SalesRegistrationRunCommand())->getDefinition()->getOption('limit')->getDefault());
    }

    private function scanner(array $results): ReconcileScanner
    {
        $db = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
        $scanner = new class ($db, new DocFactsLoader($db, null, null), new FakeSaleSync()) extends ReconcileScanner {
            /** @var list<SyncResult> */
            public array $results = [];
            public ?int $days = null;
            public ?bool $dryRun = null;

            public function run(int $days = self::DEFAULT_DAYS, ?\DateTimeImmutable $now = null, bool $dryRun = false): array
            {
                $this->days = $days;
                $this->dryRun = $dryRun;
                return $this->results;
            }
        };
        $scanner->results = $results;
        return $scanner;
    }

    public function testReconcileListsWhatItCreatedAndWhatCannotBeRegistered(): void
    {
        $sale = ['registration' => 1, 'serial_number' => 'PR1', 'amount' => '121.00', 'sold_at' => '2027-02-10 09:30:00'];
        $scanner = $this->scanner([
            SyncResult::created(10, 5, $sale),
            SyncResult::unregistrable(11, 'cashRegisterId', 1),
            SyncResult::busy(12),
        ]);
        $tester = new CommandTester(new SalesRegistrationReconcileCommand($this->dataSourceConfig(), null, $scanner));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('doc 10: created PR1 121.00 (sold at 2027-02-10 09:30:00)', $display);
        $this->assertStringContainsString('doc 11: cannot be registered (cashRegisterId)', $display);
        $this->assertStringContainsString('Reconciled: 1 created, 1 cannot be registered, 1 busy', $display);
        $this->assertSame(ReconcileScanner::DEFAULT_DAYS, $scanner->days);
        $this->assertFalse($scanner->dryRun);
    }

    public function testReconcileDryRunOnlyPrints(): void
    {
        $sale = ['registration' => 1, 'serial_number' => 'PR1-2', 'amount' => '-121.00', 'sold_at' => '2027-02-11 15:00:00'];
        $scanner = $this->scanner([SyncResult::wouldCreate(10, $sale)]);
        $tester = new CommandTester(new SalesRegistrationReconcileCommand($this->dataSourceConfig(), null, $scanner));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true, '--days' => '3']));

        $this->assertStringContainsString('doc 10: would create PR1-2 -121.00', $tester->getDisplay());
        $this->assertStringContainsString('Dry run: 1 created, 0 cannot be registered, 0 busy', $tester->getDisplay());
        $this->assertTrue($scanner->dryRun);
        $this->assertSame(3, $scanner->days);
        $this->assertSame(Command::FAILURE, $tester->execute(['--days' => '0']));
    }

    public function testCommandsRefuseADirectoryThatIsNotADataSource(): void
    {
        $run = new class extends SalesRegistrationRunCommand {
            protected function getDataSourceDir(): string
            {
                return sys_get_temp_dir() . '/neni-zdroj-dat';
            }
        };
        $reconcile = new class extends SalesRegistrationReconcileCommand {
            protected function getDataSourceDir(): string
            {
                return sys_get_temp_dir() . '/neni-zdroj-dat';
            }
        };

        foreach ([$run, $reconcile] as $command) {
            $tester = new CommandTester($command);
            $this->assertSame(Command::FAILURE, $tester->execute([]));
            $this->assertStringContainsString('Not a Shipard data source directory', $tester->getDisplay());
        }
    }
}
