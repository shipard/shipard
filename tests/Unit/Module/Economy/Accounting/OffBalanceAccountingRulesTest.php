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
 *      seedům (name, short_name, account_kind) hlídá tenhle test,
 *   3. předpis invpo: kategorie proformas.out / offbalance.contra s maskami
 *      756 / 799, blok jen se dvěma hlavičkovými kroky celkové částky
 *      (MD 756, DAL 799), bez řádků, DPH i partnerSrc — a každá maska
 *      má účet v obou seedech i v provisioneru.
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

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return JsoncParser::parseFile(self::MODULES . '/economy/accounting/config/accountingRules.cz.jsonc');
    }

    public function testProformaRuleBooksTotalOnOffBalanceOnly(): void
    {
        $rules = $this->rules();
        $this->assertArrayHasKey('proformas.out', $rules['categories']);
        $this->assertArrayHasKey('offbalance.contra', $rules['categories']);

        $masks = [];
        foreach ($rules['accounts'] as $entry) {
            if (in_array($entry['cat'] ?? null, ['proformas.out', 'offbalance.contra'], true)) {
                $this->assertArrayNotHasKey('query', $entry, 'podrozvahové masky jsou bez podmínky');
                $masks[$entry['cat']][] = (string) $entry['accountMask'];
            }
        }
        $this->assertSame(['proformas.out' => ['756'], 'offbalance.contra' => ['799']], $masks);

        $steps = null;
        foreach ($rules['documents'] as $doc) {
            if (($doc['docType'] ?? null) === 'invpo') {
                $steps = array_values($doc['accounting']);
            }
        }
        $this->assertNotNull($steps, 'předpis nemá blok invpo');
        $this->assertCount(2, $steps, 'jen dva hlavičkové kroky');
        $this->assertSame(
            [['proformas.out', 'head', 'total', 0], ['offbalance.contra', 'head', 'total', 1]],
            array_map(fn(array $s) => [$s['cat'], $s['src'], $s['col'], $s['side']], $steps),
            'MD 756 / DAL 799 celkovou částkou hlavičky',
        );
        foreach ($steps as $i => $step) {
            foreach (['partnerSrc', 'accountSrc', 'operation', 'operations', 'query', 'headQuery', 'sign', 'reverseSign'] as $key) {
                $this->assertArrayNotHasKey($key, $step, "invpo krok #{$i}: {$key} nemá co dělat na podrozvaze");
            }
        }

        // Každá podrozvahová maska míří na účet, který je v obou seedech i v provisioneru.
        $provisioned = array_column(OffBalanceAccountsProvisioner::ACCOUNTS, 'number');
        foreach (array_merge(...array_values($masks)) as $mask) {
            $this->assertContains($mask, $provisioned, "maska {$mask} nemá bezpodmínečný provisioner");
            foreach (self::CHARTS as $chart) {
                $this->assertArrayHasKey($mask, $this->chart($chart), "{$chart} nemá účet {$mask}");
                $this->assertArrayHasKey($mask . '100', $this->chart($chart), "{$chart} nemá analytiku {$mask}100");
            }
        }
    }
}
