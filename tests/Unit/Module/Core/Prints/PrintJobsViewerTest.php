<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigCompiler;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Prints\PrintJobsViewer;
use Shipard\Tests\Unit\Module\Core\Devices\DevicesConfig;

/**
 * Přehled Tiskové úlohy (#177 U42): řádek seznamu, filtry, detail. Viewer
 * je jen ke čtení a bajty úlohy nenačítá.
 */
final class PrintJobsViewerTest extends TestCase
{
    use DevicesConfig;

    private const string JOBS = 'core_prints_jobs';

    private const array PRINT_JOB = [
        'id' => 31, 'job_id' => '0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10', 'kind' => 'print',
        'print_id' => 'docs.cashRegister.receiptPos', 'record_table' => 'docs_core_heads', 'record_id' => 1059,
        'device' => 3, 'peripheral' => 7, 'role' => 'receipt', 'format' => 'escpos', 'profile_id' => 'escpos-58-cp852',
        'data_size' => 1482, 'state' => 'failed', 'error_code' => 'PRINTER_ERROR', 'attempts' => 2, 'created_by' => 5,
        'warnings' => '[{"severity":"warning","code":"ESCPOS_PROFILE_UNKNOWN","text":"Unknown printer profile"}]',
        'created_at' => '2026-11-28 10:15:00', 'result_at' => '2026-11-28 10:15:04',
        'device_name' => 'Pokladna 1', 'peripheral_name' => 'Účtenky u kasy', 'peripheral_app_id' => 'mock-receipt',
        'user_name' => 'Jana Prodavačka', 'user_login' => 'jana',
    ];

