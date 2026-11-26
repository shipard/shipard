<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionResult;
use Shipard\Core\RecordAction\RecordActionValidationException;
use Shipard\Module\Economy\SalesRegistration\Actions\VerifyConnectionAction;
use Shipard\Module\Economy\SalesRegistration\Engine\SalesMessage;
use Shipard\Module\Economy\SalesRegistration\Engine\SendResult;
use Shipard\Module\Economy\SalesRegistration\Engine\SendStatus;
use Shipard\Module\Economy\SalesRegistration\RegistrationDocument;
use Shipard\Module\Economy\SalesRegistration\Safety\MessageRoute;
use Shipard\Module\Economy\SalesRegistration\Safety\SalesRegistrationServerConfig;
use Shipard\Module\Economy\SalesRegistration\Sender\SalesRegistrationSender;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\TempDataSource;

/**
 * Akce „Ověřit spojení“ (#171 U4): zkušební zpráva výchozí jednotky,
 * `SHIPARD-TEST`, `TEST-<čas>`, 0,00 — vždy v ověřovacím módu; výsledek říká
 * prostředí, identitu, kód a text chyby a varování.
 */
class VerifyConnectionActionTest extends TestCase
{
    use SalesRegistrationConfig;
    use TempDataSource;

    protected function setUp(): void
    {
        $this->createDataSourceDir();
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath($this->dataSourceDir . '/test.log');
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        $this->removeDataSourceDir();
    }

