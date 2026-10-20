<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\EInvoiceRenderCommand;
use Shipard\Module\Core\Exchange\EInvoice\EInvoiceOptions;
use Shipard\Module\Core\Exchange\EInvoice\EInvoiceWriter;
use Shipard\Module\Core\Exchange\Isdoc\IsdocSchemaValidator;
use Shipard\Module\Core\Exchange\Isdoc\IsdocWriter;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Generátor podvržený seamem — vrací XML, které neprojde XSD (exit 2). */
class BrokenWriterEInvoiceRenderCommand extends EInvoiceRenderCommand
{
    protected function writerFor(string $format): ?EInvoiceWriter
    {
        return new class implements EInvoiceWriter {
            public function format(): string
            {
                return 'isdoc';
            }

            public function write(array $canonical, EInvoiceOptions $options): string
            {
                return '<?xml version="1.0"?><Invoice xmlns="' . IsdocWriter::NAMESPACE . '" version="6.0.1"><ID>x</ID></Invoice>';
            }
        };
    }
}

/** `shpd-server einvoice-render` — exit 0 / 1 / 2, atomický zápis, stderr (#119 D9). */
class EInvoiceRenderCommandTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../Fixtures/Exchange/einvoice';

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shipard_einvoice_render_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    private function tester(?EInvoiceRenderCommand $command = null): CommandTester
    {
        $command ??= new EInvoiceRenderCommand();
        $app = new Application();
        $app->add($command);
        return new CommandTester($command);
    }

    /**
     * @param array<string, string> $options
     * @return array{int, string, string} exit, stdout, stderr
     */
    private function runCommand(array $options, ?EInvoiceRenderCommand $command = null): array
    {
        $tester = $this->tester($command);
        $input = [];
        foreach ($options as $name => $value) {
            $input["--{$name}"] = $value;
        }
        $exit = $tester->execute($input, ['capture_stderr_separately' => true]);
        return [$exit, $tester->getDisplay(), $tester->getErrorOutput()];
    }

    /** @return array<string, string> */
    private function defaults(string $input = 'issued_two_rates'): array
    {
        return [
            'format'    => 'isdoc',
            'input'     => self::FIXTURES . "/{$input}.json",
            'output'    => $this->tempDir . '/out.isdoc',
            'uuid-name' => 'test/einvoice-render',
        ];
    }

    public function testWritesValidIsdocDeterministically(): void
    {
        [$exit, $stdout, $stderr] = $this->runCommand($this->defaults());

        $this->assertSame(0, $exit, $stderr);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('ISDOC: ' . $this->tempDir . '/out.isdoc', $stdout);
        $xml = (string) file_get_contents($this->tempDir . '/out.isdoc');
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertSame([], (new IsdocSchemaValidator())->validateAll($xml));
        $this->assertStringContainsString('<IssuingSystem>Shipard</IssuingSystem>', $xml);
        $this->assertSame([], glob($this->tempDir . '/*.tmp.*') ?: [], 'žádný dočasný soubor');

        [$exit2] = $this->runCommand($this->defaults() + ['issuing-system' => 'Shipard']);
        $this->assertSame(0, $exit2);
        $this->assertSame($xml, file_get_contents($this->tempDir . '/out.isdoc'), 'druhý běh = stejné bajty');

        [$exit3] = $this->runCommand(['issuing-system' => 'Corpus build'] + $this->defaults());
        $this->assertSame(0, $exit3);
        $this->assertStringContainsString('<IssuingSystem>Corpus build</IssuingSystem>', (string) file_get_contents($this->tempDir . '/out.isdoc'));
    }

    public function testInputProblemsGoToStderrAndNothingIsWritten(): void
    {
        $canonical = json_decode((string) file_get_contents(self::FIXTURES . '/issued_two_rates.json'), true);
        unset($canonical['supplier']['companyId']);
        $canonical['currency'] = 'EUR';
        $input = $this->tempDir . '/in.json';
        file_put_contents($input, json_encode($canonical));

        [$exit, $stdout, $stderr] = $this->runCommand(['input' => $input] + $this->defaults());

        $this->assertSame(1, $exit);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('supplier.companyId: chybí', $stderr);
        $this->assertStringContainsString('currency:', $stderr);
        $this->assertFileDoesNotExist($this->tempDir . '/out.isdoc');
    }

    public function testUnknownFormatMissingOptionAndBadJson(): void
    {
        [$exit, , $stderr] = $this->runCommand(['format' => 'ubl'] + $this->defaults());
        $this->assertSame(1, $exit);
        $this->assertStringContainsString("Unknown format 'ubl'", $stderr);

        [$exit, , $stderr] = $this->runCommand(array_diff_key($this->defaults(), ['uuid-name' => 1]));
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--uuid-name', $stderr);

        $bad = $this->tempDir . '/bad.json';
        file_put_contents($bad, '{not json');
        [$exit, , $stderr] = $this->runCommand(['input' => $bad] + $this->defaults());
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not a JSON object', $stderr);

        [$exit, , $stderr] = $this->runCommand(['input' => $this->tempDir . '/missing.json'] + $this->defaults());
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Cannot read', $stderr);
        $this->assertFileDoesNotExist($this->tempDir . '/out.isdoc');
    }

    public function testOutputFailingSchemaIsExitTwo(): void
    {
        [$exit, $stdout, $stderr] = $this->runCommand($this->defaults(), new BrokenWriterEInvoiceRenderCommand());

        $this->assertSame(2, $exit);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('schema validation', $stderr);
        $this->assertStringContainsString('[6.0.1]', $stderr);
        $this->assertStringContainsString('[6.0.2]', $stderr);
        $this->assertFileDoesNotExist($this->tempDir . '/out.isdoc');
    }

    public function testNonPayerFixtureRenders(): void
    {
        [$exit, , $stderr] = $this->runCommand($this->defaults('issued_non_payer'));
        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString('<VATApplicable>false</VATApplicable>', (string) file_get_contents($this->tempDir . '/out.isdoc'));
    }
}
