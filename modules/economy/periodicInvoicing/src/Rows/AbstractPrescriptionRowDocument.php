<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Rows;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Economy\PeriodicInvoicing\Contributor\InvoiceContributorRegistry;

/**
 * Společný základ dokumentu řádku předpisu periodické fakturace (#174 D31,
 * tvar řádku #175 U2): tvarová validace (čísla, platnost od ≤ do, tvar id
 * přispěvatele) a pořadí nového řádku jako u řádků dokladu
 * (DocRowsDocument) — MAX(order_pos) + 1 v rámci rodiče, explicitní pořadí
 * zůstává. Jestli řádek platí k DUZP, rozhoduje až InvoiceBuilder.
 *
 * Potomek dodá tabulku řádků, sloupec rodiče a hlášku řádku bez rodiče;
 * pravidla, která závisí na rodiči (platnost řádku uvnitř platnosti
 * záznamu), přidá v `validateAgainstParent()`. Stav rodiče dokument sám
 * nehlídá — zámek podle stavu rodiče dodává
 * {@see AbstractPrescriptionRowLockProvider}, který jádro vynucuje i na
 * generickém CRUD.
 */
abstract class AbstractPrescriptionRowDocument extends Document
{
    /** Tabulka řádků předpisu. */
    abstract protected function rowsTable(): string;

    /** Sloupec řádku s id rodiče (záznam zdroje, u šablony druh). */
    abstract protected function parentColumn(): string;

    /** Hláška řádku bez rodiče („Řádek musí patřit zakázce.“). */
    abstract protected function parentRequiredMessage(): string;

    /** Má řádek platnost od / do? Šablona na druhu ne. */
    protected function hasValidity(): bool
    {
        return true;
    }

    /**
     * Pravidla řádku proti jeho rodiči; volá se jen u řádku s rodičem.
     * Výchozí nic.
     *
     * @param array<string, mixed> $data
     */
    protected function validateAgainstParent(array $data, ValidationResult $result): void
    {
    }

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();
        $parentColumn = $this->parentColumn();
        $hasParent = (int) ($data[$parentColumn] ?? 0) > 0;

        if (!$hasParent) {
            $result->addError($parentColumn, $this->parentRequiredMessage(), 'required');
        }
        foreach (['quantity', 'unit_price'] as $col) {
            $value = $data[$col] ?? null;
            if ($value !== null && $value !== '' && !is_numeric($value)) {
                $result->addError($col, 'Zadej číslo.', 'invalid');
            }
        }
        if ($this->hasValidity()) {
            $from = self::isoDate($data['valid_from'] ?? null);
            $to = self::isoDate($data['valid_to'] ?? null);
            if ($from !== null && $to !== null && $to < $from) {
                $result->addError('valid_to', 'Platnost do nesmí být dříve než platnost od.', 'invalid_range');
            }
        }
        $contributor = trim((string) ($data['contributor'] ?? ''));
        if ($contributor !== ''
            && (!preg_match('/^[a-z][a-zA-Z0-9_.]*$/', $contributor)
                || InvoiceContributorRegistry::isKnown($this->config, $contributor) === false)
        ) {
            $result->addError('contributor', 'Neznámý přispěvatel obsahu.', 'invalid');
        }
        if ($hasParent) {
            $this->validateAgainstParent($data, $result);
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
        $parentId = (int) ($data[$this->parentColumn()] ?? 0);
        if ($parentId > 0) {
            $data['order_pos'] = $this->nextOrderPos($parentId);
        }
    }

    /** MAX(order_pos) + 1 v rámci rodiče (přepsatelné v testech). */
    protected function nextOrderPos(int $parentId): int
    {
        if ($this->db === null) {
            return 1;
        }
        $max = $this->db->fetchSingle(
            'SELECT MAX([order_pos]) FROM [' . $this->rowsTable() . '] WHERE [' . $this->parentColumn() . '] = %i',
            $parentId,
        );
        return (int) ($max ?: 0) + 1;
    }

    protected static function isoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $string = trim((string) ($value ?? ''));
        return $string !== '' ? substr($string, 0, 10) : null;
    }
}
