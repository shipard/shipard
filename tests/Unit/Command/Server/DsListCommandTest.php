<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\DsListCommand;
use Shipard\Core\Logging\ErrorLogger;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDsListCommand extends DsListCommand
{
    public string $serverMode = 'development';
    public bool $serverAi = false;

    public function __construct(private readonly string $dataSourcesDir)
    {
        parent::__construct();
    }

    protected function getDataSourcesDir(): string
    {
        return $this->dataSourcesDir;
    }

    protected function getServerMode(): string
    {
        return $this->serverMode;
    }

    protected function serverOffersAi(): bool
    {
        return $this->serverAi;
    }
}

/** `shpd-server ds-list [--json]` (#126 D10): tabulka, legenda, souhrn, stabilní JSON bez tajemství. */
class DsListCommandTest extends TestCase
{
    private string $tmpDir;
    private string $dsDir;

    protected function setUp(): void
    {
        ErrorLogger::resetForTesting();
        $this->tmpDir = sys_get_temp_dir() . '/shpd-ds-list-test-' . uniqid();
        $this->dsDir = $this->tmpDir . '/data-sources';
        mkdir($this->dsDir, 0755, true);
        ErrorLogger::setLogPath($this->tmpDir . '/shipard.log');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
        ErrorLogger::resetForTesting();
    }

    /** @param array<string, mixed>|string $main */
    private function createDs(string $id, array|string $main, ?string $stateJson = null): void
    {
        $dir = $this->dsDir . '/' . $id;
        mkdir($dir . '/config', 0755, true);
        file_put_contents($dir . '/config/main.json', is_array($main) ? json_encode($main, JSON_UNESCAPED_UNICODE) : $main);
        if ($stateJson !== null) {
            file_put_contents($dir . '/config/state.json', $stateJson);
        }
    }

    private function makeTester(string $serverMode = 'development', bool $serverAi = false): CommandTester
    {
        $cmd = new TestableDsListCommand($this->dsDir);
        $cmd->serverMode = $serverMode;
        $cmd->serverAi = $serverAi;
        return new CommandTester($cmd);
    }

