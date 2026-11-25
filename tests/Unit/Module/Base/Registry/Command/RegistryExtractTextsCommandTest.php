<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Registry\Command;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Registry\Command\RegistryExtractTextsCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Registry\ExtractedTextFiller;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableRegistryExtractTextsCommand extends RegistryExtractTextsCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        ExtractedTextFiller $filler,
        private readonly string $dsDir,
    ) {
        parent::__construct($dsConfig, $dsConnection, $filler);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }
}

class RegistryExtractTextsCommandTest extends TestCase
{
    private string $capturedSql = '';

    /** @param array<int, array<string, mixed>> $docRows */
    private function makeTester(array $docRows, ExtractedTextFiller $filler): CommandTester
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$params) use ($docRows) {
                $this->capturedSql = $sql;
                return $docRows;
            },
        );

        $command = new TestableRegistryExtractTextsCommand(
            $this->createMock(DataSourceConfig::class),
            $db,
            $filler,
            sys_get_temp_dir(),
        );
        $app = new Application();
        $app->add($command);

        return new CommandTester($command);
    }

    public function testDefaultFillsMissingOnly(): void
    {
        $filler = $this->createMock(ExtractedTextFiller::class);
        $filler->expects($this->exactly(2))->method('fill')
            ->willReturnOnConsecutiveCalls(
                ['chars' => 100, 'attachments' => 1],
                ['chars' => 0, 'attachments' => 0],
            );

        $tester = $this->makeTester([['id' => 1], ['id' => 2]], $filler);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString("extracted_text` IS NULL", $this->capturedSql);
        $this->assertStringContainsString('Processed 2 document(s), filled 1.', $tester->getDisplay());
    }

    public function testAllRegeneratesWithoutMissingFilter(): void
    {
        $filler = $this->createMock(ExtractedTextFiller::class);
        $filler->expects($this->once())->method('fill')->with(7)
            ->willReturn(['chars' => 50, 'attachments' => 1]);

        $tester = $this->makeTester([['id' => 7]], $filler);
        $exitCode = $tester->execute(['--all' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringNotContainsString('IS NULL', $this->capturedSql);
    }

    public function testLimitAppendsToQuery(): void
    {
        $filler = $this->createMock(ExtractedTextFiller::class);
        $tester = $this->makeTester([], $filler);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--limit' => '10']));
        $this->assertStringContainsString('LIMIT 10', $this->capturedSql);
    }

    public function testNegativeLimitFails(): void
    {
        $tester = $this->makeTester([], $this->createMock(ExtractedTextFiller::class));

        $this->assertSame(Command::FAILURE, $tester->execute(['--limit' => '-1']));
        $this->assertStringContainsString('--limit', $tester->getDisplay());
    }
}
