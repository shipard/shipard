<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Items\Dataset;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;
use Shipard\Module\Economy\Items\AccountingItemsGenerator;
use Shipard\Module\Economy\Items\AccountingItemsOffer;
use Shipard\Module\Economy\Items\AccountingItemsUnavailableException;
use Shipard\Module\Economy\Items\Dataset\AccountingItemsSeeder;

class AccountingItemsSeederTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_accitems_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    /**
     * @param array<string, mixed> $provision
     */
    private function context(array $provision): SeedContext
    {
        $w = DatasetWriter::create($this->tmp . '/set');
        $w->writeManifest(new DatasetManifest('demo', 'Demo', null, 'fixed', '2026-08-26T10:00:00Z', [], provision: $provision));
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $dsConfig->method('getDefaultLanguage')->willReturn('cs');
        return new SeedContext(
            reader: DatasetReader::open($this->tmp . '/set'),
            db: $this->createMock(Connection::class),
            dsConnection: $this->createMock(DataSourceConnection::class),
            config: $this->createMock(ConfigRuntime::class),
            dsConfig: $dsConfig,
            registry: new DocumentRegistry(),
            tables: [],
            dsDir: $this->tmp,
            attachments: $this->createMock(AttachmentService::class),
            dispatcher: null,
            merge: false,
        );
    }

    /**
     * @param array{created?: list<array<string, mixed>>, skipped?: list<array<string, mixed>>, failed?: list<array<string, mixed>>}|\Throwable $outcome
     */
    private function seeder(array|\Throwable $outcome, ?string $variant = 'default'): AccountingItemsSeeder
    {
        $generator = $this->createMock(AccountingItemsGenerator::class);
        if ($outcome instanceof \Throwable) {
            $generator->method('generate')->willThrowException($outcome);
        } else {
            $generator->method('generate')->willReturn($outcome + ['created' => [], 'skipped' => [], 'failed' => []]);
        }
        $offer = $this->createMock(AccountingItemsOffer::class);
        $offer->method('variant')->willReturn($variant);

        return new class($offer, $generator) extends AccountingItemsSeeder {
            public ?array $lastRequest = null;

            public function __construct(AccountingItemsOffer $offer, private readonly AccountingItemsGenerator $stub)
            {
                parent::__construct($offer);
            }

            protected function generator(SeedContext $ctx): AccountingItemsGenerator
            {
                return $this->stub;
            }
        };
    }

    public function testWithoutProvisionNothingHappens(): void
    {
        $report = new SeedReport();
        $this->seeder(new \LogicException('must not be called'))->seed($this->context([]), $report);

        $this->assertSame([], $report->counts());
        $this->assertSame([], $report->errors());
    }

    public function testOutcomeMapping(): void
    {
        $report = new SeedReport();
        $this->seeder([
            'created' => [['id' => 1, 'code' => '568201', 'name' => 'Poplatky'], ['id' => 2, 'code' => '562100', 'name' => 'Úroky']],
            'skipped' => [
                ['code' => '548100Z', 'reason' => 'already_exists'],
                ['code' => '662100', 'reason' => 'account_not_found', 'accountNumber' => '662100'],
                ['code' => 'NOPE', 'reason' => 'unknown_code'],
            ],
            'failed'  => [['code' => '042100', 'reason' => 'save_failed', 'message' => 'boom']],
        ])->seed($this->context(['accountingItems' => 'all']), $report);

        $counts = $report->counts()['accountingItems'];
        $this->assertSame(3, $counts['ok'], 'created + already_exists');
        $this->assertSame(1, $counts['skipped']);
        $this->assertSame(2, $counts['failed']);
        $this->assertStringContainsString("kód 'NOPE' v nabídce osnovy 'default' není", implode("\n", $report->errors()));
        $this->assertStringContainsString('položka 042100: boom', implode("\n", $report->errors()));
        $warnings = implode("\n", $report->warnings());
        $this->assertStringContainsString('účet 662100 v osnově není', $warnings);
        $this->assertStringContainsString('založeno 2', $warnings);
    }

    public function testUnavailableOfferIsSingleFailure(): void
    {
        $report = new SeedReport();
        $this->seeder(new AccountingItemsUnavailableException('chart_undecided'), variant: null)
            ->seed($this->context(['accountingItems' => ['568201']]), $report);

        $this->assertSame(1, $report->counts()['accountingItems']['failed']);
        $this->assertCount(1, $report->errors());
        $this->assertStringContainsString('economy.accountChart není rozhodnuto', $report->errors()[0]);
    }

    public function testDescribeCoversEveryReason(): void
    {
        foreach (['chart_undecided', 'chart_none', 'accounting_inactive', 'seed_missing', 'item_kind_missing', 'unit_missing'] as $reason) {
            $text = AccountingItemsSeeder::describe(new AccountingItemsUnavailableException($reason, 'npo'));
            $this->assertNotSame('', $text);
            $this->assertStringNotContainsString('Accounting items offer is not available', $text, $reason);
        }
        $this->assertStringContainsString("'npo'", AccountingItemsSeeder::describe(new AccountingItemsUnavailableException('seed_missing', 'npo')));
    }
}
