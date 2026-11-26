<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing\Viewer;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Core\Viewer\Detail\DetailContent;
use Shipard\Module\Economy\PeriodicInvoicing\Viewer\InvoicingDetailTab;
use Shipard\Module\Economy\PeriodicInvoicing\Viewer\InvoicingTexts;
use Shipard\Module\Economy\PeriodicInvoicing\Viewer\PeriodOverview;
use Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing\FakeInvoicingSource;
use Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing\FakePeriodRepository;

/**
 * Společná záložka Fakturace (#175 D8): předpis, příští splatné období
 * a tabulka období s odkazem na doklad — nad libovolným zdrojem, bez akcí.
 */
class InvoicingDetailTabTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../../modules/economy/periodicInvoicing';

    private const RECORD = [
        'id' => 6, 'number' => 'N-1', 'title' => 'Byt 12', 'kind' => 4, 'date_end' => null,
        'inv_periodicity' => 'month', 'inv_from' => '2026-08-01', 'inv_timing' => null, 'inv_doc_type' => null,
    ];

    /** Konfigurace s katalogem a číselníky modulu v daném jazyce. */
    private function config(string $language): ConfigRuntime
    {
        $items = [];
        foreach (JsoncParser::parseFile(self::MODULE . '/module.jsonc')['config'] as $entry) {
            $items[$entry['id']] = ConfigLocalizer::localize(JsoncParser::parseFile(self::MODULE . '/' . $entry['file']), $language);
        }
        $items['docs.core.docTypes'] = ['invno' => ['name' => 'Faktura vydaná'], 'invpo' => ['name' => 'Zálohová faktura vydaná']];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn(string $id): mixed => $items[$id] ?? null);
        return $config;
    }

    private function contracts(): FakeInvoicingSource
    {
        $source = new FakeInvoicingSource(id: 'contracts', table: 'test_contracts_heads', periodsTable: 'test_contracts_periods', recordColumn: 'contract');
        $source->kinds = [4 => ['id' => 4, 'inv_doc_type' => 'invpo', 'inv_number_series' => 1, 'inv_timing' => 'end']];
        return $source;
    }

    private function repository(FakeInvoicingSource $source): FakePeriodRepository
    {
        $repo = new FakePeriodRepository($this->createMock(Connection::class), $source->periodsTable(), $source->periodsRecordColumn());
        $repo->periods[6] = [
            '2026-10-01' => ['id' => 3, 'contract' => 6, 'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'state' => 'issued', 'doc' => 605, 'result' => null, 'message' => null],
            '2026-09-01' => ['id' => 2, 'contract' => 6, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'state' => 'issued', 'doc' => 604, 'result' => null, 'message' => null],
            '2026-08-01' => ['id' => 1, 'contract' => 6, 'period_from' => '2026-08-01', 'period_to' => '2026-08-31', 'state' => 'planned', 'doc' => null, 'result' => 'failed', 'message' => 'Chybí řada'],
        ];
        $repo->docs = [
            605 => ['id' => 605, 'docState' => 10, 'doc_type' => 'invpo', 'doc_number' => '!0000000605', 'total_amount' => '17545.00', 'doc_currency' => 'czk'],
            604 => ['id' => 604, 'docState' => 90, 'doc_type' => 'invno', 'doc_number' => 'FV260004', 'total_amount' => null, 'doc_currency' => 'czk'],
        ];
        return $repo;
    }

    public function testTabShowsPrescriptionAndPeriodsOfTheSourceInTheRequestLanguage(): void
    {
        $source = $this->contracts();
        $tab = (new InvoicingDetailTab($source, $this->repository($source), $this->config('cs'), 'cs', '2026-10-08'))->build(self::RECORD);

        $this->assertSame(['invoicing', 'Fakturace'], [$tab['id'], $tab['label']]);
        $this->assertSame('composite', $tab['content']['type']);
        [$properties, $heading, $table] = $tab['content']['blocks'];

        // Předpis: hodnoty záznamu → druh ze zdroje → výchozí; fakturace na
        // konci období → příští splatné je říjen (vystaví se 31. 10.).
        $this->assertSame('Fakturace', $properties['groups'][0]['title']);
        $this->assertSame([
            ['label' => 'Příští splatné období', 'value' => '01.10.2026 – 31.10.2026'],
            ['label' => 'Periodicita', 'value' => 'Měsíčně'],
            ['label' => 'Fakturovat od', 'value' => '01.08.2026'],
            ['label' => 'Fakturace', 'value' => 'Na konci období'],
            ['label' => 'Typ dokladu', 'value' => 'Zálohová faktura vydaná'],
        ], $properties['groups'][0]['items']);
        $this->assertSame(['type' => 'heading', 'text' => 'Období'], $heading);

        $this->assertSame(
            [['period', 'Období'], ['state', 'Stav'], ['document', 'Doklad'], ['amount', 'Částka'], ['message', 'Zpráva']],
            array_map(static fn(array $c): array => [$c['id'], $c['label']], $table['columns']),
        );
        [$october, $september, $august] = $table['rows'];
        $this->assertSame(['01.10.2026 – 31.10.2026', 'Vystaveno', 'koncept #605', '17 545,00 CZK'], [$october['period'], $october['state'], $october['document'], $october['amount']]);
        $this->assertSame(['kind' => 'open_detail', 'target' => ['viewerId' => 'docs.proformasOut.heads', 'recordId' => 605]], ['kind' => $october['_action']['kind'], 'target' => $october['_action']['target']]);
        // Doklad v koši = zastaveno; částka bez hodnoty prázdná.
        $this->assertSame(['Zastaveno', 'FV260004', '', 'docs.invoicesOut.heads'], [$september['state'], $september['document'], $september['amount'], $september['_action']['target']['viewerId']]);
        $this->assertSame(['Naplánováno', '', 'Chybí řada'], [$august['state'], $august['document'], $august['message']]);
        $this->assertArrayNotHasKey('_action', $august);

        // Obsah záložky jen ze sdílených typů bloků detailu, žádné akce.
        foreach ($tab['content']['blocks'] as $block) {
            $this->assertContains($block['type'], DetailContent::SHARED_TYPES);
        }
        $this->assertArrayNotHasKey('actions', $tab);
    }

    public function testWithoutCatalogAndPeriodsTabFallsBackToEnglish(): void
    {
        $source = $this->contracts();
        $repo = new FakePeriodRepository($this->createMock(Connection::class), 'test_contracts_periods', 'contract');
        $tab = (new InvoicingDetailTab($source, $repo, null, 'en', '2026-10-08'))->build(['inv_from' => null, 'inv_periodicity' => null] + self::RECORD);

        $this->assertSame('Invoicing', $tab['label']);
        // Bez „fakturovat od“ není příští období; číselníky bez konfigurace klíčem.
        $this->assertSame([['label' => 'Invoicing', 'value' => 'end'], ['label' => 'Document type', 'value' => 'invpo']], $tab['content']['blocks'][0]['groups'][0]['items']);
        $this->assertSame(['type' => 'heading', 'text' => 'No period has been issued yet.'], $tab['content']['blocks'][2]);

        // Po datu ukončení už žádné splatné období není.
        $ended = (new InvoicingDetailTab($source, $repo, null, 'en', '2026-10-08'))->build(['date_end' => '2026-08-31'] + self::RECORD);
        $this->assertSame(['label' => 'Next period due', 'value' => 'none — past the end date'], $ended['content']['blocks'][0]['groups'][0]['items'][0]);
    }

    public function testPeriodOverviewFeedsTheActionChoices(): void
    {
        $source = $this->contracts();
        $overview = PeriodOverview::load($this->repository($source), 6, new InvoicingTexts(null), 'en');

        $this->assertSame(['issued', 'stopped', 'planned'], array_column($overview->entries, 'state'));
        $this->assertSame([['value' => '3', 'label' => '2026-10-01 – 2026-10-31 — draft #605']], $overview->regenerable());
        $this->assertSame([['value' => '2', 'label' => '2026-09-01 – 2026-09-30']], $overview->stopped());
    }

    public function testCatalogFallbacksMatchTheShippedEnglishNames(): void
    {
        // Fallback v PHP = holé `name` katalogu znak po znaku; každý klíč čtený
        // ve zdrojích modulu v katalogu existuje.
        $catalog = JsoncParser::parseFile(self::MODULE . '/config/viewerLabels.jsonc');
        $used = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::MODULE . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            preg_match_all("/->text\\('([a-zA-Z.]+)', '((?:[^'\\\\]|\\\\.)*)'/u", (string) file_get_contents($file->getPathname()), $matches, PREG_SET_ORDER);
            foreach ($matches as [, $key, $fallback]) {
                $used[$key] = stripslashes($fallback);
            }
        }
        $this->assertGreaterThan(20, count($used));
        foreach ($used as $key => $fallback) {
            $this->assertArrayHasKey($key, $catalog, $key);
            $this->assertSame($catalog[$key]['name'], $fallback, $key);
            $this->assertSame($catalog[$key]['name'], $catalog[$key]['name:en'], $key);
            $this->assertNotSame('', (string) ($catalog[$key]['name:cs'] ?? ''), $key);
        }
        $this->assertSame([], array_values(array_diff(array_keys($catalog), array_keys($used))), 'katalog bez nepoužitých klíčů');
    }
}
