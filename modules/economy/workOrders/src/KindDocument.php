<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Druh zakázky (economy_work_orders_kinds, docs/work-orders.md D14).
 *
 * Typ druhu je po prvním potvrzení jen ke čtení: určuje, co zakázky druhu
 * mají (zákazník, nadřazená zakázka, fakturace), takže by jeho změna
 * rozbila existující zakázky. „Potvrzený“ = uložený řádek už není
 * v Konceptu; formulář typ po založení ani nenabízí (KindsForm).
 */
class KindDocument extends Document
{
    public const TABLE = 'economy_work_orders_kinds';
    public const STATE_DRAFT = 10;

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

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        foreach (['name', 'notice'] as $col) {
            if (array_key_exists($col, $data) && $data[$col] !== null) {
                $trimmed = trim((string) $data[$col]);
                $data[$col] = $trimmed === '' ? null : $trimmed;
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

    protected function types(): WorkOrderTypes
    {
        return new WorkOrderTypes($this->config);
    }
}
