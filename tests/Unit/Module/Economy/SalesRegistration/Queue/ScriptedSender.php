<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Queue;

use Shipard\Module\Economy\SalesRegistration\Engine\SalesMessage;
use Shipard\Module\Economy\SalesRegistration\Engine\SendResult;
use Shipard\Module\Economy\SalesRegistration\Engine\SendStatus;
use Shipard\Module\Economy\SalesRegistration\Sender\SalesRegistrationSender;

/**
 * Odesílač, který nic neposílá: vrací připravené výsledky (nebo hodí
 * výjimku) a pamatuje si zprávy, které dostal.
 */
final class ScriptedSender extends SalesRegistrationSender
{
    /** @var list<SendResult|\Throwable> */
    public array $script = [];

    /** @var list<SalesMessage> */
    public array $messages = [];

    /** @var list<array<string, mixed>> */
    public array $registrations = [];

    public function __construct()
    {
        // Bez konfigurace — send() je celý přepsaný.
    }

    public function send(array $registration, SalesMessage $message): SendResult
    {
        $this->registrations[] = $registration;
        $this->messages[] = $message;
        $next = array_shift($this->script) ?? self::confirmed();
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }

    public static function confirmed(string $code = 'pok-1', string $environment = 'production', bool $testIdentity = false): SendResult
    {
        return (new SendResult(SendStatus::Confirmed, true, $code, transactionId: 'tx-1', requestBody: '<req/>', responseBody: '<res/>', durationMs: 120))
            ->withRoute($environment, $testIdentity, false);
    }

    public static function verified(): SendResult
    {
        return (new SendResult(SendStatus::Verified, true, durationMs: 90))->withRoute('production', false, true);
    }

    public static function dropped(): SendResult
    {
        return SendResult::notSent(SendStatus::Dropped, SendResult::PROBLEM_SAFETY, 'default');
    }

    public static function rejectedByService(int $code = 5, string $text = 'Neplatná data'): SendResult
    {
        return (new SendResult(SendStatus::Rejected, true, errorCode: $code, errorText: $text, durationMs: 80))
            ->withRoute('production', false, false);
    }

    public static function retryLater(): SendResult
    {
        return (new SendResult(SendStatus::RetryLater, true, errorCode: -1, errorText: 'Dočasná technická chyba'))
            ->withRoute('production', false, false);
    }

    public static function unknown(): SendResult
    {
        return (new SendResult(SendStatus::Unknown, true, errorText: 'timeout'))->withRoute('production', false, false);
    }

    public static function ourProblem(string $problem): SendResult
    {
        return SendResult::notSent(SendStatus::Rejected, $problem)->withRoute('production', false, false);
    }
}
