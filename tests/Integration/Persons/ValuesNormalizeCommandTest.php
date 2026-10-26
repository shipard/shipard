<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Persons;

use Shipard\Command\DataSource\ValuesNormalizeCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class TestableValuesNormalizeCommand extends ValuesNormalizeCommand
{
    public function __construct(DataSourceConfig $dsConfig, DataSourceConnection $dsConnection, private readonly string $dsDir)
    {
        parent::__construct($dsConfig, $dsConnection, null);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }
}

/**
 * `shpd-ds values-normalize` nad reálným DS (#118 D22): osoba s IČO bez
 * vedoucích nul a telefonem bez předvolby, adresa bez země s PSČ s mezerou,
 * kontakt, účet s IBAN v čísle účtu — dry-run nic nezmění, ostrý běh
 * převede jen dotčené sloupce (docState zůstává), druhý běh hlásí 0 změn.
 * Aktivní moduly čte příkaz z main.json zdroje dat (economy.codebooks
 * přes install.base).
 */
class ValuesNormalizeCommandTest extends IntegrationTestCase
{
    private const PREFIX = 'IT-VN';

    private int $personId = 0;

    /** @var array<string, list<int>> */
    private array $children = [];

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->children as $table => $ids) {
            foreach ($ids as $id) {
                $dibi->delete($table)->where('[id] = %i', $id)->execute();
            }
        }
        if ($this->personId > 0) {
            $dibi->delete('base_persons_persons')->where('[id] = %i', $this->personId)->execute();
        }
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert($table, $row)->execute();
        $id = (int) $dibi->getInsertId();
        if ($table === 'base_persons_persons') {
            $this->personId = $id;
        } else {
            $this->children[$table][] = $id;
        }
        return $id;
    }

    /** @return array{person: int, address: int, contact: int, account: int} */
    private function seed(): array
    {
        $person = $this->insert('base_persons_persons', [
            'person_id'    => 'VN' . substr((string) abs(crc32($this->name())), 0, 6),
            'person_type'  => 2,
            'full_name'    => self::PREFIX . ' s.r.o.',
            'last_name'    => self::PREFIX . ' s.r.o.',
            'first_name'   => '',
            'company_id'   => '6947',
            'phone'        => '577 123 456',
            'email'        => ' Odbyt@Example.Test ',
            'docState'     => 40,
            'docStateMain' => 3,
        ]);
        $address = $this->insert('base_persons_addresses', [
            'person'       => $person,
            'address_type' => 1,
            'city'         => 'Zlín',
            'zip'          => ' 760 01 ',
            'country'      => null,
            'order_pos'    => 1,
            'docState'     => 40,
            'docStateMain' => 3,
        ]);
        $contact = $this->insert('base_persons_contacts', [
            'person'       => $person,
            'name'         => self::PREFIX . ' kontakt',
            'phone'        => '604 111 222',
            'email'        => 'Kontakt@Example.Test',
            'order_pos'    => 1,
            'docState'     => 40,
            'docStateMain' => 3,
        ]);
        $account = $this->insert('base_persons_bank_accounts', [
            'person'         => $person,
            'name'           => self::PREFIX . ' účet',
            'account_number' => 'CZ65 0800 0000 1920 0014 5399',
            'currency'       => 'czk',
            'source'         => 0,
            'order_pos'      => 0,
            'docState'       => 40,
            'docStateMain'   => 3,
        ]);
        return ['person' => $person, 'address' => $address, 'contact' => $contact, 'account' => $account];
    }

    /** @return array{0: int, 1: array<string, mixed>} exit kód a JSON report */
    private function runCommand(bool $dryRun, ?string $only = null, bool $verbose = false): array
    {
        $tester = new CommandTester(new TestableValuesNormalizeCommand($this->dsConfig, $this->db, $this->realDsPath));
        $input = ['--json' => true, '--dry-run' => $dryRun];
        if ($only !== null) {
            $input['--only'] = $only;
        }
        $exit = $tester->execute($input, $verbose ? ['verbosity' => OutputInterface::VERBOSITY_VERBOSE] : []);
        return [$exit, (array) json_decode($tester->getDisplay(), true)];
    }

    /** @return array<string, mixed> */
    private function row(string $table, int $id): array
    {
        return (array) $this->db->getDibiConnection()->fetch('SELECT * FROM %n WHERE [id] = %i', $table, $id);
    }

    public function testDryRunCountsAndWritesNothing(): void
    {
        $ids = $this->seed();
        [$exit, $report] = $this->runCommand(true);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertTrue($report['dryRun']);
        $this->assertGreaterThanOrEqual(1, $report['tables']['base_persons_persons']['changed']);
        $this->assertGreaterThanOrEqual(1, $report['tables']['base_persons_bank_accounts']['ibanMoved']);
        $this->assertArrayHasKey('rows', $report['tables']['economy_codebooks_bank_accounts'], 'economy.codebooks je na DS aktivní');
        $this->assertSame('577 123 456', $this->row('base_persons_persons', $ids['person'])['phone'], 'dry-run nic nezapsal');
        $this->assertSame(' 760 01 ', $this->row('base_persons_addresses', $ids['address'])['zip']);
        $this->assertSame('CZ65 0800 0000 1920 0014 5399', $this->row('base_persons_bank_accounts', $ids['account'])['account_number']);
    }

    public function testOnlyPhoneLeavesOtherColumnsAndVerboseNamesThePerson(): void
    {
        $ids = $this->seed();
        [$exit, $report] = $this->runCommand(true, 'phone', true);
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame(['phone'], $report['only']);
        $this->assertSame('not_selected', $report['tables']['base_persons_bank_accounts']['skipped']);
        $change = null;
        foreach ($report['changes'] as $entry) {
            if ($entry['table'] === 'base_persons_persons' && $entry['id'] === $ids['person']) {
                $change = $entry;
            }
        }
        $this->assertNotNull($change, 'podrobný výpis nese změnu telefonu osoby');
        $this->assertSame(['phone' => ['from' => '577 123 456', 'to' => '+420577123456']], $change['columns']);
        $this->assertSame(self::PREFIX . ' s.r.o.', $change['person']['name']);

        [$exit, $report] = $this->runCommand(false, 'phone');
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertArrayNotHasKey('changes', $report, 'bez -v žádné hodnoty');
        $person = $this->row('base_persons_persons', $ids['person']);
        $this->assertSame('+420577123456', $person['phone']);
        $this->assertSame('6947', $person['company_id'], 'IČO mimo --only zůstává');
        $this->assertSame('CZ65 0800 0000 1920 0014 5399', $this->row('base_persons_bank_accounts', $ids['account'])['account_number'], 'účet mimo --only zůstává');
        $this->assertSame('+420604111222', $this->row('base_persons_contacts', $ids['contact'])['phone']);

        [$exit, $second] = $this->runCommand(false, 'phone');
        $this->assertSame(0, $second['totals']['changed'], 'druhý běh téhož druhu nic nezmění');
    }

    public function testRunNormalizesAndSecondRunChangesNothing(): void
    {
        $ids = $this->seed();
        [$exit, $report] = $this->runCommand(false);
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertFalse($report['dryRun']);

        $person = $this->row('base_persons_persons', $ids['person']);
        $this->assertSame('00006947', $person['company_id']);
        $this->assertSame('+420577123456', $person['phone'], 'osoba bez adresy se zemí: země zdroje dat');
        $this->assertSame('odbyt@example.test', $person['email']);
        $this->assertSame(40, (int) $person['docState'], 'stav beze změny');

        $address = $this->row('base_persons_addresses', $ids['address']);
        $this->assertSame('76001', $address['zip'], 'adresa bez země podle země osoby (D24)');
        $this->assertNull($address['country'], 'země se nedoplňuje');

        $contact = $this->row('base_persons_contacts', $ids['contact']);
        $this->assertSame('+420604111222', $contact['phone']);
        $this->assertSame('kontakt@example.test', $contact['email']);

        $account = $this->row('base_persons_bank_accounts', $ids['account']);
        $this->assertSame('19-2000145399/0800', $account['account_number'], 'IBAN z čísla účtu přesunut (D20)');
        $this->assertSame('CZ6508000000192000145399', $account['iban']);

        [$exit, $second] = $this->runCommand(false);
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame(0, $second['totals']['changed'], 'druhý běh nic nezmění');
    }
}
