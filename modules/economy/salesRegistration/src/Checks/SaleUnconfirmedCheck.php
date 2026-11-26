<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Checks;

use Shipard\Core\Alerts\AlertFinding;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleState;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Upozornění `economy.salesregistration.sale_unconfirmed` (#171 D13):
 * tržba čeká na potvrzení déle než hodinu. Po 48 hodinách ji přebírá
 * {@see SaleOverdueCheck}.
 */
class SaleUnconfirmedCheck extends SaleCheck
{
    protected function states(): array
    {
        return SaleState::OPEN;
    }

    protected function olderThanSec(): ?int
    {
        return self::UNCONFIRMED_SEC;
    }

    protected function youngerThanSec(): ?int
    {
        return self::OVERDUE_SEC;
    }

    protected function finding(array $group, Texts $t): AlertFinding
    {
        return $this->build(
            $group,
            'warning',
            $t->t('alert.unconfirmedTitle', 'Sales waiting for confirmation: {count} ({name})'),
            $t->t(
                'alert.unconfirmedMessage',
                'Sales of registration {name} have been waiting for confirmation for more than an hour — the oldest since {oldest}. They are sent again automatically; the reason is in the Attempts tab of the sale. Sales must be registered within 48 hours.',
            ),
            $t,
        );
    }
}
