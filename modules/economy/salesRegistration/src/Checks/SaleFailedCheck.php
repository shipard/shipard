<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Checks;

use Shipard\Core\Alerts\AlertFinding;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleState;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Upozornění `economy.salesregistration.sale_failed` (#171 D25): službu
 * tržbu odmítla nebo zpráva nebyla platná. Sama se už neopakuje — po
 * odstranění příčiny akce Odeslat znovu.
 */
class SaleFailedCheck extends SaleCheck
{
    protected function states(): array
    {
        return [SaleState::FAILED];
    }

    protected function olderThanSec(): ?int
    {
        return null;
    }

    protected function youngerThanSec(): ?int
    {
        return null;
    }

    protected function finding(array $group, Texts $t): AlertFinding
    {
        return $this->build(
            $group,
            'error',
            $t->t('alert.failedTitle', 'Sales rejected by the service: {count} ({name})'),
            $t->t(
                'alert.failedMessage',
                'The sales registration service rejected sales of registration {name} — the oldest from {oldest}. They are not sent again automatically: find the reason in the Attempts tab and use Send again.',
            ),
            $t,
        );
    }
}
