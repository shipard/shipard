<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons\Values;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\Values\DirectoryValuesNormalizer;
use Shipard\Module\Base\Persons\Values\PersonValues;

/** Runner nad řádky v paměti — dotazy jsou v chráněných švech. */
final class InMemoryDirectoryValuesNormalizer extends DirectoryValuesNormalizer
{
    /** @var list<array{table: string, id: int, changes: array<string, mixed>}> */
    public array $updates = [];

    /**
     * @param array<string, list<array<string, mixed>>> $tables
     * @param list<string>|null $only
     */
    public function __construct(Connection $db, public array $tables, string $dsCountry = 'cz', bool $ownAccounts = true, int $batchSize = 1000, ?array $only = null, bool $verbose = false)
    {
        parent::__construct($db, $dsCountry, $ownAccounts, $batchSize, $only, $verbose);
    }

    protected function loadPersonLabels(): array
    {
        $labels = [];
        foreach ($this->tables[self::TABLE_PERSONS] ?? [] as $row) {
            $labels[(int) $row['id']] = ['id' => (int) $row['id'], 'code' => (string) ($row['person_id'] ?? ''), 'name' => (string) ($row['full_name'] ?? '')];
        }
        return $labels;
    }

    protected function loadAddressRows(): array
    {
        $rows = array_filter(
            $this->tables[self::TABLE_ADDRESSES] ?? [],
            static fn (array $r): bool => (int) ($r['docState'] ?? 40) !== 90 && ($r['country'] ?? null) !== null && $r['country'] !== '',
        );
        usort($rows, static fn (array $a, array $b): int => [$a['person'], $a['order_pos'] ?? 0, $a['id']] <=> [$b['person'], $b['order_pos'] ?? 0, $b['id']]);
        return array_values($rows);
    }

    protected function fetchBatch(string $table, array $columns, int $afterId, int $limit): array
    {
        $rows = array_values(array_filter($this->tables[$table] ?? [], static fn (array $r): bool => (int) $r['id'] > $afterId));
        usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        $out = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $projected = [];
            foreach ($columns as $column) {
                $projected[$column] = $row[$column] ?? null;
            }
            $out[] = $projected;
        }
        return $out;
    }

    protected function updateRow(string $table, int $id, array $changes): void
    {
        $this->updates[] = ['table' => $table, 'id' => $id, 'changes' => $changes];
        foreach ($this->tables[$table] as &$row) {
            if ((int) $row['id'] === $id) {
                $row = $changes + $row;
            }
        }
        unset($row);
    }
}

/**
 * Normalizace existujících údajů adresáře (#118 D22): každá tabulka a sloupec
 * stejnými pravidly jako uložení, země osoby z uložených adres (D18), adresa
 * bez země podle země osoby (D24), přesun IBAN ze sloupce čísla účtu ve
 * všech stavech (D20), dry-run bez zápisu, idempotence, výpis nevalidních
 * hodnot bez hodnot; `--only` po druzích a podrobný výpis s osobou,
 * hodnotami a návrhy oprav (D27).
 */
