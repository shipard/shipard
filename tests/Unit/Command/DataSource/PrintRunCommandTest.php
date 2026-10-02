<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\PrintRunCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestablePrintRunDataCommand extends PrintRunCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        ?DataSourceConnection $dsConnection,
        ServerConfig $serverConfig,
        private readonly string $modulesPath,
    ) {
        parent::__construct($dsConfig, $dsConnection, $serverConfig);
    }

    protected function getModulePathResolver(): ModulePathResolver
    {
        return new ModulePathResolver([$this->modulesPath]);
    }
}

/**
 * Nástroje pro vývoj šablon (#90 D28): `print-run --data` renderuje hotový
 * `PrintData` bez databáze a builderu, `--format=html` zapíše do adresáře
 * to, co jde do render služby. Šablony a fixture jsou ty skutečné
 * (`modules/`, `tests/Fixtures/Prints/`).
 */
class PrintRunCommandTest extends TestCase
{
    private const PRINT_ID = 'docs.invoicesOut.invoice';

    private string $dsDir;
    private string $fixture;

    protected function setUp(): void
    {
        $this->dsDir = sys_get_temp_dir() . '/shpd_printrun_' . uniqid('', true);
        mkdir($this->dsDir, 0755, true);
        $this->fixture = dirname(__DIR__, 3) . '/Fixtures/Prints/invoice.json';
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->dsDir);
    }

    private function rmTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Spojení do databáze je mock, na který nesmí přijít jediné volání —
     * render hotových dat ho nepotřebuje.
     *
     * @param array<string, mixed> $input
     * @return array{0: int, 1: string, 2: string} exit, stdout, stderr
     */
    private function exec(array $input): array
    {
        $dsConfig = $this->createStub(DataSourceConfig::class);
        $dsConfig->method('getModules')->willReturn(['docs.invoicesOut']);
        $dsConfig->method('getDefaultLanguage')->willReturn('cs');
        $dsConfig->method('getDataSourceDir')->willReturn($this->dsDir);

        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method($this->anything());

        // Server bez render služby (`render` v server.json chybí).
        $serverConfig = $this->createStub(ServerConfig::class);
        $serverConfig->method('getRender')->willReturn(null);

        $tester = new CommandTester(new TestablePrintRunDataCommand(
            $dsConfig,
            $db,
            $serverConfig,
            dirname(__DIR__, 4) . '/modules',
        ));
        $exit = $tester->execute($input, ['capture_stderr_separately' => true]);

        return [$exit, $tester->getDisplay(), $tester->getErrorOutput()];
    }

    public function testDataToHtmlWritesWhatGoesToRenderService(): void
    {
        $out = $this->dsDir . '/out/html';
        mkdir(dirname($out));
        file_put_contents(dirname($out) . '/keep.txt', 'x');

        [$exit, $stdout, $stderr] = $this->exec([
            'printId' => self::PRINT_ID, '--data' => $this->fixture, '--format' => 'html', '--output' => $out,
        ]);

        $this->assertSame(Command::SUCCESS, $exit, $stderr);
        $this->assertSame('', $stdout);

        $page = (string) file_get_contents($out . '/index.html');
        $this->assertStringContainsString('<link rel="stylesheet" href="doc-base.css">', $page);
        $this->assertStringContainsString('Tiskárna Vzorová s.r.o.', $page);
        $this->assertStringContainsString('Konzultace', $page);

        $this->assertStringContainsString('IT-PRINT-INV', (string) file_get_contents($out . '/header.html'));
        $this->assertStringContainsString('pageNumber', (string) file_get_contents($out . '/footer.html'));
        $this->assertFileEquals(
            dirname(__DIR__, 4) . '/modules/docs/core/prints/_layout/doc-base.css',
            $out . '/doc-base.css',
        );
        $this->assertFileExists(dirname($out) . '/keep.txt');
    }

    public function testHtmlOverwritesSameFilesAndKeepsOthers(): void
    {
        $out = $this->dsDir . '/html';
        mkdir($out);
        file_put_contents($out . '/index.html', 'old');
        file_put_contents($out . '/notes.txt', 'mine');

        [$exit] = $this->exec([
            'printId' => self::PRINT_ID, '--data' => $this->fixture, '--format' => 'html', '--output' => $out,
        ]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('<!DOCTYPE html>', (string) file_get_contents($out . '/index.html'));
        $this->assertSame('mine', file_get_contents($out . '/notes.txt'));
    }

    public function testLanguageOptionOverridesLanguageOfData(): void
    {
        $out = $this->dsDir . '/html';

        [$exit] = $this->exec([
            'printId' => self::PRINT_ID, '--data' => $this->fixture, '--format' => 'html',
            '--output' => $out, '--language' => 'en',
        ]);

        $this->assertSame(Command::SUCCESS, $exit);
        $page = (string) file_get_contents($out . '/index.html');
        $this->assertStringContainsString('<html lang="en">', $page);
        $this->assertStringContainsString('Supplier', $page);
    }

    public function testDataOfAnotherPrintIsRejected(): void
    {
        [$exit, , $stderr] = $this->exec([
            'printId' => self::PRINT_ID,
            '--data' => dirname($this->fixture) . '/proforma.json',
            '--format' => 'html',
            '--output' => $this->dsDir . '/html',
        ]);

        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString("belong to print 'docs.proformasOut.proforma'", $stderr);
        $this->assertDirectoryDoesNotExist($this->dsDir . '/html');
    }

    public function testInvalidCombinationsOfOptions(): void
    {
        // --data + json nedává smysl (json je default formát).
        [$exit, , $stderr] = $this->exec(['printId' => self::PRINT_ID, '--data' => $this->fixture]);
        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('--format=html or --format=pdf', $stderr);

        [$exit, , $stderr] = $this->exec([
            'printId' => self::PRINT_ID, 'recordId' => '5', '--data' => $this->fixture,
            '--format' => 'html', '--output' => $this->dsDir . '/html',
        ]);
        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('recordId is not used with --data', $stderr);

        [$exit, , $stderr] = $this->exec(['printId' => self::PRINT_ID, '--data' => $this->fixture, '--format' => 'html']);
        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('--format=html requires --output', $stderr);

        [$exit, , $stderr] = $this->exec(['printId' => self::PRINT_ID]);
        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('Invalid recordId', $stderr);
    }

    public function testUnreadableOrBrokenDataFile(): void
    {
        $args = ['printId' => self::PRINT_ID, '--format' => 'html', '--output' => $this->dsDir . '/html'];

        [$exit, , $stderr] = $this->exec($args + ['--data' => $this->dsDir . '/missing.json']);
        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('Cannot read --data file', $stderr);

        file_put_contents($this->dsDir . '/broken.json', '{ not json');
        [$exit, , $stderr] = $this->exec($args + ['--data' => $this->dsDir . '/broken.json']);
        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('is not a JSON object', $stderr);

        // Jen sekce `data` bez obálky.
        file_put_contents($this->dsDir . '/bare.json', '{"printId": "' . self::PRINT_ID . '", "document": {}}');
        [$exit, , $stderr] = $this->exec($args + ['--data' => $this->dsDir . '/bare.json']);
        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString("missing 'version'", $stderr);
    }

    public function testDataToPdfWithoutRenderServiceFails(): void
    {
        // Bez render služby skončí PDF jako nenakonfigurovaná služba;
        // k databázi se příkaz nedostane ani tady.
        [$exit, , $stderr] = $this->exec([
            'printId' => self::PRINT_ID, '--data' => $this->fixture,
            '--format' => 'pdf', '--output' => $this->dsDir . '/x.pdf',
        ]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('unconfigured', $stderr);
        $this->assertFileDoesNotExist($this->dsDir . '/x.pdf');
    }
}
