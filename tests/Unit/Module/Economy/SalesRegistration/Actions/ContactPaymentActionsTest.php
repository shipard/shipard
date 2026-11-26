<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Actions;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionValidationException;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\SalesRegistration\Actions\CancelContactPaymentAction;
use Shipard\Module\Economy\SalesRegistration\Actions\ContactPaymentAction;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentOutcome;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentPolicy;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentService;
use Shipard\Module\Economy\SalesRegistration\Engine\SendResult;
use Shipard\Module\Economy\SalesRegistration\Engine\SendStatus;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocEvaluation;
use Shipard\Module\Economy\SalesRegistration\Evaluation\RegistrationFacts;
use Shipard\Module\Economy\SalesRegistration\Queue\AttemptResult;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleState;
use Shipard\Module\Economy\SalesRegistration\Sales\SyncResult;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\Queue\ScriptedSender;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\TempDataSource;

/**
 * Akce Kontaktní platba a Zrušit kontaktní platbu nad dokladem V pořádku
 * (#171 D8b, D8c, U14, U17): kde se nabízejí, dialog s časem platby
 * a varováním, potvrzení zrušení a hláška podle toho, co se stalo s tržbou.
 */
class ContactPaymentActionsTest extends TestCase
{
    use SalesRegistrationConfig;
    use TempDataSource;

    private const TABLE = 'docs_core_heads';

    protected function tearDown(): void
    {
        $this->removeDataSourceDir();
    }

    private function policy(bool $withRegistration = true): ContactPaymentPolicy
    {
        if (!$withRegistration) {
            return new ContactPaymentPolicy([]);
        }
        $type = SalesRegistrationTypes::fromConfig($this->salesRegistrationConfig())->get('cz.eet2');
        return new ContactPaymentPolicy([new RegistrationFacts(1, $type, '2027-01-01', null, 2, [2 => '181'])]);
    }

    /** Služba, která jen vrací připravený výsledek a pamatuje si volání. */
    private function service(ContactPaymentOutcome $outcome): ContactPaymentService
    {
        $service = (new \ReflectionClass(RecordingService::class))->newInstanceWithoutConstructor();
        $service->outcome = $outcome;
        return $service;
    }

    /** @param list<string> $paying */
    private function setAction(?ContactPaymentService $service = null, bool $withRegistration = true, array $paying = []): ContactPaymentAction
    {
        return new class($this->policy($withRegistration), $service, $paying) extends ContactPaymentAction {
            /** @param list<string> $paying */
            public function __construct(
                private readonly ContactPaymentPolicy $testPolicy,
                private readonly ?ContactPaymentService $testService,
                private readonly array $paying,
            ) {
            }

            protected function policy(RecordActionContext $context): ContactPaymentPolicy
            {
                return $this->testPolicy;
            }

            protected function service(RecordActionContext $context): ?ContactPaymentService
            {
                return $this->testService;
            }

            protected function payingDocuments(array $record, RecordActionContext $context): array
            {
                return $this->paying;
            }
        };
    }

    private function cancelAction(?ContactPaymentService $service = null): CancelContactPaymentAction
    {
        return new class($service) extends CancelContactPaymentAction {
            public function __construct(private readonly ?ContactPaymentService $testService)
            {
            }

            protected function service(RecordActionContext $context): ?ContactPaymentService
            {
                return $this->testService;
            }
        };
    }

    private function context(): RecordActionContext
    {
        return new RecordActionContext(
            self::TABLE,
            $this->createMock(DataSourceConnection::class),
            $this->salesRegistrationConfig(),
            $this->dataSourceConfig(),
            'cs',
            null,
            5,
            false,
        );
    }

    /** @param array<string, mixed> $override */
    private function invoice(array $override = []): array
    {
        return $override + ['id' => 10, 'doc_type' => 'invno', 'payment_method' => 1, 'docState' => 40, 'imported' => 0, 'contact_payment' => 0];
    }

    private function input(?string $paidAt = '2027-02-12 08:50:00'): RecordActionInput
    {
        return new RecordActionInput($paidAt !== null ? ['paid_at' => new \DateTimeImmutable($paidAt)] : [], []);
    }

