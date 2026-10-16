<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Vat;

use Shipard\Api\ReportDefinitionLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Overview\OverviewContext;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Module\Economy\Vat\Deadline\CzFilingDeadlineRule;
use Shipard\Module\Economy\Vat\Overview\VatBlock;
use Shipard\Module\Economy\Vat\Overview\VatPeriodSelector;
use Shipard\Module\Economy\Vat\Overview\VatPositionReader;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Blok DPH nad reálným DS (#120 D16, D17): položky = pozice živého
 * přiznání vybraných období (týž ReportRunner), termín podání z pravidla
 * ČR, proklik do reportu. Na DS bez aktivní registrace prázdný výsledek.
 */
class VatBlockTest extends IntegrationTestCase
{
    public function testItemsMatchLiveReturnForSelectedPeriods(): void
    {
        $today    = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $registry = ReportDefinitionLoader::load($this->dsConfig, new ModulePathResolver([dirname(__DIR__, 3) . '/modules']), 'cs');
        $runner   = new ReportRunner($registry, $this->db, null, $this->dsConfig->getId(), 'cs', country: 'cz');
        $ctx      = new OverviewContext($this->db, null, 'cs', new \DateTimeImmutable('today'), $runner, $this->tables, 'cz', 'czk');

        $selector     = new VatPeriodSelector($this->db, isset($this->tables['economy_vat_filings']));
        $registration = $selector->activeRegistration($today);

        $result = (new VatBlock())->collect($ctx);

        if ($registration === null) {
            $this->assertSame([], $result->items, 'neplátce nemá položky');
            $this->assertSame([], $result->messages);
            return;
        }

        $periods = $selector->periods($registration['id'], $today);
        if ($periods['current'] === null && $periods['previous'] === null) {
            $this->assertSame(['noVatPeriod'], array_map(static fn ($m) => $m->code, $result->messages));
            return;
        }

        $items = [];
        foreach ($result->items as $item) {
            $items[$item->id] = $item;
        }
        $rule = new CzFilingDeadlineRule();
        foreach (['previous', 'current'] as $slot) {
            $period = $periods[$slot];
            if ($period === null) {
                $this->assertArrayNotHasKey('vat:' . $slot, $items);
                continue;
            }
            $expected = VatPositionReader::read($runner->run(VatBlock::REPORT_ID, ['period' => $period['id']]));
            $this->assertArrayHasKey('vat:' . $slot, $items);
            $this->assertSame(['value' => $expected['amount'], 'currency' => 'CZK'], $items['vat:' . $slot]->amount);
            $this->assertSame($rule->returnDeadline($period['dateEnd']), $items['vat:' . $slot]->dueDate);
            $this->assertStringContainsString($period['name'], $items['vat:' . $slot]->label);
            $this->assertSame(
                ['kind' => 'open_report', 'target' => ['reportId' => VatBlock::REPORT_ID, 'params' => ['period' => $period['id']]]],
                $items['vat:' . $slot]->link,
            );
        }
    }
}
