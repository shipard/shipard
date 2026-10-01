<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Reports\Export;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Reports\Export\ReportExportContext;
use Shipard\Core\Reports\Export\ReportExportLabels;
use Shipard\Core\Reports\Export\ReportPeriodFormatter;
use Shipard\Core\Reports\Export\ReportTabularizer;
use Shipard\Core\Reports\ReportColumn;
use Shipard\Core\Reports\ReportMessage;
use Shipard\Core\Reports\ReportMessageSeverity;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Reports\ReportRowKind;
use Shipard\Core\Reports\ReportStatus;
use Shipard\Core\Utils\JsoncParser;

class ReportTabularizerTest extends TestCase
{
    // ── Sloupce ─────────────────────────────────────────────────────────────

    public function testHeadersSplitSidesColumnIntoThree(): void
    {
        $table = (new ReportTabularizer())->tabularize(ReportExportFixtures::result(), ReportExportFixtures::context());

        $this->assertSame(
            ['Účet', 'Název', 'Počáteční stav', 'Obraty — MD', 'Obraty — D', 'Obraty — Zůstatek', 'Datum', 'Poznámka'],
            $table->headers,
        );
        $this->assertSame(1, $table->labelColumn);
    }

    public function testAccountColumnOmittedWhenNoRowHasAccount(): void
    {
        $result = new ReportResult(
            reportId: 'test.vat',
            params: [],
            dataSource: 'abcd-efgh-ijkl-mnop',
            messages: [],
            columns: [new ReportColumn('base', ReportColumn::TYPE_MONEY, 'Základ')],
            rows: [new ReportRow(ReportRowKind::Detail, 1, null, 'Řádek 1', [
                'base' => ['md' => 0.0, 'd' => 0.0, 'balance' => 10.0],
            ])],
        );

        $table = (new ReportTabularizer())->tabularize($result, ReportExportFixtures::context());

        $this->assertSame(['Název', 'Základ'], $table->headers);
        $this->assertSame(0, $table->labelColumn);
        $this->assertSame(['Řádek 1', 10.0], $table->rows[0]->cells);
    }

    // ── Řádky a buňky ───────────────────────────────────────────────────────

    public function testCellsCarryTypedValues(): void
    {
        $table = (new ReportTabularizer())->tabularize(ReportExportFixtures::result(), ReportExportFixtures::context());

        $first = $table->rows[0]->cells;
        $this->assertSame('501001', $first[0]);
        $this->assertSame('Spotřeba materiálu', $first[1]);
        // balance sloupec = jen zůstatek; nula zůstává číslem (ne prázdná buňka).
        $this->assertSame(0.0, $first[2]);
        // sides sloupec = MD, D, zůstatek — vždy přesně.
        $this->assertSame([1234567.5, 10.0, 1234557.5], array_slice($first, 3, 3));
        $this->assertInstanceOf(\DateTimeImmutable::class, $first[6]);
        $this->assertSame('2026-05-31', $first[6]->format('Y-m-d'));
        $this->assertSame('první nákup', $first[7]);
    }

    public function testMissingValuesBecomeEmptyCells(): void
    {
        $table = (new ReportTabularizer())->tabularize(ReportExportFixtures::result(), ReportExportFixtures::context());

        // Řádek bez `opening`, data ani poznámky.
        $this->assertSame(['504???', '504???', null, 1.0, 0.0, 1.0, null, null], $table->rows[1]->cells);
        // Součtový řádek bez účtu — prázdná buňka ve sloupci Účet.
        $this->assertNull($table->rows[4]->cells[0]);
    }

    public function testRowsKeepOrderKindAndLevel(): void
    {
        $table = (new ReportTabularizer())->tabularize(ReportExportFixtures::result(), ReportExportFixtures::context());

        $this->assertSame(
            [
                [ReportRowKind::Detail, 4, false],
                [ReportRowKind::Detail, 4, false],
                [ReportRowKind::Subtotal, 3, true],
                [ReportRowKind::Computed, 1, true],
                [ReportRowKind::Total, 0, true],
            ],
            array_map(static fn ($row): array => [$row->kind, $row->level, $row->isEmphasized()], $table->rows),
        );
    }

    public function testInvalidDateStaysText(): void
    {
        $result = new ReportResult(
            reportId: 'test.dates',
            params: [],
            dataSource: 'abcd-efgh-ijkl-mnop',
            messages: [],
            columns: [new ReportColumn('date', ReportColumn::TYPE_DATE, 'Datum')],
            rows: [
                new ReportRow(ReportRowKind::Detail, 1, null, 'a', ['date' => '2026-02-31']),
                new ReportRow(ReportRowKind::Detail, 1, null, 'b', ['date' => 'neznámé']),
                new ReportRow(ReportRowKind::Detail, 1, null, 'c', ['date' => '']),
            ],
        );

        $table = (new ReportTabularizer())->tabularize($result, ReportExportFixtures::context());

        $this->assertSame('2026-02-31', $table->rows[0]->cells[1]);
        $this->assertSame('neznámé', $table->rows[1]->cells[1]);
        $this->assertNull($table->rows[2]->cells[1]);
    }

    // ── Úvodní blok ─────────────────────────────────────────────────────────

