<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Prints;

use Shipard\Api\AuthContext;
use Shipard\Api\Controller\PrintJobsController;
use Shipard\Api\PrintDefinitionLoader;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\EscPos\PrinterProfile;
use Shipard\Core\Prints\PrintFormat;
use Shipard\Core\Prints\PrintOutput;
use Shipard\Core\Prints\PrintRunnerFactory;
use Shipard\Module\Core\Devices\DeviceDocument;
use Shipard\Module\Core\Devices\PeripheralDocument;
use Shipard\Module\Core\Prints\Jobs\PrintJobRouter;
use Shipard\Module\Core\Prints\Jobs\PrintJobService;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Tiskové úlohy nad skutečnou databází (#177 task 4b): zařízení s tiskárnou
 * účtenek → zkušební účtenka a šuplík → výsledek → znovu načtení úlohy;
 * tisk prodejky, když ji zdroj dat má. Ověřuje hlavně to, co in-memory
 * databáze neumí: bajty v binárním sloupci projdou tam i zpět beze změny.
 *
 * Test si zakládá zařízení s náhodným `deviceId` a po sobě uklízí.
 */
class PrintJobsFlowTest extends IntegrationTestCase
{
    private const int USER = 1;
    private const string RECEIPT_PRINT = 'docs.cashRegister.receiptPos';

    private string $deviceId;
    private int $deviceRowId;
    private int $peripheralRowId;

    protected function setUp(): void
    {
        parent::setUp();
        if (!PrintJobsController::available($this->tables)) {
            $this->markTestSkipped('Zdroj dat nemá tiskové úlohy nebo zařízení — spusť ds-upgrade.');
        }

        $hex = bin2hex(random_bytes(16));
        $this->deviceId = sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20),
        );

        $now = new \DateTimeImmutable();
        $this->deviceRowId = $this->db->insertRow(DeviceDocument::TABLE, [
            'name'          => 'Test tiskových úloh',
            'mode'          => 'shared',
            'device_id'     => $this->deviceId,
            'platform'      => 'mock',
            'public_key'    => 'test',
            'key_algorithm' => 'ES256',
            'registered_at' => $now,
            'docState'      => DeviceDocument::STATE_ACTIVE,
            'docStateMain'  => DeviceDocument::STATE_MAIN_ACTIVE,
        ]);
        $this->peripheralRowId = $this->db->insertRow(PeripheralDocument::TABLE, [
            'device'        => $this->deviceRowId,
            'peripheral_id' => 'test-receipt',
            'kind'          => 'printer',
            'name'          => 'Test receipt printer',
            'profile_id'    => 'escpos-80-cp852',
            'present'       => 1,
            'role'          => 'receipt',
            'reported_at'   => $now,
        ]);
    }

    protected function onTearDown(): void
    {
        if (!isset($this->deviceRowId)) {
            return;
        }
        $this->db->deleteWhere(PrintJobService::TABLE, 'device = %i', $this->deviceRowId);
        $this->db->deleteWhere(PeripheralDocument::TABLE, 'device = %i', $this->deviceRowId);
        $this->db->deleteWhere(DeviceDocument::TABLE, 'id = %i', $this->deviceRowId);
    }

    private function controller(): PrintJobsController
    {
        $modules = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $registry = PrintDefinitionLoader::load($this->dsConfig, $modules, 'cs');
        $config = ConfigRuntime::load($this->dsConfig->getDataSourceDir(), $this->dsConfig->getDefaultLanguage());

        return new PrintJobsController($registry, new PrintJobService(
            $this->db,
            new PrintJobRouter($this->db, $config),
            $registry,
            fn (string $printId, int $recordId, PrinterProfile $profile): PrintOutput
                => PrintRunnerFactory::create($registry, $this->dsConfig, $this->db, $modules)
                    ->run($printId, $recordId, PrintFormat::Escpos, null, $profile),
            $config,
        ));
    }

    private static function user(int $id = self::USER): AuthContext
    {
        return new AuthContext(true, $id, 'session', 'shpd_st_test');
    }

    /** @return array<string, mixed> */
    private static function data(Response $response, int $status = 200): array
    {
        self::assertSame($status, $response->getStatus(), json_encode($response->getPayload()) ?: '');
        return $response->getPayload()['data'];
    }

    public function testTestReceiptGoesThroughTheWholeFlow(): void
    {
        $ctrl = $this->controller();

        $created = self::data($ctrl->create(['kind' => 'test', 'deviceId' => $this->deviceId], self::user(), $this->tables), 201);
        $this->assertSame('test-receipt', $created['peripheralId']);
        $this->assertSame('escpos', $created['format']);
        $bytes = (string) base64_decode($created['data'], true);
        $this->assertStringStartsWith("\x1B@", $bytes);
        // Diakritika zkušební účtenky jsou v CP852 bajty nad 0x7F — neplatné
        // UTF-8, na kterém by se textový sloupec pokazil.
        $this->assertFalse(mb_check_encoding($bytes, 'UTF-8'));

        $row = $this->db->fetchRow('SELECT * FROM core_prints_jobs WHERE job_id = %s', $created['jobId']);
        $this->assertNotNull($row);
        $this->assertSame($bytes, (string) $row['data'], 'Bajty v databázi jsou tytéž, ne base64');
        $this->assertSame(strlen($bytes), (int) $row['data_size']);
        $this->assertSame($this->deviceRowId, (int) $row['device']);
        $this->assertSame($this->peripheralRowId, (int) $row['peripheral']);
        $this->assertSame('escpos-80-cp852', $row['profile_id']);
        $this->assertSame('created', $row['state']);
        $this->assertSame(self::USER, (int) $row['created_by']);

        $this->assertSame(
            ['state' => 'failed', 'attempts' => 1],
            self::data($ctrl->result($created['jobId'], ['status' => 'failed', 'errorCode' => 'PRINTER_ERROR'], self::user())),
        );

        $again = self::data($ctrl->get($created['jobId'], self::user()));
        $this->assertSame($created['data'], $again['data'], 'Zkusit znovu dostane stejná data');
        $this->assertSame('failed', $again['state']);
        $this->assertSame(1, $again['attempts']);
        $this->assertSame('test-receipt', $again['peripheralId']);

        $this->assertSame(
            ['state' => 'sent', 'attempts' => 2],
            self::data($ctrl->result($created['jobId'], ['status' => 'sent'], self::user())),
        );
        $row = $this->db->fetchRow('SELECT state, error_code, attempts FROM core_prints_jobs WHERE job_id = %s', $created['jobId']);
        $this->assertSame('sent', $row['state']);
        $this->assertNull($row['error_code']);

        $this->assertSame(403, $ctrl->get($created['jobId'], self::user(self::USER + 100000))->getStatus());
    }

    public function testDrawerJobAndMissingRole(): void
    {
        $ctrl = $this->controller();

        $drawer = self::data($ctrl->create(['kind' => 'drawer', 'deviceId' => $this->deviceId], self::user(), $this->tables), 201);
        $this->assertSame("\x1Bp\x30\x37\x79", base64_decode($drawer['data'], true));

        $this->db->updateWhere(PeripheralDocument::TABLE, ['role' => null], 'id = %i', $this->peripheralRowId);
        $response = $ctrl->create(['kind' => 'test', 'deviceId' => $this->deviceId], self::user(), $this->tables);
        $this->assertSame(409, $response->getStatus());
        $this->assertSame('NO_PERIPHERAL_FOR_ROLE', $response->getPayload()['error']['code']);
    }

    public function testPruneRemovesOnlyOldJobs(): void
    {
        $ctrl = $this->controller();
        $old = self::data($ctrl->create(['kind' => 'drawer', 'deviceId' => $this->deviceId], self::user(), $this->tables), 201)['jobId'];
        $new = self::data($ctrl->create(['kind' => 'drawer', 'deviceId' => $this->deviceId], self::user(), $this->tables), 201)['jobId'];
        $this->db->updateWhere(
            PrintJobService::TABLE,
            ['created_at' => new \DateTimeImmutable('-31 days')],
            'job_id = %s',
            $old,
        );

        // Úklid běží nad celou tabulkou — jinou starou úlohu než svoji tu test nemá.
        $this->assertGreaterThanOrEqual(1, PrintJobService::forMaintenance($this->db)->prune());

        $this->assertNull($this->db->fetchRow('SELECT id FROM core_prints_jobs WHERE job_id = %s', $old));
        $this->assertNotNull($this->db->fetchRow('SELECT id FROM core_prints_jobs WHERE job_id = %s', $new));
    }

    public function testReceiptOfACashRegisterSale(): void
    {
        $sale = $this->db->fetchRow(
            "SELECT id, doc_number FROM docs_core_heads WHERE doc_type = 'cashreg' AND docState = 40 ORDER BY id DESC LIMIT 1",
        );
        if ($sale === null) {
            $this->markTestSkipped('Zdroj dat nemá žádnou prodejku ve stavu V pořádku.');
        }

        $created = self::data($this->controller()->create(
            ['printId' => self::RECEIPT_PRINT, 'recordId' => (int) $sale['id'], 'deviceId' => $this->deviceId],
            self::user(),
            $this->tables,
        ), 201);

        $bytes = (string) base64_decode($created['data'], true);
        $this->assertStringStartsWith("\x1B@", $bytes);
        $this->assertStringContainsString((string) $sale['doc_number'], $bytes);

        $row = $this->db->fetchRow('SELECT * FROM core_prints_jobs WHERE job_id = %s', $created['jobId']);
        $this->assertSame('print', $row['kind']);
        $this->assertSame(self::RECEIPT_PRINT, $row['print_id']);
        $this->assertSame('docs_core_heads', $row['record_table']);
        $this->assertSame((int) $sale['id'], (int) $row['record_id']);
        $this->assertSame($bytes, (string) $row['data']);
    }
}
