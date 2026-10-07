<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Accounting\OffBalanceAccountsProvisioner;

/**
 * Konzistence podrozvahy (#79 D2, #106 D2) — programová kontrola nad jsonc,
 * bez DS:
 *
 *   1. oba seed rozvrhy mají skupiny 75–79 (NPO i 97–99) s povahou 6 a
 *      účty 756/756100/757/757100/799/799100 s povahou 6,
 *   2. OffBalanceAccountsProvisioner nese definice inline — drift proti
 *      seedům (name, short_name, account_kind) hlídá tenhle test,
 *   3. předpisy invpo / invpi: kategorie proformas.out / proformas.in /
 *      offbalance.contra s maskami 756 / 757 / 799, blok jen se dvěma
 *      hlavičkovými kroky celkové částky (invpo MD 756 / DAL 799, invpi
 *      MD 799 / DAL 757 — předpis výzvy na DAL jako závazek), bez řádků,
 *      DPH i partnerSrc — a každá maska má účet v obou seedech
 *      i v provisioneru.
 */
class OffBalanceAccountingRulesTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';

    private const CHARTS = ['accountChartDefault', 'accountChartNpo'];

    private const ACCOUNTS = ['75', '756', '756100', '757', '757100', '79', '799', '799100'];

    private const CATEGORIES = ['proformas.out', 'proformas.in', 'offbalance.contra'];

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
            foreach (self::ACCOUNTS as $number) {
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
        $this->assertSame(self::ACCOUNTS, array_column(OffBalanceAccountsProvisioner::ACCOUNTS, 'number'));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return JsoncParser::parseFile(self::MODULES . '/economy/accounting/config/accountingRules.cz.jsonc');
    }

    public function testOffBalanceCategoriesHaveUnconditionalProvisionedMasks(): void
    {
        $rules = $this->rules();
        foreach (self::CATEGORIES as $category) {
            $this->assertArrayHasKey($category, $rules['categories']);
        }

        $masks = [];
        foreach ($rules['accounts'] as $entry) {
            if (in_array($entry['cat'] ?? null, self::CATEGORIES, true)) {
                $this->assertArrayNotHasKey('query', $entry, 'podrozvahové masky jsou bez podmínky');
                $masks[$entry['cat']][] = (string) $entry['accountMask'];
            }
        }
        $this->assertSame(
            ['proformas.out' => ['756'], 'proformas.in' => ['757'], 'offbalance.contra' => ['799']],
            $masks,
        );

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

    public function testIssuedProformaRuleBooksTotalOnOffBalanceOnly(): void
    {
        $this->assertOffBalanceRule('invpo', [
            ['proformas.out', 'head', 'total', 0],
            ['offbalance.contra', 'head', 'total', 1],
        ], 'MD 756 / DAL 799 celkovou částkou hlavičky');
    }

    /** #106 D2: zrcadlo invpo — předpis výzvy na DAL (závazek), aby byla skupina pro výdej přirozená. */
    public function testReceivedProformaRuleBooksTotalOnOffBalanceOnly(): void
    {
        $this->assertOffBalanceRule('invpi', [
            ['offbalance.contra', 'head', 'total', 0],
            ['proformas.in', 'head', 'total', 1],
        ], 'MD 799 / DAL 757 celkovou částkou hlavičky');
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: int}> $expected [cat, src, col, side] */
    private function assertOffBalanceRule(string $docType, array $expected, string $message): void
    {
        $steps = null;
        foreach ($this->rules()['documents'] as $doc) {
            if (($doc['docType'] ?? null) === $docType) {
                $steps = array_values($doc['accounting']);
            }
        }
        $this->assertNotNull($steps, "předpis nemá blok {$docType}");
        $this->assertCount(2, $steps, 'jen dva hlavičkové kroky');
        $this->assertSame(
            $expected,
            array_map(fn(array $s) => [$s['cat'], $s['src'], $s['col'], $s['side']], $steps),
            $message,
        );
        foreach ($steps as $i => $step) {
            foreach (['partnerSrc', 'accountSrc', 'operation', 'operations', 'query', 'headQuery', 'sign', 'reverseSign'] as $key) {
                $this->assertArrayNotHasKey($key, $step, "{$docType} krok #{$i}: {$key} nemá co dělat na podrozvaze");
            }
            $this->assertNotEmpty($step['text'] ?? '', "{$docType} krok #{$i}: text řádku deníku");
        }
    }
}
