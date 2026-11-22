<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Dibi\Connection;
use Shipard\Core\Database\RecordCode;

/**
 * Kód číselné řady dokladů — přirozený klíč záznamu (#166 D16, D17):
 *
 *  - řada vázaná na pokladnu / sklad: `<typ dokladu>-<kód entity>`
 *    (`cash-HL`, `cashreg-HL`);
 *  - nevázaná řada typu s nejnižším id mezi nesmazanými: `<typ dokladu>`
 *    (`invno`) — výchozí řada z provisioneru;
 *  - další nevázaná řada: `<typ dokladu>-<kód z čísla dokladu>`, bez něj
 *    `<typ dokladu>-<n>`, n od 2.
 *
 * Znak mimo povolený tvar ({@see RecordCode}) se nahradí pomlčkou. Obsazený
 * kód dostane příponu `-2`, `-3`, …; obsazený je i kód řady v koši —
 * unikátní index platí pro všechny řádky.
 *
 * Jedno pravidlo pro nový záznam (formulář, `NumberSeriesDocument`) i pro
 * doplnění existujících řad při `ds-upgrade`.
 */
final class NumberSeriesCodeGenerator
{
    public const TABLE = 'docs_core_number_series';
    public const MAX_LENGTH = 40;

    private const DELETED = 90;
    /** Základ kódu řady bez použitelného typu dokladu (poškozený řádek). */
    private const FALLBACK_BASE = 'series';

    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * Kód pro řadu — novou (bez `id`) i existující bez kódu.
     *
     * @param array<string, mixed> $series `doc_type`, vazby (`cash_desk`,
     *        `warehouse`), `doc_number_code`, u existující řady `id`
     *        a `docState`
     * @param array<string, true> $alsoTaken kódy obsazené nad rámec toho,
     *        co je vidět v databázi (souběžný zápis, {@see RecordCode::key()})
     */
    public function generate(array $series, array $alsoTaken = []): string
    {
        $id = (int) ($series['id'] ?? 0);
        $docType = (string) ($series['doc_type'] ?? '');

        $entityCode = null;
        foreach (NumberSeriesDocument::BINDINGS as $column => $meta) {
            $entityId = (int) ($series[$column] ?? 0);
            if ($entityId > 0) {
                $entityCode = $this->entityCode($meta['table'], $entityId);
                break;
            }
        }

        return self::compose(
            $docType,
            $entityCode,
            $entityCode === null && $this->isFirstOfType($docType, $id, (int) ($series['docState'] ?? 0)),
            (string) ($series['doc_number_code'] ?? ''),
            $this->takenKeys($id) + $alsoTaken,
        );
    }

    /**
     * Čisté pravidlo kódu.
     *
     * @param string|null $entityCode kód pokladny / skladu vázané řady;
     *        null = řada není vázaná
     * @param bool $firstOfType nevázaná řada s nejnižším id mezi
     *        nesmazanými řadami typu
     * @param array<string, true> $taken obsazené kódy ({@see RecordCode::key()})
     */
    public static function compose(
        string $docType,
        ?string $entityCode,
        bool $firstOfType,
        string $docNumberCode,
        array $taken,
    ): string {
        $type = RecordCode::base($docType);
        if ($type === '') {
            $type = self::FALLBACK_BASE;
        }

        if ($entityCode === null && $firstOfType) {
            return RecordCode::unique($type, $taken, self::MAX_LENGTH);
        }

        $rest = RecordCode::part($entityCode ?? $docNumberCode);
        return $rest === ''
            ? RecordCode::numbered($type, $taken, self::MAX_LENGTH)
            : RecordCode::unique($type . '-' . $rest, $taken, self::MAX_LENGTH);
    }

    /**
     * Kdo kód už má — jiná řada než `$exceptId`, i v koši. Porovnává
     * databáze (kolace sloupce nerozlišuje velikost písmen).
     *
     * @return array{id: int, name: string, deleted: bool}|null
     */
    public function holder(string $code, int $exceptId = 0): ?array
    {
        $row = $this->db->fetch(
            'SELECT [id], [name], [docState] FROM %n WHERE [code] = %s AND [id] <> %i ORDER BY [id] LIMIT 1',
            self::TABLE,
            $code,
            $exceptId,
        );
        if ($row === null) {
            return null;
        }
        return [
            'id'      => (int) $row['id'],
            'name'    => (string) ($row['name'] ?? ''),
            'deleted' => (int) ($row['docState'] ?? 0) === self::DELETED,
        ];
    }

    /** Kód pokladny / skladu; chybějící entita = prázdný kód. */
    private function entityCode(string $table, int $entityId): string
    {
        $row = $this->db->fetch('SELECT [code] FROM %n WHERE [id] = %i', $table, $entityId);
        return $row === null ? '' : (string) ($row['code'] ?? '');
    }

    /**
     * Je řada tou s nejnižším id mezi nesmazanými řadami typu? Nová řada
     * (bez id) jen tehdy, když typ žádnou nesmazanou řadu nemá; řada v koši
     * nikdy.
     */
    private function isFirstOfType(string $docType, int $id, int $docState): bool
    {
        if ($docState === self::DELETED) {
            return false;
        }
        $row = $this->db->fetch(
            'SELECT MIN([id]) AS [first_id] FROM %n WHERE [doc_type] = %s AND [docState] <> %i',
            self::TABLE,
            $docType,
            self::DELETED,
        );
        $firstId = $row === null ? 0 : (int) ($row['first_id'] ?? 0);
        return $firstId === 0 || $firstId === $id;
    }

    /**
     * Obsazené kódy všech ostatních řad včetně koše.
     *
     * @return array<string, true>
     */
    private function takenKeys(int $exceptId): array
    {
        $taken = [];
        $rows = $this->db->fetchAll(
            "SELECT [code] FROM %n WHERE [code] IS NOT NULL AND [code] <> '' AND [id] <> %i",
            self::TABLE,
            $exceptId,
        );
        foreach ($rows as $row) {
            $taken[RecordCode::key((string) $row['code'])] = true;
        }
        return $taken;
    }
}
