<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Analysis;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\Analysis\AnalysisStalledAlertCheck;

/**
 * „Analýza pošty stojí“ (tasks/ai-analyzer-removal.md D23): mlčí bez
 * použitelného backendu i při prázdné nebo čerstvé frontě, hlásí zprávy
 * ve frontě déle než 15 minut; cutoff se počítá z `now()`.
 */
class AnalysisStalledAlertCheckTest extends TestCase
{
    public const NOW = '2026-10-10 10:00:00';

    /** @var list<array<int, mixed>> argumenty dotazu na zaseklé zprávy */
    private array $stalledQueries = [];

    /**
     * @param array<string, mixed>|null $backendRow řádek sondy backendu (null = žádný profil/backend)
     * @param array<string, mixed>      $stalledRow výsledek dotazu na zprávy ve frontě déle než 15 min
     */
    private function makeCheck(?array $backendRow, array $stalledRow, string $language = 'cs'): AnalysisStalledAlertCheck
    {
        $this->stalledQueries = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, ...$args) use ($backendRow, $stalledRow) {
                if (str_contains($sql, 'b.api_key')) {
                    return $backendRow;
                }
                $this->stalledQueries[] = [$sql, ...$args];
                return $stalledRow;
            },
        );

        return new class ($db, $this->createMock(ConfigRuntime::class), $language) extends AnalysisStalledAlertCheck {
            protected function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(AnalysisStalledAlertCheckTest::NOW);
            }
        };
    }

    public function testEmptyQueueYieldsNoFindings(): void
    {
        $check = $this->makeCheck(['api_key' => 'v1:cipher'], ['cnt' => 0, 'oldest' => null]);

        $this->assertSame([], $check->run());
        $this->assertCount(1, $this->stalledQueries, 'fronta se s použitelným backendem kontroluje');
    }

    public function testFreshMessagesAreNotStalledBecauseOfTheCutoff(): void
    {
        // Čerstvá zpráva = dotaz vrátí 0; test hlídá, že cutoff je now − 15 min
        // a predikát fronty (stav 10) je v dotazu.
        $check = $this->makeCheck(['api_key' => 'v1:cipher'], ['cnt' => 0, 'oldest' => null]);

        $this->assertSame([], $check->run());
        $sql = (string) $this->stalledQueries[0][0];
        $args = array_slice($this->stalledQueries[0], 1);
        $this->assertStringContainsString('AND m.modified <= %s', $sql);
        $this->assertStringContainsString('m.analysis_state = %i', $sql);
        $this->assertSame('2026-10-10 09:45:00', end($args), 'cutoff = now − 15 minut');
        $this->assertContains(self::NOW, $args, 'živý claim se měří k now()');
    }

    public function testOldMessagesYieldFinding(): void
    {
        $check = $this->makeCheck(['api_key' => 'v1:cipher'], ['cnt' => 3, 'oldest' => '2026-10-10 09:12:00']);

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame('queue_stalled', $findings[0]->findingKey);
        $this->assertSame('warning', $findings[0]->severity);
        $this->assertSame('Analýza pošty stojí', $findings[0]->title);
        $this->assertStringContainsString('3 zpráv', $findings[0]->message);
        $this->assertStringContainsString('2026-10-10 09:12:00', $findings[0]->message);
        $this->assertStringContainsString('mail-analyze --sweep', $findings[0]->message);
        $this->assertStringContainsString('ai.analysis.maxConcurrent', $findings[0]->message);
        $this->assertSame(['count' => 3, 'oldest' => '2026-10-10 09:12:00'], $findings[0]->context);
    }

    public function testEnglishFinding(): void
    {
        $check = $this->makeCheck(['api_key' => 'v1:cipher'], ['cnt' => 1, 'oldest' => '2026-10-10 09:00:00'], 'en');

        $findings = $check->run();

        $this->assertSame('Mail analysis is stalled', $findings[0]->title);
        $this->assertStringContainsString('1 messages have been waiting', $findings[0]->message);
    }

    public function testOldMessagesWithoutUsableBackendAreSilent(): void
    {
        // Backend bez klíče = nedokončené nastavení, ne porucha runneru;
        // dotaz na frontu se ani nepoloží.
        foreach ([null, ['api_key' => null], ['api_key' => '']] as $backendRow) {
            $check = $this->makeCheck($backendRow, ['cnt' => 5, 'oldest' => '2026-10-10 08:00:00']);

            $this->assertSame([], $check->run());
            $this->assertSame([], $this->stalledQueries);
        }
    }
}
