<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Command;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\Command\MailTargetBackfillCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\MessageTargetWriter;
use Shipard\Module\Core\Mail\MessageTitleComposer;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableMailTargetBackfillCommand extends MailTargetBackfillCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        MessageTargetWriter $writer,
        private readonly string $dsDir,
    ) {
        parent::__construct($dsConfig, $dsConnection, $writer);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }
}

/**
 * Backfill partnera a titulku navázaných zpráv (D7): dry-run nezapisuje,
 * doplňuje jen NULL sloupce, keyset stránkuje přes `id`.
 */
class MailTargetBackfillCommandTest extends TestCase
{
    /** @var list<string> */
    private array $fetchAllSql = [];

    /** @var list<array<string, mixed>> */
    private array $updates = [];

    protected function setUp(): void
    {
        $this->fetchAllSql = [];
        $this->updates = [];
    }

    /**
     * @param list<list<array<string, mixed>>> $batches co vrátí po sobě jdoucí
     *        keyset dotazy nad zprávami
     */
    private function makeTester(array $batches): CommandTester
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (mixed ...$args) use (&$batches): array {
                $this->fetchAllSql[] = (string) $args[0];
                return array_shift($batches) ?? [];
            },
        );
        $db->method('fetchRow')->willReturnCallback(
            static fn(mixed ...$args): ?array => in_array('docs_core_heads', array_filter($args, 'is_string'), true)
                ? [
                    'doc_type' => 'invni',
                    'doc_number' => '2019-0123',
                    'total_amount' => '13105.00',
                    'doc_currency' => 'CZK',
                    'partner' => 55,
                    'partner_full_name' => 'Dodavatel s.r.o.',
                ]
                : null,
        );
        $db->method('execute')->willReturnCallback(function (mixed ...$args): void {
            $this->updates[] = ['sql' => (string) $args[0], 'args' => array_slice($args, 1)];
        });

        $command = new TestableMailTargetBackfillCommand(
            $this->createMock(DataSourceConfig::class),
            $db,
            new MessageTargetWriter($db, new MessageTitleComposer(null)),
            sys_get_temp_dir(),
        );
        (new Application())->add($command);

        return new CommandTester($command);
    }

    /** @return array<string, mixed> */
    private function message(int $id, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'target_table_id' => 'docs_core_heads',
            'target_row' => 4711,
            'partner_person' => null,
            'partner_name' => null,
            'ai_title' => null,
        ];
    }

    public function testFillsEmptyColumnsAndCountsCategories(): void
    {
        $tester = $this->makeTester([[
            $this->message(1),
            // cíl mimo docs_core_heads → skipped, ne chyba
            $this->message(2, ['target_table_id' => 'base_registry_documents']),
            // už doplněná zpráva → unchanged
            $this->message(3, [
                'partner_person' => 55,
                'partner_name' => 'Dodavatel s.r.o.',
                'ai_title' => '2019-0123 — Dodavatel s.r.o., 13 105 CZK',
            ]),
        ]]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('scanned 3, updated 1, skipped 1, unchanged 1.', $tester->getDisplay());
        $this->assertCount(1, $this->updates);
        $this->assertSame(1, $this->updates[0]['args'][2]);
    }

    public function testKeepsManuallyChosenPartner(): void
    {
        // P7: ruční volba uživatele a titulek od AI se nepřepisují.
        $tester = $this->makeTester([[
            $this->message(1, ['partner_person' => 99, 'ai_title' => 'Ruční titulek']),
        ]]);

        $tester->execute([]);

        $this->assertCount(1, $this->updates);
        $this->assertSame(['partner_name' => 'Dodavatel s.r.o.'], $this->updates[0]['args'][1]);
    }

    public function testDryRunWritesNothingAndPrintsSample(): void
    {
        $tester = $this->makeTester([[$this->message(1)]]);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        $this->assertSame([], $this->updates);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('[dry-run] scanned 1, updated 1', $display);
        $this->assertStringContainsString('ai_title = 2019-0123 — Dodavatel s.r.o., 13 105 CZK', $display);
    }

    public function testNothingToDoIsSuccess(): void
    {
        $tester = $this->makeTester([[]]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('scanned 0, updated 0, skipped 0, unchanged 0.', $tester->getDisplay());
    }

    public function testKeysetPagingAdvancesByIdWithoutOffset(): void
    {
        $tester = $this->makeTester([[$this->message(10)], [$this->message(11)], []]);

        $tester->execute(['--batch' => '1']);

        $this->assertCount(3, $this->fetchAllSql);
        foreach ($this->fetchAllSql as $sql) {
            $this->assertStringContainsString('id > %i', $sql);
            $this->assertStringNotContainsString('OFFSET', $sql);
        }
        $this->assertStringContainsString('scanned 2, updated 2', $tester->getDisplay());
    }

    public function testLimitStopsEarly(): void
    {
        $tester = $this->makeTester([[$this->message(10)], [$this->message(11)]]);

        $tester->execute(['--limit' => '1', '--batch' => '5']);

        $this->assertCount(1, $this->fetchAllSql);
        $this->assertStringContainsString('scanned 1, updated 1', $tester->getDisplay());
    }

    public function testInvalidOptionsFail(): void
    {
        $tester = $this->makeTester([[]]);
        $this->assertSame(Command::FAILURE, $tester->execute(['--limit' => '-1']));

        $tester2 = $this->makeTester([[]]);
        $this->assertSame(Command::FAILURE, $tester2->execute(['--batch' => '0']));
    }
}