    private function createFixtures(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', [
            'id' => 'aaaa-aaaa-aaaa-aaaa', 'name' => 'Alpha', 'modules' => ['install.base'], 'country' => 'cz',
            'defaultLanguage' => 'cs', 'database_name' => 'aaaa_aaaa_aaaa_aaaa', 'database_password' => 'S3cret!Pw',
            'created' => '2026-10-01T10:00:00+02:00', 'dataClass' => 'free',
            'purpose' => 'Testovací sada plátce DPH pro evaluaci AI nad korpusem dokladů',
            'origin' => 'dataset-seed cz-vat-payer', 'dataClassChanged' => '2026-10-16T12:00:00Z',
            'auth' => ['providers' => [['id' => 'x', 'clientSecret' => 'oidc-secret-value']]],
        ]);
        $this->createDs('bbbb-bbbb-bbbb-bbbb', [
            'id' => 'bbbb-bbbb-bbbb-bbbb', 'name' => 'Bravo', 'modules' => ['install.base'], 'country' => 'cz',
        ], '{"version":1,"state":"active","cron":false}');
        $this->createDs('cccc-cccc-cccc-cccc', [
            'id' => 'cccc-cccc-cccc-cccc', 'name' => 'Charlie', 'dataClass' => 'Free', 'purpose' => 'Import',
        ], '{"version":1,"state":"read_only","maintenance":{"reason":"import","since":"2026-10-01T00:00:00Z"}}');
        $this->createDs('dddd-dddd-dddd-dddd', '{not json');
        mkdir($this->dsDir . '/lost+found', 0755, true);
    }

    public function testTableShowsClassPurposeOriginCronStateAndServerAiOnADevelopmentServer(): void
    {
        $this->createFixtures();
        $tester = $this->makeTester(serverAi: true);

        $this->assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay();

        $this->assertMatchesRegularExpression('/ID\s*\|\s*Name\s*\|\s*Class\s*\|\s*Purpose\s*\|\s*Origin\s*\|\s*Country\s*\|\s*Modules\s*\|\s*Cron\s*\|\s*State\s*\|\s*Server AI/', $display);
        // Explicitní free, účel zkrácený na 40 znaků (U7), serverové AI on.
        $this->assertMatchesRegularExpression(
            '/aaaa-aaaa-aaaa-aaaa\s*\|\s*Alpha\s*\|\s*free\s*\|\s*Testovací sada plátce DPH pro evaluaci…\s*\|\s*dataset-seed cz-vat-payer\s*\|\s*cz\s*\|\s*install\.base\s*\|\s*on\s*\|\s*active\s*\|\s*on/',
            $display,
        );
        // Bez třídy = realCopy*, cron off, serverové AI off.
        $this->assertMatchesRegularExpression('/bbbb-bbbb-bbbb-bbbb\s*\|\s*Bravo\s*\|\s*realCopy\*\s*\|\s*\|\s*\|\s*cz\s*\|\s*install\.base\s*\|\s*off\s*\|\s*active\s*\|\s*off/', $display);
        // Neplatná hodnota = realCopy!, maintenance = suspended.
        $this->assertMatchesRegularExpression('/cccc-cccc-cccc-cccc\s*\|\s*Charlie\s*\|\s*realCopy!\s*\|\s*Import\s*\|.*\|\s*on\s*\|\s*suspended\s*\|\s*off/', $display);
        // Nečitelný main.json = otazníky, stav dál ze state.json.
        $this->assertMatchesRegularExpression('/dddd-dddd-dddd-dddd\s*\|\s*\?\s*\|\s*\?\s*\|\s*\?\s*\|\s*\?\s*\|\s*\?\s*\|\s*\?\s*\|\s*on\s*\|\s*active\s*\|\s*off/', $display);
        $this->assertStringNotContainsString('lost+found', $display);

        $this->assertStringContainsString('realCopy* = not set in main.json', $display);
        $this->assertStringContainsString('realCopy! = invalid value', $display);
        $this->assertStringContainsString('? = config/main.json not readable — run as the data sources owner or with sudo', $display);
        $this->assertStringContainsString('4 data source(s): 1 free, 3 realCopy (3 without explicit class)', $display);
        $this->assertStringNotContainsString('S3cret!Pw', $display);
    }

    public function testServerAiColumnOnlyInDevelopment(): void
    {
        $this->createFixtures();

        $tester = $this->makeTester('production', true);
        $this->assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('Server AI', $display);
        $this->assertMatchesRegularExpression('/aaaa-aaaa-aaaa-aaaa\s*\|\s*Alpha\s*\|\s*free\s*\|.*\|\s*on\s*\|\s*active\s*\|\s*$/m', $display);

        $tester = $this->makeTester('development', false);
        $tester->execute([]);
        $this->assertMatchesRegularExpression('/aaaa-aaaa-aaaa-aaaa\s*\|.*\|\s*active\s*\|\s*off\s*\|/', $tester->getDisplay(), 'bez klíče je serverové AI off i pro free');
        $this->assertStringNotContainsString('? = config/main.json not readable', $this->makeTesterWithoutUnreadable()->getDisplay());
    }

    private function makeTesterWithoutUnreadable(): CommandTester
    {
        exec('rm -rf ' . escapeshellarg($this->dsDir . '/dddd-dddd-dddd-dddd'));
        $tester = $this->makeTester();
        $tester->execute([]);
        return $tester;
    }

    public function testJsonIsAStableContractWithoutSecrets(): void
    {
        $this->createFixtures();
        $tester = $this->makeTester(serverAi: true);

        $this->assertSame(0, $tester->execute(['--json' => true]));
        $raw = $tester->getDisplay();
        $data = json_decode($raw, true);
        $this->assertIsArray($data, $raw);

        $this->assertSame(['mode' => 'development', 'serverAi' => true], $data['server']);
        $this->assertCount(4, $data['dataSources']);
        $alpha = $data['dataSources'][0];
        $this->assertSame(
            ['id', 'name', 'created', 'dataClass', 'dataClassExplicit', 'dataClassInvalid', 'purpose', 'origin',
             'dataClassChanged', 'dataset', 'country', 'defaultLanguage', 'modules', 'databaseName', 'state', 'cron', 'serverAi', 'readable'],
            array_keys($alpha),
        );
        $this->assertSame('aaaa-aaaa-aaaa-aaaa', $alpha['id']);
        $this->assertSame('free', $alpha['dataClass']);
        $this->assertTrue($alpha['dataClassExplicit']);
        $this->assertSame('Testovací sada plátce DPH pro evaluaci AI nad korpusem dokladů', $alpha['purpose'], 'plná hodnota, bez zkrácení');
        $this->assertTrue($alpha['serverAi']);
        $this->assertTrue($alpha['readable']);

        $bravo = $data['dataSources'][1];
        $this->assertSame('realCopy', $bravo['dataClass']);
        $this->assertFalse($bravo['dataClassExplicit']);
        $this->assertFalse($bravo['dataClassInvalid']);
        $this->assertFalse($bravo['cron']);
        $this->assertFalse($bravo['serverAi']);

        $this->assertTrue($data['dataSources'][2]['dataClassInvalid']);
        $this->assertFalse($data['dataSources'][3]['readable']);
        $this->assertSame('?', $data['dataSources'][3]['name']);

        foreach (['database_password', 'S3cret!Pw', 'clientSecret', 'oidc-secret-value', '"auth"'] as $needle) {
            $this->assertStringNotContainsString($needle, $raw, "'{$needle}' nepatří do výpisu");
        }
    }

    public function testJsonServerAiIsFalseOutsideDevelopmentEvenWithAKey(): void
    {
        $this->createFixtures();
        $tester = $this->makeTester('production', true);
        $tester->execute(['--json' => true]);
        $data = json_decode($tester->getDisplay(), true);

        $this->assertSame(['mode' => 'production', 'serverAi' => false], $data['server']);
        $this->assertFalse($data['dataSources'][0]['serverAi']);
    }

    public function testEmptyDirectory(): void
    {
        $tester = $this->makeTester();
        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('(no data sources)', $tester->getDisplay());

        $tester = $this->makeTester();
        $this->assertSame(0, $tester->execute(['--json' => true]));
        $this->assertSame([], json_decode($tester->getDisplay(), true)['dataSources']);
    }
}
