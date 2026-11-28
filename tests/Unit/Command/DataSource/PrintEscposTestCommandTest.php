<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\PrintEscposTestCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestablePrintEscposTestCommand extends PrintEscposTestCommand
{
    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-28 10:15:42');
    }
}

/** Zkušební účtenka profilu z CLI (#177 task 4a). */
class PrintEscposTestCommandTest extends TestCase
{
    private string $dsDir;

    protected function setUp(): void
    {
        $this->dsDir = sys_get_temp_dir() . '/shpd_escpostest_' . uniqid('', true);
        mkdir($this->dsDir . '/config/configuration', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dsDir . '/config/configuration/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->dsDir . '/*.bin') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dsDir . '/config/configuration');
        @rmdir($this->dsDir . '/config');
        @rmdir($this->dsDir);
    }

    /** Zkompilovaná konfigurace zdroje dat se skutečnými cfgItemy modulu core.prints. */
    private function compileConfig(): void
    {
        $items = [];
        foreach (['printerDrivers', 'printerProfiles', 'printerTestTexts'] as $name) {
            $items['core.prints.' . $name] = ConfigLocalizer::localize(
                JsoncParser::parseFile(dirname(__DIR__, 4) . "/modules/core/prints/config/{$name}.jsonc"),
                'cs',
            );
        }
        file_put_contents(
            $this->dsDir . '/config/configuration/compiled.cs.json',
            json_encode(['items' => $items], JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: int, 1: string, 2: string} exit, stdout, stderr
     */
    private function exec(array $input): array
    {
        $dsConfig = $this->createStub(DataSourceConfig::class);
        $dsConfig->method('getDefaultLanguage')->willReturn('cs');
        $dsConfig->method('getDataSourceDir')->willReturn($this->dsDir);

        $tester = new CommandTester(new TestablePrintEscposTestCommand($dsConfig));
        $exit   = $tester->execute($input, ['capture_stderr_separately' => true]);

        return [$exit, $tester->getDisplay(), $tester->getErrorOutput()];
    }

    public function testWritesTestReceiptOfTheProfile(): void
    {
        $this->compileConfig();
        $out = $this->dsDir . '/wide.bin';

        [$exit, $stdout, $stderr] = $this->exec(['--profile' => 'escpos-80-cp852', '--output' => $out]);

        $this->assertSame(Command::SUCCESS, $exit, $stderr);
        $this->assertSame('', $stderr);
        $bytes = (string) file_get_contents($out);
        $this->assertStringStartsWith("\x1B@\x1C.\x1Bt\x12", $bytes);
        // „ZKUŠEBNÍ ÚČTENKA“ v CP852 a čas z hodin příkazu.
        $this->assertStringContainsString("ZKU\xE6EBN\xD6 \xE9\xACTENKA\n", $bytes);
        $this->assertStringContainsString("2026-11-28 10:15:42\n", $bytes);
        $this->assertStringContainsString(str_repeat('1234567890', 4) . "12\n", $bytes);
        $this->assertStringEndsWith("\x1DVA\x05", $bytes);
        $this->assertStringContainsString(strlen($bytes) . ' bytes, profile escpos-80-cp852', $stdout);
    }

    public function testNarrowProfileHasNarrowRuler(): void
    {
        $this->compileConfig();
        $out = $this->dsDir . '/narrow.bin';

        [$exit] = $this->exec(['--profile' => 'escpos-58-cp852', '--output' => $out]);

        $this->assertSame(Command::SUCCESS, $exit);
        $bytes = (string) file_get_contents($out);
        // Pravítko běžným písmem má 32 znaků, malým 42.
        $this->assertStringContainsString(
            "\n" . str_repeat('1234567890', 3) . "12\n\x1B!\x01" . str_repeat('1234567890', 4) . "12\n",
            $bytes,
        );
    }

    public function testUnknownOrMissingProfileUsesFallbackWithWarning(): void
    {
        $this->compileConfig();

        foreach ([['--profile' => 'epson-tm'], []] as $index => $options) {
            $out = $this->dsDir . "/fallback{$index}.bin";
            [$exit, $stdout, $stderr] = $this->exec($options + ['--output' => $out]);

            $this->assertSame(Command::SUCCESS, $exit);
            $this->assertStringContainsString('Warning:', $stderr);
            $this->assertStringContainsString('escpos-58-cp852', $stderr);
            $this->assertStringContainsString('profile escpos-58-cp852', $stdout);
            $this->assertFileExists($out);
        }
    }

    public function testOutputIsRequired(): void
    {
        $this->compileConfig();

        [$exit, , $stderr] = $this->exec(['--profile' => 'escpos-80-cp852']);

        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('--output=<file> is required', $stderr);
    }

    public function testDataSourceWithoutCompiledConfigFails(): void
    {
        $out = $this->dsDir . '/none.bin';

        [$exit, , $stderr] = $this->exec(['--profile' => 'escpos-80-cp852', '--output' => $out]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Cannot read compiled config', $stderr);
        $this->assertFileDoesNotExist($out);
    }

    public function testDataSourceWithoutPrinterConfigFailsWithHint(): void
    {
        file_put_contents($this->dsDir . '/config/configuration/compiled.cs.json', '{"items": {}}');
        $out = $this->dsDir . '/none.bin';

        [$exit, , $stderr] = $this->exec(['--output' => $out]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('ds-upgrade', $stderr);
        $this->assertFileDoesNotExist($out);
    }

    public function testCannotWriteOutputFile(): void
    {
        $this->compileConfig();

        [$exit, , $stderr] = $this->exec(['--output' => $this->dsDir . '/missing-dir/x.bin']);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Cannot write output file', $stderr);
    }
}
