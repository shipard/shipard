<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\DsCronCommand;
use Shipard\Core\Logging\ErrorLogger;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDsCronCommand extends DsCronCommand
{
    public function __construct(private readonly string $dataSourcesDir)
    {
        parent::__construct();
    }

    protected function getDataSourcesDir(): string
    {
        return $this->dataSourcesDir;
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-12T10:00:00Z');
    }
}

class DsCronCommandTest extends TestCase
{
    private string $tmpDir;
    private string $dsDir;

    protected function setUp(): void
    {
        ErrorLogger::resetForTesting();
        $this->tmpDir = sys_get_temp_dir() . '/shpd-ds-cron-test-' . uniqid();
        $this->dsDir = $this->tmpDir . '/data-sources';
        mkdir($this->dsDir, 0755, true);
        ErrorLogger::setLogPath($this->tmpDir . '/shipard.log');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
        ErrorLogger::resetForTesting();
    }

    private function createDs(string $id, ?string $stateJson = null, string $mainJson = '{"name":"DS"}'): string
    {
        $dir = $this->dsDir . '/' . $id;
        mkdir($dir . '/config', 0755, true);
        file_put_contents($dir . '/config/main.json', $mainJson);
        if ($stateJson !== null) {
            file_put_contents($dir . '/config/state.json', $stateJson);
        }
        return $dir;
    }

    private function makeTester(): CommandTester
    {
        return new CommandTester(new TestableDsCronCommand($this->dsDir));
    }

    /** @return array<string, mixed>|null */
    private function stateFile(string $id): ?array
    {
        $path = $this->dsDir . '/' . $id . '/config/state.json';
        if (!is_file($path)) {
            return null;
        }
        return json_decode((string) file_get_contents($path), true);
    }

    // ── Přehled ─────────────────────────────────────────────────────────────

    public function testOverviewListsDataSourcesSortedByIdWithCronAndState(): void
    {
        $this->createDs('cccc-cccc-cccc-cccc', '{"version":1,"state":"active","cron":false}', '{"name":"Charlie"}');
        $this->createDs('aaaa-aaaa-aaaa-aaaa', null, '{"name":"Alpha"}');
        $this->createDs('bbbb-bbbb-bbbb-bbbb', '{"version":1,"state":"read_only","maintenance":{"reason":"import","since":"2026-10-01T00:00:00Z"}}', '{"name":"Bravo"}');
        $this->createDs('dddd-dddd-dddd-dddd', '{broken', '{not json');
        mkdir($this->dsDir . '/lost+found', 0755, true);

        $tester = $this->makeTester();
        $this->assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay();

        $this->assertMatchesRegularExpression('/aaaa-aaaa-aaaa-aaaa\s*\|\s*Alpha\s*\|\s*on\s*\|\s*active/', $display);
        $this->assertMatchesRegularExpression('/bbbb-bbbb-bbbb-bbbb\s*\|\s*Bravo\s*\|\s*on\s*\|\s*suspended \(maintenance: import\)/', $display);
        $this->assertMatchesRegularExpression('/cccc-cccc-cccc-cccc\s*\|\s*Charlie\s*\|\s*off\s*\|\s*active/', $display);
        $this->assertMatchesRegularExpression('/dddd-dddd-dddd-dddd\s*\|\s*\?\s*\|\s*\?\s*\|\s*corrupted/', $display);
        $this->assertLessThan(strpos($display, 'bbbb-bbbb'), strpos($display, 'aaaa-aaaa'));
        $this->assertLessThan(strpos($display, 'cccc-cccc'), strpos($display, 'bbbb-bbbb'));
        $this->assertStringNotContainsString('lost+found', $display);
        $this->assertStringContainsString('4 data source(s), 1 with cron off', $display);
    }

    public function testOverviewWithoutDataSources(): void
    {
        $tester = $this->makeTester();
        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('(no data sources)', $tester->getDisplay());
    }

    // ── Přepnutí ────────────────────────────────────────────────────────────