    /** @param array<string, mixed> $sale */
    private function created(array $sale = []): SyncResult
    {
        return SyncResult::created(10, 1, $sale + ['registration' => 1, 'serial_number' => 'FV1270001', 'amount' => '2420.00', 'currency' => 'czk'], SyncResult::TIME_PAYMENT);
    }

    private function attempt(string $state, SendResult $result, ?string $next = null): AttemptResult
    {
        return new AttemptResult(1, 1, $state, $result, $next);
    }

    // ── Kde se akce nabízejí (U14, U17) ─────────────────────────────────────

    public function testContactPaymentIsOfferedOnlyWhereTheTypeDataAllowIt(): void
    {
        $action = $this->setAction();
        $offered = [
            'faktura Převodem'         => $this->invoice(),
            'zálohová Převodem'        => $this->invoice(['doc_type' => 'invpo']),
            'zálohová Kartou'          => $this->invoice(['doc_type' => 'invpo', 'payment_method' => 2]),
        ];
        $notOffered = [
            'faktura Kartou (pravidlo)' => $this->invoice(['payment_method' => 2]),
            'faktura Hotově (pravidlo)' => $this->invoice(['payment_method' => 0]),
            'platební brána'            => $this->invoice(['payment_method' => 5]),
            'zápočet'                   => $this->invoice(['payment_method' => 4]),
            'dobírka'                   => $this->invoice(['payment_method' => 3]),
            'pokladní doklad'           => $this->invoice(['doc_type' => 'cash', 'payment_method' => 0]),
            'koncept'                   => $this->invoice(['docState' => 10]),
            'v opravě'                  => $this->invoice(['docState' => 80]),
            'storno'                    => $this->invoice(['docState' => 30]),
            'příznak už nastavený'      => $this->invoice(['contact_payment' => 1]),
            'import'                    => $this->invoice(['imported' => 1]),
        ];
        foreach ($offered as $label => $record) {
            $this->assertTrue($action->available(self::TABLE, $record, $this->context()), $label);
        }
        foreach ($notOffered as $label => $record) {
            $this->assertFalse($action->available(self::TABLE, $record, $this->context()), $label);
        }

        $this->assertFalse(
            $this->setAction(withRegistration: false)->available(self::TABLE, $this->invoice(), $this->context()),
            'bez registrace V pořádku se akce nenabízí',
        );
    }

    public function testCancellationIsOfferedOnlyOnAConfirmedDocumentWithTheFlag(): void
    {
        $action = $this->cancelAction();

        $this->assertTrue($action->available(self::TABLE, $this->invoice(['contact_payment' => 1]), $this->context()));
        $this->assertFalse($action->available(self::TABLE, $this->invoice(), $this->context()));
        $this->assertFalse($action->available(self::TABLE, $this->invoice(['contact_payment' => 1, 'docState' => 80]), $this->context()), 'V opravě se ruší zaškrtávátkem');
        $this->assertFalse($action->available(self::TABLE, $this->invoice(['contact_payment' => 1, 'docState' => 30]), $this->context()));
    }

    // ── Dialog a potvrzení ──────────────────────────────────────────────────

    public function testDialogAsksForThePaymentTimeDefaultingToNow(): void
    {
        $descriptor = $this->setAction()->describe(self::TABLE, $this->invoice(), $this->context());

        $this->assertSame('Kontaktní platba', $descriptor->label);
        $this->assertNull($descriptor->confirm);
        $dialog = $descriptor->dialog?->toArray();
        $this->assertSame('Kontaktní platba', $dialog['title']);
        $this->assertStringContainsString('zaplatil na místě', $dialog['intro']);
        $this->assertSame('Zaevidovat tržbu', $dialog['submitLabel']);
        $this->assertCount(1, $dialog['fields'], 'bez úhrady pokladním dokladem žádné varování');
        $this->assertSame('paid_at', $dialog['fields'][0]['id']);
        $this->assertSame('datetime', $dialog['fields'][0]['type']);
        $this->assertTrue($dialog['fields'][0]['required']);
        $this->assertSame('now', $dialog['fields'][0]['default']);
    }

