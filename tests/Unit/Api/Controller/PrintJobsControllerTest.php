<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\PrintJobsController;
use Shipard\Api\Response;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Prints\EscPos\PrinterProfile;
use Shipard\Core\Prints\PrintData;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintFormat;
use Shipard\Core\Prints\PrintOutput;
use Shipard\Core\Prints\PrintRegistry;
use Shipard\Module\Core\Prints\Jobs\PrintJobRouter;
use Shipard\Module\Core\Prints\Jobs\PrintJobService;
use Shipard\Tests\Fixtures\Module\Prints\InMemoryPrintJobsDb;
use Shipard\Tests\Unit\Core\Prints\PrintDefinitionTest;
use Shipard\Tests\Unit\Module\Core\Prints\Jobs\PrintJobsFixture;

/**
 * REST tiskových úloh (#177 U36, U38): tvar odpovědí, výchozí druh a role,
 * práva a kódy chyb s HTTP stavy.
 */
final class PrintJobsControllerTest extends TestCase
{
    use PrintJobsFixture;

    private const string RECEIPT = 'docs.cashRegister.receiptPos';
    private const string BYTES   = "\x1B@\x1C.\x1Bt\x12\x80\xFF\n";

    /** @var list<array{0: string, 1: int}> */
    private array $rendered = [];

    protected function setUp(): void
    {
        $this->db = InMemoryPrintJobsDb::create();
        $this->seedPeripheral($this->seedDevice());
    }

    private static function user(int $id = 7): AuthContext
    {
        return new AuthContext(true, $id, 'session', 'shpd_st_u' . $id);
    }

    private static function admin(): AuthContext
    {
        return new AuthContext(true, 1, 'session', 'shpd_st_admin', isAdmin: true);
    }

