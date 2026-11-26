<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Actions;

use Shipard\Core\RecordAction\RecordAction;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionDescriptor;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionResult;
use Shipard\Core\RecordAction\RecordActionValidationException;
use Shipard\Module\Economy\SalesRegistration\Engine\SalesMessage;
use Shipard\Module\Economy\SalesRegistration\Engine\SendResult;
use Shipard\Module\Economy\SalesRegistration\Engine\SendStatus;
use Shipard\Module\Economy\SalesRegistration\RegistrationDocument;
use Shipard\Module\Economy\SalesRegistration\Safety\MessageRoute;
use Shipard\Module\Economy\SalesRegistration\Safety\MessageRouteResolver;
use Shipard\Module\Economy\SalesRegistration\Safety\MessageRouteText;
use Shipard\Module\Economy\SalesRegistration\Safety\PlaygroundIdentity;
use Shipard\Module\Economy\SalesRegistration\Safety\SalesRegistrationServerConfig;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationType;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Module\Economy\SalesRegistration\Sender\SalesRegistrationSender;
use Shipard\Module\Economy\SalesRegistration\Texts;
use Shipard\Module\Economy\SalesRegistration\UnitDocument;

/**
 * Akce „Ověřit spojení“ nad registrací (#171 U4): pošle zkušební zprávu
 * **vždy v ověřovacím módu** — výchozí jednotka registrace, pokladna
 * `SHIPARD-TEST`, pořadové číslo `TEST-<čas>`, částka 0,00. Nic se
 * neeviduje; ověří se certifikát, spojení a zpracovatelnost zprávy.
 *
 * Kam zpráva jde, řídí pojistka serveru a režim registrace jako u každé jiné
 * zprávy (`SalesRegistrationSender`) — výsledek říká prostředí, identitu,
 * kód a text chyby a varování služby.
 */
class VerifyConnectionAction implements RecordAction
{
    public const string CASH_REGISTER_ID = 'SHIPARD-TEST';
    public const string SERIAL_PREFIX = 'TEST-';
    public const string AMOUNT = '0.00';

    public function available(string $table, array $record, RecordActionContext $context): bool
    {
        if (!in_array((int) ($record['docState'] ?? 0), RegistrationDocument::LIVE_STATES, true)
            || (int) ($record['default_unit'] ?? 0) <= 0
            || $this->typeOf($record, $context) === null
        ) {
            return false;
        }
        if (trim((string) ($record['cert_pem'] ?? '')) !== '') {
            return true;
        }
        // Bez certifikátu jen tehdy, když zpráva půjde pod testovací identitou.
        $route = $this->route($record, $context);

        return !$route->dropped && $route->usesTestIdentity();
    }

    public function describe(string $table, array $record, RecordActionContext $context): RecordActionDescriptor
    {
        $t = Texts::of($context->config);

        return new RecordActionDescriptor(
            $t->t('verify.label', 'Verify connection'),
            RecordActionDescriptor::SECONDARY,
            confirm: $t->t('verify.confirm', 'A test message of 0.00 is sent in verification mode — nothing is registered. Continue?'),
        );
    }

    public function execute(
        string $table,
        array $record,
        RecordActionInput $input,
        RecordActionContext $context,
    ): RecordActionResult {
        $t = Texts::of($context->config);
        $type = $this->typeOf($record, $context);
        if ($type === null) {
            throw RecordActionValidationException::form($t->t('verify.errorType', 'The registration type is not available in this data source.'));
        }
        $unitId = $this->defaultUnitId($record, $context);
        if ($unitId === null) {
            throw RecordActionValidationException::form($t->t('verify.errorNoUnit', 'The registration has no default registration unit.'));
        }
        if ($context->dsConfig === null) {
            throw RecordActionValidationException::form($t->t('verify.errorNoDataSource', 'The connection cannot be verified outside a data source.'));
        }

        $now = $this->now();
        $message = new SalesMessage(
            uuid: SalesMessage::newUuid(),
            unitId: $unitId,
            cashRegisterId: self::CASH_REGISTER_ID,
            serialNumber: self::SERIAL_PREFIX . $now->format('YmdHis'),
            soldAt: $now,
            amount: self::AMOUNT,
            currency: $type->currency,
            firstSending: true,
            verification: true,
        );

        $result = $this->sender($context)->send($record, $message);

        return $this->present($result, $record, $type, $context, $t);
    }