    /** D8c: fakturu už hradí pokladní doklad, který do evidence spadá. */
    public function testDialogWarnsWhenACashDocumentAlreadyPaysTheInvoice(): void
    {
        $descriptor = $this->setAction(paying: ['PP1270003', 'PP1270007'])->describe(self::TABLE, $this->invoice(), $this->context());

        $fields = $descriptor->dialog?->toArray()['fields'];
        $this->assertCount(2, $fields);
        $this->assertSame('notice', $fields[0]['type']);
        $this->assertStringContainsString('PP1270003, PP1270007', $fields[0]['text']);
        $this->assertStringContainsString('zaevidovala dvakrát', $fields[0]['text']);
        $this->assertSame('paid_at', $fields[1]['id']);
    }

    public function testCancellationAsksForConfirmation(): void
    {
        $descriptor = $this->cancelAction()->describe(self::TABLE, $this->invoice(['contact_payment' => 1]), $this->context());

        $this->assertSame('Zrušit kontaktní platbu', $descriptor->label);
        $this->assertStringContainsString('záporná tržba', (string) $descriptor->confirm);
        $this->assertNull($descriptor->dialog);
    }

    // ── Provedení ───────────────────────────────────────────────────────────

    public function testPaymentTimeGoesToTheServiceAsEntered(): void
    {
        $service = $this->service(ContactPaymentOutcome::written($this->created(), $this->attempt(SaleState::CONFIRMED, ScriptedSender::confirmed())));

        $result = $this->setAction($service)->execute(self::TABLE, $this->invoice(), $this->input(), $this->context());

        $this->assertSame([['set', 10, '2027-02-12 08:50:00']], $service->calls);
        $this->assertSame('success', $result->level);
        $this->assertSame('Tržba FV1270001 na 2 420,00 CZK byla zaevidována a potvrzena.', $result->message);
    }

    public function testResultTellsWhatHappenedToTheSale(): void
    {
        $cases = [
            [ContactPaymentOutcome::written($this->created(), $this->attempt(SaleState::VERIFIED, ScriptedSender::verified())), 'success', 'ověřovacím režimu'],
            [ContactPaymentOutcome::written($this->created(), $this->attempt(SaleState::DROPPED, ScriptedSender::dropped())), 'warning', 'pojistka tohoto serveru'],
            [ContactPaymentOutcome::written($this->created(), $this->attempt(SaleState::FAILED, ScriptedSender::rejectedByService(5, 'Neplatná data'))), 'warning', 'služba ji odmítla: Neplatná data'],
            [ContactPaymentOutcome::written($this->created(), $this->attempt(SaleState::PENDING, ScriptedSender::retryLater(), '2027-02-12 08:01:00')), 'warning', 'další pokus je naplánovaný na 2027-02-12 08:01:00'],
            [ContactPaymentOutcome::written($this->created()), 'info', 'čeká ve frontě'],
            [ContactPaymentOutcome::written(SyncResult::unregistrable(10, DocEvaluation::REASON_NO_UNIT, 1)), 'warning', 'tržbu nejde založit: Evidenční jednotku nemá'],
            [ContactPaymentOutcome::written(SyncResult::none(10)), 'info', 'není žádná tržba'],
            [ContactPaymentOutcome::written(SyncResult::busy(10)), 'info', 'během několika minut'],
            [ContactPaymentOutcome::written(null), 'info', 'během několika minut'],
        ];
        foreach ($cases as $i => [$outcome, $level, $text]) {
            $result = $this->setAction($this->service($outcome))->execute(self::TABLE, $this->invoice(), $this->input(), $this->context());

            $this->assertSame($level, $result->level, "případ {$i}");
            $this->assertStringContainsString($text, $result->message, "případ {$i}");
        }
    }

