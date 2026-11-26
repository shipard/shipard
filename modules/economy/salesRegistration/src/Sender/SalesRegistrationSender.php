<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Sender;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Module\Economy\SalesRegistration\Certificate\KeyFiles;
use Shipard\Module\Economy\SalesRegistration\Engine\EngineEnvironment;
use Shipard\Module\Economy\SalesRegistration\Engine\SalesMessage;
use Shipard\Module\Economy\SalesRegistration\Engine\SalesRegistrationEngine;
use Shipard\Module\Economy\SalesRegistration\Engine\SenderIdentity;
use Shipard\Module\Economy\SalesRegistration\Engine\SendResult;
use Shipard\Module\Economy\SalesRegistration\Engine\SendStatus;
use Shipard\Module\Economy\SalesRegistration\Safety\MessageRoute;
use Shipard\Module\Economy\SalesRegistration\Safety\MessageRouteResolver;
use Shipard\Module\Economy\SalesRegistration\Safety\PlaygroundIdentity;
use Shipard\Module\Economy\SalesRegistration\Safety\SalesRegistrationServerConfig;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationType;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;

/**
 * Odeslání jedné zprávy evidence tržeb za registraci (#171 D9–D12) — obecné
 * pro všechny typy evidence:
 *
 *  1. typ registrace → engine (`SalesRegistrationTypes`);
 *  2. pojistka serveru + režim registrace → prostředí, identita, ověřovací
 *     mód (`MessageRouteResolver`); `drop` = výsledek `dropped` bez sítě;
 *  3. identita: certifikát registrace (klíč dešifrovaný až tady), nebo
 *     sdílená testovací identita — s ní jde ve zprávě **její** identifikátor
 *     poplatníka;
 *  4. dočasné soubory klíče (`KeyFiles`) jen po dobu volání enginu;
 *  5. log: registrace, typ, prostředí, stav, kódy, `transactionId`, doba —
 *     bez klíče, hesla a těl zpráv.
 *
 * Nic neukládá a neopakuje — žurnál pokusů a opakování staví fáze 2 nad
 * vráceným {@see SendResult}.
 */
class SalesRegistrationSender
{
    public function __construct(
        private readonly ?ConfigRuntime $config,
        private readonly DataSourceConfig $dsConfig,
        private readonly SalesRegistrationServerConfig $safety,
    ) {
    }

    /**
     * @param array<string, mixed> $registration řádek registrace včetně `cert_pem` a `cert_key`
     */
    public function send(array $registration, SalesMessage $message): SendResult
    {
        $result = $this->dispatch($registration, $message);

        ErrorLogger::info('sales registration: message ' . $result->status->value, [
            'registration' => (int) ($registration['id'] ?? 0),
            'type'         => (string) ($registration['type'] ?? ''),
            'uuid'         => $message->uuid,
        ] + $result->logContext());

        return $result;
    }

    /** @param array<string, mixed> $registration */
    private function dispatch(array $registration, SalesMessage $message): SendResult
    {
        $route = MessageRouteResolver::resolve($this->safety, (string) ($registration['mode'] ?? ''), $message->verification);
        if ($route->dropped) {
            return SendResult::notSent(SendStatus::Dropped, SendResult::PROBLEM_SAFETY, $route->dropReason);
        }

        $type = SalesRegistrationTypes::fromConfig($this->config)->get((string) ($registration['type'] ?? ''));
        $engine = $type !== null ? $this->engine($type) : null;
        if ($type === null || $engine === null) {
            return $this->notSent(SendResult::PROBLEM_TYPE, $route);
        }
        $url = $type->environmentUrl((string) $route->environment);
        if ($url === null) {
            return $this->notSent(SendResult::PROBLEM_ENVIRONMENT, $route);
        }

        if ($route->usesTestIdentity()) {
            $identity = $this->testIdentity($type);
            if ($identity === null) {
                return $this->notSent(SendResult::PROBLEM_NO_TEST_IDENTITY, $route);
            }
            [$keyPem, $certificatePem, $taxpayerId] = [$identity->keyPem(), $identity->certificatePem(), $identity->taxpayerId()];
        } else {
            $certificatePem = (string) ($registration['cert_pem'] ?? '');
            $encryptedKey = trim((string) ($registration['cert_key'] ?? ''));
            $taxpayerId = trim((string) ($registration['taxpayer_id'] ?? ''));
            if (trim($certificatePem) === '' || $encryptedKey === '' || $taxpayerId === '') {
                return $this->notSent(SendResult::PROBLEM_NO_CERTIFICATE, $route);
            }
            try {
                // Dešifrovat až těsně před použitím; plaintext žije jen v této metodě.
                $keyPem = DsSecretCipher::forConfig($this->dsConfig)->decrypt($encryptedKey);
            } catch (\Throwable $e) {
                ErrorLogger::logException($e, 'sales registration: private key of the registration cannot be decrypted');
                return $this->notSent(SendResult::PROBLEM_KEY, $route);
            }
        }

        $routed = $message->routed($taxpayerId, $route->verification);
        $environment = new EngineEnvironment((string) $route->environment, $url);
        $test = $route->usesTestIdentity();

        try {
            $result = $this->keyFiles()->with(
                $keyPem,
                $certificatePem,
                static fn(string $keyPath, string $certificatePath): SendResult
                    => $engine->send($routed, new SenderIdentity($keyPath, $certificatePath, $taxpayerId, $test), $environment),
            );
        } catch (\Throwable $e) {
            // Dočasný soubor klíče nejde založit, nebo engine porušil kontrakt a vyhodil.
            ErrorLogger::logException($e, 'sales registration: message cannot be handed to the engine');
            return $this->notSent(SendResult::PROBLEM_INTERNAL, $route);
        }

        return $result->withRoute($environment->name, $test, $route->verification);
    }

    private function notSent(string $problem, MessageRoute $route): SendResult
    {
        return SendResult::notSent(SendStatus::Rejected, $problem)
            ->withRoute((string) $route->environment, $route->usesTestIdentity(), $route->verification);
    }

    /** Instance engine typu, null = třída chybí nebo není engine (přepsatelné v testech). */
    protected function engine(SalesRegistrationType $type): ?SalesRegistrationEngine
    {
        $class = $type->engineClass;
        if (!class_exists($class) || !is_subclass_of($class, SalesRegistrationEngine::class)) {
            ErrorLogger::warn('sales registration: engine class is not usable', ['type' => $type->id, 'class' => $class]);
            return null;
        }
        return new $class();
    }

    /** Testovací identita typu (přepsatelné v testech). */
    protected function testIdentity(SalesRegistrationType $type): ?PlaygroundIdentity
    {
        return PlaygroundIdentity::forType($type->id);
    }

    /** Dočasné soubory klíče ve zdroji dat (přepsatelné v testech). */
    protected function keyFiles(): KeyFiles
    {
        return KeyFiles::forConfig($this->dsConfig);
    }
}
