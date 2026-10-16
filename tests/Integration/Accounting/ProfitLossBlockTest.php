<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accounting;

use Shipard\Api\ReportDefinitionLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Overview\OverviewContext;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Module\Economy\Accounting\Overview\ProfitLossBlock;
use Shipard\Module\Economy\Accounting\Overview\ProfitLossReader;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Výsledek hospodaření v Přehledu firmy (#120 D8) = `ytd` výsledovky za
 * běžný měsíc (fiskální rok obsahující dnešek, pozice měsíce mezi
 * běžnými). Report se spouští přes ReportRunner stejně jako blok —
 * ověřuje se shoda čísla a tvar prokliku.
 */
class ProfitLossBlockTest extends IntegrationTestCase
{
    public function testItemEqualsYtdOfProfitLossReportForCurrentMonth(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $year  = $this->db->fetchRow(
            'SELECT [id], [name] FROM [economy_codebooks_fiscal_years]'
            . ' WHERE [docState] != 90 AND [date_begin] <= %s AND [date_end] >= %s ORDER BY [date_begin] DESC LIMIT 1',
            $today, $today,
        );
        if ($year === null) {
            $this->markTestSkipped('DS nemá fiskální rok pro dnešek');
        }
        $months   = $this->db->fetchAll(
            'SELECT [id], [date_begin], [date_end] FROM [economy_codebooks_fiscal_months]'
            . ' WHERE [fiscal_year] = %i AND [period_type] = 1 ORDER BY [date_begin], [id]',
            (int) $year['id'],
        );
        $position = null;
        foreach ($months as $i => $month) {
            $begin = $month['date_begin'] instanceof \DateTimeInterface ? $month['date_begin']->format('Y-m-d') : (string) $month['date_begin'];
            $end   = $month['date_end'] instanceof \DateTimeInterface ? $month['date_end']->format('Y-m-d') : (string) $month['date_end'];
            if ($begin <= $today && $end >= $today) {
                $position = $i + 1;
                break;
            }
        }
        if ($position === null) {
            $this->markTestSkipped('Dnešek neleží v běžném měsíci fiskálního roku');
        }

        $registry = ReportDefinitionLoader::load($this->dsConfig, new ModulePathResolver([dirname(__DIR__, 3) . '/modules']), 'cs');
        $runner   = new ReportRunner($registry, $this->db, null, $this->dsConfig->getId(), 'cs');
        $ctx      = new OverviewContext($this->db, null, 'cs', new \DateTimeImmutable('today'), $runner, $this->tables, 'cz', 'czk');

        $result = (new ProfitLossBlock())->collect($ctx);

        $expected = ProfitLossReader::read($runner->run(ProfitLossBlock::REPORT_ID, [
            'fiscalYear' => (string) $year['name'], 'monthFrom' => (string) $position, 'monthTo' => (string) $position, 'detail' => 'synthetic',
        ]));
        $this->assertNotNull($expected['result']);

        $items = [];
        foreach ($result->items as $item) {
            $items[$item->id] = $item;
        }
        $this->assertArrayHasKey('result:result', $items);
        $this->assertSame(['value' => $expected['result'], 'currency' => 'CZK'], $items['result:result']->amount);
        $this->assertSame($today, $items['result:result']->asOf);
        $this->assertSame(
            ['kind' => 'open_report', 'target' => ['reportId' => ProfitLossBlock::REPORT_ID, 'params' => [
                'fiscalYear' => (string) $year['name'], 'monthFrom' => $position, 'monthTo' => $position,
            ]]],
            $items['result:result']->link,
        );
        if ($expected['revenues'] !== null) {
            $this->assertSame($expected['revenues'], $items['result:revenues']->amount['value']);
            $this->assertSame($expected['costs'], $items['result:costs']->amount['value']);
        }
    }
}
