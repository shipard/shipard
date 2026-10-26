<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Bank;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Bank\BankStatementsViewer;

/**
 * Detail výpisu (#140 D26, §2): číslo, účet a období nese hlavička,
 * Rekonciliace je v Přehledu jen bez štítku (Nezkontrolováno) — Souhlasí
 * a Nesouhlasí jsou štítky hlavičky.
 */
class BankStatementsViewerTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function detail(array $overrides = []): array
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);
        $db->method('fetchRow')->willReturn(array_merge([
            'id'                   => 3,
            'statement_number'     => '2026/07',
            'period_start'         => '2026-07-01',
            'period_end'           => '2026-07-31',
            'currency'             => 'czk',
            'opening_balance'      => '1000.00',
            'closing_balance'      => '1500.00',
            'reconciliation_state' => 0,
            'docState'             => 40,
            'account_code'         => 'FIO',
            'account_name'         => 'Fio běžný',
        ], $overrides));

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['economy.bank.reconciliationStates', [
                '0' => ['name' => 'Nezkontrolováno'],
                '1' => ['name' => 'Souhlasí'],
                '2' => ['name' => 'Nesouhlasí'],
            ]],
            ['core.system.docStatesArchive', [
                '40' => ['stateName' => 'V pořádku', 'stateStyle' => 'done', 'mainState' => 3, 'viewGroup' => 'active', 'detailBadge' => false, 'goto' => []],
            ]],
        ]);

        $viewer = new BankStatementsViewer($db, 'economy_bank_statements');
        $viewer->setConfig($config);
        $viewer->setLanguage('cs');
        return $viewer->renderDetail(3);
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, string>|null položky skupiny Přehledu podle popisku; null = skupina není
     */
    private static function group(array $detail, string $title): ?array
    {
        $groups = array_column($detail['tabs'][0]['content']['blocks'][0]['groups'], 'items', 'title');
        return isset($groups[$title]) ? array_column($groups[$title], 'value', 'label') : null;
    }

    public function testOverviewOmitsHeaderValuesAndShowsReconciliationOnlyWithoutBadge(): void
    {
        $detail = $this->detail();

        $this->assertSame('2026/07', $detail['title']);
        $this->assertSame('FIO — Fio běžný · 1. 7. 2026 – 31. 7. 2026', $detail['subtitle']);
        $this->assertSame([], $detail['badges'], 'Nezkontrolováno štítek nemá');
        $this->assertSame(['Rekonciliace' => 'Nezkontrolováno'], self::group($detail, 'Výpis'));
        $this->assertSame('1 000,00 CZK', self::group($detail, 'Zůstatky')['Počáteční zůstatek']);

        $detail = $this->detail(['reconciliation_state' => 1]);
        $this->assertSame([['label' => 'Souhlasí', 'style' => 'success']], $detail['badges']);
        $this->assertNull(self::group($detail, 'Výpis'), 'skupina Výpis bez položek zaniká');

        $detail = $this->detail(['reconciliation_state' => 2]);
        $this->assertSame([['label' => 'Nesouhlasí', 'style' => 'error']], $detail['badges']);
        $this->assertNull(self::group($detail, 'Výpis'));
    }

    public function testStatementWithoutNumberHasPeriodAsTitle(): void
    {
        $detail = $this->detail(['statement_number' => null]);

        $this->assertSame('1. 7. 2026 – 31. 7. 2026', $detail['title']);
        $this->assertSame('FIO — Fio běžný', $detail['subtitle']);
        $this->assertSame(['Rekonciliace' => 'Nezkontrolováno'], self::group($detail, 'Výpis'));
    }
}