class DirectoryValuesNormalizerTest extends TestCase
{
    /** @return array<string, list<array<string, mixed>>> */
    private function tables(): array
    {
        return [
            'base_persons_persons' => [
                ['id' => 1, 'person_id' => 'F001', 'full_name' => 'Acme s.r.o.', 'company_id' => '6947', 'vat_id' => 'cz 0000 6947', 'tax_id' => null, 'phone' => '577 123 456', 'email' => ' Odbyt@Acme.Example ', 'web' => 'https://www.acme.example/'],
                ['id' => 2, 'person_id' => 'F002', 'full_name' => 'Bratislavská s.r.o.', 'company_id' => '36339008', 'vat_id' => null, 'tax_id' => null, 'phone' => '0905 123 456', 'email' => null, 'web' => null],
                ['id' => 3, 'person_id' => 'F003', 'full_name' => 'Kanonická a.s.', 'company_id' => '00006947', 'vat_id' => 'CZ00006947', 'tax_id' => null, 'phone' => '+420577123456', 'email' => 'odbyt@acme.example', 'web' => ''],
                ['id' => 4, 'person_id' => 'F004', 'full_name' => 'Překlep s.r.o.', 'company_id' => '52654151', 'vat_id' => null, 'tax_id' => null, 'phone' => null, 'email' => null, 'web' => null],
            ],
            'base_persons_addresses' => [
                ['id' => 10, 'person' => 2, 'address_type' => 2, 'order_pos' => 1, 'zip' => '110 00', 'country' => 'cz'],
                ['id' => 11, 'person' => 2, 'address_type' => 1, 'order_pos' => 2, 'zip' => '811 01', 'country' => 'SK'],
                ['id' => 12, 'person' => 1, 'address_type' => 1, 'order_pos' => 1, 'zip' => ' 760 01 ', 'country' => null],
                ['id' => 13, 'person' => 5, 'address_type' => 1, 'order_pos' => 1, 'zip' => ' SW1A 1AA ', 'country' => 'GB'],
                ['id' => 14, 'person' => 1, 'address_type' => 2, 'order_pos' => 2, 'zip' => '7790', 'country' => '', 'docState' => 90],
            ],
            'base_persons_contacts' => [
                ['id' => 20, 'person' => 2, 'email' => ' Jana@Acme.Example', 'phone' => '0905 123 456'],
            ],
            'base_persons_bank_accounts' => [
                ['id' => 30, 'person' => 1, 'account_number' => 'CZ65 0800 0000 1920 0014 5399', 'iban' => null, 'bic' => 'gibaczpx'],
                ['id' => 31, 'person' => 1, 'account_number' => 'CZ6508000000192000145399', 'iban' => 'CZ65 0800 0000 1920 0014 5399', 'bic' => null],
                ['id' => 32, 'person' => 1, 'account_number' => 'CZ6508000000192000145399', 'iban' => 'CZ8520100000002308417557', 'bic' => null],
                ['id' => 33, 'person' => 1, 'account_number' => '19 - 2000145399 / 0800', 'iban' => '', 'bic' => ''],
                ['id' => 34, 'person' => 1, 'account_number' => 'DE72 1007 0000 0532 0130 00', 'iban' => null, 'bic' => null],
            ],
            'economy_codebooks_bank_accounts' => [
                ['id' => 40, 'code' => 'HLAVNI', 'name' => 'Hlavní účet', 'account_number' => 'CZ6508000000192000145399', 'iban' => null, 'bic' => null],
            ],
        ];
    }

    /**
     * Nálezy s návrhem opravy (D27): IČO s překlepem a platné DIČ, platné
     * číslo účtu s IBAN o jednu číslici kratším, číslo bez mod 11 s platným IBAN.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function tablesWithSuggestions(): array
    {
        return [
            'base_persons_persons' => [
                ['id' => 7, 'person_id' => 'F007', 'full_name' => 'Překlep v IČO s.r.o.', 'company_id' => '52654151', 'vat_id' => 'CZ52654150', 'tax_id' => null, 'phone' => null, 'email' => null, 'web' => null],
            ],
            'base_persons_addresses' => [],
            'base_persons_contacts' => [],
            'base_persons_bank_accounts' => [
                ['id' => 70, 'person' => 7, 'account_number' => '19-2000145399/0800', 'iban' => 'CZ650800000019200014539', 'bic' => null],
                ['id' => 71, 'person' => 7, 'account_number' => '2308417558/2010', 'iban' => 'CZ8520100000002308417557', 'bic' => null],
                ['id' => 72, 'person' => 7, 'account_number' => '2308417558/2010', 'iban' => 'CZ8520100000002308417558', 'bic' => null],
            ],
            'economy_codebooks_bank_accounts' => [],
        ];
    }

    /** @param list<string>|null $only */
    private function normalizer(bool $ownAccounts = true, int $batchSize = 1000, ?array $tables = null, ?array $only = null, bool $verbose = false): InMemoryDirectoryValuesNormalizer
    {
        return new InMemoryDirectoryValuesNormalizer($this->createMock(Connection::class), $tables ?? $this->tables(), 'cz', $ownAccounts, $batchSize, $only, $verbose);
    }

    /** @return array<string, mixed> */
    private function changesOf(InMemoryDirectoryValuesNormalizer $n, string $table, int $id): array
    {
        foreach ($n->updates as $update) {
            if ($update['table'] === $table && $update['id'] === $id) {
                return $update['changes'];
            }
        }
        return [];
    }

