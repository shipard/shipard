<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoicingSettings;

/**
 * Druh zakázky (economy_work_orders_kinds, docs/work-orders.md D14).
 *
 * Typ druhu je po prvním potvrzení jen ke čtení: určuje, co zakázky druhu
 * mají (zákazník, nadřazená zakázka, fakturace), takže by jeho změna
 * rozbila existující zakázky. „Potvrzený“ = uložený řádek už není
 * v Konceptu; formulář typ po založení ani nenabízí (KindsForm).
 *
 * Druh periodického typu nese výchozí hodnoty fakturace (D3, sloupce
 * `inv_*`): typ dokladu a řada jsou povinné mimo Koncept — bez nich se
 * nemá co vystavovat; řada musí být typu dokladu. U ostatních typů se
 * fakturační sloupce při uložení vyprázdní.
 */
class KindDocument extends Document
{
    public const TABLE = 'economy_work_orders_kinds';
    public const STATE_DRAFT = 10;
    public const STATE_DELETED = 90;

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();
        $types = $this->types();

        if (trim((string) ($data['name'] ?? '')) === '') {
            $result->addError('name', 'Název je povinný', 'required');
        }

        $type = (string) ($data['type'] ?? '');
        if ($type === '') {
            $result->addError('type', 'Typ zakázky je povinný', 'required');
        } elseif ($types->isUnknown($type)) {
            $result->addError('type', 'Neznámý typ zakázky', 'invalid');
        }

        $id = !empty($data['id']) ? (int) $data['id'] : null;
        $original = $id !== null ? $this->loadKindRow($id) : null;
        if ($original !== null
            && $type !== ''
            && (int) ($original['docState'] ?? self::STATE_DRAFT) !== self::STATE_DRAFT
            && $type !== (string) ($original['type'] ?? '')
        ) {
            $result->addError(
                'type',
                'Typ potvrzeného druhu nejde změnit — zakázky druhu by přestaly odpovídat.',
                'typeLocked',
            );
        }

        if ($type !== '' && !$types->isUnknown($type)
            && $types->invoicing($type) === WorkOrderTypes::INVOICING_PERIODIC
        ) {
            $this->validateInvoicing($data, $result);
        }

        return $result;
    }

    /**
     * Výchozí hodnoty fakturace periodického druhu (D3). Typ dokladu
     * a řada jsou povinné mimo Koncept, ostatní jen tvarově.
     *
     * @param array<string, mixed> $data
     */
    private function validateInvoicing(array $data, ValidationResult $result): void
    {
        $newState = (int) ($data['docState'] ?? self::STATE_DRAFT);
        $required = in_array($newState, [40, 80], true);

        $docType = $data['inv_doc_type'] ?? null;
        $hasDocType = $docType !== null && $docType !== '';
        if ($hasDocType && !InvoicingSettings::isDocType($docType)) {
            $result->addError('inv_doc_type', 'Periodická fakturace vystavuje jen faktury a zálohové faktury vydané.', 'invalid');
            $hasDocType = false;
        } elseif (!$hasDocType && $required) {
            $result->addError('inv_doc_type', 'Druh periodické zakázky musí mít typ dokladu — bez něj se nemá co vystavovat.', 'required');
        }

        $seriesId = (int) ($data['inv_number_series'] ?? 0);
        if ($seriesId > 0) {
            $series = $this->loadDocSeries($seriesId);
            if ($series === null) {
                if ($this->db !== null) {
                    $result->addError('inv_number_series', 'Řada dokladů neexistuje', 'not_found');
                }
            } elseif ($hasDocType && (string) ($series['doc_type'] ?? '') !== (string) $docType) {
                $result->addError('inv_number_series', 'Řada dokladů musí být stejného typu jako typ dokladu.', 'series_type_mismatch');
            }
        } elseif ($required) {
            $result->addError('inv_number_series', 'Druh periodické zakázky musí mít řadu dokladů.', 'required');
        }

        if (isset($data['inv_due_days']) && $data['inv_due_days'] !== '' && (int) $data['inv_due_days'] < 0) {
            $result->addError('inv_due_days', 'Splatnost nesmí být záporná.', 'invalid');
        }
        $timing = $data['inv_timing'] ?? null;
        if ($timing !== null && $timing !== '' && !InvoicingSettings::isTiming($timing)) {
            $result->addError('inv_timing', 'Neznámý okamžik fakturace.', 'invalid');
        }
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        foreach (['name', 'notice'] as $col) {
            if (array_key_exists($col, $data) && $data[$col] !== null) {
                $trimmed = trim((string) $data[$col]);
                $data[$col] = $trimmed === '' ? null : $trimmed;
            }
        }

        $type = (string) ($data['type'] ?? $originalData['type'] ?? '');
        if ($type !== '' && $this->types()->invoicing($type) !== WorkOrderTypes::INVOICING_PERIODIC) {
            foreach (InvoicingSettings::COLUMNS as $col) {
                $data[$col] = null;
            }
        }
    }

    /** Uložený řádek druhu, null = nový druh (přepsatelné v testech). */
    protected function loadKindRow(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch('SELECT [id], [type], [docState] FROM [' . self::TABLE . '] WHERE [id] = %i', $id);
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /** Řada dokladů (id, doc_type, docState), null = neexistuje (přepsatelné v testech). */
    protected function loadDocSeries(int $seriesId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch('SELECT [id], [doc_type], [docState] FROM [docs_core_number_series] WHERE [id] = %i', $seriesId);
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    protected function types(): WorkOrderTypes
    {
        return new WorkOrderTypes($this->config);
    }
}