    /**
     * Akce s podvrženým odesílačem: zaznamená zprávu a vrátí daný výsledek.
     */
    private function action(?SendResult $result = null, string $safetyMode = 'off', ?string $unitId = '181'): VerifyConnectionAction
    {
        return new class($result, $safetyMode, $unitId) extends VerifyConnectionAction {
            /** @var list<array{registration: array<string, mixed>, message: SalesMessage}> */
            public array $sent = [];

            public function __construct(
                private readonly ?SendResult $result,
                private readonly string $safetyMode,
                private readonly ?string $unitId,
            ) {
            }

            protected function defaultUnitId(array $record, RecordActionContext $context): ?string
            {
                return $this->unitId;
            }

            protected function safety(RecordActionContext $context): SalesRegistrationServerConfig
            {
                return SalesRegistrationServerConfig::ofMode($this->safetyMode);
            }

            protected function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-11-26 14:30:15');
            }

            protected function sender(RecordActionContext $context): SalesRegistrationSender
            {
                $action = $this;
                return new class($context->config, $context->dsConfig, $this->safety($context), $action) extends SalesRegistrationSender {
                    public function __construct($config, $dsConfig, $safety, private readonly object $action)
                    {
                        parent::__construct($config, $dsConfig, $safety);
                    }

                    public function send(array $registration, SalesMessage $message): SendResult
                    {
                        return $this->action->record($registration, $message);
                    }
                };
            }

            /** @param array<string, mixed> $registration */
            public function record(array $registration, SalesMessage $message): SendResult
            {
                $this->sent[] = ['registration' => $registration, 'message' => $message];
                return $this->result ?? new SendResult(SendStatus::Verified, true, environment: 'production', verification: true);
            }
        };
    }

    private function context(?int $userId = 5): RecordActionContext
    {
        return new RecordActionContext(
            RegistrationDocument::TABLE,
            $this->createMock(DataSourceConnection::class),
            $this->salesRegistrationConfig(['economy.salesRegistration.engines' => ['cz.eet2' => \Shipard\Module\Economy\SalesRegistration\Country\Cz\Eet2Engine::class]]),
            $this->dataSourceConfig(),
            'cs',
            null,
            $userId,
            true,
        );
    }

    /** @return array<string, mixed> */
    private function record(array $override = []): array
    {
        return $override + [
            'id' => 7, 'type' => 'cz.eet2', 'name' => 'Prodejna', 'mode' => 'production', 'taxpayer_id' => 'CZ12345678',
            'default_unit' => 3, 'cert_pem' => '-----BEGIN CERTIFICATE-----', 'cert_key' => 'v1:x', 'docState' => 40,
        ];
    }

    private function execute(VerifyConnectionAction $action, array $record = []): RecordActionResult
    {
        return $action->execute(RegistrationDocument::TABLE, $this->record($record), new RecordActionInput(), $this->context());
    }

    private function sendResult(SendStatus $status, bool $sent = true, array $named = []): SendResult
    {
        return new SendResult($status, $sent, ...($named + ['environment' => 'production', 'verification' => true]));
    }

    public function testSendsTheTestMessageAlwaysInVerificationMode(): void
    {
        $action = $this->action();

        $this->execute($action);

        $this->assertCount(1, $action->sent);
        $message = $action->sent[0]['message'];
        $this->assertTrue($message->verification, 'Ověřit spojení nikdy neeviduje');
        $this->assertSame('181', $message->unitId, 'výchozí jednotka registrace');
        $this->assertSame('SHIPARD-TEST', $message->cashRegisterId);
        $this->assertSame('TEST-20261126143015', $message->serialNumber);
        $this->assertSame('0.00', $message->amount);
        $this->assertSame('czk', $message->currency);
        $this->assertTrue($message->firstSending);
        $this->assertSame([], $message->extras);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $message->uuid);
        $this->assertSame(7, $action->sent[0]['registration']['id']);
    }

    public function testTestMessageFitsTheMasksOfTheType(): void
    {
        $type = \Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes::fromConfig($this->salesRegistrationConfig())->get('cz.eet2');
        $action = $this->action();
        $this->execute($action);
        $message = $action->sent[0]['message'];

        $this->assertTrue($type->isValidCashRegisterId($message->cashRegisterId));
        $this->assertTrue($type->isValidSerialNumber($message->serialNumber));
    }

    public function testVerifiedConnectionIsASuccessNamingEnvironmentAndIdentity(): void
    {
        $production = $this->execute($this->action($this->sendResult(SendStatus::Verified)));
        $this->assertSame(RecordActionResult::SUCCESS, $production->level);
        $this->assertSame('Spojení ověřeno — produkce, certifikát registrace CZ12345678.', $production->message);

        $playground = $this->execute($this->action($this->sendResult(SendStatus::Verified, named: ['environment' => 'playground', 'testIdentity' => true])));
        $this->assertSame(RecordActionResult::SUCCESS, $playground->level);
        $this->assertSame('Spojení ověřeno — playground, sdílená testovací identita CZ00000019.', $playground->message);
    }

    public function testWarningsOfTheServiceTurnTheResultIntoAWarning(): void
    {
        $result = $this->execute($this->action($this->sendResult(SendStatus::Verified, named: [
            'warnings' => [1 => 'EIC poplatnika se neshoduje s EIC v certifikatu', 6 => 'id_jednotky neodpovida'],
        ])));

        $this->assertSame(RecordActionResult::WARNING, $result->level);
        $this->assertStringStartsWith('Spojení ověřeno — produkce', $result->message);
        $this->assertStringContainsString('Varování služby: 1 — EIC poplatnika se neshoduje s EIC v certifikatu; 6 — id_jednotky neodpovida', $result->message);
    }

    /** @return array<string, array{SendResult, string}> */
    public static function failures(): array
    {
        $at = ['environment' => 'production', 'verification' => true];
        return [
            'rejected'    => [new SendResult(SendStatus::Rejected, true, ...$at + ['errorCode' => 4, 'errorText' => 'Neplatny podpis SOAP zpravy']), 'Služba zprávu odmítla (produkce, certifikát registrace CZ12345678) — kód 4: Neplatny podpis SOAP zpravy'],
            'retry later' => [new SendResult(SendStatus::RetryLater, true, ...$at + ['errorCode' => -1, 'errorText' => 'Docasna technicka chyba.']), 'Služba je dočasně nedostupná (produkce, certifikát registrace CZ12345678) — kód -1: Docasna technicka chyba. Zkuste to později.'],
            'no response' => [new SendResult(SendStatus::Unknown, true, ...$at + ['errorText' => 'Operation timed out']), 'Služba neodpověděla platnou odpovědí (produkce, certifikát registrace CZ12345678): Operation timed out'],
            'untrusted'   => [new SendResult(SendStatus::Unknown, true, ...$at + ['errorText' => 'The signature of the message is not valid.', 'untrusted' => true]), 'Odpovědi služby nelze věřit (produkce, certifikát registrace CZ12345678): The signature of the message is not valid.'],
            'no certificate'   => [SendResult::notSent(SendStatus::Rejected, SendResult::PROBLEM_NO_CERTIFICATE), 'Registrace nemá certifikát — nejdřív ho nahrajte.'],
            'key unusable'     => [SendResult::notSent(SendStatus::Rejected, SendResult::PROBLEM_KEY), 'Certifikát registrace nejde použít — nahrajte ho znovu.'],
            'no test identity' => [SendResult::notSent(SendStatus::Rejected, SendResult::PROBLEM_NO_TEST_IDENTITY), 'Typ evidence nemá testovací identitu pro neprodukční prostředí.'],
            'no environment'   => [SendResult::notSent(SendStatus::Rejected, SendResult::PROBLEM_ENVIRONMENT), 'Typ evidence nemá adresu cílového prostředí.'],
            'message invalid'  => [SendResult::notSent(SendStatus::Rejected, SendResult::PROBLEM_MESSAGE, 'id_jednotky has an invalid format'), 'Zkušební zprávu se nepodařilo sestavit: id_jednotky has an invalid format'],
            'internal'         => [SendResult::notSent(SendStatus::Rejected, SendResult::PROBLEM_INTERNAL), 'Zkušební zprávu se nepodařilo odeslat kvůli vnitřní chybě — podrobnosti jsou v logu serveru.'],
            'type'             => [SendResult::notSent(SendStatus::Rejected, SendResult::PROBLEM_TYPE), 'Typ evidence této registrace není ve zdroji dat dostupný.'],
        ];
    }

    #[DataProvider('failures')]
    public function testFailuresAreWarningsWithCodeAndText(SendResult $sendResult, string $expected): void
    {
        $result = $this->execute($this->action($sendResult));

        $this->assertSame(RecordActionResult::WARNING, $result->level);
        $this->assertSame($expected, $result->message);
    }

    public function testDroppedByTheSafetySwitchSaysWhy(): void
    {
        $dropped = SendResult::notSent(SendStatus::Dropped, SendResult::PROBLEM_SAFETY, MessageRoute::DROP_CONFIGURED);

        $result = $this->execute($this->action($dropped, 'drop'));

        $this->assertSame(RecordActionResult::WARNING, $result->level);
        $this->assertSame('Pojistka serveru je v režimu drop — nic se neodešle.', $result->message);
    }

    public function testNeedsADefaultUnitThatBelongsToTheRegistration(): void
    {
        $action = $this->action(unitId: null);

        try {
            $this->execute($action);
            $this->fail('bez jednotky se nic neodešle');
        } catch (RecordActionValidationException $e) {
            $this->assertSame([RecordActionValidationException::FORM => 'Registrace nemá výchozí evidenční jednotku.'], $e->fieldErrors());
        }
        $this->assertSame([], $action->sent);
    }

    public function testAvailability(): void
    {
        $context = $this->context();
        $table = RegistrationDocument::TABLE;
        $available = fn(string $safety, array $record): bool => $this->action(safetyMode: $safety)->available($table, $this->record($record), $context);

        $this->assertTrue($available('off', []), 'certifikát + výchozí jednotka');
        $this->assertTrue($available('drop', []), 's certifikátem i pod pojistkou drop — akce řekne proč se nic neodešle');
        $this->assertFalse($available('off', ['default_unit' => null]), 'bez výchozí jednotky');
        $this->assertFalse($available('off', ['type' => 'xx.gone']));
        foreach ([70, 90] as $state) {
            $this->assertFalse($available('off', ['docState' => $state]), "stav {$state}");
        }

        $noCertificate = ['cert_pem' => null, 'cert_key' => null, 'taxpayer_id' => null];
        $this->assertFalse($available('off', $noCertificate), 'do produkce bez certifikátu nejde nic');
        $this->assertTrue($available('playground', $noCertificate), 'pojistka playground = testovací identita');
        $this->assertTrue($available('off', $noCertificate + ['mode' => 'playground']), 'režim playground = testovací identita');
        $this->assertFalse($available('drop', $noCertificate));
    }

    public function testButtonAsksForConfirmationAndHasNoDialog(): void
    {
        $descriptor = $this->action()->describe(RegistrationDocument::TABLE, $this->record(), $this->context());

        $this->assertSame('Ověřit spojení', $descriptor->label);
        $this->assertSame('secondary', $descriptor->variant);
        $this->assertNull($descriptor->dialog);
        $this->assertStringContainsString('v ověřovacím módu', (string) $descriptor->confirm);
        $this->assertStringContainsString('nic se neeviduje', (string) $descriptor->confirm);
    }
}