    public function testPersonsFollowTheirCountryFromStoredAddresses(): void
    {
        $n = $this->normalizer();
        $report = $n->run(false);

        $this->assertSame(
            ['company_id' => '00006947', 'vat_id' => 'CZ00006947', 'phone' => '+420577123456', 'email' => 'odbyt@acme.example', 'web' => 'www.acme.example'],
            $this->changesOf($n, 'base_persons_persons', 1),
            'osoba bez adresy se zemí: země zdroje dat; jen změněné sloupce',
        );
        $this->assertSame(['phone' => '+421905123456'], $this->changesOf($n, 'base_persons_persons', 2), 'sídlo sk má přednost před první adresou cz');
        $this->assertSame([], $this->changesOf($n, 'base_persons_persons', 3), 'kanonický řádek beze změny');
        $this->assertSame(
            ['rows' => 4, 'changed' => 2, 'columns' => ['company_id' => 1, 'vat_id' => 1, 'phone' => 2, 'email' => 1, 'web' => 1]],
            $report['tables']['base_persons_persons'],
        );
    }

    public function testAddressesWithoutCountryFollowThePersonCountry(): void
    {
        $n = $this->normalizer();
        $n->run(false);

        $this->assertSame(['zip' => '76001'], $this->changesOf($n, 'base_persons_addresses', 12), 'bez země podle země osoby (D24), `country` se nedoplňuje');
        $this->assertSame(['zip' => 'SW1A 1AA', 'country' => 'gb'], $this->changesOf($n, 'base_persons_addresses', 13));
        $this->assertSame(['zip' => '81101', 'country' => 'sk'], $this->changesOf($n, 'base_persons_addresses', 11));
        $this->assertSame(['zip' => '11000'], $this->changesOf($n, 'base_persons_addresses', 10));
        $this->assertSame([], $this->changesOf($n, 'base_persons_addresses', 14), 'řádek v koši: tvar už kanonický');
    }

    public function testContactsFollowThePersonCountry(): void
    {
        $n = $this->normalizer();
        $report = $n->run(false);

        $this->assertSame(['email' => 'jana@acme.example', 'phone' => '+421905123456'], $this->changesOf($n, 'base_persons_contacts', 20));
        $this->assertSame(['rows' => 1, 'changed' => 1, 'columns' => ['email' => 1, 'phone' => 1]], $report['tables']['base_persons_contacts']);
    }

    public function testBankAccountsMoveIbanFromAccountNumberInEveryState(): void
    {
        $n = $this->normalizer();
        $report = $n->run(false);

        $this->assertSame(
            ['account_number' => '19-2000145399/0800', 'iban' => 'CZ6508000000192000145399', 'bic' => 'GIBACZPX'],
            $this->changesOf($n, 'base_persons_bank_accounts', 30),
            'IBAN v čísle účtu, iban prázdný → přesun',
        );
        $this->assertSame(
            ['account_number' => '19-2000145399/0800', 'iban' => 'CZ6508000000192000145399'],
            $this->changesOf($n, 'base_persons_bank_accounts', 31),
            'iban nese tentýž účet → tuzemské číslo do čísla účtu',
        );
        $this->assertSame([], $this->changesOf($n, 'base_persons_bank_accounts', 32), 'iban nese jiný účet → beze změny');
        $this->assertSame(['account_number' => '19-2000145399/0800'], $this->changesOf($n, 'base_persons_bank_accounts', 33), 'jen tvar');
        $this->assertSame(
            ['account_number' => '', 'iban' => 'DE72100700000532013000'],
            $this->changesOf($n, 'base_persons_bank_accounts', 34),
            'cizí IBAN: číslo účtu prázdné',
        );
        $this->assertSame(
            ['rows' => 5, 'changed' => 4, 'columns' => ['account_number' => 4, 'iban' => 3, 'bic' => 1], 'ibanMoved' => 3],
            $report['tables']['base_persons_bank_accounts'],
        );
        $this->assertSame(
            ['account_number' => '19-2000145399/0800', 'iban' => 'CZ6508000000192000145399'],
            $this->changesOf($n, 'economy_codebooks_bank_accounts', 40),
            'vlastní účty touž funkcí',
        );
        $this->assertSame(1, $report['tables']['economy_codebooks_bank_accounts']['ibanMoved']);
        $this->assertSame(['rows' => 16, 'changed' => 12, 'invalid' => count($report['invalid'])], $report['totals']);
    }

