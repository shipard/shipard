<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Bank\Dataset;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Bank\BankStatementApplier;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;
use Shipard\Module\Economy\Bank\Dataset\BankStatementSeeder;
use Shipard\Tests\Unit\Module\Core\Exchange\Dataset\DatasetPreflightTest;

/**
 * Seeder sekce bank (#131 fáze 2a): kód účtu → id a cílový stav do
 * kanonického výpisu pro BankStatementApplier; neznámý účet, rozbitý
 * soubor, neúspěšný apply a nezapsané transakce = failed; dedup = varování.
 */
class BankStatementSeederTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_bankseed_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    private function writeSet(): DatasetReader
    {
        $w = DatasetWriter::create($this->tmp . '/set');
        $w->writeJsonc('bank/0001-bu1-2026-01.jsonc', DatasetPreflightTest::bankStatement());
        $w->writeJsonc('bank/0002-bu9-2026-01.jsonc', DatasetPreflightTest::bankStatement([
            'bankAccount' => 'BU9',
            'statement' => ['externalId' => 'BU9-2026-01', 'periodStart' => '2026-01-01', 'periodEnd' => '2026-01-31'],
        ]));
        $w->writeJsonc('bank/0003-bu1-2026-02.jsonc', DatasetPreflightTest::bankStatement([
            'statement' => ['statementNumber' => '2/2026', 'externalId' => 'BU1-2026-02', 'periodStart' => '2026-02-01', 'periodEnd' => '2026-02-28', 'openingBalance' => 1.0, 'closingBalance' => 1.0, 'currency' => 'CZK'],
            'transactions' => [],
            'docState' => 10,
        ]));
        $w->writeRaw('bank/0004-broken.jsonc', '{"bankAccount": ');
        $w->writeManifest(new DatasetManifest('demo', 'Demo', null, 'fixed', '2026-08-26T10:00:00Z', ['bank' => 4]));
        return DatasetReader::open($this->tmp . '/set');
    }

    private function context(DatasetReader $reader): SeedContext
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(function (string $sql, mixed ...$params): ?Row {
            $this->assertStringContainsString('FROM [economy_codebooks_bank_accounts] WHERE [code] = %s AND [docState] <> 90', $sql);
            return ($params[0] ?? null) === 'BU1' ? new Row(['id' => 3]) : null;
        });
        return new SeedContext(
            reader: $reader,
            db: $db,
            dsConnection: $this->createMock(DataSourceConnection::class),
            config: $this->createMock(ConfigRuntime::class),
            dsConfig: $this->createMock(DataSourceConfig::class),
            registry: new DocumentRegistry(),
            tables: [],
            dsDir: $this->tmp,
            attachments: $this->createMock(AttachmentService::class),
            dispatcher: null,
            merge: false,
        );
    }

    /**
     * @param array<string, string> $outcomes externalId výpisu → ok | skipped | txError | error | invalid
     * @return BankStatementApplier&object{calls: list<array<string, mixed>>}
     */
    private function applier(array $outcomes): BankStatementApplier
    {
        return new class($outcomes) extends BankStatementApplier {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function __construct(private readonly array $outcomes)
            {
            }

            public function apply(array $canonical): ApplyResult
            {
                $this->calls[] = $canonical;
                $outcome = $this->outcomes[(string) ($canonical['statement']['externalId'] ?? '')] ?? 'ok';
                if ($outcome === 'error') {
                    return ApplyResult::error('apply_failed', 'Kurz EUR chybí pro data: 2026-01-05.', $canonical, 422);
                }
                if ($outcome === 'invalid') {
                    $enriched = $canonical + ['_issues' => [['path' => 'statement.periodEnd', 'code' => 'period_order', 'message' => 'Konec období před začátkem.']]];
                    return ApplyResult::error('validation_failed', 'Validace výpisu selhala.', $enriched, 422);
                }
                $result = ['savedStatementId' => 1, 'created' => 3, 'skipped' => 0, 'unmatchedPartner' => 1, 'reconciliation' => 0, 'currencyWarning' => null, 'txErrors' => []];
                if ($outcome === 'skipped') {
                    $result['created'] = 0;
                    $result['skipped'] = 3;
                }
                if ($outcome === 'txError') {
                    $result['created'] = 2;
                    $result['txErrors'] = [['date' => '2026-01-31', 'amount' => 169.0, 'errors' => [['column' => 'operation', 'message' => 'Pohyb neodpovídá směru.']]]];
                }
                return ApplyResult::ok($canonical + ['_result' => $result], 1);
            }
        };
    }

    public function testMapsAccountCodeAndTargetStateAndReportsPerFile(): void
    {
        $applier = $this->applier(['BU1-2026-02' => 'skipped']);
        $seeder = new BankStatementSeeder($applier);
        $this->assertSame('bank', $seeder->section());
        $report = new SeedReport();

        $seeder->seed($this->context($this->writeSet()), $report);

        $this->assertSame(['ok' => 2, 'failed' => 2, 'skipped' => 0], $report->counts()['bank']);
        $this->assertCount(2, $applier->calls, 'neznámý účet a rozbitý soubor k applieru nejdou');

        $first = $applier->calls[0];
        $source = DatasetPreflightTest::bankStatement();
        $this->assertSame('shpd.bank.statement', $first['format']);
        $this->assertSame('1.0', $first['formatVersion']);
        $this->assertSame(3, $first['bankAccountId'], 'kód BU1 přeložený na id číselníku');
        $this->assertSame($source['statement'], $first['statement']);
        $this->assertSame($source['transactions'], $first['transactions'], 'transakce beze změny — partnera dohledá applier');
        $this->assertSame(['targetState' => 40], $first['applyOptions']);
        $this->assertArrayNotHasKey('bankAccount', $first);
        $this->assertArrayNotHasKey('docState', $first);
        $this->assertSame(['targetState' => 10], $applier->calls[1]['applyOptions']);
        $this->assertSame([], $applier->calls[1]['transactions']);

        $errors = $report->errors();
        $this->assertCount(2, $errors);
        $this->assertStringContainsString("0002-bu9-2026-01.jsonc: bankovní účet 'BU9' na DS neexistuje (chybí v setup/bank_accounts?)", $errors[0]);
        $this->assertStringContainsString('0004-broken.jsonc', $errors[1]);
        $warnings = $report->warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('0003-bu1-2026-02.jsonc: 3 transakcí už na DS bylo (dedup podle externalId)', $warnings[0]);
    }

    public function testApplierFailuresAndUnsavedTransactionsFail(): void
    {
        $report = new SeedReport();
        (new BankStatementSeeder($this->applier(['BU1-2026-01' => 'txError', 'BU1-2026-02' => 'error'])))
            ->seed($this->context($this->writeSet()), $report);

        $this->assertSame(['ok' => 0, 'failed' => 4, 'skipped' => 0], $report->counts()['bank']);
        $errors = $report->errors();
        $this->assertStringContainsString('0001-bu1-2026-01.jsonc: transakce se nezapsaly: 2026-01-31 169: Pohyb neodpovídá směru.', $errors[0]);
        $this->assertStringContainsString('0003-bu1-2026-02.jsonc: Kurz EUR chybí pro data: 2026-01-05.', $errors[2]);
        $this->assertSame([], $report->warnings());
    }

    public function testValidationIssuesAreAppendedToTheError(): void
    {
        $report = new SeedReport();
        (new BankStatementSeeder($this->applier(['BU1-2026-01' => 'invalid'])))
            ->seed($this->context($this->writeSet()), $report);

        $this->assertStringContainsString(
            '0001-bu1-2026-01.jsonc: Validace výpisu selhala. (statement.periodEnd Konec období před začátkem.)',
            $report->errors()[0],
        );
    }
}