    public function testOffAllWritesEveryDataSourceAndContinuesPastCorrupted(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        $this->createDs('bbbb-bbbb-bbbb-bbbb', '{"version":1,"state":"read_only"}');
        $this->createDs('cccc-cccc-cccc-cccc', '{broken');
        $this->createDs('dddd-dddd-dddd-dddd', '{"version":1,"state":"active","cron":false}');

        $tester = $this->makeTester();
        $exit = $tester->execute(['--off' => true, '--all' => true]);

        $this->assertSame(1, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('✓ aaaa-aaaa-aaaa-aaaa: cron off', $display);
        $this->assertStringContainsString('✓ bbbb-bbbb-bbbb-bbbb: cron off', $display);
        $this->assertStringContainsString('✗ cccc-cccc-cccc-cccc: state.json unusable', $display);
        $this->assertStringContainsString('· dddd-dddd-dddd-dddd: unchanged (cron off)', $display);

        $this->assertSame([
            'version' => 1,
            'state' => 'active',
            'cron' => false,
            'changedBy' => 'cli',
            'changed' => '2026-10-12T10:00:00Z',
        ], $this->stateFile('aaaa-aaaa-aaaa-aaaa'));
        // Stav zůstává, jen přepínač.
        $this->assertSame('read_only', $this->stateFile('bbbb-bbbb-bbbb-bbbb')['state']);
        $this->assertFalse($this->stateFile('bbbb-bbbb-bbbb-bbbb')['cron']);
        // Poškozený soubor nepřepsán.
        $this->assertSame('{broken', file_get_contents($this->dsDir . '/cccc-cccc-cccc-cccc/config/state.json'));

        // Přehled po přepnutí.
        $this->assertStringContainsString('4 data source(s), 3 with cron off', $display);
    }

    public function testOnWithIdsSwitchesListedOnly(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', '{"version":1,"state":"active","cron":false}');
        $this->createDs('bbbb-bbbb-bbbb-bbbb', '{"version":1,"state":"active","cron":false}');
        $this->createDs('cccc-cccc-cccc-cccc', '{"version":1,"state":"active","cron":false}');

        $tester = $this->makeTester();
        $exit = $tester->execute(['--on' => true, 'ids' => ['aaaa-aaaa-aaaa-aaaa', 'cccc-cccc-cccc-cccc']]);

        $this->assertSame(0, $exit);
        $this->assertArrayNotHasKey('cron', $this->stateFile('aaaa-aaaa-aaaa-aaaa'));
        $this->assertArrayNotHasKey('cron', $this->stateFile('cccc-cccc-cccc-cccc'));
        $this->assertFalse($this->stateFile('bbbb-bbbb-bbbb-bbbb')['cron']);
        $this->assertStringContainsString('3 data source(s), 1 with cron off', $tester->getDisplay());
    }

    public function testUnchangedDoesNotRewriteFile(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        $tester = $this->makeTester();
        $this->assertSame(0, $tester->execute(['--on' => true, 'ids' => ['aaaa-aaaa-aaaa-aaaa']]));
        $this->assertStringContainsString('· aaaa-aaaa-aaaa-aaaa: unchanged (cron on)', $tester->getDisplay());
        $this->assertNull($this->stateFile('aaaa-aaaa-aaaa-aaaa'));
    }

    public function testUnknownAndMalformedIdsFailButOthersAreSwitched(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');

        $tester = $this->makeTester();
        $exit = $tester->execute(['--off' => true, 'ids' => ['zzzz-zzzz-zzzz-zzzz', '../aaaa-aaaa-aaaa-aaaa', 'aaaa-aaaa-aaaa-aaaa']]);

        $this->assertSame(1, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('✗ zzzz-zzzz-zzzz-zzzz: unknown data source', $display);
        $this->assertStringContainsString('✗ ../aaaa-aaaa-aaaa-aaaa: invalid data source ID', $display);
        $this->assertStringContainsString('✓ aaaa-aaaa-aaaa-aaaa: cron off', $display);
        $this->assertFalse($this->stateFile('aaaa-aaaa-aaaa-aaaa')['cron']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidInvocations(): iterable
    {
        yield 'on and off' => [['--on' => true, '--off' => true, '--all' => true]];
        yield 'on without targets' => [['--on' => true]];
        yield 'off without targets' => [['--off' => true]];
        yield 'all with ids' => [['--off' => true, '--all' => true, 'ids' => ['aaaa-aaaa-aaaa-aaaa']]];
        yield 'all without on/off' => [['--all' => true]];
        yield 'ids without on/off' => [['ids' => ['aaaa-aaaa-aaaa-aaaa']]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInvocations')]
    public function testInvalidOptionCombinationsFailWithoutWriting(array $args): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        $tester = $this->makeTester();
        $this->assertSame(1, $tester->execute($args));
        $this->assertStringContainsString('Usage: ds-cron', $tester->getDisplay());
        $this->assertNull($this->stateFile('aaaa-aaaa-aaaa-aaaa'));
    }
}