    public function testInvalidValuesAreListedWithoutValues(): void
    {
        $report = $this->normalizer()->run(false);

        foreach ($report['invalid'] as $entry) {
            $this->assertSame(['table', 'id', 'column', 'code'], array_keys($entry), 'jen tabulka, id, sloupec a kód');
        }
        $this->assertContains(['table' => 'base_persons_persons', 'id' => 4, 'column' => 'company_id', 'code' => 'company_id_invalid'], $report['invalid']);
        $this->assertContains(['table' => 'base_persons_addresses', 'id' => 14, 'column' => 'zip', 'code' => 'zip_invalid'], $report['invalid'], 'PSČ bez země podle země osoby (D24)');
        $mismatch = array_filter($report['invalid'], static fn (array $e): bool => $e['table'] === 'base_persons_bank_accounts' && $e['id'] === 32);
        $this->assertNotEmpty($mismatch, 'IBAN jiného účtu v čísle účtu se nehlásí mlčky');
        $clean = array_filter($report['invalid'], static fn (array $e): bool => in_array($e['id'], [1, 2, 3, 10, 11, 12, 13, 30, 31, 33, 34, 40], true));
        $this->assertSame([], array_values($clean), 'kanonické a platné řádky bez nálezu');
        $this->assertSame(count($report['invalid']), $report['totals']['invalid']);
    }

    public function testDryRunWritesNothing(): void
    {
        $n = $this->normalizer();
        $report = $n->run(true);

        $this->assertSame([], $n->updates);
        $this->assertTrue($report['dryRun']);
        $this->assertSame(12, $report['totals']['changed'], 'počty stejné jako v ostrém běhu');
        $this->assertSame(3, $report['tables']['base_persons_bank_accounts']['ibanMoved']);
    }

    public function testSecondRunChangesNothing(): void
    {
        $n = $this->normalizer();
        $first = $n->run(false);
        $n->updates = [];
        $second = $n->run(false);

        $this->assertSame([], $n->updates);
        $this->assertSame(0, $second['totals']['changed']);
        $this->assertSame($first['invalid'], $second['invalid'], 'nevalidní hodnoty se neopravují, jen vypisují');
        foreach ($second['tables'] as $stats) {
            $this->assertSame(0, $stats['changed']);
        }
    }

    public function testKeysetBatchesCoverAllRows(): void
    {
        $tables = ['base_persons_persons' => $this->tables()['base_persons_persons']];
        $report = $this->normalizer(batchSize: 2, tables: $tables)->run(true);
        $this->assertSame(4, $report['tables']['base_persons_persons']['rows']);
        $this->assertSame(1, $report['tables']['base_persons_persons']['changed'], 'bez adres má osoba 2 zemi zdroje dat (cz) a její sk číslo se neslije (D26)');

        $exact = $this->normalizer(batchSize: 4, tables: $tables)->run(true);
        $this->assertSame(4, $exact['tables']['base_persons_persons']['rows'], 'dávka přesně plná nekončí smyčku předčasně');
    }

    // ── --only (D27) ────────────────────────────────────────────────────────

    public function testColumnKindsCoverTheSaveColumns(): void
    {
        $this->assertSame(array_keys(PersonValues::PERSON_COLUMNS), array_keys(DirectoryValuesNormalizer::COLUMN_KINDS['base_persons_persons']));
        $this->assertSame(array_keys(PersonValues::CONTACT_COLUMNS), array_keys(DirectoryValuesNormalizer::COLUMN_KINDS['base_persons_contacts']));
        foreach (DirectoryValuesNormalizer::COLUMN_KINDS as $columns) {
            foreach ($columns as $kind) {
                $this->assertContains($kind, DirectoryValuesNormalizer::ONLY_KINDS);
            }
        }
    }