    /** @param list<array> $calls */
    private function viewer(array &$calls, ?array $record = null, string $language = 'cs'): PrintJobsViewer
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$args) use (&$calls): array {
            $calls[] = $args;
            return [];
        });
        $db->method('fetchRow')->willReturnCallback(function (...$args) use (&$calls, $record): ?array {
            $calls[] = $args;
            return $record;
        });

        $viewer = new class($db, self::JOBS) extends PrintJobsViewer {
            /** @var list<array<string, mixed>> */
            public array $devices = [];

            protected function deviceRows(): array
            {
                return $this->devices;
            }
        };

        $module = 'modules/core/prints/config/';
        $items = [
            PrintJobsViewer::KINDS_CFG       => self::repoJsonc($module . 'jobKinds.jsonc', $language),
            PrintJobsViewer::STATES_CFG      => self::repoJsonc($module . 'jobStates.jsonc', $language),
            PrintJobsViewer::LABELS_CFG_ITEM => self::repoJsonc($module . 'viewerDetailLabels.jsonc', $language),
            'core.devices.peripheralRoles'   => self::repoJsonc('modules/core/devices/config/peripheralRoles.jsonc', $language),
            'core.system.viewerDetailLabels' => self::repoJsonc('modules/core/system/config/viewerDetailLabels.jsonc', $language),
            ConfigCompiler::PRINTS_ITEM      => ['docs.cashRegister.receiptPos' => ['name' => 'Účtenka', 'table' => 'docs_core_heads']],
        ];
        $config = $this->createStub(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $items[$id] ?? null);

        $viewer->setConfig($config);
        $viewer->setLanguage($language);
        $viewer->setTables([
            self::JOBS => TableDefinition::fromArray(self::repoJsonc('modules/core/prints/tables/' . self::JOBS . '.jsonc', $language)),
        ]);
        return $viewer;
    }

    public function testRowOfAFailedRecordPrint(): void
    {
        $calls = [];
        $row = $this->viewer($calls)->renderRow(self::PRINT_JOB);

        $this->assertSame(31, $row['id']);
        $this->assertSame('Účtenka · záznam 1059', $row['t1']);
        $this->assertSame('28.11.2026 10:15', $row['i1']);
        $this->assertSame(['Pokladna 1', 'Chyba', 'PRINTER_ERROR'], array_column($row['t2'], 'text'));
        // Červený je jen stav — kód chyby k němu je doplněk, ne druhý poplach.
        $this->assertSame(['muted', 'danger', 'muted'], array_column($row['t2'], 'class'));
        $this->assertSame('Jana Prodavačka • Pokusy: 2', $row['t3']);
        $this->assertSame('cancelled', $row['stateStyle']);
    }

    public function testRowOfAnUnverifiedTestReceiptAndDrawer(): void
    {
        $calls = [];
        $viewer = $this->viewer($calls);
        $job = ['kind' => 'test', 'print_id' => null, 'record_id' => null, 'state' => 'created', 'error_code' => null,
            'attempts' => 0, 'user_name' => '', 'user_login' => 'jana'] + self::PRINT_JOB;

        $row = $viewer->renderRow($job);
        $this->assertSame('Zkušební účtenka', $row['t1']);
        $this->assertSame(['Pokladna 1', 'Neověřeno'], array_column($row['t2'], 'text'));
        $this->assertSame('jana • Pokusy: 0', $row['t3']);
        $this->assertSame('edit', $row['stateStyle']);

        $row = $viewer->renderRow(['kind' => 'drawer', 'state' => 'sent', 'attempts' => 1] + $job);
        $this->assertSame('Otevření šuplíku', $row['t1']);
        $this->assertSame(['Pokladna 1', 'Odesláno'], array_column($row['t2'], 'text'));
        $this->assertSame('done', $row['stateStyle']);
    }

    public function testRowInEnglishAndUnknownPrintKeepsItsId(): void
    {
        $calls = [];
        $row = $this->viewer($calls, language: 'en')->renderRow(['print_id' => 'gone.module.print'] + self::PRINT_JOB);

        $this->assertSame('gone.module.print · record 1059', $row['t1']);
        $this->assertSame('2026-11-28 10:15', $row['i1']);
        $this->assertSame(['Pokladna 1', 'Failed', 'PRINTER_ERROR'], array_column($row['t2'], 'text'));
        $this->assertSame('Jana Prodavačka • Attempts: 2', $row['t3']);
    }

    public function testListNeverSelectsJobData(): void
    {
        $calls = [];
        $this->viewer($calls)->selectRows('printer', [
            ['id' => 'state', 'value' => 'failed'],
            ['id' => 'kind', 'value' => 'print'],
            ['id' => 'device', 'value' => '3'],
            ['id' => 'state', 'value' => ''],
        ], 0);

        $sql = (string) $calls[0][0];
        $this->assertStringNotContainsString('j.*', $sql);
        $this->assertStringNotContainsString('`data`', $sql);
        $this->assertStringContainsString('j.`state` = %s AND j.`kind` = %s AND j.`device` = %i', $sql);
        $this->assertStringContainsString('ORDER BY j.`created_at` DESC, j.`id` DESC', $sql);
        $this->assertSame(['failed', 'print', 3], array_slice($calls[0], 1, 3));
    }

    public function testFiltersOfferStatesKindsAndDevicesWhenThereAreMore(): void
    {
        $calls = [];
        $viewer = $this->viewer($calls);

        $filters = $viewer->getFilters();
        $this->assertSame(['state', 'kind'], array_column($filters, 'id'));
        $this->assertSame(['Stav', 'Druh'], array_column($filters, 'label'));
        $this->assertSame(
            [['value' => 'created', 'label' => 'Neověřeno'], ['value' => 'sent', 'label' => 'Odesláno'], ['value' => 'failed', 'label' => 'Chyba']],
            $filters[0]['options'],
        );
        $this->assertSame(['Tisk záznamu', 'Zkušební účtenka', 'Otevření šuplíku'], array_column($filters[1]['options'], 'label'));

        $viewer->devices = [['id' => 3, 'name' => 'Pokladna 1'], ['id' => 4, 'name' => 'Sklad']];
        $filters = $viewer->getFilters();
        $this->assertSame(['state', 'kind', 'device'], array_column($filters, 'id'));
        $this->assertSame([['value' => 3, 'label' => 'Pokladna 1'], ['value' => 4, 'label' => 'Sklad']], $filters[2]['options']);
    }

    public function testViewerIsReadOnly(): void
    {
        $calls = [];
        $viewer = $this->viewer($calls);

        $this->assertSame([], $viewer->getToolbarActions(null));
        $this->assertSame([], $viewer->getToolbarActions(self::PRINT_JOB));
    }

    public function testDetailShowsWhereAndHowTheJobEnded(): void
    {
        $calls = [];
        $detail = $this->viewer($calls, self::PRINT_JOB)->renderDetail(31);

        $this->assertSame('Účtenka · záznam 1059', $detail['title']);
        $this->assertSame('Pokladna 1', $detail['subtitle']);
        $this->assertSame([['label' => 'Chyba', 'style' => 'cancelled']], $detail['badges']);
        $this->assertArrayNotHasKey('actions', $detail);

        $groups = $detail['tabs'][0]['content']['groups'];
        $this->assertSame(['Tiskárna', 'Výsledek', 'Úloha', 'Varování'], array_column($groups, 'title'));
        $this->assertSame(
            [
                ['label' => 'Periferie', 'value' => 'Účtenky u kasy'],
                ['label' => 'Role', 'value' => 'Účtenky'],
                ['label' => 'Profil tiskárny', 'value' => 'escpos-58-cp852'],
            ],
            $groups[0]['items'],
        );
        $this->assertSame(
            [
                ['label' => 'Kód chyby', 'value' => 'PRINTER_ERROR'],
                ['label' => 'Pokusy', 'value' => '2'],
                ['label' => 'Výsledek nahlášen', 'value' => '28.11.2026 10:15'],
            ],
            $groups[1]['items'],
        );
        $this->assertSame(
            [
                ['label' => 'Vytvořeno', 'value' => '28.11.2026 10:15'],
                ['label' => 'Uživatel', 'value' => 'Jana Prodavačka'],
                ['label' => 'Formát', 'value' => 'escpos'],
                ['label' => 'Velikost dat', 'value' => '1482 bajtů'],
                ['label' => 'ID úlohy', 'value' => '0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10'],
            ],
            $groups[2]['items'],
        );
        $this->assertSame([['label' => 'ESCPOS_PROFILE_UNKNOWN', 'value' => 'Unknown printer profile']], $groups[3]['items']);

        $detailSql = (string) $calls[0][0];
        $this->assertStringNotContainsString('j.*', $detailSql);
        $this->assertStringNotContainsString('`data`', $detailSql);
    }

    public function testDetailOfAJobWithoutResultAndWithLostPeripheral(): void
    {
        $calls = [];
        $record = ['kind' => 'drawer', 'print_id' => null, 'record_id' => null, 'state' => 'created', 'error_code' => null,
            'attempts' => 0, 'result_at' => null, 'warnings' => null, 'peripheral_name' => null] + self::PRINT_JOB;
        $detail = $this->viewer($calls, $record)->renderDetail(31);

        $this->assertSame('Otevření šuplíku', $detail['title']);
        $this->assertSame([['label' => 'Neověřeno', 'style' => 'edit']], $detail['badges']);

        $groups = $detail['tabs'][0]['content']['groups'];
        $this->assertSame(['Tiskárna', 'Výsledek', 'Úloha'], array_column($groups, 'title'));
        $this->assertSame('periferie už neexistuje', $groups[0]['items'][0]['value']);
        $this->assertSame([['label' => 'Pokusy', 'value' => '0']], $groups[1]['items']);
    }
}
