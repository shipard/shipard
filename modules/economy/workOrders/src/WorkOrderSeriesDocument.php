<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;
use Shipard\Core\Numbering\NumberPattern;

/**
 * Číselná řada zakázek (economy_work_orders_number_series, docs/work-orders.md
 * D17, §5.7). Řada patří jednomu druhu a druh se po založení nemění (jako
 * typ dokladu u řad dokladů — zakázky řady by přestaly odpovídat druhu).
 *
 * Vzorec a kód řady validuje jádro číslování bez doménových placeholderů;
 * navíc musí vzorec obsahovat pořadí (`%3`–`%6`), jinak by se čísla v řadě
 * opakovala (P3) — doklady tenhle guard nemají, jejich vzorce jsou ze seedu.
 */
class WorkOrderSeriesDocument extends Document
{
    public const TABLE = 'economy_work_orders_number_series';

    public const DEFAULT_PATTERN = '%C%y%4';
    public const DEFAULT_RESET_SCOPE = 'fiscal_year';

    private const ALLOWED_RESET_SCOPES = ['none', 'fiscal_year'];
    private const STATE_DELETED = 90;

    /** Pořadí ve vzorci — `%3` až `%6` (NumberPattern::GENERAL_PLACEHOLDERS). */
    private const SEQUENCE_PLACEHOLDER = '/%[3-6]/';

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $kind = (int) ($data['kind'] ?? 0);
        if ($kind <= 0) {
            $result->addError('kind', 'Druh zakázky je povinný', 'required');
        } else {
            $id = !empty($data['id']) ? (int) $data['id'] : null;
            $original = $id !== null ? $this->loadRow($id) : null;
            if ($original !== null && (int) ($original['kind'] ?? 0) !== $kind) {
                $result->addError('kind', 'Druh řady nejde po založení změnit — zakázky řady by přestaly odpovídat druhu.', 'kindLocked');
            } elseif ($this->db !== null) {
                $kindRow = $this->loadKindRow($kind);
                if ($kindRow === null) {
                    $result->addError('kind', 'Druh zakázky neexistuje', 'not_found');
                } elseif ((int) ($kindRow['docState'] ?? 0) === self::STATE_DELETED) {
                    $result->addError('kind', 'Druh zakázky je smazaný', 'invalid_state');
                }
            }
        }

        if (trim((string) ($data['name'] ?? '')) === '') {
            $result->addError('name', 'Název řady je povinný', 'required');
        }

        $pattern = trim((string) ($data['number_pattern'] ?? ''));
        $code    = trim((string) ($data['number_code'] ?? ''));
        foreach (NumberPattern::validate($pattern, $code) as $error) {
            $result->addError(
                $error['target'] === NumberPattern::TARGET_CODE ? 'number_code' : 'number_pattern',
                $error['target'] === NumberPattern::TARGET_PATTERN && $error['code'] === 'required'
                    ? 'Vzorec čísla zakázky je povinný'
                    : $error['message'],
                $error['code'],
            );
        }
        if ($pattern !== '' && preg_match(self::SEQUENCE_PLACEHOLDER, $pattern) !== 1) {
            $result->addError(
                'number_pattern',
                'Vzorec musí obsahovat pořadí (%3 až %6), jinak by se čísla v řadě opakovala.',
                'sequence_required',
            );
        }

        if (!empty($data['reset_scope'])
            && !in_array($data['reset_scope'], self::ALLOWED_RESET_SCOPES, true)
        ) {
            $result->addError('reset_scope', 'Neplatný typ restartu', 'invalid_value');
        }

        if (!empty($data['valid_from']) && !empty($data['valid_to'])
            && (string) $data['valid_from'] > (string) $data['valid_to']
        ) {
            $result->addError('valid_to', 'Konec platnosti musí být později než začátek', 'invalid_range');
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        foreach (['name', 'number_code', 'number_pattern', 'notice'] as $col) {
            if (array_key_exists($col, $data) && $data[$col] !== null) {
                $trimmed = trim((string) $data[$col]);
                $data[$col] = $trimmed === '' ? null : $trimmed;
            }
        }
        if (array_key_exists('reset_scope', $data) && empty($data['reset_scope'])) {
            $data['reset_scope'] = self::DEFAULT_RESET_SCOPE;
        }
    }

    /** Uložený řádek řady, null = nová řada (přepsatelné v testech). */
    protected function loadRow(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch('SELECT [id], [kind] FROM [' . self::TABLE . '] WHERE [id] = %i', $id);
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /** Řádek druhu (stav), null = neexistuje (přepsatelné v testech). */
    protected function loadKindRow(int $kindId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch('SELECT [id], [docState] FROM [' . KindDocument::TABLE . '] WHERE [id] = %i', $kindId);
        return $row === null || $row === false ? null : iterator_to_array($row);
    }
}
