<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Core\Document\ValidationResult;
use Shipard\Core\I18n\DisplayDate;
use Shipard\Module\Economy\PeriodicInvoicing\Rows\AbstractPrescriptionRowDocument;

/**
 * Řádek předpisu smlouvy (facility_buildings_contract_rows, #174 D30).
 * Tvarovou validaci a pořadí nového řádku dává společný základ řádku
 * předpisu enginu periodické fakturace; smlouvy přidávají pravidlo D36 —
 * platnost řádku leží uvnitř platnosti smlouvy (prázdná strana = bez
 * omezení). Totéž pravidlo hlídá {@see ContractDocument} při potvrzení
 * smlouvy, protože platnost smlouvy se může změnit i po uložení řádků.
 *
 * Stav smlouvy řádek sám nehlídá — zámek dodává
 * {@see ContractRowLockProvider}.
 */
class ContractRowDocument extends AbstractPrescriptionRowDocument
{
    public const TABLE = ContractDocument::ROWS_TABLE;

    /** Jazyk hlášek validace — česká věta bez jazyka požadavku. */
    private const MESSAGE_LANGUAGE = 'cs';

    protected function rowsTable(): string
    {
        return self::TABLE;
    }

    protected function parentColumn(): string
    {
        return 'contract';
    }

    protected function parentRequiredMessage(): string
    {
        return 'Řádek musí patřit smlouvě.';
    }

    protected function validateAgainstParent(array $data, ValidationResult $result): void
    {
        $contract = $this->loadContractValidity((int) $data['contract']);
        if ($contract === null) {
            return;
        }
        $problems = self::validityProblems(
            $data,
            self::isoDate($contract['date_start'] ?? null),
            self::isoDate($contract['date_end'] ?? null),
        );
        foreach ($problems as $column => $message) {
            $result->addError($column, $message, 'outside_contract');
        }
    }

    /**
     * Platnost řádku proti platnosti smlouvy (D36): sloupec řádku → hláška;
     * prázdné = řádek je uvnitř. Prázdná platnost řádku ani smlouvy
     * neomezuje.
     *
     * @param array<string, mixed> $row řádek s `valid_from` / `valid_to`
     * @return array<string, string>
     */
    public static function validityProblems(array $row, ?string $contractStart, ?string $contractEnd): array
    {
        $from = self::isoDate($row['valid_from'] ?? null);
        $to = self::isoDate($row['valid_to'] ?? null);
        $show = static fn (string $date): string => DisplayDate::date($date, self::MESSAGE_LANGUAGE) ?? $date;

        $problems = [];
        if ($from !== null && $contractStart !== null && $from < $contractStart) {
            $problems['valid_from'] = "platnost od {$show($from)} je před zahájením smlouvy ({$show($contractStart)}).";
        } elseif ($from !== null && $contractEnd !== null && $from > $contractEnd) {
            $problems['valid_from'] = "platnost od {$show($from)} je po ukončení smlouvy ({$show($contractEnd)}).";
        }
        if ($to !== null && $contractEnd !== null && $to > $contractEnd) {
            $problems['valid_to'] = "platnost do {$show($to)} je po ukončení smlouvy ({$show($contractEnd)}).";
        } elseif ($to !== null && $contractStart !== null && $to < $contractStart) {
            $problems['valid_to'] = "platnost do {$show($to)} je před zahájením smlouvy ({$show($contractStart)}).";
        }
        return $problems;
    }

    // ── DB přístup (přepsatelný v testech) ──────────────────────────────────

    /** Platnost smlouvy (date_start, date_end), null = smlouva neexistuje. */
    protected function loadContractValidity(int $contractId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [date_start], [date_end] FROM [' . ContractDocument::TABLE . '] WHERE [id] = %i',
            $contractId,
        );
        return $row === null || $row === false ? null : iterator_to_array($row);
    }
}
