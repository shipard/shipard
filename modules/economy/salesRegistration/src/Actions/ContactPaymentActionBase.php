<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Actions;

use Shipard\Core\RecordAction\RecordAction;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionResult;
use Shipard\Core\RecordAction\RecordActionValidationException;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentOutcome;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentPolicy;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentService;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFactsLoader;
use Shipard\Module\Economy\SalesRegistration\Evaluation\Money;
use Shipard\Module\Economy\SalesRegistration\Evaluation\ReasonTexts;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleState;
use Shipard\Module\Economy\SalesRegistration\Safety\SalesRegistrationServerConfig;
use Shipard\Module\Economy\SalesRegistration\Sales\SyncResult;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Společný základ akcí **Kontaktní platba** a **Zrušit kontaktní platbu**
 * nad dokladem V pořádku (#171 U17): sestavení služby z kontextu akce
 * a převod jejího výsledku na hlášku — potvrzeno / čeká ve frontě / nejde
 * založit a proč.
 *
 * Akce nejsou jen pro správce (deklarace bez `admin`, jako Odeslat znovu):
 * smí je každý, kdo pracuje s doklady. Zdroj dat jen ke čtení odmítne už
 * endpoint akcí; zámek dokladu ani podaného období DPH tu nevadí — obsah
 * dokladu ani zaúčtování se nemění.
 */
abstract class ContactPaymentActionBase implements RecordAction
{
    public const int STATE_CONFIRMED = 40;

    /** Je doklad ve stavu, ve kterém akce dávají smysl (V pořádku, ne import)? */
    protected function isConfirmed(array $record): bool
    {
        return (int) ($record['docState'] ?? 0) === self::STATE_CONFIRMED && empty($record['imported']);
    }

    /** Hláška podle toho, co se s tržbou stalo. */
    protected function result(ContactPaymentOutcome $outcome, RecordActionContext $context): RecordActionResult
    {
        $t = Texts::of($context->config);
        $sync = $outcome->sync;

        if ($sync === null || $sync->status === SyncResult::BUSY) {
            return RecordActionResult::info($t->t(
                'contactPayment.resultLater',
                'The change was saved; the sale is created and sent automatically within a few minutes.',
            ));
        }
        if ($sync->status === SyncResult::NONE) {
            return RecordActionResult::info($t->t('contactPayment.resultNone', 'The change was saved; there is no sale to register.'));
        }
        if ($sync->status === SyncResult::UNREGISTRABLE) {
            return RecordActionResult::warning($t->t(
                'contactPayment.resultUnregistrable',
                'The change was saved, but the sale cannot be created: {reason} Once the settings are fixed, the sale is created automatically with the original time.',
                ['reason' => ReasonTexts::of($context->config)->text((string) $sync->reason)],
            ));
        }

        $params = [
            'number' => (string) ($sync->sale['serial_number'] ?? ''),
            'amount' => Money::display(Money::cents($sync->sale['amount'] ?? 0), (string) ($sync->sale['currency'] ?? ''), $context->language),
        ];
        $attempt = $outcome->attempt;
        if ($attempt === null) {
            return RecordActionResult::info($t->t(
                'contactPayment.resultQueued',
                'Sale {number} for {amount} was created and waits in the queue; it is sent automatically.',
                $params,
            ));
        }
        return match ($attempt->state) {
            SaleState::CONFIRMED => RecordActionResult::success($t->t(
                'contactPayment.resultConfirmed',
                'Sale {number} for {amount} was registered and confirmed.',
                $params,
            )),
            SaleState::VERIFIED => RecordActionResult::success($t->t(
                'contactPayment.resultVerified',
                'Sale {number} for {amount} was verified — the registration is in verification mode, nothing was registered.',
                $params,
            )),
            SaleState::DROPPED => RecordActionResult::warning($t->t(
                'contactPayment.resultDropped',
                'Sale {number} for {amount} was created, but the message was stopped by the safety switch of this server — nothing was sent.',
                $params,
            )),
            SaleState::FAILED => RecordActionResult::warning($t->t(
                'contactPayment.resultFailed',
                'Sale {number} for {amount} was created, but the service rejected it: {error}',
                $params + ['error' => trim((string) ($attempt->result->errorText ?? '')) ?: (string) ($attempt->result->errorCode ?? '')],
            )),
            default => RecordActionResult::warning($t->t(
                'contactPayment.resultRetry',
                'Sale {number} for {amount} was created, but it could not be sent now; the next attempt is scheduled for {next}.',
                $params + ['next' => (string) $attempt->nextAttempt],
            )),
        };
    }

    /** Doklad se mezitím změnil — akce už neplatí. */
    protected function conflict(RecordActionContext $context): RecordActionValidationException
    {
        return RecordActionValidationException::form(Texts::of($context->config)->t(
            'contactPayment.errorConflict',
            'The document has changed in the meantime — reload it and try again.',
        ));
    }

    // ── Sestavení z kontextu (přepsatelné v testech) ────────────────────────

    protected function loader(RecordActionContext $context): DocFactsLoader
    {
        return new DocFactsLoader($context->db->getDibiConnection(), $context->config, $context->dsConfig);
    }

    protected function policy(RecordActionContext $context): ContactPaymentPolicy
    {
        return ContactPaymentPolicy::fromLoader($this->loader($context));
    }

    /** Služba s pojistkou serveru z kontextu akce; null = mimo zdroj dat. */
    protected function service(RecordActionContext $context): ?ContactPaymentService
    {
        if ($context->dsConfig === null) {
            return null;
        }
        return ContactPaymentService::create(
            $context->db->getDibiConnection(),
            $context->config,
            $context->dsConfig,
            SalesRegistrationServerConfig::forServer($context->serverConfig),
        );
    }
}
