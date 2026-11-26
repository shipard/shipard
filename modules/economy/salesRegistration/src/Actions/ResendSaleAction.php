<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Actions;

use Shipard\Core\RecordAction\RecordAction;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionDescriptor;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionResult;
use Shipard\Core\RecordAction\RecordActionValidationException;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleQueue;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleState;
use Shipard\Module\Economy\SalesRegistration\Safety\SalesRegistrationServerConfig;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Akce **Odeslat znovu** nad tržbou (#171 U12): tržbu ve stavu `failed`
 * (odmítnutou službou) nebo `pending` (čeká na další pokus) vrátí do fronty
 * s okamžitým termínem a hned ji zkusí odeslat — stejnou cestou jako cron.
 * Data tržby se nemění; vzniká nový pokus s novým UUID.
 *
 * Není jen pro správce: smí ji každý, kdo pracuje s doklady (deklarace
 * bez `admin`). Zdroj dat jen ke čtení odmítne už endpoint akcí.
 */
class ResendSaleAction implements RecordAction
{
    public function available(string $table, array $record, RecordActionContext $context): bool
    {
        return in_array((string) ($record['state'] ?? ''), SaleState::RESENDABLE, true);
    }

    public function describe(string $table, array $record, RecordActionContext $context): RecordActionDescriptor
    {
        $t = Texts::of($context->config);

        return new RecordActionDescriptor(
            $t->t('resend.label', 'Send again'),
            RecordActionDescriptor::SECONDARY,
            confirm: $t->t('resend.confirm', 'The sale is sent to the sales registration service again with the same data. Continue?'),
        );
    }

    public function execute(
        string $table,
        array $record,
        RecordActionInput $input,
        RecordActionContext $context,
    ): RecordActionResult {
        $t = Texts::of($context->config);
        $queue = $this->queue($context);
        if ($queue === null) {
            throw RecordActionValidationException::form($t->t('resend.errorNoDataSource', 'The sale cannot be sent outside a data source.'));
        }
        $saleId = (int) ($record['id'] ?? 0);
        if (!$queue->resend($saleId)) {
            throw RecordActionValidationException::form($t->t('resend.errorState', 'The sale is not waiting to be sent — it has been settled in the meantime.'));
        }

        $attempt = $queue->attempt($saleId);
        if ($attempt === null) {
            return RecordActionResult::info($t->t('resend.queued', 'The sale was returned to the queue; another process is sending it right now.'));
        }

        return match ($attempt->state) {
            SaleState::CONFIRMED => RecordActionResult::success($t->t('resend.confirmed', 'The sale was confirmed.')),
            SaleState::VERIFIED  => RecordActionResult::success($t->t('resend.verified', 'The sale was verified — the registration is in verification mode, nothing was registered.')),
            SaleState::DROPPED   => RecordActionResult::warning($t->t('resend.dropped', 'The message was stopped by the safety switch of this server — nothing was sent.')),
            SaleState::FAILED    => RecordActionResult::warning($t->t(
                'resend.failed',
                'The service rejected the sale: {error}',
                ['error' => trim((string) ($attempt->result->errorText ?? '')) ?: (string) ($attempt->result->errorCode ?? '')],
            )),
            default              => RecordActionResult::warning($t->t(
                'resend.retry',
                'The sale could not be sent now; the next attempt is scheduled for {next}. See the Attempts tab for the reason.',
                ['next' => (string) $attempt->nextAttempt],
            )),
        };
    }

    /** Fronta s pojistkou serveru z kontextu akce (přepsatelné v testech). */
    protected function queue(RecordActionContext $context): ?SaleQueue
    {
        if ($context->dsConfig === null) {
            return null;
        }
        return SaleQueue::create(
            $context->db->getDibiConnection(),
            $context->config,
            $context->dsConfig,
            SalesRegistrationServerConfig::forServer($context->serverConfig),
        );
    }
}