    public function testOnlyRestrictsColumnsCountsAndFindings(): void
    {
        $n = $this->normalizer(only: ['phone']);
        $report = $n->run(false);

        $this->assertSame(['phone'], $report['only']);
        $this->assertSame(['rows' => 4, 'changed' => 2, 'columns' => ['phone' => 2]], $report['tables']['base_persons_persons']);
        $this->assertSame(['rows' => 1, 'changed' => 1, 'columns' => ['phone' => 1]], $report['tables']['base_persons_contacts']);
        $this->assertSame(['skipped' => 'not_selected'], $report['tables']['base_persons_addresses']);
        $this->assertSame(['skipped' => 'not_selected'], $report['tables']['base_persons_bank_accounts']);
        $this->assertSame(['skipped' => 'not_selected'], $report['tables']['economy_codebooks_bank_accounts']);
        $this->assertSame(['phone' => '+420577123456'], $this->changesOf($n, 'base_persons_persons', 1), 'IČO, DIČ, e-mail ani web se nemění');
        $this->assertSame([], $this->changesOf($n, 'base_persons_bank_accounts', 30));
        $this->assertSame([], $report['invalid'], 'nálezy jen pro vybrané druhy — IČO osoby 4 se nehlásí');
        $this->assertSame(['rows' => 5, 'changed' => 3, 'invalid' => 0], $report['totals']);

        $accounts = $this->normalizer(only: ['accounts'])->run(true);
        $this->assertSame(['skipped' => 'not_selected'], $accounts['tables']['base_persons_persons']);
        $this->assertSame(
            ['rows' => 5, 'changed' => 4, 'columns' => ['account_number' => 4, 'iban' => 3, 'bic' => 1], 'ibanMoved' => 3],
            $accounts['tables']['base_persons_bank_accounts'],
            'přesun IBAN je jedna změna účtu',
        );
        $this->assertSame(1, $accounts['tables']['economy_codebooks_bank_accounts']['ibanMoved']);
        $this->assertSame(['base_persons_bank_accounts'], array_values(array_unique(array_column($accounts['invalid'], 'table'))));

        $n = $this->normalizer(only: ['zip', 'ids']);
        $report = $n->run(false);
        $this->assertSame(['company_id' => '00006947', 'vat_id' => 'CZ00006947'], $this->changesOf($n, 'base_persons_persons', 1));
        $this->assertSame(['zip' => '76001'], $this->changesOf($n, 'base_persons_addresses', 12));
        $this->assertSame(['skipped' => 'not_selected'], $report['tables']['base_persons_contacts']);
        $this->assertContains(['table' => 'base_persons_persons', 'id' => 4, 'column' => 'company_id', 'code' => 'company_id_invalid'], $report['invalid']);
        $this->assertContains(['table' => 'base_persons_addresses', 'id' => 14, 'column' => 'zip', 'code' => 'zip_invalid'], $report['invalid']);
    }

