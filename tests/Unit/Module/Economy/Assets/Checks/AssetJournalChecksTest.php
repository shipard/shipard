<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets\Checks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Assets\AssetJournalCheck;
use Shipard\Module\Economy\Assets\Checks\AcquisitionMismatchCheck;
use Shipard\Module\Economy\Assets\Checks\JournalMismatchCheck;
use Shipard\Tests\Unit\Module\Economy\Assets\TestAssetJournalCheck;

/**
 * Alerty kontroly evidence × deník (docs/assets.md D67): nesoulad
 * zaúčtování s deníkem (per karta, s rokem a odkazem na report)
 * a nesoulad pořízení se zařazením po lhůtě.
 */
class AssetJournalChecksTest extends TestCase
{
    private TestAssetJournalCheck $check;

    protected function setUp(): void
    {
        $this->check = new TestAssetJournalCheck();
    }

    private function journalMismatch(string $language = 'cs'): JournalMismatchCheck
    {
        return new class(
            $this->createMock(DataSourceConnection::class),
            $this->createMock(ConfigRuntime::class),
            $language,
            $this->check,
        ) extends JournalMismatchCheck {
            public function __construct(DataSourceConnection $db, ConfigRuntime $config, string $language, private readonly AssetJournalCheck $fake)
            {
                parent::__construct($db, $config, $language);
            }

            protected function check(): AssetJournalCheck
            {
                return $this->fake;
            }

            protected function years(): array
            {
                return [
                    ['name' => '2024', 'end' => '2024-12-31', 'months' => 12],
                    ['name' => '2025', 'end' => '2025-12-31', 'months' => 12],
                ];
            }
        };
    }

    private function acquisitionMismatch(string $today, string $language = 'cs'): AcquisitionMismatchCheck
    {
        return new class(
            $this->createMock(DataSourceConnection::class),
            $this->createMock(ConfigRuntime::class),
            $language,
            $this->check,
            $today,
        ) extends AcquisitionMismatchCheck {
            public function __construct(
                DataSourceConnection $db,
                ConfigRuntime $config,
                string $language,
                private readonly AssetJournalCheck $fake,
                private readonly string $fakeToday,
            ) {
                parent::__construct($db, $config, $language);
            }

            protected function check(): AssetJournalCheck
            {
                return $this->fake;
            }

            protected function today(): string
            {
                return $this->fakeToday;
            }
        };
    }

    private function postedCard(int $id, string $date): void
    {
        $this->check->card($id);
        $this->check->event($id, 'activation', $date, 100000);
        $this->check->posting($id, 'asset.activation', '022100', '042100', 100000, substr($date, 0, 4) . '-12-31');
    }

    // ── economy.assets.journal_mismatch ─────────────────────────────────────

    public function testCleanRegisterGivesNoJournalMismatch(): void
    {
        $this->postedCard(1, '2024-03-15');

        $this->assertSame([], $this->journalMismatch()->run());
    }

    public function testJournalMismatchCarriesFirstAffectedYearAndReportAction(): void
    {
        // Karta 1: nesoulad už ke konci 2024; karta 2: až v roce 2025.
        $this->postedCard(1, '2024-03-15');
        $this->check->event(1, 'depreciation', '2024-12-31', 11000);
        $this->postedCard(2, '2024-03-15');
        $this->check->event(2, 'depreciation', '2025-12-31', 22250);
        $this->postedCard(3, '2024-03-15');

        $findings = $this->journalMismatch()->run();

        $this->assertCount(2, $findings);
        [$first, $second] = $findings;
        $this->assertSame('1', $first->findingKey);
        $this->assertSame('error', $first->severity);
        $this->assertSame('Majetek MA0001 Stroj 1: zaúčtování nesouhlasí s deníkem', $first->title);
        $this->assertStringContainsString('082100, 551100 (rok 2024)', $first->message);
        $this->assertSame(450, $first->subjectTableId);
        $this->assertSame(1, $first->subjectRowId);
        $this->assertSame(
            [
                'id'      => 'open_check',
                'label'   => 'Otevřít kontrolu',
                'kind'    => 'open_report',
                'primary' => true,
                'target'  => [
                    'reportId' => 'economy.assets.journalCheck',
                    'params'   => ['fiscalYear' => '2024', 'monthFrom' => 1, 'monthTo' => 12],
                ],
            ],
            $first->actions[0],
        );
        $this->assertSame(['viewerId' => 'economy.assets.assets', 'recordId' => 1], $first->actions[1]['target']);

        $this->assertSame('2', $second->findingKey);
        $this->assertSame('2025', $second->actions[0]['target']['params']['fiscalYear']);
        $this->assertSame('2025', $second->context['fiscal_year']);
    }