    /** @param array<string, mixed> $flags */
    private static function table(string $id, array $flags = []): TableDefinition
    {
        return TableDefinition::fromArray($flags + [
            'tableId' => 9001,
            'id'      => $id,
            'name'    => $id,
            'columns' => [['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true]],
        ]);
    }

    private function controller(): PrintJobsController
    {
        $registry = new PrintRegistry();
        $registry->add(PrintDefinition::fromArray(PrintDefinitionTest::receiptDeclaration(), 'docs.cashRegister'));
        $registry->add(PrintDefinition::fromArray(PrintDefinitionTest::declaration(), 'docs.invoicesOut'));

        $config = $this->jobsConfig();
        return new PrintJobsController($registry, new PrintJobService(
            $this->db,
            new PrintJobRouter($this->db, $config),
            $registry,
            function (string $printId, int $recordId, PrinterProfile $profile): PrintOutput {
                $this->rendered[] = [$printId, $recordId];
                return new PrintOutput(
                    PrintFormat::Escpos,
                    new PrintData(
                        $printId, 1, 'cs', 'docs_core_heads', $recordId, 40,
                        new \DateTimeImmutable('2026-11-28 10:00:00'), 'Účtenka', 'uctenka', null, [], [],
                    ),
                    bytes: self::BYTES,
                );
            },
            $config,
        ));
    }

    /** @return array<string, mixed> */
    private static function data(Response $response, int $status = 200): array
    {
        self::assertSame($status, $response->getStatus(), json_encode($response->getPayload()) ?: '');
        return $response->getPayload()['data'];
    }

    private static function assertError(string $code, int $status, Response $response): void
    {
        self::assertSame($status, $response->getStatus(), json_encode($response->getPayload()) ?: '');
        self::assertSame($code, $response->getPayload()['error']['code']);
    }

    // ── POST /_print-jobs ───────────────────────────────────────────────────

    public function testAvailabilityNeedsJobsAndDevicesTables(): void
    {
        $jobs = self::table('core_prints_jobs');
        $devices = self::table('core_devices_devices');

        $this->assertTrue(PrintJobsController::available(['core_prints_jobs' => $jobs, 'core_devices_devices' => $devices]));
        $this->assertFalse(PrintJobsController::available(['core_prints_jobs' => $jobs]));
        $this->assertFalse(PrintJobsController::available(['core_devices_devices' => $devices]));
    }

    public function testCreateDefaultsToPrintOfTheRecordOnReceiptRole(): void
    {
        $data = self::data($this->controller()->create(
            ['printId' => self::RECEIPT, 'recordId' => 5, 'deviceId' => self::DEVICE_ID],
            self::user(),
            [],
        ), 201);

        $this->assertSame(['jobId', 'peripheralId', 'format', 'data', 'warnings'], array_keys($data));
        $this->assertSame('printer-1', $data['peripheralId']);
        $this->assertSame('escpos', $data['format']);
        $this->assertSame(self::BYTES, base64_decode($data['data'], true));
        $this->assertSame([], $data['warnings']);
        $this->assertSame([[self::RECEIPT, 5]], $this->rendered);

        $row = $this->db->rows(InMemoryPrintJobsDb::JOBS)[0];
        $this->assertSame('print', $row['kind']);
        $this->assertSame('receipt', $row['role']);
        $this->assertSame(self::BYTES, $row['data'], 'V databázi jsou bajty, ne base64');
    }

    public function testCreateTestAndDrawerNeedNoPrint(): void
    {
        $test = self::data($this->controller()->create(['kind' => 'test', 'deviceId' => self::DEVICE_ID], self::user(), []), 201);
        $this->assertStringStartsWith("\x1B@", (string) base64_decode($test['data'], true));

        $drawer = self::data($this->controller()->create(
            ['kind' => 'drawer', 'deviceId' => self::DEVICE_ID, 'role' => 'receipt', 'printId' => 'ignored'],
            self::user(),
            [],
        ), 201);
        $this->assertSame("\x1Bp\x30\x37\x79", base64_decode($drawer['data'], true));
        $this->assertSame([], $this->rendered);
    }

    public function testCreateValidatesTheBody(): void
    {
        $ctrl = $this->controller();

        $cases = [
            'kind'     => ['kind' => 'pdf', 'deviceId' => self::DEVICE_ID],
            'printId'  => ['recordId' => 5, 'deviceId' => self::DEVICE_ID],
            'recordId' => ['printId' => self::RECEIPT, 'recordId' => 'abc', 'deviceId' => self::DEVICE_ID],
            'role'     => ['kind' => 'test', 'role' => 5, 'deviceId' => self::DEVICE_ID],
        ];
        foreach ($cases as $field => $body) {
            $response = $ctrl->create($body, self::user(), []);
            self::assertError('VALIDATION_ERROR', 422, $response);
            $this->assertContains($field, array_column($response->getPayload()['error']['details'], 'field'));
        }

        self::assertError('VALIDATION_ERROR', 422, $ctrl->create(null, self::user(), []));
        self::assertError('VALIDATION_ERROR', 422, $ctrl->create(['kind' => 'test', 'role' => 'a4', 'deviceId' => self::DEVICE_ID], self::user(), []));
        $this->assertSame([], $this->db->rows(InMemoryPrintJobsDb::JOBS));
    }

    public function testCreateAcceptsRecordIdAsDigits(): void
    {
        self::data($this->controller()->create(
            ['printId' => self::RECEIPT, 'recordId' => '5', 'deviceId' => self::DEVICE_ID],
            self::user(),
            [],
        ), 201);

        $this->assertSame([[self::RECEIPT, 5]], $this->rendered);
    }

    public function testCreateMapsServiceErrors(): void
    {
        $ctrl = $this->controller();

        self::assertError('PRINT_NOT_FOUND', 404, $ctrl->create(
            ['printId' => 'docs.unknown', 'recordId' => 5, 'deviceId' => self::DEVICE_ID], self::user(), [],
        ));
        self::assertError('PRINT_FORMAT_UNSUPPORTED', 409, $ctrl->create(
            ['printId' => 'docs.invoicesOut.invoice', 'recordId' => 5, 'deviceId' => self::DEVICE_ID], self::user(), [],
        ));
        self::assertError('DEVICE_UNKNOWN', 409, $ctrl->create(['kind' => 'test', 'deviceId' => 'nope'], self::user(), []));
        self::assertError('DEVICE_UNKNOWN', 409, $ctrl->create(['kind' => 'test'], self::user(), []));

        $this->db->tables['core_devices_peripherals'][2]['role'] = null;
        self::assertError('NO_PERIPHERAL_FOR_ROLE', 409, $ctrl->create(['kind' => 'test', 'deviceId' => self::DEVICE_ID], self::user(), []));
    }

    public function testPrintNeedsReadAccessToTheTableOfThePrint(): void
    {
        $tables = ['docs_core_heads' => self::table('docs_core_heads', ['adminOnly' => true])];
        $body = ['printId' => self::RECEIPT, 'recordId' => 5, 'deviceId' => self::DEVICE_ID];

        self::assertError('FORBIDDEN_ADMIN_ONLY', 403, $this->controller()->create($body, self::user(), $tables));
        $this->assertSame([], $this->rendered);

        self::data($this->controller()->create($body, self::admin(), $tables), 201);
        // Zkušební tisk a šuplík právo na tabulku nepotřebují.
        self::data($this->controller()->create(['kind' => 'drawer', 'deviceId' => self::DEVICE_ID], self::user(), $tables), 201);
    }

    public function testAnonymousIsRejected(): void
    {
        $ctrl = $this->controller();
        $anonymous = AuthContext::anonymous();

        self::assertError('UNAUTHORIZED', 401, $ctrl->create(['kind' => 'test', 'deviceId' => self::DEVICE_ID], $anonymous, []));
        self::assertError('UNAUTHORIZED', 401, $ctrl->get('x', $anonymous));
        self::assertError('UNAUTHORIZED', 401, $ctrl->result('x', ['status' => 'sent'], $anonymous));
    }

    // ── GET /_print-jobs/{jobId} a výsledek ─────────────────────────────────

    public function testGetReturnsTheJobAgainAndResultClosesIt(): void
    {
        $ctrl = $this->controller();
        $jobId = self::data($ctrl->create(['kind' => 'test', 'deviceId' => self::DEVICE_ID], self::user(), []), 201)['jobId'];

        $job = self::data($ctrl->get($jobId, self::user()));
        $this->assertSame(
            ['jobId', 'kind', 'state', 'peripheralId', 'format', 'data', 'attempts', 'warnings'],
            array_keys($job),
        );
        $this->assertSame('test', $job['kind']);
        $this->assertSame('created', $job['state']);
        $this->assertSame(0, $job['attempts']);
        $this->assertStringStartsWith("\x1B@", (string) base64_decode($job['data'], true));

        $this->assertSame(
            ['state' => 'failed', 'attempts' => 1],
            self::data($ctrl->result($jobId, ['status' => 'failed', 'errorCode' => 'PRINTER_ERROR'], self::user())),
        );
        $this->assertSame(
            ['state' => 'sent', 'attempts' => 2],
            self::data($ctrl->result($jobId, ['status' => 'sent', 'errorCode' => null], self::admin())),
        );
        $this->assertSame('sent', self::data($ctrl->get($jobId, self::admin()))['state']);
    }

    public function testForeignAndUnknownJob(): void
    {
        $ctrl = $this->controller();
        $jobId = self::data($ctrl->create(['kind' => 'test', 'deviceId' => self::DEVICE_ID], self::user(), []), 201)['jobId'];

        self::assertError('FORBIDDEN', 403, $ctrl->get($jobId, self::user(8)));
        self::assertError('FORBIDDEN', 403, $ctrl->result($jobId, ['status' => 'sent'], self::user(8)));
        self::assertError('JOB_NOT_FOUND', 404, $ctrl->get('aaaaaaaa-2222-4333-8444-555555555555', self::admin()));
        self::assertError('JOB_NOT_FOUND', 404, $ctrl->result('garbage', ['status' => 'sent'], self::admin()));
    }

    public function testResultValidatesTheBody(): void
    {
        $ctrl = $this->controller();
        $jobId = self::data($ctrl->create(['kind' => 'test', 'deviceId' => self::DEVICE_ID], self::user(), []), 201)['jobId'];

        self::assertError('VALIDATION_ERROR', 422, $ctrl->result($jobId, null, self::user()));
        self::assertError('VALIDATION_ERROR', 422, $ctrl->result($jobId, ['status' => 'failed'], self::user()));
        self::assertError('VALIDATION_ERROR', 422, $ctrl->result($jobId, ['status' => 'failed', 'errorCode' => 'paper out'], self::user()));
    }
}
