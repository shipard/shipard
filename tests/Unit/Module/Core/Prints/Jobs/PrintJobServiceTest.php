<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Prints\Jobs;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Core\Prints\EscPos\PrinterProfile;
use Shipard\Core\Prints\EscPos\PrinterProfiles;
use Shipard\Core\Prints\PrintData;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintFormat;
use Shipard\Core\Prints\PrintMessage;
use Shipard\Core\Prints\PrintNotAvailableException;
use Shipard\Core\Prints\PrintOutput;
use Shipard\Core\Prints\PrintRegistry;
use Shipard\Module\Core\Prints\Jobs\PrintJob;
use Shipard\Module\Core\Prints\Jobs\PrintJobException;
use Shipard\Module\Core\Prints\Jobs\PrintJobKind;
use Shipard\Module\Core\Prints\Jobs\PrintJobRouter;
use Shipard\Module\Core\Prints\Jobs\PrintJobService;
use Shipard\Module\Core\Prints\Jobs\PrintJobState;
use Shipard\Tests\Fixtures\Module\Prints\InMemoryPrintJobsDb;
use Shipard\Tests\Unit\Core\Prints\PrintDefinitionTest;

/**
 * Tiskové úlohy (#177 U36–U39): tři druhy úloh, limit dat, výsledek
 * a jeho práva, počet pokusů, úklid.
 */
final class PrintJobServiceTest extends TestCase
{
    use PrintJobsFixture;

    private const string RECEIPT = 'docs.cashRegister.receiptPos';
    private const string INVOICE = 'docs.invoicesOut.invoice';
    private const string INIT    = "\x1B@\x1C.\x1Bt\x12";
    private const string DRAWER  = "\x1Bp\x30\x37\x79";

    private \DateTimeImmutable $now;

    /** @var list<array{0: string, 1: int, 2: string}> volání tisku záznamu: tisk, záznam, profil */
    private array $rendered = [];

    /** Co vrátí tisk záznamu; výjimka se vyhodí. */
    private PrintOutput|\Throwable|null $printResult = null;

    protected function setUp(): void
    {
        $this->db = InMemoryPrintJobsDb::create();
        $this->now = new \DateTimeImmutable('2026-11-28 10:00:00');
        $this->seedPeripheral($this->seedDevice());
    }

    private static function author(): AuthContext
    {
        return new AuthContext(true, 7, 'session', 'shpd_st_a');
    }

    private static function other(): AuthContext
    {
        return new AuthContext(true, 8, 'session', 'shpd_st_b');
    }

    private static function admin(): AuthContext
    {
        return new AuthContext(true, 1, 'session', 'shpd_st_c', isAdmin: true);
    }

    /** @param list<PrintMessage> $messages @param list<PrintMessage> $warnings */
    private static function printOutput(string $bytes, array $messages = [], array $warnings = []): PrintOutput
    {
        return new PrintOutput(
            PrintFormat::Escpos,
            new PrintData(
                self::RECEIPT, 1, 'cs', 'docs_core_heads', 5, 40,
                new \DateTimeImmutable('2026-11-28 10:00:00'), 'Účtenka', 'uctenka', null, $messages, [],
            ),
            bytes: $bytes,
            warnings: $warnings,
        );
    }

    /** @param array<string, mixed> $config */
    private function service(array $config = []): PrintJobService
    {
        $registry = new PrintRegistry();
        $registry->add(PrintDefinition::fromArray(PrintDefinitionTest::receiptDeclaration(), 'docs.cashRegister'));
        $registry->add(PrintDefinition::fromArray(PrintDefinitionTest::declaration(), 'docs.invoicesOut'));

        $cfg = $this->jobsConfig($config);
        return new PrintJobService(
            $this->db,
            new PrintJobRouter($this->db, $cfg),
            $registry,
            function (string $printId, int $recordId, PrinterProfile $profile): PrintOutput {
                $this->rendered[] = [$printId, $recordId, $profile->id];
                if ($this->printResult instanceof \Throwable) {
                    throw $this->printResult;
                }
                return $this->printResult ?? self::printOutput(self::INIT . "Hello\n");
            },
            $cfg,
            fn (): \DateTimeImmutable => $this->now,
        );
    }

