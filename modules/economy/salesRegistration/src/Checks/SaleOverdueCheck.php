<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Checks;

use Shipard\Core\Alerts\AlertFinding;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleState;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Upozornění `economy.salesregistration.sale_overdue` (#171 D13): tržba
 * není potvrzená ani 48 hodin po svém čase. Odesílání nepřestává — povinnost
 * trvá i po lhůtě (D7).
 */
class SaleOverdueCheck extends SaleCheck
{
    protected function states(): array
    {
        return SaleState::OPEN;
    }

    protected function olderThanSec(): ?int
    {
        return self::OVERDUE_SEC;
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
            $t->t('alert.overdueTitle', 'Sales not registered within 48 hours: {count} ({name})'),
            $t->t(
                'alert.overdueMessage',
                'Sales of registration {name} are still not confirmed 48 hours after the sale — the oldest since {oldest}. Sending continues, the obligation lasts. Check the Attempts tab of the sales and the certificate of the registration.',
            ),
            $t,
        );
    }
}
