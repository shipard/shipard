<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Country\Cz;

use Ondrejnov\EET\Certificate;
use Ondrejnov\EET\Dispatcher;
use Ondrejnov\EET\Exceptions\ClientException;
use Ondrejnov\EET\Exceptions\ResponseVerificationException;
use Ondrejnov\EET\Exceptions\ServerException;
use Ondrejnov\EET\Exceptions\TransportException;
use Ondrejnov\EET\Exceptions\ValidationException;
use Ondrejnov\EET\Http\CurlTransport;
use Ondrejnov\EET\Http\Transport;
use Ondrejnov\EET\Receipt;
use Ondrejnov\EET\Security\ResponseVerifier;
use Shipard\Module\Economy\SalesRegistration\Engine\EngineEnvironment;
use Shipard\Module\Economy\SalesRegistration\Engine\SalesMessage;
use Shipard\Module\Economy\SalesRegistration\Engine\SalesRegistrationEngine;
use Shipard\Module\Economy\SalesRegistration\Engine\SenderIdentity;
use Shipard\Module\Economy\SalesRegistration\Engine\SendResult;
use Shipard\Module\Economy\SalesRegistration\Engine\SendStatus;

/**
 * Engine typu `cz.eet2` — česká EET 2.0, datové rozhraní v4.1 (#171 D12).
 * Tenká vrstva nad knihovnou `ondrejnov/eet` (pinovaný commit): knihovna
 * zprávu sestaví, podepíše, odešle a ověří podpis potvrzení; tady se její
 * výsledky a výjimky převádějí na obecný {@see SendResult}.
 *
 * | Knihovna                                   | Stav                         |
 * |--------------------------------------------|------------------------------|
 * | `send()` vrátí POK                         | `confirmed`                  |
 * | `check()` vrátí true (chyba 0)             | `verified`                   |
 * | chyba služby −1 nebo 8                     | `retryLater`                 |
 * | jiná chyba služby                          | `rejected`                   |
 * | `TransportException`                       | `unknown`                    |
 * | `ResponseVerificationException`            | `unknown` + `untrusted`, POK se nepřebírá |
 * | `ValidationException`, nečitelný certifikát | `rejected`, nic neodešlo     |
 *
 * `check()` chybu služby polyká a vrací false, proto se ověřovací mód
 * vyhodnocuje z `getLastResponse()`.
 *
 * Potvrzení se ověřuje proti řetězu I.CA z knihovny a `O = Generální
 * finanční ředitelství` v **obou** prostředích — dokument finanční správy
 * uvádí pro produkci téhož vydavatele. Proti živé produkci to ale ověřené
 * není (docs/sales-registration.md §6).
 */
final class Eet2Engine implements SalesRegistrationEngine
{
    /** Limity knihovny: 2,5 s na požadavek, 2 s na spojení. */
    public const int TIMEOUT_MS = 2500;
    public const int CONNECT_TIMEOUT_MS = 2000;

    /** Čas tržby jde v zóně místa tržby. */
    public const string TIMEZONE = 'Europe/Prague';

    /**
     * Bez argumentů = ostrý provoz. Transport a verifier jdou podvrhnout
     * v testech (falešný transport nad vzorovými odpověďmi).
     */
    public function __construct(
        private readonly ?Transport $transport = null,
        private readonly ?ResponseVerifier $verifier = null,
    ) {
    }

