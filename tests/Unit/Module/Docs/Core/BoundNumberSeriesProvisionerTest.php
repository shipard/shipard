<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\BoundNumberSeriesProvisioner;

class BoundNumberSeriesProvisionerTest extends TestCase
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

    /** @param array<string, array<string, mixed>> $docTypes */
    private function buildConfig(array $docTypes): ConfigRuntime
    {
        $data = ['_meta' => ['language' => 'cs'], 'items' => ['docs.core.docTypes' => $docTypes]];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode($data),
        );
        return ConfigRuntime::load($this->tmpDir, 'cs');
    }

    /** @return array<string, array<string, mixed>> */
    private function cfgCashAndInvoice(): array
    {
        return [
            'invno' => ['name' => 'Faktura vydaná', 'doc_number_pattern_default' => '%D%y%C%4'],
            'cashb' => [
                'name' => 'Pokladní doklad',
                'series_binding' => 'cash_desk',
                'doc_number_pattern_default' => '%D%C%y%5',
            ],
        ];
    }

    /**
     * Recording mock: entity tabulky (pokladny/sklady) jsou read-only,
     * docs_core_number_series přijímá inserty. fetchAll/fetchRow se
     * rozhodují podle tabulky v SQL.
     *
     * @param array<string, list<array<string, mixed>>> $tables
     */
    private function recordingDb(array $tables): object
    {
        $store = new \stdClass();
        $store->tables = $tables + ['docs_core_number_series' => []];
        $store->autoIncrement = count($store->tables['docs_core_number_series']);

        $db = $this->createMock(DataSourceConnection::class);

        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, mixed ...$params) use ($store): array {
                foreach ($store->tables as $table => $rows) {
                    if ($table === 'docs_core_number_series' || !str_contains($sql, $table)) {
                        continue;
                    }
                    // `docState IN %in` → pole stavů; skalár = starší tvar `docState = %i`
                    $states = is_array($params[0] ?? null) ? array_map(intval(...), $params[0]) : [(int) ($params[0] ?? 40)];
                    return array_values(array_filter(
                        $rows,
                        fn(array $r) => in_array((int) $r['docState'], $states, true),
                    ));
                }
                return [];
            }
        );

        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, mixed ...$params) use ($store): ?array {
                if (str_contains($sql, 'docs_core_number_series')) {
                    [$docType, $entityId, $excluded] = $params;
                    $column = str_contains($sql, ' warehouse = ') ? 'warehouse' : 'cash_desk';
                    foreach ($store->tables['docs_core_number_series'] as $row) {
                        if (($row['doc_type'] ?? '') === $docType
                            && (int) ($row[$column] ?? 0) === (int) $entityId
                            && (int) ($row['docState'] ?? 0) !== (int) $excluded
                        ) {
                            return $row;
                        }
                    }
                    return null;
                }
                foreach ($store->tables as $table => $rows) {
                    if ($table === 'docs_core_number_series' || !str_contains($sql, $table)) {
                        continue;
                    }
                    foreach ($rows as $row) {
                        if ((int) $row['id'] === (int) $params[0]) {
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

    public function testCreatesOneSeriesPerActiveCashDeskAndBoundType(): void
    {
        $store = $this->recordingDb([
            'economy_codebooks_cash_desks' => [
                ['id' => 1, 'code' => 'HP1', 'docState' => 40],
                ['id' => 2, 'code' => 'HP2', 'docState' => 40],
                ['id' => 3, 'code' => 'OLD', 'docState' => 10],
            ],
        ]);

        $result = (new BoundNumberSeriesProvisioner($store->db, $this->buildConfig($this->cfgCashAndInvoice())))
            ->provision();

        $this->assertSame(['created' => 2, 'existing' => 0], $result);
        $series = $store->tables['docs_core_number_series'];
        $this->assertCount(2, $series);

        $first = $series[0];
        $this->assertSame('cashb', $first['doc_type']);
        $this->assertSame(1, $first['cash_desk']);
        $this->assertArrayNotHasKey('warehouse', $first);
        $this->assertSame('Pokladní doklad — HP1', $first['name']);
        $this->assertSame('HP1', $first['doc_number_code']);
        $this->assertSame('%D%C%y%5', $first['doc_number_pattern']);
        $this->assertSame('fiscal_year', $first['reset_scope']);
        $this->assertSame(40, $first['docState']);
        $this->assertSame(3, $first['docStateMain']);
        $this->assertSame('HP2', $series[1]['doc_number_code']);
        // nevázaný typ invno se tady nevyrábí
        $this->assertSame(['cashb', 'cashb'], array_column($series, 'doc_type'));
    }

    public function testSecondRunIsNoOpAndDeletedSeriesIsRecreated(): void
    {
        $store = $this->recordingDb([
            'economy_codebooks_cash_desks' => [
                ['id' => 1, 'code' => 'HP1', 'docState' => 40],
                ['id' => 2, 'code' => 'HP2', 'docState' => 40],
            ],
            'docs_core_number_series' => [
                ['id' => 1, 'doc_type' => 'cashb', 'cash_desk' => 1, 'docState' => 40],
                ['id' => 2, 'doc_type' => 'cashb', 'cash_desk' => 2, 'docState' => 90],
            ],
        ]);
        $provisioner = new BoundNumberSeriesProvisioner($store->db, $this->buildConfig($this->cfgCashAndInvoice()));

        $this->assertSame(['created' => 1, 'existing' => 1], $provisioner->provision());
        $this->assertSame(['created' => 0, 'existing' => 2], $provisioner->provision());
        $this->assertCount(3, $store->tables['docs_core_number_series']);
    }

    public function testWarehouseBindingUsesWarehousesTableAndColumn(): void
    {
        $store = $this->recordingDb([
            'economy_codebooks_cash_desks' => [
                ['id' => 1, 'code' => 'HP1', 'docState' => 40],
            ],
            'economy_codebooks_warehouses' => [
                ['id' => 5, 'code' => 'SK1', 'docState' => 40],
            ],
        ]);
        $config = $this->buildConfig([
            'whs' => ['name' => 'Skladový doklad', 'series_binding' => 'warehouse'],
        ]);

        $result = (new BoundNumberSeriesProvisioner($store->db, $config))->provision();

        $this->assertSame(['created' => 1, 'existing' => 0], $result);
        $row = $store->tables['docs_core_number_series'][0];
        $this->assertSame('whs', $row['doc_type']);
        $this->assertSame(5, $row['warehouse']);
        $this->assertArrayNotHasKey('cash_desk', $row);
        $this->assertSame('SK1', $row['doc_number_code']);
        $this->assertSame('Skladový doklad — SK1', $row['name']);
        // fallback vzorec vázaných řad bez doc_number_pattern_default
        $this->assertSame('%D%C%y%5', $row['doc_number_pattern']);
    }

    public function testProvisionForCashDeskTouchesOnlyThatDesk(): void
    {
        $store = $this->recordingDb([
            'economy_codebooks_cash_desks' => [
                ['id' => 1, 'code' => 'HP1', 'docState' => 40],
                ['id' => 2, 'code' => 'HP2', 'docState' => 40],
                ['id' => 3, 'code' => 'NEW', 'docState' => 10],
            ],
        ]);
        $provisioner = new BoundNumberSeriesProvisioner($store->db, $this->buildConfig($this->cfgCashAndInvoice()));

        $this->assertSame(['created' => 1, 'existing' => 0], $provisioner->provisionForCashDesk(2));
        $this->assertSame(['created' => 0, 'existing' => 1], $provisioner->provisionForCashDesk(2));
        // pokladna mimo stav 40 a neexistující → no-op
        $this->assertSame(['created' => 0, 'existing' => 0], $provisioner->provisionForCashDesk(3));
        $this->assertSame(['created' => 0, 'existing' => 0], $provisioner->provisionForCashDesk(99));

        $series = $store->tables['docs_core_number_series'];
        $this->assertCount(1, $series);
        $this->assertSame(2, $series[0]['cash_desk']);
    }

    /**
     * Archivovaná pokladna (#59 Task E, E2): řady vznikají i pro stav 70
     * a dědí ho (70/4) — import historických dokladů ji potřebuje, UI ji
     * nenabízí. Koncept (10) řady nedostane.
     */
    public function testArchivedCashDeskGetsArchivedSeries(): void
    {
        $store = $this->recordingDb([
            'economy_codebooks_cash_desks' => [
                ['id' => 1, 'code' => 'HP1', 'docState' => 40],
                ['id' => 2, 'code' => 'OLD', 'docState' => 70],
                ['id' => 3, 'code' => 'NEW', 'docState' => 10],
            ],
        ]);
        $provisioner = new BoundNumberSeriesProvisioner($store->db, $this->buildConfig($this->cfgCashAndInvoice()));

        $this->assertSame(['created' => 2, 'existing' => 0], $provisioner->provision());
        $this->assertSame(['created' => 0, 'existing' => 2], $provisioner->provision());

        $series = $store->tables['docs_core_number_series'];
        $this->assertCount(2, $series);
        $this->assertSame([40, 70], array_column($series, 'docState'));
        $this->assertSame([3, 4], array_column($series, 'docStateMain'));
        $this->assertSame(2, $series[1]['cash_desk']);
        $this->assertSame('Pokladní doklad — OLD', $series[1]['name']);
    }

    public function testProvisionForArchivedCashDeskCreatesArchivedSeries(): void
    {
        $store = $this->recordingDb([
            'economy_codebooks_cash_desks' => [
                ['id' => 2, 'code' => 'OLD', 'docState' => 70],
                ['id' => 3, 'code' => 'NEW', 'docState' => 10],
            ],
        ]);
        $provisioner = new BoundNumberSeriesProvisioner($store->db, $this->buildConfig($this->cfgCashAndInvoice()));

        $this->assertSame(['created' => 1, 'existing' => 0], $provisioner->provisionForCashDesk(2));
        $this->assertSame(['created' => 0, 'existing' => 0], $provisioner->provisionForCashDesk(3), 'koncept nic');

        $series = $store->tables['docs_core_number_series'];
        $this->assertCount(1, $series);
        $this->assertSame(70, $series[0]['docState']);
        $this->assertSame(4, $series[0]['docStateMain']);
    }

    public function testUnknownBindingIsSkippedAndRejectedExplicitly(): void
    {
        $store = $this->recordingDb([
            'economy_codebooks_cash_desks' => [
                ['id' => 1, 'code' => 'HP1', 'docState' => 40],
            ],
        ]);
        $config = $this->buildConfig([
            'odd' => ['name' => 'Divný typ', 'series_binding' => 'spaceship'],
        ]);
        $provisioner = new BoundNumberSeriesProvisioner($store->db, $config);

        $this->assertSame(['created' => 0, 'existing' => 0], $provisioner->provision());

        $this->expectException(\InvalidArgumentException::class);
        $provisioner->provisionForEntity('spaceship', 1);
    }

    public function testNoBoundTypesIsNoOp(): void
    {
        $store = $this->recordingDb([
            'economy_codebooks_cash_desks' => [
                ['id' => 1, 'code' => 'HP1', 'docState' => 40],
            ],
        ]);
        $config = $this->buildConfig([
            'invno' => ['name' => 'Faktura vydaná', 'trade_dir' => 1],
        ]);

        $result = (new BoundNumberSeriesProvisioner($store->db, $config))->provision();

        $this->assertSame(['created' => 0, 'existing' => 0], $result);
        $this->assertCount(0, $store->tables['docs_core_number_series']);
    }

    // ── kód řady (#166 D17, U5) ─────────────────────────────────────────────

    public function testCreatedBoundSeriesGetsItsCode(): void
    {
        // Řada vzniká přímým insertem mimo Document (i líně z applieru) —
        // kód `<typ>-<kód pokladny>` má na konci téže operace.
        $store = new \ArrayObject();
        $dibi = $this->seriesDb([], ['economy_codebooks_cash_desks' => [4 => 'POK']], $store);
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(static function (string $sql, mixed ...$params): ?array {
            // pokladna podle id; řada (doc_type, pokladna) ještě není
            return str_contains($sql, 'docs_core_number_series') ? null : ['id' => 4, 'code' => 'POK', 'docState' => 40];
        });
        $db->method('insertRow')->willReturnCallback(static function (string $table, array $data) use ($store): int {
            $id = count($store['rows']) + 1;
            $store['rows'] = [...$store['rows'], ['id' => $id, 'code' => null] + $data];
            return $id;
        });
        $db->method('getDibiConnection')->willReturn($dibi);

        $result = (new BoundNumberSeriesProvisioner($db, $this->buildConfig($this->cfgCashAndInvoice())))
            ->provisionForCashDesk(4);

        $this->assertSame(['created' => 1, 'existing' => 0], $result);
        $this->assertSame([1 => 'cashb-POK'], self::codesOf($store));
    }
}
