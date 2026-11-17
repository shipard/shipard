<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\CashDocs;

use Shipard\Module\Docs\Core\CashDirection;
use Shipard\Module\Docs\Core\DocsHeadsViewer;

/**
 * Viewer Pokladní doklady (Účtárna) — `doc_type = 'cash'`, záložky = řady
 * per pokladna. Řádek: t1 partner nebo text dokladu, t2 směr (Příjem /
 * Výdej — výdej tlumeně), datum a způsob úhrady; částka vždy kladná, směr
 * nese štítek (záporné částky jsou vratky, #59 D9).
 */
class CashDocsViewer extends DocsHeadsViewer
{
    protected ?string $scopedDocType = 'cash';

    public function renderRow(array $rowData): array
    {
        $row = parent::renderRow($rowData);

        $t2 = [];
        $direction = CashDirection::tryFrom((int) ($rowData['cash_dir'] ?? 0));
        $dirLabel = $this->resolveCashDirectionLabel($direction);
        if ($dirLabel !== null) {
            $t2[] = $direction === CashDirection::Disbursement
                ? ['text' => $dirLabel, 'class' => 'muted']
                : ['text' => $dirLabel];
        }
        $issueDate = $this->formatDate($rowData['issue_date'] ?? null);
        if ($issueDate !== null) {
            $t2[] = ['text' => $issueDate];
        }
        $paymentMethod = $this->resolvePaymentMethodLabel($rowData['payment_method'] ?? null);
        if ($paymentMethod !== null) {
            $t2[] = ['text' => $paymentMethod, 'class' => 'muted'];
        }
        $stateTag = $this->rowStateTag((int) ($rowData['docState'] ?? 10));
        if ($stateTag !== null) {
            $t2[] = $stateTag;
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        return $row;
    }

    /** Lokalizovaný název směru z cfgItem docs.core.cashDirections; null pro 0 / neznámý. */
    private function resolveCashDirectionLabel(?CashDirection $direction): ?string
    {
        if ($direction === null || $direction === CashDirection::NotApplicable || $this->config === null) {
            return null;
        }
        $cfg = $this->config->cfgItem('docs.core.cashDirections');
        $key = (string) $direction->value;
        if (!is_array($cfg) || !isset($cfg[$key]['name'])) {
            return null;
        }
        return (string) $cfg[$key]['name'];
    }
}