    public function testRefusalsAreReportedAtThePaymentTimeField(): void
    {
        $cases = [
            [ContactPaymentOutcome::refused(ContactPaymentOutcome::REFUSAL_FUTURE), 'paid_at', 'nesmí být v budoucnu'],
            [ContactPaymentOutcome::refused(ContactPaymentOutcome::REFUSAL_TIME), 'paid_at', 'Zadejte čas platby'],
            [
                ContactPaymentOutcome::refused(ContactPaymentOutcome::REFUSAL_NOT_APPLICABLE, DocEvaluation::REASON_PAYMENT_OUTSIDE_REGISTRATION),
                'paid_at',
                'do evidence tržeb nespadl: V čase platby neplatila žádná registrace',
            ],
            [ContactPaymentOutcome::refused(ContactPaymentOutcome::REFUSAL_CONFLICT), '_form', 'mezitím změnil'],
            [ContactPaymentOutcome::refused(ContactPaymentOutcome::REFUSAL_NOT_ALLOWED), '_form', 'mezitím změnil'],
        ];
        foreach ($cases as $i => [$outcome, $field, $text]) {
            try {
                $this->setAction($this->service($outcome))->execute(self::TABLE, $this->invoice(), $this->input(), $this->context());
                $this->fail("případ {$i}: čekal jsem chybu");
            } catch (RecordActionValidationException $e) {
                $errors = $e->fieldErrors();
                $this->assertSame($field, array_key_first($errors), "případ {$i}");
                $this->assertStringContainsString($text, (string) reset($errors), "případ {$i}");
            }
        }
    }

    public function testMissingTimeAndMissingDataSourceAreErrors(): void
    {
        $service = $this->service(ContactPaymentOutcome::written(null));
        try {
            $this->setAction($service)->execute(self::TABLE, $this->invoice(), $this->input(null), $this->context());
            $this->fail('čekal jsem chybu');
        } catch (RecordActionValidationException $e) {
            $this->assertSame(['paid_at'], array_keys($e->fieldErrors()));
        }
        $this->assertSame([], $service->calls);

        $this->expectException(RecordActionValidationException::class);
        $this->setAction(null)->execute(self::TABLE, $this->invoice(), $this->input(), $this->context());
    }

    public function testCancellationSettlesTheSale(): void
    {
        $settled = SyncResult::created(10, 2, ['registration' => 1, 'serial_number' => 'FV1270001-2', 'amount' => '-2420.00', 'currency' => 'czk'], SyncResult::TIME_FLAG_CHANGE);
        $service = $this->service(ContactPaymentOutcome::written($settled, $this->attempt(SaleState::CONFIRMED, ScriptedSender::confirmed())));

        $result = $this->cancelAction($service)->execute(self::TABLE, $this->invoice(['contact_payment' => 1]), new RecordActionInput([], []), $this->context());

        $this->assertSame([['cancel', 10]], $service->calls);
        $this->assertSame('success', $result->level);
        $this->assertSame('Tržba FV1270001-2 na -2 420,00 CZK byla zaevidována a potvrzena.', $result->message);

        $this->expectException(RecordActionValidationException::class);
        $this->cancelAction($this->service(ContactPaymentOutcome::refused(ContactPaymentOutcome::REFUSAL_CONFLICT)))
            ->execute(self::TABLE, $this->invoice(['contact_payment' => 1]), new RecordActionInput([], []), $this->context());
    }

    // ── Deklarace ───────────────────────────────────────────────────────────

    public function testActionsAreDeclaredForDocumentsAndNotOnlyForAdmins(): void
    {
        $module = JsoncParser::parseFile(__DIR__ . '/../../../../../../modules/economy/salesRegistration/module.jsonc');
        $actions = array_column($module['recordActions'], null, 'id');

        foreach (['contactPayment' => ContactPaymentAction::class, 'cancelContactPayment' => CancelContactPaymentAction::class] as $id => $class) {
            $this->assertSame(self::TABLE, $actions[$id]['table'] ?? null, $id);
            $this->assertSame($class, $actions[$id]['class'] ?? null, $id);
            $this->assertArrayNotHasKey('admin', $actions[$id], "{$id}: smí ji každý, kdo pracuje s doklady");
        }
    }
}

/** Služba, která jen vrací připravený výsledek a pamatuje si volání. */
class RecordingService extends ContactPaymentService
{
    public ?ContactPaymentOutcome $outcome = null;

    /** @var list<array<int, mixed>> */
    public array $calls = [];

    public function set(int $docId, string $paidAt, ?\DateTimeImmutable $now = null): ContactPaymentOutcome
    {
        $this->calls[] = ['set', $docId, $paidAt];
        return $this->outcome;
    }

    public function cancel(int $docId, ?\DateTimeImmutable $now = null): ContactPaymentOutcome
    {
        $this->calls[] = ['cancel', $docId];
        return $this->outcome;
    }
}
