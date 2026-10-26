<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Values;

use Dibi\Connection;
use Shipard\Core\Database\NestedTransaction;
use Shipard\Module\World\Validations\Iban;

/**
 * Jednorázová, opakovatelná normalizace existujících údajů adresáře (#118
 * D22, `shpd-ds values-normalize`): osoby, adresy, kontakty, účty osob
 * a vlastní účty v číselníku převede do kanonického tvaru **týmiž funkcemi
 * jako uložení** ({@see PersonValues}, `BankAccountShape` včetně přesunu
 * IBAN ze sloupce čísla účtu — D20); žádná vlastní logika tvaru. Země
 * osoby podle D18 z uložených adres (sídlo → první adresa se zemí → země
 * zdroje dat), adresa bez země podle země osoby (D24), vlastní účty podle
 * země zdroje dat.
 *
 * Zapisuje jen změněné řádky a jen dotčené sloupce přímým UPDATE — bez
 * změny `docState`, bez tříd dokumentů a událostí; ostrý běh v jedné
 * transakci ({@see NestedTransaction}: chyba = nic), `dry-run` nezapíše
 * nic. Idempotentní: druhý běh hlásí 0 změn. Nevalidní hodnoty po opravě
 * tvaru (kódy D13, {@see PersonValues::checkPerson} …) se neopravují, jen
 * vypisují jako tabulka · id · sloupec · kód — bez hodnot, výpis smí do
 * issue. Doklady, snapshoty stran a `display_line` / `display_block` adres
 * nemění.
 *
 * Dotazy jsou v chráněných metodách — šev pro testy nad řádky v paměti.
 */
class DirectoryValuesNormalizer
{
    public const TABLE_PERSONS = 'base_persons_persons';
    public const TABLE_ADDRESSES = 'base_persons_addresses';
    public const TABLE_CONTACTS = 'base_persons_contacts';
    public const TABLE_PERSON_ACCOUNTS = 'base_persons_bank_accounts';
    public const TABLE_OWN_ACCOUNTS = 'economy_codebooks_bank_accounts';

    /** Důvod přeskočení tabulky vlastních účtů: modul `economy.codebooks` není aktivní. */
    public const SKIPPED_MODULE_INACTIVE = 'module_inactive';

    public const DEFAULT_BATCH = 1000;

    private const ACCOUNT_COLUMNS = ['account_number', 'iban', 'bic'];
    private const ADDRESS_COLUMNS = ['zip', 'country'];

    public function __construct(
        private readonly Connection $db,
        private readonly string $dsCountry,
        private readonly bool $ownAccounts,
        private readonly int $batchSize = self::DEFAULT_BATCH,
    ) {}

    /**
     * @return array{
     *   dryRun: bool,
     *   tables: array<string, array<string, mixed>>,
     *   invalid: list<array{table: string, id: int, column: string, code: string}>,
     *   totals: array{rows: int, changed: int, invalid: int},
     * }
     */
    public function run(bool $dryRun): array
    {
        $report = ['dryRun' => $dryRun, 'tables' => [], 'invalid' => [], 'totals' => ['rows' => 0, 'changed' => 0, 'invalid' => 0]];
        $work = function () use (&$report, $dryRun): void {
            $this->normalizeAll($report, $dryRun);
        };
        if ($dryRun) {
            $work();
        } else {
            NestedTransaction::run($this->db, $work);
        }
        $report['totals']['invalid'] = count($report['invalid']);
        return $report;
    }

    /** @param array<string, mixed> $report */
    private function normalizeAll(array &$report, bool $dryRun): void
    {
        $countries = $this->personCountries();
        $byId = fn (array $row): string => $countries[(int) ($row['id'] ?? 0)] ?? $this->dsCountry;
        $byPerson = fn (array $row): string => $countries[(int) ($row['person'] ?? 0)] ?? $this->dsCountry;

        $this->processTable(
            $report,
            self::TABLE_PERSONS,
            array_keys(PersonValues::PERSON_COLUMNS),
            false,
            $dryRun,
            static fn (array $row): array => PersonValues::normalizePerson($row, $byId($row)),
            static fn (array $row): array => PersonValues::checkPerson($row, $byId($row)),
        );
        $this->processTable(
            $report,
            self::TABLE_ADDRESSES,
            self::ADDRESS_COLUMNS,
            true,
            $dryRun,
            static fn (array $row): array => PersonValues::normalizeAddress($row, null, $byPerson($row)),
            static fn (array $row): array => PersonValues::checkAddress($row, null, $byPerson($row)),
        );
        $this->processTable(
            $report,
            self::TABLE_CONTACTS,
            array_keys(PersonValues::CONTACT_COLUMNS),
            true,
            $dryRun,
            static fn (array $row): array => PersonValues::normalizeContact($row, $byPerson($row)),
            static fn (array $row): array => [],
        );
        $this->processTable(
            $report,
            self::TABLE_PERSON_ACCOUNTS,
            self::ACCOUNT_COLUMNS,
            true,
            $dryRun,
            static fn (array $row): array => PersonValues::normalizeBankAccount($row),
            static fn (array $row): array => PersonValues::checkBankAccount($row, $byPerson($row)),
        );
        if ($this->ownAccounts) {
            $this->processTable(
                $report,
                self::TABLE_OWN_ACCOUNTS,
                self::ACCOUNT_COLUMNS,
                false,
                $dryRun,
                static fn (array $row): array => PersonValues::normalizeBankAccount($row),
                fn (array $row): array => PersonValues::checkBankAccount($row, $this->dsCountry),
            );
        } else {
            $report['tables'][self::TABLE_OWN_ACCOUNTS] = ['skipped' => self::SKIPPED_MODULE_INACTIVE];
        }
    }