    public function testJournalMismatchEnglish(): void
    {
        $this->postedCard(1, '2024-03-15');
        $this->check->event(1, 'depreciation', '2024-12-31', 11000);

        $finding = $this->journalMismatch('en')->run()[0];

        $this->assertSame('Asset MA0001 Stroj 1: posting does not match the journal', $finding->title);
        $this->assertSame('Open the check', $finding->actions[0]['label']);
    }

    // ── economy.assets.acquisition_mismatch ─────────────────────────────────

    public function testAcquisitionMismatchOnlyAfterThirtyDays(): void
    {
        $this->check->card(1, null, 'Fréza');
        $this->check->journal(1, '042100', 269500, 0, '2024-07-20', 'purchase.asset');
        $this->check->event(1, 'activation', '2024-07-23', 268500, false);

        // 30 dní od zařazení (23. 7.) ještě ne, 31. den ano.
        $this->assertSame([], $this->acquisitionMismatch('2024-08-22')->run());

        $findings = $this->acquisitionMismatch('2024-08-23')->run();
        $this->assertCount(1, $findings);
        $finding = $findings[0];
        $this->assertSame('1', $finding->findingKey);
        $this->assertSame('warning', $finding->severity);
        $this->assertSame('Majetek MA0001 Fréza: pořízení nesouhlasí se zařazením', $finding->title);
        $this->assertStringContainsString('269 500,00', $finding->message);
        $this->assertStringContainsString('268 500,00', $finding->message);
        $this->assertStringContainsString('rozdíl 1 000,00 trvá déle než 30 dní', $finding->message);
        $this->assertSame('open_viewer', $finding->actions[0]['kind']);
        $this->assertSame(['viewerId' => 'economy.assets.assets', 'recordId' => 1], $finding->actions[0]['target']);
        $this->assertSame('2024-07-23', $finding->context['since']);
    }

    public function testAcquisitionMismatchDisappearsAfterTheFix(): void
    {
        $this->check->card(1);
        $this->check->journal(1, '042100', 269500, 0, '2024-07-20', 'purchase.asset');
        $this->check->event(1, 'activation', '2024-07-23', 268500, false);
        $this->assertCount(1, $this->acquisitionMismatch('2024-12-01')->run());

        // Doplatek zařazený jako technické zhodnocení rozdíl vyrovná.
        $this->check->event(1, 'improvement', '2024-12-01', 1000, false);

        $this->assertSame([], $this->acquisitionMismatch('2024-12-01')->run());
    }

    public function testAcquisitionWithoutActivationAfterThirtyDays(): void
    {
        $this->check->card(1);
        $this->check->journal(1, '042100', 50000, 0, '2024-05-10', 'purchase.asset');

        $this->assertSame([], $this->acquisitionMismatch('2024-06-09')->run());

        $finding = $this->acquisitionMismatch('2024-06-10')->run()[0];
        $this->assertSame('Majetek MA0001 Stroj 1: pořízení čeká na zařazení déle než 30 dní', $finding->title);
        $this->assertStringContainsString('50 000,00', $finding->message);
    }
}
