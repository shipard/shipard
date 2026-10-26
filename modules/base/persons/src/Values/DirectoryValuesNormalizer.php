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
 * **Druhy hodnot** (`$only`, D27): `phone`, `email`, `web`, `zip` (PSČ
 * a země adresy), `ids` (IČO, DIČ, daňové číslo), `accounts` (číslo účtu,
 * IBAN, BIC včetně D20). Ostatní sloupce se v běhu nemění ani nepočítají,
 * nálezy jen pro vybrané druhy; tabulka bez vybraného sloupce se přeskočí.
 *
 * **Podrobný výpis** (`$verbose`, D27): report navíc nese `changes` —
 * u každé změny osobu (id, kód, název; u vlastního účtu kód a název
 * účtu), hodnoty před → po a zda jde o přesun IBAN (jedna položka se
 * všemi sloupci) — a u nálezů hodnotu, důvod a **návrh opravy**
 * z redundance ({@see \Shipard\Module\World\Validations\ValueChecks}),
 * který se nikdy neprovádí. Obsahuje osobní údaje — jen na obrazovku.
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
    /** Důvod přeskočení tabulky: žádný z jejích sloupců není mezi vybranými druhy (`--only`). */
    public const SKIPPED_NOT_SELECTED = 'not_selected';

    public const DEFAULT_BATCH = 1000;

    public const KIND_PHONE = 'phone';
    public const KIND_EMAIL = 'email';
    public const KIND_WEB = 'web';
    public const KIND_ZIP = 'zip';
    public const KIND_IDS = 'ids';
    public const KIND_ACCOUNTS = 'accounts';

    /** @var list<string> druhy hodnot pro `--only`, v pořadí nápovědy */
    public const ONLY_KINDS = [self::KIND_PHONE, self::KIND_EMAIL, self::KIND_WEB, self::KIND_ZIP, self::KIND_IDS, self::KIND_ACCOUNTS];

    private const ACCOUNT_COLUMN_KINDS = [
        'account_number' => self::KIND_ACCOUNTS,
        'iban'           => self::KIND_ACCOUNTS,
        'bic'            => self::KIND_ACCOUNTS,
    ];

    /**
     * Sloupce každé tabulky a jejich druh (`--only`); pořadí sloupců
     * = pořadí ve výpisu. Osoby a kontakty odpovídají sloupcům
     * {@see PersonValues::PERSON_COLUMNS} / {@see PersonValues::CONTACT_COLUMNS}
     * (hlídá test).
     *
     * @var array<string, array<string, string>> tabulka → sloupec → druh
     */
    public const COLUMN_KINDS = [
        self::TABLE_PERSONS => [
            'company_id' => self::KIND_IDS,
            'vat_id'     => self::KIND_IDS,
            'tax_id'     => self::KIND_IDS,
            'phone'      => self::KIND_PHONE,
            'email'      => self::KIND_EMAIL,
            'web'        => self::KIND_WEB,
        ],
        self::TABLE_ADDRESSES => [
            'zip'     => self::KIND_ZIP,
            'country' => self::KIND_ZIP,
        ],
        self::TABLE_CONTACTS => [
            'email' => self::KIND_EMAIL,
            'phone' => self::KIND_PHONE,
        ],
        self::TABLE_PERSON_ACCOUNTS => self::ACCOUNT_COLUMN_KINDS,
        self::TABLE_OWN_ACCOUNTS    => self::ACCOUNT_COLUMN_KINDS,
    ];

    /** @var list<string>|null vybrané druhy; `null` = všechny */
    private readonly ?array $only;

    /** @var array<int, array{id: int, code: string, name: string}> osoby pro podrobný výpis */
    private array $personLabels = [];

    /**
     * @param list<string>|null $only druhy hodnot ({@see ONLY_KINDS}); `null` = všechny
     * @throws \InvalidArgumentException neznámý druh
     */
    public function __construct(
        private readonly Connection $db,
        private readonly string $dsCountry,
        private readonly bool $ownAccounts,
        private readonly int $batchSize = self::DEFAULT_BATCH,
        ?array $only = null,
        private readonly bool $verbose = false,
    ) {
        if ($only !== null) {
            $unknown = array_diff($only, self::ONLY_KINDS);
            if ($unknown !== []) {
                throw new \InvalidArgumentException('Neznámý druh hodnot: ' . implode(', ', $unknown));
            }
            $only = array_values(array_unique($only));
        }
        $this->only = $only;
    }

    /**
     * @return array{
     *   dryRun: bool,
     *   only?: list<string>,
     *   verbose?: bool,
     *   tables: array<string, array<string, mixed>>,
     *   changes?: list<array<string, mixed>>,
     *   invalid: list<array<string, mixed>>,
     *   totals: array{rows: int, changed: int, invalid: int},
     * }
     */
    public function run(bool $dryRun): array
    {
        $report = ['dryRun' => $dryRun];
        if ($this->only !== null) {
            $report['only'] = $this->only;
        }
        if ($this->verbose) {
            $report['verbose'] = true;
        }
        $report += ['tables' => [], 'changes' => [], 'invalid' => [], 'totals' => ['rows' => 0, 'changed' => 0, 'invalid' => 0]];
        if (!$this->verbose) {
            unset($report['changes']);
        }
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
        $this->personLabels = $this->verbose ? $this->loadPersonLabels() : [];

        $this->processTable(
            $report,
            self::TABLE_PERSONS,
            false,
            $dryRun,
            static fn (array $row): array => PersonValues::normalizePerson($row, $byId($row)),
            static fn (array $row): array => PersonValues::checkPerson($row, $byId($row)),
        );
        $this->processTable(
            $report,
            self::TABLE_ADDRESSES,
            true,
            $dryRun,
            static fn (array $row): array => PersonValues::normalizeAddress($row, null, $byPerson($row)),
            static fn (array $row): array => PersonValues::checkAddress($row, null, $byPerson($row)),
        );
        $this->processTable(
            $report,
            self::TABLE_CONTACTS,
            true,
            $dryRun,
            static fn (array $row): array => PersonValues::normalizeContact($row, $byPerson($row)),
            static fn (array $row): array => [],
        );
        $this->processTable(
            $report,
            self::TABLE_PERSON_ACCOUNTS,
            true,
            $dryRun,
            static fn (array $row): array => PersonValues::normalizeBankAccount($row),
            static fn (array $row): array => PersonValues::checkBankAccount($row, $byPerson($row)),
        );
        if ($this->ownAccounts) {
            $this->processTable(
                $report,
                self::TABLE_OWN_ACCOUNTS,
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
     * porovná se čteným po vybraných sloupcích, zapíší se jen rozdíly;
     * nálezy nad normalizovaným řádkem jdou do `invalid` (jen vybrané druhy).
     *
     * @param array<string, mixed> $report
     * @param callable(array<string, mixed>): array<string, mixed> $normalize
     * @param callable(array<string, mixed>): list<array<string, mixed>> $check
     */
    private function processTable(array &$report, string $table, bool $withPerson, bool $dryRun, callable $normalize, callable $check): void
    {
        $columns = $this->selectedColumns($table);
        if ($columns === []) {
            $report['tables'][$table] = ['skipped' => self::SKIPPED_NOT_SELECTED];
            return;
        }
        $accounts = in_array($table, [self::TABLE_PERSON_ACCOUNTS, self::TABLE_OWN_ACCOUNTS], true);
        $ownAccounts = $table === self::TABLE_OWN_ACCOUNTS;
        $stats = ['rows' => 0, 'changed' => 0, 'columns' => array_fill_keys($columns, 0)];
        if ($accounts) {
            $stats['ibanMoved'] = 0;
        }
        $select = array_merge(['id'], $withPerson ? ['person'] : [], $columns);
        if ($this->verbose && $ownAccounts) {
            $select = array_merge($select, ['code', 'name']);
        }
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
                    $moved = $accounts && self::ibanMoved($row, $normalized);
                    if ($moved) {
                        $stats['ibanMoved']++;
                    }
                    if ($this->verbose) {
                        $report['changes'][] = $this->changeEntry($table, $row, $changes, $moved);
                    }
                    if (!$dryRun) {
                        $this->updateRow($table, (int) $row['id'], $changes);
                    }
                }
                foreach ($check($normalized) as $finding) {
                    if (!$this->kindSelected(self::COLUMN_KINDS[$table][$finding['column']] ?? null)) {
                        continue;
                    }
                    $report['invalid'][] = $this->findingEntry($table, $row, $finding);
                }
            }
        } while (count($rows) === $this->batchSize);

        $stats['columns'] = array_filter($stats['columns']);
        $report['tables'][$table] = $stats;
        $report['totals']['rows'] += $stats['rows'];
        $report['totals']['changed'] += $stats['changed'];
    }

    /** @return list<string> sloupce tabulky vybraných druhů v pořadí výpisu */
    private function selectedColumns(string $table): array
    {
        $columns = [];
        foreach (self::COLUMN_KINDS[$table] as $column => $kind) {
            if ($this->kindSelected($kind)) {
                $columns[] = $column;
            }
        }
        return $columns;
    }

    private function kindSelected(?string $kind): bool
    {
        return $this->only === null || ($kind !== null && in_array($kind, $this->only, true));
    }

    /**
     * Položka podrobného výpisu změny: osoba (u vlastního účtu kód a název
     * účtu), hodnoty před → po; přesun IBAN jako jedna položka.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function changeEntry(string $table, array $row, array $changes, bool $ibanMoved): array
    {
        $columns = [];
        foreach ($changes as $column => $to) {
            $columns[$column] = ['from' => $row[$column] ?? null, 'to' => $to];
        }
        return [
            'table'     => $table,
            'id'        => (int) $row['id'],
            'person'    => $this->personOf($table, $row),
            'account'   => $this->accountOf($table, $row),
            'ibanMoved' => $ibanMoved,
            'columns'   => $columns,
        ];
    }

    /**
     * Položka nálezu: bez podrobného výpisu jen tabulka · id · sloupec · kód
     * (bez hodnot, smí do issue); s ním navíc osoba, hodnota, důvod a návrh.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $finding
     * @return array<string, mixed>
     */
    private function findingEntry(string $table, array $row, array $finding): array
    {
        $entry = ['table' => $table, 'id' => (int) $row['id'], 'column' => (string) $finding['column'], 'code' => (string) $finding['code']];
        if ($this->verbose) {
            $entry += [
                'person'     => $this->personOf($table, $row),
                'account'    => $this->accountOf($table, $row),
                'value'      => $finding['value'] ?? null,
                'reason'     => $finding['reason'] ?? null,
                'suggestion' => $finding['suggestion'] ?? null,
            ];
        }
        return $entry;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, code: string, name: string}|null
     */
    private function personOf(string $table, array $row): ?array
    {
        if ($table === self::TABLE_OWN_ACCOUNTS) {
            return null;
        }
        $personId = (int) ($table === self::TABLE_PERSONS ? ($row['id'] ?? 0) : ($row['person'] ?? 0));
        return $this->personLabels[$personId] ?? ['id' => $personId, 'code' => '', 'name' => ''];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{code: string, name: string}|null
     */
    private function accountOf(string $table, array $row): ?array
    {
        if ($table !== self::TABLE_OWN_ACCOUNTS) {
            return null;
        }
        return ['code' => (string) ($row['code'] ?? ''), 'name' => (string) ($row['name'] ?? '')];
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
     * Kód a název všech osob pro podrobný výpis, jedním dotazem (jen
     * s `$verbose`).
     *
     * @return array<int, array{id: int, code: string, name: string}>
     */
    protected function loadPersonLabels(): array
    {
        $labels = [];
        foreach ($this->db->fetchAll('SELECT [id], [person_id], [full_name] FROM %n', self::TABLE_PERSONS) as $row) {
            $row = (array) $row;
            $labels[(int) $row['id']] = ['id' => (int) $row['id'], 'code' => (string) ($row['person_id'] ?? ''), 'name' => (string) ($row['full_name'] ?? '')];
        }
        return $labels;
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
