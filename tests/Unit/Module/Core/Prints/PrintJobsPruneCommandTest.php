<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Prints\Command\PrintJobsPruneCommand;
use Shipard\Tests\Fixtures\Module\Prints\InMemoryPrintJobsDb;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `print-jobs-prune` (#177 U39): úklid úloh starších než 30 dní, tichý běh
 * bez práce.
 */
final class PrintJobsPruneCommandTest extends TestCase
{
    private InMemoryPrintJobsDb $db;

    protected function setUp(): void
    {
        $this->db = InMemoryPrintJobsDb::create();
        foreach (['-40 days' => 'old', '-31 days' => 'older', '-29 days' => 'recent', '-1 hour' => 'new'] as $age => $jobId) {
            $this->db->seed(InMemoryPrintJobsDb::JOBS, [
                'job_id' => $jobId, 'created_at' => new \DateTimeImmutable($age),
            ]);
        }
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new PrintJobsPruneCommand(dsConnection: $this->db));
    }

    /** @return list<string> */
    private function jobs(): array
    {
        return array_column($this->db->rows(InMemoryPrintJobsDb::JOBS), 'job_id');
    }

    public function testRemovesJobsOlderThanThirtyDaysAndStaysQuietOtherwise(): void
    {
        $tester = $this->tester();
        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('Removed 2 print jobs older than 30 days.', $tester->getDisplay());
        $this->assertSame(['recent', 'new'], $this->jobs());

        // Není co mazat — denní cron log nezaplňuje.
        $tester = $this->tester();
        $this->assertSame(0, $tester->execute([]));
        $this->assertSame('', $tester->getDisplay());

        $tester = $this->tester();
        $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        $this->assertStringContainsString('Removed 0 print jobs older than 30 days.', $tester->getDisplay());
    }

    public function testDaysOption(): void
    {
        $tester = $this->tester();
        $this->assertSame(0, $tester->execute(['--days' => '7']));
        $this->assertSame(['new'], $this->jobs());
    }

    public function testInvalidDaysChangeNothing(): void
    {
        foreach (['0', '-1', 'abc', '1.5', ''] as $days) {
            $tester = $this->tester();
            $this->assertSame(1, $tester->execute(['--days' => $days]), "--days={$days}");
            $this->assertStringContainsString('--days must be a positive integer', $tester->getDisplay());
        }
        $this->assertCount(4, $this->jobs());
    }

    public function testRefusesADirectoryThatIsNotADataSource(): void
    {
        $command = new class extends PrintJobsPruneCommand {
            protected function getDataSourceDir(): string
            {
                return sys_get_temp_dir() . '/shpd-not-a-data-source';
            }
        };
        $tester = new CommandTester($command);

        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('Not a Shipard data source directory', $tester->getDisplay());
    }
}
