<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\ExchangeRates;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListsViewer;

/** Viewer Kurzovní lístky (§9): filtr typu, řazení podle platnosti, řádek, detail s hodnotami, bez toolbar akcí. */
class ExchangeRateListsViewerTest extends TestCase
{
    private const TYPES = [
        'cz.cnb.daily' => ['name' => 'ČNB — kurzy devizového trhu', 'homeCurrency' => 'czk', 'maxAgeDays' => 7],
        'cz.cnb.other' => ['name' => 'ČNB — kurzy ostatních měn', 'homeCurrency' => 'czk'],
    ];

    /** @param list<array> $calls */
    private function viewer(array &$calls, ?array $record = null, array $values = [], bool $withConfig = true): ExchangeRateListsViewer
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$args) use (&$calls, $values): array {
            $calls[] = $args;
            return str_contains((string) $args[0], 'economy_exchange_rates_values`') && !str_contains((string) $args[0], 'COUNT(*)') ? $values : [];
        });
        $db->method('fetchRow')->willReturn($record);

        $viewer = new ExchangeRateListsViewer($db, 'economy_exchange_rates_lists');
        if ($withConfig) {
            $config = $this->createMock(ConfigRuntime::class);
            $config->method('cfgItem')->willReturnMap([
                ['world.cz.exchangeRateLists', self::TYPES],
                [ExchangeRateListsViewer::SOURCES_CFG_ITEM, ['1' => ['name' => 'Soubor'], '2' => ['name' => 'Datová sada'], '3' => ['name' => 'Služba']]],
                [ExchangeRateListsViewer::LABELS_CFG_ITEM, ['tabs' => ['values' => ['name' => 'Kurzy']]]],
                ['core.system.viewerDetailLabels', ['tabs' => ['overview' => ['name' => 'Přehled']]]],
            ]);
            $viewer->setConfig($config);
            $dsConfig = $this->createMock(DataSourceConfig::class);
            $dsConfig->method('getCountry')->willReturn('cz');
            $viewer->setDsConfig($dsConfig);
        }
        return $viewer;
    }

    public function testSelectRowsFiltersTypeSearchesNumberAndOrdersByValidFromDesc(): void
    {
        $calls = [];
        $viewer = $this->viewer($calls);

        $viewer->selectRows('20', [['id' => 'list_type', 'value' => 'cz.cnb.other']], 1);

        $sql = $calls[0][0];
        $this->assertStringContainsString('l.`list_type` = %s', $sql);
        $this->assertStringContainsString('l.`list_number`', $sql);
        $this->assertStringContainsString('ORDER BY l.`valid_from` DESC, l.`list_type` ASC, l.`id` DESC', $sql);
        $this->assertStringContainsString('LIMIT 50, 51', $sql);
        $this->assertSame('cz.cnb.other', $calls[0][1]);
        $this->assertSame('20', $calls[0][2]);

        $calls = [];
        $this->viewer($calls)->selectRows(null, [['id' => 'list_type', 'value' => '0']], 0);
        $this->assertStringNotContainsString('l.`list_type` = %s', $calls[0][0], 'Vše = bez podmínky');
    }

    public function testFiltersComeFromListTypes(): void
    {
        $calls = [];
        $filters = $this->viewer($calls)->getFilters();

        $this->assertSame('list_type', $filters[0]['id']);
        $this->assertSame('enum', $filters[0]['type']);
        $this->assertSame(['0', 'cz.cnb.daily', 'cz.cnb.other'], array_column($filters[0]['options'], 'value'));
        $this->assertSame('ČNB — kurzy ostatních měn', $filters[0]['options'][2]['label']);

        $this->assertSame([], $this->viewer($calls, withConfig: false)->getFilters(), 'bez typů bez filtru');
    }

    public function testRenderRowShowsTypeValidityNumberCountAndSource(): void
    {
        $calls = [];
        $row = $this->viewer($calls)->renderRow([
            'id' => 5, 'list_type' => 'cz.cnb.other', 'valid_from' => new \DateTimeImmutable('2026-10-01'), 'valid_to' => '2026-10-31',
            'list_number' => '10', 'source' => 2, 'rate_count' => 3,
        ]);

        $this->assertSame(5, $row['id']);
        $this->assertSame('ČNB — kurzy ostatních měn', $row['t1']);
        $this->assertSame('č. 10', $row['i1']);
        $this->assertSame([['text' => 'od 01.10.2026'], ['text' => 'do 31.10.2026']], $row['t2']);
        $this->assertSame([['text' => '3 měny'], ['text' => 'Datová sada', 'class' => 'muted']], $row['t3']);

        $daily = $this->viewer($calls)->renderRow(['id' => 6, 'list_type' => 'cz.cnb.daily', 'valid_from' => '2026-10-16', 'valid_to' => null, 'list_number' => null, 'source' => 1, 'rate_count' => 31]);
        $this->assertNull($daily['i1']);
        $this->assertSame([['text' => 'od 16.10.2026']], $daily['t2']);
        $this->assertSame('31 měn', $daily['t3'][0]['text']);
    }

    public function testDetailHasOverviewAndValuesWithPerUnitRate(): void
    {
        $calls = [];
        $record = ['id' => 5, 'list_type' => 'cz.cnb.daily', 'home_currency' => 'czk', 'valid_from' => '2026-10-16', 'valid_to' => null, 'list_number' => '201', 'source' => 1];
        $values = [
            ['currency' => 'eur', 'amount' => 1, 'rate' => '24.325000'],
            ['currency' => 'jpy', 'amount' => 100, 'rate' => '15.871000'],
        ];
        $detail = $this->viewer($calls, $record, $values)->renderDetail(5);

        $this->assertSame(['overview', 'values'], array_column($detail['tabs'], 'id'));
        $this->assertSame('Kurzy', $detail['tabs'][1]['label']);
        $items = array_column($detail['tabs'][0]['content']['groups'][0]['items'], 'value', 'label');
        // Typ, platnost a číslo lístku nese hlavička — Přehled je neopakuje (#140 D26).
        $this->assertSame('ČNB — kurzy devizového trhu', $detail['title']);
        $this->assertSame('16.10.2026 · č. 201', $detail['subtitle']);
        $this->assertSame(['Domácí měna', 'Zdroj', 'Počet měn'], array_keys($items));
        $this->assertSame('Soubor', $items['Zdroj']);
        $this->assertSame('2', $items['Počet měn']);
        $this->assertSame(
            [
                ['currency' => 'EUR', 'amount' => '1', 'rate' => '24,325', 'per_unit' => '24,325'],
                ['currency' => 'JPY', 'amount' => '100', 'rate' => '15,871', 'per_unit' => '0,15871'],
            ],
            $detail['tabs'][1]['content']['rows'],
        );
        $this->assertSame(['currency', 'amount', 'rate', 'per_unit'], array_column($detail['tabs'][1]['content']['columns'], 'id'));
        $this->assertStringContainsString('WHERE `list` = %i', $calls[0][0]);
        $this->assertSame(5, $calls[0][1]);

        $this->assertSame(['tabs' => []], $this->viewer($calls)->renderDetail(99));
    }

    public function testNoToolbarActions(): void
    {
        $calls = [];
        $viewer = $this->viewer($calls);
        $this->assertSame([], $viewer->getToolbarActions(null));
        $this->assertSame([], $viewer->getToolbarActions(['id' => 1]));
    }
}
