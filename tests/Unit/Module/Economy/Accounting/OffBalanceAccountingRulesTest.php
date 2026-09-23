<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Accounting\OffBalanceAccountsProvisioner;

/**
 * Konzistence podrozvahy (#79 D2) — programová kontrola nad jsonc, bez DS:
 *
 *   1. oba seed rozvrhy mají skupiny 75–79 (NPO i 97–99) s povahou 6 a
 *      účty 756/756100/799/799100 s povahou 6,
 *   2. OffBalanceAccountsProvisioner nese definice inline — drift proti
 *      seedům (name, short_name, account_kind) hlídá tenhle test.
 */
class OffBalanceAccountingRulesTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';

    private const CHARTS = ['accountChartDefault', 'accountChartNpo'];

    /** @return array<string, array<string, mixed>> number → záznam seedu */
    private function chart(string $chart): array
    {
        $byNumber = [];
        foreach (JsoncParser::parseFile(self::MODULES . "/economy/accounting/config/{$chart}.jsonc") as $entry) {
            $byNumber[(string) $entry['number']] = $entry;
        }
        return $byNumber;
    }

    public function testOffBalanceGroupsHaveOffBalanceKindInBothSeedCharts(): void
    {
        foreach (self::CHARTS as $chart) {
            $byNumber = $this->chart($chart);
            foreach ($byNumber as $number => $entry) {
                $number = (string) $number;
                if (($entry['name'] ?? null) !== 'Podrozvahové účty' && !in_array(substr($number, 0, 2), ['75', '76', '77', '78', '79'], true)) {
                    continue;
                }
                $this->assertSame(
                    OffBalanceAccountsProvisioner::KIND_OFF_BALANCE,
                    (int) ($entry['account_kind'] ?? -1),
                    "{$chart}: podrozvahový účet {$number} musí mít povahu 6",
                );
            }
            foreach (['75', '756', '756100', '79', '799', '799100'] as $number) {
                $this->assertArrayHasKey($number, $byNumber, "{$chart}: účet {$number} chybí");
            }
        }
    }

    public function testProvisionerMatchesBothSeedCharts(): void
    {
        foreach (self::CHARTS as $chart) {
            $byNumber = $this->chart($chart);
            foreach (OffBalanceAccountsProvisioner::ACCOUNTS as $acc) {
                $number = $acc['number'];
                $this->assertArrayHasKey($number, $byNumber, "{$chart}: účet {$number} chybí");
                $this->assertSame($byNumber[$number]['name'], $acc['name'], "{$chart}: name {$number} se rozešel se seedem");
                $this->assertSame($byNumber[$number]['short_name'], $acc['short_name'], "{$chart}: short_name {$number}");
                $this->assertSame((int) $byNumber[$number]['account_kind'], $acc['account_kind'], "{$chart}: account_kind {$number}");
            }
        }
        $this->assertSame(
            ['75', '756', '756100', '79', '799', '799100'],
            array_column(OffBalanceAccountsProvisioner::ACCOUNTS, 'number'),
        );
    }
}
