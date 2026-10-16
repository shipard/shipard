<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal\Overview;

use Shipard\Core\Feed\FeedTexts;
use Shipard\Module\Economy\Accbal\CaseQuery;

/** Přehled firmy → Závazky (#120 D6): po splatnosti + splatné do 7 dní, per měna. */
final class PayablesBlock extends AccbalBlockBase
{
    /** Horizont „splatné do N dní" (D6). */
    public const DUE_WITHIN_DAYS = 7;

    protected function groupCode(): string
    {
        return 'payables';
    }

    protected function itemSpecs(FeedTexts $texts): array
    {
        return [
            ['id' => 'overdue', 'label' => $texts->t('payables.overdue', 'Overdue payables'), 'filters' => ['kind' => CaseQuery::KIND_DEBT, 'overdue' => '1']],
            ['id' => 'dueSoon', 'label' => $texts->t('payables.dueSoon', 'Payables due within 7 days'), 'filters' => ['kind' => CaseQuery::KIND_DEBT, 'dueWithin' => (string) self::DUE_WITHIN_DAYS]],
        ];
    }
}