    public function send(SalesMessage $message, SenderIdentity $identity, EngineEnvironment $environment): SendResult
    {
        // Doplňky zprávy (čerpání a zúčtování, pověřující poplatník) fáze 1
        // neumí — raději odmítnout, než je z daňové zprávy tiše vypustit.
        if ($message->extras !== []) {
            return SendResult::notSent(
                SendStatus::Rejected,
                SendResult::PROBLEM_MESSAGE,
                'Unsupported message items: ' . implode(', ', array_map('strval', array_keys($message->extras))),
            );
        }

        try {
            $certificate = Certificate::fromPem($identity->keyPath, $identity->certificatePath);
        } catch (ClientException $e) {
            return SendResult::notSent(SendStatus::Rejected, SendResult::PROBLEM_KEY, 'The signing certificate cannot be read.');
        }

        $dispatcher = new Dispatcher(
            $environment->url,
            $certificate,
            $this->verifier ?? ResponseVerifier::forPlayground(),
            $this->transport ?? new CurlTransport(self::TIMEOUT_MS, self::CONNECT_TIMEOUT_MS),
        );

        try {
            $receipt = $this->receipt($message);
            $receipt->validate();
        } catch (ValidationException $e) {
            return SendResult::notSent(SendStatus::Rejected, SendResult::PROBLEM_MESSAGE, implode('; ', $e->getErrors()));
        } catch (ClientException $e) {
            return SendResult::notSent(SendStatus::Rejected, SendResult::PROBLEM_MESSAGE, $e->getMessage());
        }

        try {
            if ($message->verification) {
                return $dispatcher->check($receipt)
                    ? $this->result(SendStatus::Verified, $dispatcher)
                    : $this->refused($dispatcher);
            }

            return $this->result(SendStatus::Confirmed, $dispatcher, confirmationCode: $dispatcher->send($receipt));
        } catch (ResponseVerificationException $e) {
            // Potomek ServerException — musí být první. POK z odpovědi se nepřebírá.
            return $this->result(SendStatus::Unknown, $dispatcher, errorText: $e->getMessage(), untrusted: true);
        } catch (ServerException $e) {
            return $this->result(
                $e->isRetryable() ? SendStatus::RetryLater : SendStatus::Rejected,
                $dispatcher,
                errorCode: $e->getCode(),
                errorText: $e->getMessage(),
            );
        } catch (TransportException $e) {
            return $this->result(SendStatus::Unknown, $dispatcher, errorText: $e->getMessage());
        } catch (ClientException $e) {
            return $this->result(SendStatus::Rejected, $dispatcher, errorText: $e->getMessage(), problem: SendResult::PROBLEM_MESSAGE);
        }
    }

    private function receipt(SalesMessage $message): Receipt
    {
        $receipt = new Receipt();
        $receipt->uuid_zpravy = $message->uuid;
        $receipt->prvni_zaslani = $message->firstSending;
        $receipt->eic_popl = $message->taxpayerId;
        $receipt->id_jednotky = $message->unitId;
        $receipt->id_pokl = $message->cashRegisterId;
        $receipt->porad_cis = $message->serialNumber;
        $receipt->dat_trzby = $message->soldAt->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $receipt->celk_trzba = $message->amount;

        return $receipt;
    }

    /** Ověřovací mód skončil chybou služby — `check()` ji spolkl, je v poslední odpovědi. */
    private function refused(Dispatcher $dispatcher): SendResult
    {
        $response = $dispatcher->getLastResponse();
        $code = $response?->errorCode;
        if ($code === null) {
            return $this->result(SendStatus::Unknown, $dispatcher, errorText: 'The verification response carries no result.');
        }

        return $this->result(
            in_array($code, ServerException::RETRYABLE_CODES, true) ? SendStatus::RetryLater : SendStatus::Rejected,
            $dispatcher,
            errorCode: $code,
            errorText: $response->errorMessage ?: (Dispatcher::ERROR_MESSAGES[$code] ?? null),
        );
    }

    private function result(
        SendStatus $status,
        Dispatcher $dispatcher,
        ?string $confirmationCode = null,
        ?int $errorCode = null,
        ?string $errorText = null,
        bool $untrusted = false,
        ?string $problem = null,
    ): SendResult {
        $response = $dispatcher->getLastResponse();
        $duration = $dispatcher->getLastResponseTime();

        return new SendResult(
            status: $status,
            sent: $dispatcher->getLastRequest() !== null,
            confirmationCode: $confirmationCode,
            errorCode: $errorCode,
            errorText: $errorText,
            warnings: $untrusted ? [] : ($response?->warnings ?? []),
            transactionId: $response?->transactionId,
            requestBody: $dispatcher->getLastRequest(),
            responseBody: $dispatcher->getLastResponseBody(),
            durationMs: $duration !== null ? (int) round($duration * 1000) : null,
            test: !$untrusted && ($response?->test ?? false),
            untrusted: $untrusted,
            problem: $problem,
        );
    }
}
