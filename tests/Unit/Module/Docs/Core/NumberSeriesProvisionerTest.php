<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\NumberSeriesProvisioner;

class NumberSeriesProvisionerTest extends TestCase
{
    use NumberSeriesDbTrait;

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }

    /**
     * @param array<string, array<string, mixed>> $docTypes
     */
    private function buildConfig(array $docTypes): ConfigRuntime
    {
        $items = ['docs.core.docTypes' => $docTypes];
        $data = ['_meta' => ['language' => 'cs'], 'items' => $items];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode($data),
        );
        return ConfigRuntime::load($this->tmpDir, 'cs');
    }

    /**
     * Recording mock — captures inserts into in-memory rows + answers
     * existence lookups by doc_type.
     *
     * @param list<array{id: int, doc_type: string, docState: int}> $existing
     */
    private function recordingDb(array $existing = []): object
    {
        $store = new \stdClass();
        $store->tables = ['docs_core_number_series' => $existing];
        $store->autoIncrement = count($existing);

        $db = $this->createMock(DataSourceConnection::class);

        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, mixed ...$params) use ($store): ?array {
                if (str_contains($sql, 'docs_core_number_series')
                    && str_contains($sql, 'doc_type')
                ) {
                    $needle = (string) ($params[0] ?? '');
                    $excluded = (int) ($params[1] ?? 0);
                    foreach ($store->tables['docs_core_number_series'] as $row) {
                        if (($row['doc_type'] ?? '') === $needle
                            && (int) ($row['docState'] ?? 0) !== $excluded
                        ) {
                            return $row;
                        }
                    }
                    return null;
                }
                return null;
            }
        );

        $db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use ($store): int {
                $store->autoIncrement++;
                $row = $data;
                $row['id'] = $store->autoIncrement;
                $store->tables[$table][] = $row;
                return $store->autoIncrement;
            }
        );

        $store->db = $db;
        return $store;
    }

    public function testEmptyDsCreatesOneSeriesPerDocType(): void
    {
        $store = $this->recordingDb();
        $config = $this->buildConfig([
            'invno' => [
                'name'    => 'Issued invoice',
                'name:cs' => 'Faktura vydaná',
                'doc_number_pattern_default' => '%D%y%C%4',
            ],
            'invni' => [
                'name'    => 'Received invoice',
                'name:cs' => 'Faktura přijatá',
                'doc_number_pattern_default' => '%D%y%C%4',
            ],
        ]);

        $provisioner = new NumberSeriesProvisioner($store->db, $config);
        $result = $provisioner->provision();

        $this->assertSame(2, $result['numberSeries']['created']);
        $this->assertSame(0, $result['numberSeries']['existing']);
        $this->assertCount(2, $store->tables['docs_core_number_series']);

        $invno = $store->tables['docs_core_number_series'][0];
        $this->assertSame('invno', $invno['doc_type']);
        $this->assertSame('Faktura vydaná', $invno['name']);
        $this->assertSame('%D%y%C%4', $invno['doc_number_pattern']);
        $this->assertSame('fiscal_year', $invno['reset_scope']);
        $this->assertSame(40, $invno['docState']);
        $this->assertSame(3, $invno['docStateMain']);
        $this->assertNull($invno['doc_number_code']);
    }

    public function testSecondRunIsNoOp(): void
    {
        $store = $this->recordingDb([
            ['id' => 1, 'doc_type' => 'invno', 'docState' => 40],
            ['id' => 2, 'doc_type' => 'invni', 'docState' => 40],
        ]);
        $config = $this->buildConfig([
            'invno' => ['name:cs' => 'Faktura vydaná', 'doc_number_pattern_default' => '%D%y%C%4'],
            'invni' => ['name:cs' => 'Faktura přijatá', 'doc_number_pattern_default' => '%D%y%C%4'],
        ]);

        $provisioner = new NumberSeriesProvisioner($store->db, $config);
        $result = $provisioner->provision();

        $this->assertSame(0, $result['numberSeries']['created']);
        $this->assertSame(2, $result['numberSeries']['existing']);
        $this->assertCount(2, $store->tables['docs_core_number_series']);
    }

    public function testDeletedSeriesDoesNotPreventCreation(): void
    {
        $store = $this->recordingDb([
            ['id' => 1, 'doc_type' => 'invno', 'docState' => 90],
        ]);
        $config = $this->buildConfig([
            'invno' => ['name:cs' => 'Faktura vydaná', 'doc_number_pattern_default' => '%D%y%C%4'],
        ]);

        $provisioner = new NumberSeriesProvisioner($store->db, $config);
        $result = $provisioner->provision();

        $this->assertSame(1, $result['numberSeries']['created']);
        $this->assertSame(0, $result['numberSeries']['existing']);
        $this->assertCount(2, $store->tables['docs_core_number_series']);
    }

    public function testMissingDocTypesCfgItemReturnsZeroes(): void
    {
        $store = $this->recordingDb();
        $items = [];
        $data = ['_meta' => ['language' => 'cs'], 'items' => $items];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode($data),
        );
        $config = ConfigRuntime::load($this->tmpDir, 'cs');

        $provisioner = new NumberSeriesProvisioner($store->db, $config);
        $result = $provisioner->provision();

        $this->assertSame(0, $result['numberSeries']['created']);
        $this->assertSame(0, $result['numberSeries']['existing']);
        $this->assertCount(0, $store->tables['docs_core_number_series']);
    }

    public function testBoundTypeDoesNotGetUnboundSeries(): void
    {
        $store = $this->recordingDb();
        $config = $this->buildConfig([
            'invno' => ['name:cs' => 'Faktura vydaná', 'doc_number_pattern_default' => '%D%y%C%4'],
            'cashb' => [
                'name:cs' => 'Pokladní doklad',
                'series_binding' => 'cash_desk',
                'doc_number_pattern_default' => '%D%C%y%5',
            ],
        ]);

        $result = (new NumberSeriesProvisioner($store->db, $config))->provision();

        $this->assertSame(1, $result['numberSeries']['created']);
        $this->assertSame(0, $result['numberSeries']['existing']);
        $this->assertSame(
            ['invno'],
            array_column($store->tables['docs_core_number_series'], 'doc_type'),
        );
    }

    public function testFallbackPatternUsedWhenDocTypeMissing(): void
    {
        $store = $this->recordingDb();
        $config = $this->buildConfig([
            'invno' => ['name:cs' => 'Faktura vydaná'],
        ]);

        $provisioner = new NumberSeriesProvisioner($store->db, $config);
        $provisioner->provision();

        $row = $store->tables['docs_core_number_series'][0];
        $this->assertSame('%D%y%4', $row['doc_number_pattern']);
    }

    // ── kód řady (#166 D17, U5) ─────────────────────────────────────────────

    /**
     * DataSourceConnection nad řadami v `$store`: insert přidá řádek,
     * doplnění kódu běží nad týmiž řádky.
     *
     * @param \ArrayObject<string, mixed> $store
     * @param list<array<string, mixed>> $existing
     */
    private function dbWithCodes(\ArrayObject $store, array $existing = []): DataSourceConnection
    {
        $dibi = $this->seriesDb($existing, [], $store);
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(static function (string $sql, mixed ...$params) use ($store): ?array {
            foreach ($store['rows'] as $row) {
                if ($row['doc_type'] === $params[0] && (int) $row['docState'] !== (int) $params[1]) {
                    return $row;
                }
            }
            return null;
        });
        $db->method('insertRow')->willReturnCallback(static function (string $table, array $data) use ($store): int {
            $id = count($store['rows']) + 1;
            $store['rows'] = [...$store['rows'], ['id' => $id, 'code' => null] + $data];
            return $id;
        });
        $db->method('getDibiConnection')->willReturn($dibi);
        return $db;
    }

    public function testCreatedSeriesGetTheirCodes(): void
    {
        $store = new \ArrayObject();
        $config = $this->buildConfig([
            'invno' => ['name' => 'Faktura vydaná'],
            'invni' => ['name' => 'Faktura přijatá'],
        ]);

        (new NumberSeriesProvisioner($this->dbWithCodes($store), $config))->provision();

        $this->assertSame([1 => 'invno', 2 => 'invni'], self::codesOf($store), 'výchozí řada typu má kód typu dokladu');
    }

    public function testWithoutNewSeriesCodesAreNotTouched(): void
    {
        // Doplnění existujících řad je věc ds-upgrade; provisioner, který nic
        // nezaložil, do tabulky nesahá.
        $store = new \ArrayObject();
        $db = $this->dbWithCodes($store, [['id' => 1, 'doc_type' => 'invno', 'code' => null, 'docState' => 40]]);

        $result = (new NumberSeriesProvisioner($db, $this->buildConfig(['invno' => ['name' => 'Faktura vydaná']])))->provision();

        $this->assertSame(['created' => 0, 'existing' => 1], $result['numberSeries']);
        $this->assertSame([1 => null], self::codesOf($store));
    }
}
