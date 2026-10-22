<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets\Dataset;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\ValidationResult;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Economy\Assets\Dataset\AssetSeeder;
use Shipard\Tests\Unit\Module\Core\Exchange\Dataset\DatasetPreflightTest;

/**
 * Seeder karet (#131 fáze 2c, D38, D40): přirozené klíče → id, karta bez
 * importního módu, dlouhodobá karta vždy V pořádku (archiv až vyřazením),
 * existující číslo = skipped, neznámý typ nebo vlastník a odmítnutí
 * dokumentu = failed.
 */
class AssetSeederTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_assetseed_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    private function writeSet(): DatasetReader
    {
        $w = DatasetWriter::create($this->tmp . '/set');
        $w->writeJsonc('assets/0001-ma0001.jsonc', DatasetPreflightTest::asset());
        $w->writeJsonc('assets/0002-ma0002.jsonc', DatasetPreflightTest::asset([
            'asset' => ['assetNumber' => 'MA0002'] + DatasetPreflightTest::asset()['asset'],
        ]));
        $w->writeJsonc('assets/0003-ma0003.jsonc', DatasetPreflightTest::asset([
            'asset' => ['assetNumber' => 'MA0003', 'type' => 'Nábytek'] + DatasetPreflightTest::asset()['asset'],
        ]));
        $w->writeJsonc('assets/0004-ma0004.jsonc', [
            'format' => 'shpd.dataset.asset.v1',
            'asset'  => [
                'assetNumber' => 'MA0004', 'name' => 'Vrtačka', 'category' => 'small', 'tracking' => 'single',
                'foreign' => true, 'owner' => ['name' => 'Neznámý s.r.o.', 'companyId' => '00000000'],
                'acquiredDate' => '2025-03-01', 'price' => 4990.0, 'state' => 'confirmed',
            ],
        ]);
        $w->writeJsonc('assets/0005-ma0005.jsonc', [
            'format' => 'shpd.dataset.asset.v1',
            'asset'  => [
                'assetNumber' => 'MA0005', 'name' => 'Bruska', 'category' => 'small', 'tracking' => 'single',
                'foreign' => true, 'owner' => ['name' => 'Půjčovna nářadí s.r.o.', 'country' => 'cz', 'companyId' => '12345678'],
                'acquiredDate' => '2025-03-01', 'disposedDate' => '2026-01-31', 'price' => 4990.0, 'state' => 'archived',
            ],
        ]);
        $w->writeJsonc('assets/0006-ma0006.jsonc', DatasetPreflightTest::asset([
            'asset' => ['assetNumber' => 'MA0006', 'accountingGroup' => '031', 'state' => 'archived'] + DatasetPreflightTest::asset()['asset'],
        ]));
        $w->writeManifest(new DatasetManifest('demo', 'Demo', null, 'fixed', '2026-08-26T10:00:00Z', ['assets' => 6]));
        return DatasetReader::open($this->tmp . '/set');
    }

    private function context(DatasetReader $reader): SeedContext
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(function (string $sql, mixed ...$params): ?Row {
            $this->assertStringContainsString('FROM [economy_assets_assets] WHERE [asset_number] = %s', $sql);
            $this->assertStringNotContainsString('docState', $sql, 'existující číslo se hledá přes všechny stavy (unikátní index)');
            return $params[0] === 'MA0002' ? new Row(['id' => 9]) : null;
        });
        $db->method('fetchAll')->willReturnCallback(function (string $sql, mixed ...$params): array {
            if (str_contains($sql, 'FROM [economy_assets_types] WHERE [name] = %s')) {
                return $params[0] === 'Výpočetní technika' ? [new Row(['id' => 5])] : [];
            }
            if (str_contains($sql, 'FROM [economy_assets_accounting_groups] WHERE [code] = %s')) {
                return match ($params[0]) { '022' => [new Row(['id' => 2])], '031' => [new Row(['id' => 6])], default => [] };
            }
            $this->fail("unexpected query: {$sql}");
        });
        $categories = JsoncParser::parseFile(dirname(__DIR__, 6) . '/modules/economy/assets/config/categories.jsonc');
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === 'economy.assets.categories' ? $categories : null,
        );
        return new SeedContext(
            reader: $reader,
            db: $db,
            dsConnection: $this->createMock(DataSourceConnection::class),
            config: $config,
            dsConfig: $this->createMock(DataSourceConfig::class),
            registry: new DocumentRegistry(),
            tables: [],
            dsDir: $this->tmp,
            attachments: $this->createMock(AttachmentService::class),
            dispatcher: null,
            merge: false,
        );
    }

    private function party(): PartyResolver
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturnCallback(function (array $party, mixed $type, bool $identifiersOnly): ResolveResult {
            $this->assertTrue($identifiersOnly, 'vlastník jen podle identifikátorů');
            return ($party['companyId'] ?? null) === '12345678' ? ResolveResult::matched(5, 'companyId') : ResolveResult::notFound();
        });
        return $party;
    }

    /** @return AssetSeeder&object{heads: list<array<string, mixed>>} */
    private function seeder(): AssetSeeder
    {
        return new class($this->party()) extends AssetSeeder {
            /** @var list<array<string, mixed>> */
            public array $heads = [];

            protected function saveCard(SeedContext $ctx, array $head): DocumentResult
            {
                $this->heads[] = $head;
                if ($head['asset_number'] === 'MA0006') {
                    $validation = new ValidationResult();
                    $validation->addError('accounting_group', 'Účetní skupina nemá účet odpisů a oprávek.', 'accountingGroupIncomplete');
                    return DocumentResult::validationFailed($validation);
                }
                return DocumentResult::ok(['id' => 30 + count($this->heads)] + $head);
            }
        };
    }

    public function testTranslatesNaturalKeysAndReportsPerFile(): void
    {
        $reader = $this->writeSet();
        $seeder = $this->seeder();
        $this->assertSame('assets', $seeder->section());
        $report = new SeedReport();

        $seeder->seed($this->context($reader), $report);

        $counts = $report->counts()['assets'];
        $this->assertSame(2, $counts['ok'], 'MA0001, MA0005');
        $this->assertSame(1, $counts['skipped'], 'MA0002 už existuje');
        $this->assertSame(3, $counts['failed'], 'MA0003 neznámý typ, MA0004 neznámý vlastník, MA0006 odmítl dokument');

        $this->assertSame(['MA0001', 'MA0005', 'MA0006'], array_column($seeder->heads, 'asset_number'), 'dokument se volá jen s přeloženou hlavičkou');
        $server = $seeder->heads[0];
        $this->assertSame([
            'asset_number' => 'MA0001', 'name' => 'Server 1U, 2× CPU, 64 GB RAM', 'category' => 'tangible', 'tracking' => 'single',
            'tax_method' => 'straight', 'tax_rule' => 'cz-1', 'acc_method' => 'as_tax',
            'is_foreign' => 0, 'acquired_date' => null, 'disposed_date' => null, 'price' => null,
            'asset_type' => 5, 'accounting_group' => 2, 'owner' => null, 'docState' => 40,
        ], $server, 'typ názvem → id, skupina kódem → id, dlouhodobá karta bez dat a ceny, bez markeru _import');
        $this->assertArrayNotHasKey('_import', $server);

        $drill = $seeder->heads[1];
        $this->assertSame(['MA0005', 70, 5, 1, '2025-03-01', '2026-01-31', 4990.0],
            [$drill['asset_number'], $drill['docState'], $drill['owner'], $drill['is_foreign'], $drill['acquired_date'], $drill['disposed_date'], $drill['price']],
            'drobný majetek: cílový stav přímo, data a cena z obálky, vlastník identifikátory → id');

        $this->assertSame(40, $seeder->heads[2]['docState'], 'dlouhodobá vyřazená karta se ukládá V pořádku — do archivu ji přesune vyřazení (D38)');
        $this->assertSame(6, $seeder->heads[2]['accounting_group']);

        $errors = $report->errors();
        $this->assertCount(3, $errors, implode("\n", $errors));
        $this->assertStringContainsString("0003-ma0003.jsonc: economy_assets_types name 'Nábytek': na tomto zdroji dat není", $errors[0]);
        $this->assertStringContainsString("0004-ma0004.jsonc: asset.owner: vlastník 'Neznámý s.r.o.' (IČO 00000000) v adresáři není", $errors[1]);
        $this->assertStringContainsString('0006-ma0006.jsonc: asset.accountingGroup: Účetní skupina nemá účet odpisů a oprávek.', $errors[2]);

        $warnings = $report->warnings();
        $this->assertCount(1, $warnings, implode("\n", $warnings));
        $this->assertStringContainsString('0002-ma0002.jsonc: karta MA0002 už na DS je', $warnings[0]);
    }
}