    /** @param array<string, mixed> $record */
    private function present(SendResult $result, array $record, SalesRegistrationType $type, RecordActionContext $context, Texts $t): RecordActionResult
    {
        if ($result->status === SendStatus::Dropped) {
            $text = MessageRouteText::describe($this->route($record, $context), $type, $t);
            return RecordActionResult::warning($text['text']);
        }

        $where = $this->where($result, $record, $type, $t);

        if ($result->status === SendStatus::Verified) {
            $message = $t->t('verify.ok', 'Connection verified — {where}.', ['where' => $where]);
            if ($result->warnings === []) {
                return RecordActionResult::success($message);
            }
            return RecordActionResult::warning($message . ' ' . $t->t('verify.warnings', 'Warnings of the service: {warnings}', ['warnings' => self::warnings($result)]));
        }

        if (!$result->sent) {
            return RecordActionResult::warning(match ($result->problem) {
                SendResult::PROBLEM_NO_CERTIFICATE => $t->t('verify.noCertificate', 'The registration has no certificate — upload it first.'),
                SendResult::PROBLEM_KEY => $t->t('verify.keyUnusable', 'The certificate of the registration cannot be used — upload it again.'),
                SendResult::PROBLEM_NO_TEST_IDENTITY => $t->t('verify.noTestIdentity', 'This registration type has no test identity for the non-production environment.'),
                SendResult::PROBLEM_ENVIRONMENT => $t->t('verify.noEnvironment', 'This registration type has no address of the target environment.'),
                SendResult::PROBLEM_MESSAGE => $t->t('verify.messageInvalid', 'The test message could not be built: {text}', ['text' => (string) $result->errorText]),
                SendResult::PROBLEM_INTERNAL => $t->t('verify.internal', 'The test message could not be sent because of an internal error — see the server log.'),
                default => $t->t('verify.errorType', 'The registration type is not available in this data source.'),
            });
        }

        $params = ['where' => $where, 'code' => (string) ($result->errorCode ?? '—'), 'text' => (string) $result->errorText];

        return RecordActionResult::warning(match (true) {
            $result->status === SendStatus::RetryLater => $t->t('verify.retryLater', 'The service is temporarily unavailable ({where}) — code {code}: {text} Try again later.', $params),
            $result->status === SendStatus::Rejected => $t->t('verify.rejected', 'The service refused the message ({where}) — code {code}: {text}', $params),
            $result->untrusted => $t->t('verify.untrusted', 'The response of the service cannot be trusted ({where}): {text}', $params),
            default => $t->t('verify.noResponse', 'No valid response from the service ({where}): {text}', $params),
        });
    }

    /**
     * Prostředí a identita slovy: „produkce, certifikát registrace CZ…“.
     *
     * @param array<string, mixed> $record
     */
    private function where(SendResult $result, array $record, SalesRegistrationType $type, Texts $t): string
    {
        $environment = $result->environment === SalesRegistrationType::ENV_PRODUCTION
            ? $t->t('verify.envProduction', 'production')
            : $t->t('verify.envPlayground', 'playground');
        $identity = $result->testIdentity
            ? $t->t('verify.identityTest', 'shared test identity {taxpayerId}', ['taxpayerId' => (string) PlaygroundIdentity::forType($type->id)?->taxpayerId()])
            : $t->t('verify.identityRegistration', 'certificate of the registration {taxpayerId}', ['taxpayerId' => trim((string) ($record['taxpayer_id'] ?? ''))]);

        return $environment . ', ' . trim($identity);
    }

    private static function warnings(SendResult $result): string
    {
        $parts = [];
        foreach ($result->warnings as $code => $text) {
            $parts[] = $code . ' — ' . $text;
        }
        return implode('; ', $parts);
    }

    /** @param array<string, mixed> $record */
    private function route(array $record, RecordActionContext $context): MessageRoute
    {
        return MessageRouteResolver::resolve($this->safety($context), (string) ($record['mode'] ?? ''), true);
    }

    /** @param array<string, mixed> $record */
    private function typeOf(array $record, RecordActionContext $context): ?SalesRegistrationType
    {
        return SalesRegistrationTypes::fromConfig($context->config)->get((string) ($record['type'] ?? ''));
    }

    /**
     * Číslo výchozí jednotky registrace, null = jednotka chybí nebo
     * registraci nepatří (přepsatelné v testech).
     *
     * @param array<string, mixed> $record
     */
    protected function defaultUnitId(array $record, RecordActionContext $context): ?string
    {
        $row = $context->db->fetchRow(
            'SELECT `unit_id` FROM `' . UnitDocument::TABLE . '` WHERE `id` = %i AND `registration` = %i',
            (int) ($record['default_unit'] ?? 0),
            (int) ($record['id'] ?? 0),
        );
        $unitId = trim((string) ($row['unit_id'] ?? ''));

        return $unitId !== '' ? $unitId : null;
    }

    /** Pojistka serveru; bez `ServerConfig` v kontextu si `server.json` načte sama (přepsatelné v testech). */
    protected function safety(RecordActionContext $context): SalesRegistrationServerConfig
    {
        return SalesRegistrationServerConfig::forServer($context->serverConfig);
    }

    /** Odesílač (přepsatelné v testech). */
    protected function sender(RecordActionContext $context): SalesRegistrationSender
    {
        return new SalesRegistrationSender($context->config, $context->dsConfig, $this->safety($context));
    }

    /** Teď (přepsatelné v testech). */
    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
