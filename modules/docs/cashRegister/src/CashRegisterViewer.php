<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\CashRegister;

use Shipard\Module\Docs\Core\DocsHeadsViewer;

/**
 * Viewer Prodejky (Prodej) — `doc_type = 'cashreg'`, záložky = řady per
 * pokladna. Řádek: t1 partner nebo text dokladu, t2 datum a způsob úhrady
 * (bez splatnosti a názvu typu); částka záporná jen u vratky (#59 D9).
 */
class CashRegisterViewer extends DocsHeadsViewer
{
    protected ?string $scopedDocType = 'cashreg';

    public function renderRow(array $rowData): array
    {
        $row = parent::renderRow($rowData);

        $t2 = [];
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
}