    public function testUnknownKindIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('xyz');
        $this->normalizer(only: ['phone', 'xyz']);
    }

    // ── podrobný výpis (D27) ────────────────────────────────────────────────

    public function testVerboseListsEveryChangeWithPersonAndValues(): void
    {
        $report = $this->normalizer(verbose: true)->run(true);

        $this->assertTrue($report['verbose']);
        $changes = [];
        foreach ($report['changes'] as $change) {
            $changes[$change['table'] . ':' . $change['id']] = $change;
        }
        $this->assertSame(
            [
                'table'     => 'base_persons_persons',
                'id'        => 1,
                'person'    => ['id' => 1, 'code' => 'F001', 'name' => 'Acme s.r.o.'],
                'account'   => null,
                'ibanMoved' => false,
                'columns'   => [
                    'company_id' => ['from' => '6947', 'to' => '00006947'],
                    'vat_id'     => ['from' => 'cz 0000 6947', 'to' => 'CZ00006947'],
                    'phone'      => ['from' => '577 123 456', 'to' => '+420577123456'],
                    'email'      => ['from' => ' Odbyt@Acme.Example ', 'to' => 'odbyt@acme.example'],
                    'web'        => ['from' => 'https://www.acme.example/', 'to' => 'www.acme.example'],
                ],
            ],
            $changes['base_persons_persons:1'],
        );
        $this->assertSame(['id' => 2, 'code' => 'F002', 'name' => 'Bratislavská s.r.o.'], $changes['base_persons_contacts:20']['person'], 'kontakt nese osobu');
        $this->assertSame(
            [
                'table'     => 'base_persons_bank_accounts',
                'id'        => 30,
                'person'    => ['id' => 1, 'code' => 'F001', 'name' => 'Acme s.r.o.'],
                'account'   => null,
                'ibanMoved' => true,
                'columns'   => [
                    'account_number' => ['from' => 'CZ65 0800 0000 1920 0014 5399', 'to' => '19-2000145399/0800'],
                    'iban'           => ['from' => null, 'to' => 'CZ6508000000192000145399'],
                    'bic'            => ['from' => 'gibaczpx', 'to' => 'GIBACZPX'],
                ],
            ],
            $changes['base_persons_bank_accounts:30'],
            'přesun IBAN jako jedna položka se všemi sloupci',
        );
        $this->assertFalse($changes['base_persons_bank_accounts:33']['ibanMoved'], 'jen tvar');
        $this->assertSame(
            ['person' => null, 'account' => ['code' => 'HLAVNI', 'name' => 'Hlavní účet'], 'ibanMoved' => true],
            ['person' => $changes['economy_codebooks_bank_accounts:40']['person'], 'account' => $changes['economy_codebooks_bank_accounts:40']['account'], 'ibanMoved' => $changes['economy_codebooks_bank_accounts:40']['ibanMoved']],
            'vlastní účet kódem a názvem účtu',
        );
        $this->assertCount($report['totals']['changed'], $report['changes']);

        $invalid = [];
        foreach ($report['invalid'] as $entry) {
            $invalid[$entry['table'] . ':' . $entry['id'] . ':' . $entry['column']] = $entry;
        }
        $this->assertSame(
            [
                'table' => 'base_persons_persons', 'id' => 4, 'column' => 'company_id', 'code' => 'company_id_invalid',
                'person' => ['id' => 4, 'code' => 'F004', 'name' => 'Překlep s.r.o.'], 'account' => null,
                'value' => '52654151', 'reason' => 'nemá platný kontrolní součet', 'suggestion' => null,
            ],
            $invalid['base_persons_persons:4:company_id'],
        );
        $this->assertSame(['id' => 1, 'code' => 'F001', 'name' => 'Acme s.r.o.'], $invalid['base_persons_addresses:14:zip']['person'], 'adresa nese osobu');
        $this->assertSame('7790', $invalid['base_persons_addresses:14:zip']['value']);
    }

    public function testVerboseFindingsCarrySuggestions(): void
    {
        $report = $this->normalizer(tables: $this->tablesWithSuggestions(), verbose: true)->run(true);

        $byKey = [];
        foreach ($report['invalid'] as $entry) {
            $byKey[$entry['id'] . ':' . $entry['column']] = $entry;
        }
        $this->assertSame('52654150', $byKey['7:company_id']['suggestion'], 'IČO z DIČ');
        $this->assertSame('CZ6508000000192000145399', $byKey['70:iban']['suggestion'], 'IBAN z platného čísla účtu');
        $this->assertSame('iban_invalid', $byKey['70:iban']['code']);
        $this->assertSame('2308417557/2010', $byKey['71:account_number']['suggestion'], 'číslo účtu z platného IBAN');
        $this->assertSame('nesplňuje kontrolu mod 11', $byKey['71:account_number']['reason']);
        $this->assertNull($byKey['72:account_number']['suggestion'], 'oba neplatné: bez návrhu');
        $this->assertNull($byKey['72:iban']['suggestion']);
        $this->assertSame([], $report['changes'], 'tvary už kanonické — nálezy se neopravují');
    }

    public function testWithoutVerboseTheReportCarriesNoValuesOrNames(): void
    {
        $report = $this->normalizer()->run(true);

        $this->assertArrayNotHasKey('changes', $report);
        $this->assertArrayNotHasKey('verbose', $report);
        $this->assertArrayNotHasKey('only', $report);
        $json = (string) json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Acme', 'F001', 'Překlep', '577 123 456', '+420577123456', 'odbyt@acme.example', 'CZ6508000000192000145399', '19-2000145399/0800', '52654151', '7790', 'HLAVNI', 'Hlavní'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "výstup bez hodnot a jmen: {$secret}");
        }
        foreach ($report['invalid'] as $entry) {
            $this->assertSame(['table', 'id', 'column', 'code'], array_keys($entry));
        }
    }

    public function testOwnAccountsSkippedWithoutTheModule(): void
    {
        $n = $this->normalizer(ownAccounts: false);
        $report = $n->run(false);

        $this->assertSame(['skipped' => 'module_inactive'], $report['tables']['economy_codebooks_bank_accounts']);
        $this->assertSame([], $this->changesOf($n, 'economy_codebooks_bank_accounts', 40));
        $this->assertSame(15, $report['totals']['rows']);
        $this->assertSame(
            ['base_persons_persons', 'base_persons_addresses', 'base_persons_contacts', 'base_persons_bank_accounts', 'economy_codebooks_bank_accounts'],
            array_keys($report['tables']),
            'pořadí tabulek ve výpisu',
        );
    }
}