    public function testIntroCarriesPeriodParamsAndDataSourceName(): void
    {
        $table = (new ReportTabularizer())->tabularize(ReportExportFixtures::result(), ReportExportFixtures::context());

        $this->assertSame('Hlavní kniha', $table->title);
        $this->assertSame(
            [
                ['Období', '2026 / 5'],
                ['Úroveň detailu', 'Analyticky'],
                ['Generated', '1. 10. 2026 10:00'],
                // Název firmy, nikdy ID zdroje dat.
                ['Data source', 'Ukázková firma s.r.o.'],
                ['Note', 'Amounts are exported in full precision (never in thousands).'],
            ],
            $table->intro,
        );
        $this->assertSame(ReportStatus::Ok, $table->status);
    }

    // ── Zprávy ──────────────────────────────────────────────────────────────

    public function testMessagesResolveRowRefAndAddStatusLine(): void
    {
        $result = ReportExportFixtures::result([
            ReportExportFixtures::errorMessage(),
            new ReportMessage(ReportMessageSeverity::Warning, 'test.noRow', 'Bez vazby na řádek'),
            new ReportMessage(ReportMessageSeverity::Info, 'test.outOfRange', 'Řádek neexistuje', 'rows.99'),
        ]);

        $table = (new ReportTabularizer())->tabularize($result, ReportExportFixtures::context());

        $this->assertSame(ReportStatus::Errors, $table->status);
        $this->assertSame(
            ['Status', 'The report contains errors — see the Messages sheet.'],
            $table->intro[array_key_last($table->intro)],
        );
        $this->assertSame(['Severity', 'Code', 'Text', 'Row'], $table->messageHeaders);
        $this->assertSame(
            [
                ['severity' => 'error', 'severityLabel' => 'Chyba', 'code' => 'journal.accountNotFound',
                 'text' => 'Účet 504??? nebyl nalezen v rozvrhu', 'rowIndex' => 1],
                ['severity' => 'warning', 'severityLabel' => 'Warning', 'code' => 'test.noRow',
                 'text' => 'Bez vazby na řádek', 'rowIndex' => null],
                ['severity' => 'info', 'severityLabel' => 'Info', 'code' => 'test.outOfRange',
                 'text' => 'Řádek neexistuje', 'rowIndex' => null],
            ],
            $table->messages,
        );
    }

    // ── Období ──────────────────────────────────────────────────────────────

    public function testFiscalPeriodLabelMatchesPicker(): void
    {
        $label = static fn (int $from, int $to, int $months = 12): string => ReportPeriodFormatter::label(
            ['fiscalYear' => '2026', 'monthFrom' => $from, 'monthTo' => $to],
            $months,
            'cs',
        );

        $this->assertSame('2026 / 8', $label(8, 8));
        $this->assertSame('2026 / 2Q', $label(4, 6));
        $this->assertSame('2026 / 2|2', $label(7, 12));
        $this->assertSame('2026', $label(1, 12));
        $this->assertSame('2026 / 2–4', $label(2, 4));
        // Zkrácený fiskální rok: celý rok = 1–9, ne 1–12.
        $this->assertSame('2026', $label(1, 9, 9));
    }

    public function testVatPeriodLabelAndFileSuffix(): void
    {
        $period = ['period' => 7, 'reportType' => 'cs', 'name' => '2026/05', 'vatRegistration' => 1,
                   'dateFrom' => '2026-05-01', 'dateTo' => '2026-05-31'];

        $this->assertSame('2026/05 (1. 5. 2026 – 31. 5. 2026)', ReportPeriodFormatter::label($period, 12, 'cs'));
        $this->assertSame('2026/05 (2026-05-01 – 2026-05-31)', ReportPeriodFormatter::label($period, 12, 'en'));
        $this->assertSame('2026-05', ReportPeriodFormatter::fileSuffix($period, 12));
    }

    public function testFiscalFileSuffix(): void
    {
        $suffix = static fn (int $from, int $to): string => ReportPeriodFormatter::fileSuffix(
            ['fiscalYear' => '2026', 'monthFrom' => $from, 'monthTo' => $to],
            12,
        );

        $this->assertSame('2026-05', $suffix(5, 5));
        $this->assertSame('2026-04-06', $suffix(4, 6));
        $this->assertSame('2026', $suffix(1, 12));
    }

    // ── Popisky z cfgItem ───────────────────────────────────────────────────

    public function testLabelsFromCompiledCfgItem(): void
    {
        // Reálný jsonc modulu, lokalizovaný stejně jako kompilace konfigurace.
        $raw = JsoncParser::parseFile(
            dirname(__DIR__, 5) . '/modules/core/system/config/reportExportLabels.jsonc',
        );
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === ReportExportLabels::CFG_ITEM
                ? \Shipard\Core\I18n\ConfigLocalizer::localize($raw, 'cs')
                : null,
        );

        $labels = ReportExportLabels::fromConfig($config);

        $this->assertSame('Účet', $labels->get('account'));
        $this->assertSame('Zprávy', $labels->get('sheetMessages'));
        $this->assertSame('Úroveň detailu', $labels->paramName('detail'));
        $this->assertSame('Synteticky', $labels->paramValue('detail', 'synthetic'));
        $this->assertSame('Ano', $labels->paramValue('anything', true));
        // Parametr bez záznamu → id a syrová hodnota.
        $this->assertSame('other', $labels->paramName('other'));
        $this->assertSame('x', $labels->paramValue('other', 'x'));
    }

    public function testLabelsFallBackToEnglishWithoutConfig(): void
    {
        $labels = ReportExportLabels::fromConfig(null);

        $this->assertSame('Account', $labels->get('account'));
        $this->assertSame('Messages', $labels->get('sheetMessages'));
        $this->assertSame((new ReportExportContext('r', 'd'))->labels->get('label'), $labels->get('label'));
    }
}
