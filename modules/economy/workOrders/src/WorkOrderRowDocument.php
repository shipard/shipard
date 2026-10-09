<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Economy\WorkOrders\Invoicing\Contributor\InvoiceContributorRegistry;

/**
 * Řádek zakázky (economy_work_orders_rows, docs/work-orders.md §5.3) —
 * fakturační předpis periodické zakázky. Tvarová validace (čísla, platnost
 * od ≤ do, tvar id přispěvatele) a pořadí nového řádku jako u řádků
 * dokladu (DocRowsDocument): MAX(order_pos) + 1 v rámci zakázky, explicitní
 * pořadí zůstává. Jestli řádek platí k DUZP, rozhoduje až InvoiceBuilder.
 * Stav zakázky řádek sám nehlídá — zámek podle stavu rodiče (V pořádku
 * a další stavy s readOnly) dodává WorkOrderRowLockProvider, který jádro
 * vynucuje i na generickém CRUD (tasks/work-orders-rows-readonly.md).
 */
class WorkOrderRowDocument extends Document
{
    public const TABLE = 'economy_work_orders_rows';

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if ((int) ($data['work_order'] ?? 0) <= 0) {
            $result->addError('work_order', 'Řádek musí patřit zakázce.', 'required');
        }
        foreach (['quantity', 'unit_price'] as $col) {
            $value = $data[$col] ?? null;
            if ($value !== null && $value !== '' && !is_numeric($value)) {
                $result->addError($col, 'Zadej číslo.', 'invalid');
            }
        }
        $from = self::isoDate($data['valid_from'] ?? null);
        $to = self::isoDate($data['valid_to'] ?? null);
        if ($from !== null && $to !== null && $to < $from) {
            $result->addError('valid_to', 'Platnost do nesmí být dříve než platnost od.', 'invalid_range');
        }
        $contributor = trim((string) ($data['contributor'] ?? ''));
        if ($contributor !== ''
            && (!preg_match('/^[a-z][a-zA-Z0-9_.]*$/', $contributor)
                || InvoiceContributorRegistry::isKnown($this->config, $contributor) === false)
        ) {
            $result->addError('contributor', 'Neznámý přispěvatel obsahu.', 'invalid');
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        foreach (['description', 'vat_code', 'operation', 'contributor'] as $col) {
            if (array_key_exists($col, $data) && $data[$col] !== null) {
                $trimmed = trim((string) $data[$col]);
                $data[$col] = $trimmed === '' ? null : $trimmed;
            }
        }
        if ($originalData !== null || (int) ($data['order_pos'] ?? 0) > 0) {
            return;
        }
        $workOrderId = (int) ($data['work_order'] ?? 0);
        if ($workOrderId > 0) {
            $data['order_pos'] = $this->nextOrderPos($workOrderId);
        }
    }

    /** MAX(order_pos) + 1 v rámci zakázky (přepsatelné v testech). */
    protected function nextOrderPos(int $workOrderId): int
    {
        if ($this->db === null) {
            return 1;
        }
        $max = $this->db->fetchSingle(
            'SELECT MAX([order_pos]) FROM [' . self::TABLE . '] WHERE [work_order] = %i',
            $workOrderId,
        );
        return (int) ($max ?: 0) + 1;
    }

    private static function isoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $string = trim((string) ($value ?? ''));
        return $string !== '' ? substr($string, 0, 10) : null;
    }
}