    /**
     * Jedna tabulka po dávkách (keyset přes `id`): normalizovaný řádek se
     * porovná se čteným po sloupcích, zapíší se jen rozdíly; nálezy nad
     * normalizovaným řádkem jdou do `invalid`.
     *
     * @param array<string, mixed> $report
     * @param list<string> $columns
     * @param callable(array<string, mixed>): array<string, mixed> $normalize
     * @param callable(array<string, mixed>): list<array{column: string, code: string, message: string}> $check
     */
    private function processTable(array &$report, string $table, array $columns, bool $withPerson, bool $dryRun, callable $normalize, callable $check): void
    {
        $accounts = in_array($table, [self::TABLE_PERSON_ACCOUNTS, self::TABLE_OWN_ACCOUNTS], true);
        $stats = ['rows' => 0, 'changed' => 0, 'columns' => array_fill_keys($columns, 0)];
        if ($accounts) {
            $stats['ibanMoved'] = 0;
        }
        $select = array_merge(['id'], $withPerson ? ['person'] : [], $columns);
        $after = 0;
        do {
            $rows = $this->fetchBatch($table, $select, $after, $this->batchSize);
            foreach ($rows as $row) {
                $stats['rows']++;
                $after = (int) $row['id'];
                $normalized = $normalize($row);
                $changes = [];
                foreach ($columns as $column) {
                    if (($normalized[$column] ?? null) !== ($row[$column] ?? null)) {
                        $changes[$column] = $normalized[$column] ?? null;
                        $stats['columns'][$column]++;
                    }
                }
                if ($changes !== []) {
                    $stats['changed']++;
                    if ($accounts && self::ibanMoved($row, $normalized)) {
                        $stats['ibanMoved']++;
                    }
                    if (!$dryRun) {
                        $this->updateRow($table, (int) $row['id'], $changes);
                    }
                }
                foreach ($check($normalized) as $finding) {
                    $report['invalid'][] = ['table' => $table, 'id' => (int) $row['id'], 'column' => $finding['column'], 'code' => $finding['code']];
                }
            }
        } while (count($rows) === $this->batchSize);

        $stats['columns'] = array_filter($stats['columns']);
        $report['tables'][$table] = $stats;
        $report['totals']['rows'] += $stats['rows'];
        $report['totals']['changed'] += $stats['changed'];
    }

    /**
     * Země osob podle uložených adres (sídlo → první adresa se zemí), jedním
     * dotazem pro celý adresář; osoba bez adresy se zemí v mapě není.
     *
     * @return array<int, string>
     */
    private function personCountries(): array
    {
        $grouped = [];
        foreach ($this->loadAddressRows() as $row) {
            $grouped[(int) $row['person']][] = $row;
        }
        $countries = [];
        foreach ($grouped as $person => $rows) {
            $country = PersonContext::countryFromAddresses($rows);
            if ($country !== null) {
                $countries[$person] = $country;
            }
        }
        return $countries;
    }

    /** Původní číslo účtu mělo tvar IBAN a po normalizaci se změnilo (D20). */
    private static function ibanMoved(array $row, array $normalized): bool
    {
        $original = $row['account_number'] ?? null;
        return is_string($original)
            && Iban::hasShape(Iban::normalize($original))
            && ($normalized['account_number'] ?? null) !== $original;
    }

    /**
     * Adresy se zemí mimo koš v pořadí `person, order_pos, id` — stejný
     * výběr jako {@see PersonContext::storedCountry()}, jedním dotazem.
     *
     * @return list<array<string, mixed>>
     */
    protected function loadAddressRows(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [person], [address_type], [country] FROM %n'
            . ' WHERE [docState] <> 90 AND [country] IS NOT NULL AND [country] <> %s'
            . ' ORDER BY [person], [order_pos], [id]',
            self::TABLE_ADDRESSES,
            '',
        );
        return array_map(static fn ($row): array => (array) $row, $rows);
    }

    /**
     * Dávka řádků s `id` větším než `$afterId`, seřazená podle `id`.
     *
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    protected function fetchBatch(string $table, array $columns, int $afterId, int $limit): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . implode(', ', array_map(static fn (string $column): string => "[{$column}]", $columns))
            . ' FROM %n WHERE [id] > %i ORDER BY [id] LIMIT %i',
            $table,
            $afterId,
            $limit,
        );
        return array_map(static fn ($row): array => (array) $row, $rows);
    }

    /** @param array<string, mixed> $changes jen změněné sloupce */
    protected function updateRow(string $table, int $id, array $changes): void
    {
        $this->db->update($table, $changes)->where('[id] = %i', $id)->execute();
    }
}
