<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Actions;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionValidationException;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\SalesRegistration\Actions\ResendSaleAction;
use Shipard\Module\Economy\SalesRegistration\Engine\SendResult;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleQueue;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleState;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\Queue\MemorySaleStore;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\Queue\ScriptedSender;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\TempDataSource;

/**
 * Akce Odeslat znovu nad tržbou (#171 U12): jen `failed` a `pending`, vrací
 * tržbu do fronty a hned ji zkusí odeslat; smí ji i ne-správce.
 */
class ResendSaleActionTest extends TestCase
{
    use SalesRegistrationConfig;
    use TempDataSource;

    private const TABLE = 'economy_sales_registration_sales';

    private MemorySaleStore $store;
    private ScriptedSender $sender;
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/salesreg-resend-' . bin2hex(random_bytes(4)) . '.log';
        ErrorLogger::setLogPath($this->logFile);
        $this->store = new MemorySaleStore();
        $this->store->registrations[1] = ['id' => 1, 'type' => 'cz.eet2', 'docState' => 40];
        $this->sender = new ScriptedSender();
    }

    protected function tearDown(): void
    {
        ErrorLogger::setLogPath(null);
        @unlink($this->logFile);
        $this->removeDataSourceDir();
    }

    private function action(bool $withQueue = true): ResendSaleAction
    {
        $queue = $withQueue ? new SaleQueue($this->store, $this->sender, $this->salesRegistrationConfig()) : null;
        return new class($queue) extends ResendSaleAction {
            public function __construct(private readonly ?SaleQueue $testQueue)
            {
            }

            protected function queue(RecordActionContext $context): ?SaleQueue
            {
                return $this->testQueue;
            }
        };
    }

    private function context(bool $isAdmin = false): RecordActionContext
    {
        return new RecordActionContext(
            self::TABLE,
            $this->createMock(DataSourceConnection::class),
            $this->salesRegistrationConfig(),
            $this->dataSourceConfig(),
            'cs',
            null,
            5,
            $isAdmin,
        );
    }

    private function execute(int $saleId, ?ResendSaleAction $action = null): \Shipard\Core\RecordAction\RecordActionResult
    {
        return ($action ?? $this->action())->execute(self::TABLE, $this->store->sales[$saleId], new RecordActionInput([], []), $this->context());
    }

    public function testIsOfferedOnlyForFailedAndPendingSales(): void
    {
        $action = $this->action();
        foreach ([SaleState::FAILED => true, SaleState::PENDING => true, SaleState::SENDING => false, SaleState::CONFIRMED => false, SaleState::VERIFIED => false, SaleState::DROPPED => false] as $state => $expected) {
            $this->assertSame($expected, $action->available(self::TABLE, ['id' => 1, 'state' => $state], $this->context()), $state);
        }
    }

    public function testDescribesItselfWithAConfirmation(): void
    {
        $descriptor = $this->action()->describe(self::TABLE, ['id' => 1, 'state' => 'failed'], $this->context());

        $this->assertSame('Odeslat znovu', $descriptor->label);
        $this->assertStringContainsString('se stejnými daty', (string) $descriptor->confirm);
        $this->assertNull($descriptor->dialog);
    }

    public function testFailedSaleIsSentAgainAndConfirmed(): void
    {
        $this->store->addSale(7, ['state' => SaleState::FAILED, 'next_attempt' => null, 'attempt_count' => 1]);

        $result = $this->execute(7);

        $this->assertSame('success', $result->level);
        $this->assertSame('Tržba byla potvrzena.', $result->message);
        $this->assertSame(SaleState::CONFIRMED, $this->store->sales[7]['state']);
        $this->assertSame(2, $this->store->sales[7]['attempt_count']);
        $this->assertCount(1, $this->sender->messages);
    }

    public function testResultTellsWhatHappenedToTheSale(): void
    {
        foreach ([
            [ScriptedSender::verified(), 'success', 'ověřovacím režimu'],
            [ScriptedSender::dropped(), 'warning', 'pojistka tohoto serveru'],
            [ScriptedSender::rejectedByService(5, 'Neplatná data'), 'warning', 'Služba tržbu odmítla: Neplatná data'],
            [ScriptedSender::retryLater(), 'warning', 'další pokus je naplánovaný'],
            [ScriptedSender::ourProblem(SendResult::PROBLEM_NO_CERTIFICATE), 'warning', 'Důvod je v záložce Pokusy'],
        ] as $i => [$sendResult, $level, $text]) {
            $id = 10 + $i;
            $this->store->addSale($id, ['state' => SaleState::FAILED, 'next_attempt' => null]);
            $this->sender->script = [$sendResult];

            $result = $this->execute($id);

            $this->assertSame($level, $result->level, $text);
            $this->assertStringContainsString($text, $result->message);
        }
    }

    public function testSaleSettledInTheMeantimeIsRefused(): void
    {
        $this->store->addSale(7, ['state' => SaleState::CONFIRMED]);

        try {
            $this->execute(7);
            $this->fail('potvrzenou tržbu poslat znovu nejde');
        } catch (RecordActionValidationException $e) {
            $this->assertSame(
                [RecordActionValidationException::FORM => 'Tržba nečeká na odeslání — mezitím byla vyřízena.'],
                $e->fieldErrors(),
            );
        }
        $this->assertSame([], $this->sender->messages);
    }

    public function testOutsideADataSourceNothingIsSent(): void
    {
        $this->store->addSale(7, ['state' => SaleState::FAILED]);

        $this->expectException(RecordActionValidationException::class);
        $this->execute(7, $this->action(withQueue: false));
    }

    /** U12: oprávnění jako úprava dokladů — deklarace bez `admin`. */
    public function testIsDeclaredForEveryUserNotOnlyAdmins(): void
    {
        $module = JsoncParser::parseFile(__DIR__ . '/../../../../../../modules/economy/salesRegistration/module.jsonc');
        $actions = array_column($module['recordActions'], null, 'id');

        $this->assertSame(ResendSaleAction::class, $actions['resend']['class']);
        $this->assertSame(self::TABLE, $actions['resend']['table']);
        $this->assertArrayNotHasKey('admin', $actions['resend']);
        $this->assertTrue($actions['uploadCertificate']['admin']);
    }
}