    private function testJob(?AuthContext $auth = null): PrintJob
    {
        return $this->service()->create(PrintJobKind::Test, null, null, self::DEVICE_ID, 'receipt', $auth ?? self::author());
    }

    private function assertFails(string $code, int $status, callable $call): PrintJobException
    {
        try {
            $call();
        } catch (PrintJobException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->httpStatus);
            return $e;
        }
        $this->fail("Čekal jsem {$code}");
    }

    /** @return array<string, mixed> */
    private function storedJob(): array
    {
        $rows = $this->db->rows(InMemoryPrintJobsDb::JOBS);
        $this->assertCount(1, $rows);
        return $rows[0];
    }

    // ── create ──────────────────────────────────────────────────────────────

    public function testTestJobCarriesTestReceiptOfThePrinterProfile(): void
    {
        $job = $this->testJob();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $job->jobId);
        $this->assertSame(PrintJobKind::Test, $job->kind);
        $this->assertSame(PrintJobState::Created, $job->state);
        $this->assertSame('printer-1', $job->peripheralId);
        $this->assertSame('escpos', $job->format);
        $this->assertStringStartsWith(self::INIT, $job->data);
        $this->assertStringContainsString('escpos-80-cp852', $job->data);
        $this->assertSame([], $job->warnings);
        $this->assertSame([], $this->rendered, 'Zkušební účtenka tisk záznamu nevolá');

        $row = $this->storedJob();
        $this->assertSame($job->jobId, $row['job_id']);
        $this->assertSame('test', $row['kind']);
        $this->assertNull($row['print_id']);
        $this->assertNull($row['record_table']);
        $this->assertNull($row['record_id']);
        $this->assertSame('receipt', $row['role']);
        $this->assertSame('escpos', $row['format']);
        $this->assertSame('escpos-80-cp852', $row['profile_id']);
        $this->assertSame($job->data, $row['data']);
        $this->assertSame(strlen($job->data), $row['data_size']);
        $this->assertNull($row['warnings']);
        $this->assertSame('created', $row['state']);
        $this->assertSame(0, $row['attempts']);
        $this->assertSame(7, $row['created_by']);
        $this->assertEquals($this->now, $row['created_at']);
    }

    public function testDataAreWrittenAsBinary(): void
    {
        $this->testJob();

        $this->assertContains('data%bin', $this->db->lastJobInsertKeys);
        $this->assertNotContains('data', $this->db->lastJobInsertKeys);
    }

    public function testDrawerJobIsTheDriverCommandOnly(): void
    {
        $job = $this->service()->create(PrintJobKind::Drawer, null, null, self::DEVICE_ID, 'receipt', self::author());

        $this->assertSame(self::DRAWER, $job->data);
        $this->assertSame('drawer', $this->storedJob()['kind']);
        $this->assertSame([], $this->rendered);
    }

    public function testDrawerJobOnProfileWithoutDrawer(): void
    {
        $profiles = self::printsJsonc('printerProfiles.jsonc');
        $profiles['escpos-80-cp852']['features'] = ['cut'];
        $service = $this->service([PrinterProfiles::CFG_ITEM => $profiles]);

        $this->assertFails('PROFILE_NO_DRAWER', 409, static fn () => $service->create(
            PrintJobKind::Drawer, null, null, self::DEVICE_ID, 'receipt', self::author(),
        ));
        $this->assertSame([], $this->db->rows(InMemoryPrintJobsDb::JOBS));
    }

    public function testPrintJobRendersTheRecordWithThePrinterProfile(): void
    {
        $this->printResult = self::printOutput(
            self::INIT . "Receipt\n",
            [PrintMessage::warning('QR_NO_ACCOUNT', 'QR platba bez účtu')],
            [PrintMessage::warning('ESCPOS_CHAR_REPLACED', 'Znak mimo kódovou stránku')],
        );

        $job = $this->service()->create(PrintJobKind::Print, self::RECEIPT, 5, self::DEVICE_ID, 'receipt', self::author());

        $this->assertSame([[self::RECEIPT, 5, 'escpos-80-cp852']], $this->rendered);
        $this->assertSame(self::INIT . "Receipt\n", $job->data);
        $this->assertSame(['QR_NO_ACCOUNT', 'ESCPOS_CHAR_REPLACED'], array_column($job->warnings, 'code'));

        $row = $this->storedJob();
        $this->assertSame('print', $row['kind']);
        $this->assertSame(self::RECEIPT, $row['print_id']);
        $this->assertSame('docs_core_heads', $row['record_table']);
        $this->assertSame(5, $row['record_id']);
        $this->assertSame($job->warnings, json_decode((string) $row['warnings'], true));
    }

    public function testFallbackProfileWarningComesFirst(): void
    {
        $this->db->tables['core_devices_peripherals'][2]['profile_id'] = 'unknown-profile';
        $this->printResult = self::printOutput('x', [PrintMessage::warning('QR_NO_ACCOUNT', 'QR')]);

        $job = $this->service()->create(PrintJobKind::Print, self::RECEIPT, 5, self::DEVICE_ID, 'receipt', self::author());

        $this->assertSame([PrinterProfiles::WARNING_UNKNOWN_PROFILE, 'QR_NO_ACCOUNT'], array_column($job->warnings, 'code'));
        $this->assertSame(PrinterProfiles::FALLBACK_ID, $this->storedJob()['profile_id']);
        $this->assertSame(PrinterProfiles::FALLBACK_ID, $this->rendered[0][2]);
    }

    public function testPrintJobNeedsPrintAndRecord(): void
    {
        $e = $this->assertFails('VALIDATION_ERROR', 422, fn () => $this->service()->create(
            PrintJobKind::Print, null, null, self::DEVICE_ID, 'receipt', self::author(),
        ));
        $this->assertSame(['printId', 'recordId'], array_column($e->details, 'field'));

        $e = $this->assertFails('VALIDATION_ERROR', 422, fn () => $this->service()->create(
            PrintJobKind::Print, self::RECEIPT, 0, self::DEVICE_ID, 'receipt', self::author(),
        ));
        $this->assertSame(['recordId'], array_column($e->details, 'field'));
    }

    public function testUnknownPrintAndPrintToPdf(): void
    {
        $this->assertFails('PRINT_NOT_FOUND', 404, fn () => $this->service()->create(
            PrintJobKind::Print, 'docs.unknown', 5, self::DEVICE_ID, 'receipt', self::author(),
        ));
        $this->assertFails('PRINT_FORMAT_UNSUPPORTED', 409, fn () => $this->service()->create(
            PrintJobKind::Print, self::INVOICE, 5, self::DEVICE_ID, 'receipt', self::author(),
        ));
        $this->assertSame([], $this->rendered);
    }

    public function testPrintErrorsKeepCodesOfThePrintsEndpoint(): void
    {
        $this->printResult = new PrintNotAvailableException('Print is not available for this record');

        $this->assertFails('PRINT_NOT_AVAILABLE', 409, fn () => $this->service()->create(
            PrintJobKind::Print, self::RECEIPT, 5, self::DEVICE_ID, 'receipt', self::author(),
        ));
        $this->assertSame([], $this->db->rows(InMemoryPrintJobsDb::JOBS));
    }

    public function testRoutingErrorsPassThrough(): void
    {
        $this->assertFails('DEVICE_UNKNOWN', 409, fn () => $this->service()->create(
            PrintJobKind::Test, null, null, 'aaaaaaaa-2222-4333-8444-555555555555', 'receipt', self::author(),
        ));
    }

    public function testDataOverTheLimitAreRejected(): void
    {
        $this->printResult = self::printOutput(str_repeat('x', PrintJobService::MAX_BYTES + 1));

        $this->assertFails('PAYLOAD_TOO_LARGE', 413, fn () => $this->service()->create(
            PrintJobKind::Print, self::RECEIPT, 5, self::DEVICE_ID, 'receipt', self::author(),
        ));
        $this->assertSame([], $this->db->rows(InMemoryPrintJobsDb::JOBS));

        $this->printResult = self::printOutput(str_repeat('x', PrintJobService::MAX_BYTES));
        $job = $this->service()->create(PrintJobKind::Print, self::RECEIPT, 5, self::DEVICE_ID, 'receipt', self::author());
        $this->assertSame(PrintJobService::MAX_BYTES, strlen($job->data));
    }

    // ── get ─────────────────────────────────────────────────────────────────

    public function testAuthorAndAdminReadTheJobWithData(): void
    {
        $created = $this->testJob();

        foreach ([self::author(), self::admin()] as $auth) {
            $job = $this->service()->get($created->jobId, $auth);

            $this->assertSame($created->jobId, $job->jobId);
            $this->assertSame($created->data, $job->data);
            $this->assertSame('printer-1', $job->peripheralId);
            $this->assertSame(PrintJobKind::Test, $job->kind);
            $this->assertSame(PrintJobState::Created, $job->state);
            $this->assertSame(0, $job->attempts);
        }
    }

    public function testForeignJobIsForbidden(): void
    {
        $created = $this->testJob();

        $this->assertFails('FORBIDDEN', 403, fn () => $this->service()->get($created->jobId, self::other()));
        $this->assertFails('FORBIDDEN', 403, fn () => $this->service()->get($created->jobId, AuthContext::anonymous()));
    }

    public function testUnknownJob(): void
    {
        $this->testJob();

        foreach (['aaaaaaaa-2222-4333-8444-555555555555', 'x', '', null, 5] as $jobId) {
            $this->assertFails('JOB_NOT_FOUND', 404, fn () => $this->service()->get($jobId, self::admin()));
        }
    }

    // ── report ──────────────────────────────────────────────────────────────

    public function testStateFollowsTheLastResultAndAttemptsCount(): void
    {
        $job = $this->testJob();
        $service = $this->service();

        $this->assertSame(
            ['state' => 'failed', 'attempts' => 1],
            $service->report($job->jobId, 'failed', 'PRINTER_ERROR', self::author()),
        );
        $row = $this->storedJob();
        $this->assertSame('PRINTER_ERROR', $row['error_code']);
        $this->assertEquals($this->now, $row['result_at']);

        $this->assertSame(
            ['state' => 'sent', 'attempts' => 2],
            $service->report($job->jobId, 'sent', 'IGNORED', self::admin()),
        );
        $this->assertNull($this->storedJob()['error_code']);
        $this->assertSame(PrintJobState::Sent, $service->get($job->jobId, self::author())->state);
    }

    public function testForeignUserCannotReportTheResult(): void
    {
        $job = $this->testJob();

        $this->assertFails('FORBIDDEN', 403, fn () => $this->service()->report($job->jobId, 'sent', null, self::other()));
        $this->assertSame(0, $this->storedJob()['attempts']);
    }

    public function testInvalidStatusAndErrorCode(): void
    {
        $job = $this->testJob();
        $service = $this->service();

        foreach (['created', 'done', '', null, 1] as $status) {
            $e = $this->assertFails('VALIDATION_ERROR', 422, static fn () => $service->report($job->jobId, $status, null, self::author()));
            $this->assertSame('status', $e->details[0]['field']);
        }
        foreach ([null, '', 'printer_error', 'PRINTER ERROR', '1X', str_repeat('A', 65), 5] as $code) {
            $e = $this->assertFails('VALIDATION_ERROR', 422, static fn () => $service->report($job->jobId, 'failed', $code, self::author()));
            $this->assertSame('errorCode', $e->details[0]['field']);
        }
        $this->assertSame(0, $this->storedJob()['attempts']);

        $this->assertSame(1, $service->report($job->jobId, 'failed', str_repeat('A', 64), self::author())['attempts']);
    }

    public function testReportOfUnknownJob(): void
    {
        $this->assertFails('JOB_NOT_FOUND', 404, fn () => $this->service()->report(
            'aaaaaaaa-2222-4333-8444-555555555555', 'sent', null, self::admin(),
        ));
    }

    // ── prune ───────────────────────────────────────────────────────────────

    public function testPruneRemovesJobsOlderThanThirtyDays(): void
    {
        $old = $this->testJob();
        $this->now = $this->now->modify('+29 days');
        $kept = $this->testJob();
        $this->now = $this->now->modify('+2 days');

        $this->assertSame(1, $this->service()->prune());

        $this->assertSame([$kept->jobId], array_column($this->db->rows(InMemoryPrintJobsDb::JOBS), 'job_id'));
        $this->assertFails('JOB_NOT_FOUND', 404, fn () => $this->service()->get($old->jobId, self::admin()));
        $this->assertSame(0, $this->service()->prune());
        $this->assertSame(1, $this->service()->prune(0));
    }
}
