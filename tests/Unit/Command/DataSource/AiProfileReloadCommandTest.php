<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\AiProfileReloadCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableAiProfileReloadCommand extends AiProfileReloadCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        private readonly string $dsDir,
    ) {
        parent::__construct($dsConfig, $dsConnection);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }
}

class AiProfileReloadCommandTest extends TestCase
{
    private string $tempDir;
    private string $templatePath;
    private MockObject $dsConfig;
    private MockObject $dsConnection;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_ai_profile_reload_' . uniqid();
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/main.json', '{}');

        $this->templatePath = $this->tempDir . '/profile.jsonc';
        $this->writeTemplate('v1.2.0');

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConfig->method('getId')->willReturn('test-test-test-test');

        $this->dsConnection = $this->createMock(DataSourceConnection::class);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tempDir);
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function writeTemplate(string $version, string $promptBody = 'New prompt body'): void
    {
        $tpl = [
            'profile_id' => 'czech_general',
            'name' => 'Obecná analýza pošty (česky)',
            'language' => 'cs',
            'prompt_version' => $version,
            'supported_doc_types' => ['invoiceReceived', 'creditNote', 'other'],
            'confidence_thresholds' => ['ready' => 0.9, 'review' => 0.6],
            'prompt_template' => $promptBody,
            'output_schema' => ['type' => 'object'],
        ];
        file_put_contents($this->templatePath, json_encode($tpl, JSON_PRETTY_PRINT));
    }

    private function tester(): CommandTester
    {
        $command = new TestableAiProfileReloadCommand(
            $this->dsConfig,
            $this->dsConnection,
            $this->tempDir,
        );
        (new Application())->add($command);
        return new CommandTester($command);
    }

    public function testFailsWhenProfileMissing(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);
        $this->dsConnection->expects($this->never())->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--template-path' => $this->templatePath]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString("'czech_general' not found", $output);
        $this->assertStringContainsString('ds-upgrade', $output);
    }

    public function testSkipsWhenSameVersionWithoutForce(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn([
            'id' => 33,
            'prompt_version' => 'v1.2.0',
            'prompt_template' => 'old',
        ]);
        $this->dsConnection->expects($this->never())->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--template-path' => $this->templatePath]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('already at version v1.2.0', $tester->getDisplay());
    }

    public function testFailsOnDowngradeWithoutForce(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn([
            'id' => 33,
            'prompt_version' => 'v2.0.0',
            'prompt_template' => 'old',
        ]);
        $this->dsConnection->expects($this->never())->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--template-path' => $this->templatePath]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('newer than template', $tester->getDisplay());
    }

    public function testUpdatesWhenTemplateIsNewer(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn([
            'id' => 33,
            'prompt_version' => 'v1.0.0',
            'prompt_template' => 'old',
        ]);

        $captured = null;
        $this->dsConnection->expects($this->once())
            ->method('updateWhere')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): void {
                $captured = ['table' => $table, 'data' => $data];
            });

        $tester = $this->tester();
        $exitCode = $tester->execute(['--template-path' => $this->templatePath]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame('core_mail_ai_profiles', $captured['table']);

        $data = $captured['data'];
        $this->assertSame('v1.2.0', $data['prompt_version']);
        $this->assertSame('New prompt body', $data['prompt_template']);
        $this->assertSame('cs', $data['language']);
        $this->assertArrayHasKey('output_schema', $data);
        $this->assertArrayHasKey('supported_doc_types', $data);
        $this->assertArrayHasKey('confidence_thresholds', $data);
        $this->assertArrayHasKey('modified', $data);

        // Admin-controlled pole se NESMÍ přepisovat.
        $this->assertArrayNotHasKey('name', $data);
        $this->assertArrayNotHasKey('is_default', $data);
        $this->assertArrayNotHasKey('is_active', $data);
        $this->assertArrayNotHasKey('backend', $data);
        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('created', $data);

        $this->assertStringContainsString('v1.0.0 → v1.2.0', $tester->getDisplay());
    }

    public function testDryRunDoesNotWrite(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn([
            'id' => 33,
            'prompt_version' => 'v1.0.0',
            'prompt_template' => 'old',
        ]);
        $this->dsConnection->expects($this->never())->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--template-path' => $this->templatePath,
            '--dry-run' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Dry-run', $output);
        $this->assertStringContainsString('v1.0.0 → v1.2.0', $output);
        $this->assertStringContainsString('prompt_template length:', $output);
    }

    public function testForceUpdatesEvenAtSameVersion(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn([
            'id' => 33,
            'prompt_version' => 'v1.2.0',
            'prompt_template' => 'old',
        ]);
        $this->dsConnection->expects($this->once())->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--template-path' => $this->templatePath,
            '--force' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('v1.2.0 → v1.2.0', $tester->getDisplay());
    }

    public function testForceAllowsDowngrade(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn([
            'id' => 33,
            'prompt_version' => 'v2.0.0',
            'prompt_template' => 'old',
        ]);
        $this->dsConnection->expects($this->once())->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--template-path' => $this->templatePath,
            '--force' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    public function testProfileMismatchFailsWithoutDbCall(): void
    {
        $this->dsConnection->expects($this->never())->method('fetchRow');
        $this->dsConnection->expects($this->never())->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--template-path' => $this->templatePath,
            '--profile' => 'english_invoices',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Profile mismatch', $tester->getDisplay());
    }
}
