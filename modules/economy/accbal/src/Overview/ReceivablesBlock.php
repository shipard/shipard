<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal\Overview;

use Shipard\Core\Feed\FeedTexts;
use Shipard\Module\Economy\Accbal\CaseQuery;

/** Přehled firmy → Pohledávky (#120 D6): celkem + po splatnosti, per měna. */
final class ReceivablesBlock extends AccbalBlockBase
{
    protected function groupCode(): string
    {
        return 'receivables';
    }

    protected function itemSpecs(FeedTexts $texts): array
    {
        return [
            ['id' => 'total', 'label' => $texts->t('receivables.total', 'Receivables'), 'filters' => ['kind' => CaseQuery::KIND_DEBT]],
            ['id' => 'overdue', 'label' => $texts->t('receivables.overdue', 'Overdue receivables'), 'filters' => ['kind' => CaseQuery::KIND_DEBT, 'overdue' => '1']],
        ];
    }
}
