<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Viewer;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\I18n\DisplayDate;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingSettingsResolver;
use Shipard\Module\Economy\PeriodicInvoicing\PeriodCalendar;
use Shipard\Module\Economy\PeriodicInvoicing\PeriodRepository;
use Shipard\Module\Economy\PeriodicInvoicing\Source\PeriodicInvoicingSource;

/**
 * Záložka Fakturace v detailu záznamu zdroje periodické fakturace (D24,
 * #175 D8): příští splatné období a předpis nahoře, evidence období
 * (nejnovější nahoře) se stavem vč. odvozeného „zastaveno“, dokladem
 * (odkaz do detailu dokladu), částkou a zprávou posledního běhu.
 *
 * Společná pro všechny zdroje — viewer zdroje ji jen zapojí do svých tabů.
 * Akce (Vystavit dlužná období, Přegenerovat, Obnovit) na záložce nejsou:
 * jsou to akce záznamu (`recordActions`, třídy `Action\*`), které zdroj
 * deklaruje nad svou tabulkou.
 */
final class InvoicingDetailTab
{
    public const TAB_ID = 'invoicing';

    private readonly InvoicingTexts $texts;

    /**
     * @param ?string $today dnešní datum `Y-m-d` (testy); null = dnes
     */
    public function __construct(
        private readonly PeriodicInvoicingSource $source,
        private readonly PeriodRepository $periods,
        ?ConfigRuntime $config,
        private readonly string $language = 'en',
        private readonly ?string $today = null,
    ) {
        $this->texts = new InvoicingTexts($config);
    }

    /**
     * @param array<string, mixed> $record normalizovaný záznam zdroje
     * @return array{id: string, label: string, content: array<string, mixed>} tab detailu
     */
    public function build(array $record): array
    {
        $blocks = [
            ['type' => 'properties', 'groups' => [['title' => $this->texts->text('group.invoicing', 'Invoicing'), 'items' => $this->prescriptionItems($record)]]],
            ['type' => 'heading', 'text' => $this->texts->text('heading.periods', 'Periods')],
        ];

        $rows = $this->periodRows((int) $record['id']);
        if ($rows === []) {
            $blocks[] = ['type' => 'heading', 'text' => $this->texts->text('text.noPeriods', 'No period has been issued yet.')];
        } else {
            $blocks[] = [
                'type'    => 'table',
                'columns' => [
                    ['id' => 'period', 'label' => $this->texts->text('column.period', 'Period')],
                    ['id' => 'state', 'label' => $this->texts->text('column.state', 'State')],
                    ['id' => 'document', 'label' => $this->texts->text('column.document', 'Document'), 'link' => true],
                    ['id' => 'amount', 'label' => $this->texts->text('column.amount', 'Amount'), 'align' => 'right'],
                    ['id' => 'message', 'label' => $this->texts->text('column.message', 'Message')],
                ],
                'rows' => $rows,
            ];
        }

        return [
            'id'      => self::TAB_ID,
            'label'   => $this->texts->text('tab.invoicing', 'Invoicing'),
            'content' => ['type' => 'composite', 'blocks' => $blocks],
        ];
    }

    /**
     * Příští splatné období a efektivní předpis (záznam → druh → výchozí).
     *
     * @param array<string, mixed> $record
     * @return list<array{label: string, value: string}>
     */
    private function prescriptionItems(array $record): array
    {
        $settings = InvoicingSettingsResolver::resolve($record, $this->source->kind($record));
        $periodicity = (string) ($record['inv_periodicity'] ?? '');
        $invoiceFrom = self::isoDate($record['inv_from'] ?? null);

        $items = [];
        if ($periodicity !== '' && $invoiceFrom !== null) {
            $next = PeriodCalendar::nextDue(
                $invoiceFrom,
                self::isoDate($record['date_end'] ?? null),
                $this->today ?? date('Y-m-d'),
                $periodicity,
                $settings->timing,
            );
            self::addItem(
                $items,
                $this->texts->text('label.nextDue', 'Next period due'),
                $next !== null
                    ? $this->date($next->from) . ' – ' . $this->date($next->to)
                    : $this->texts->text('text.noNextDue', 'none — past the end date'),
            );
        }
        self::addItem($items, $this->texts->text('label.periodicity', 'Periodicity'), $this->texts->cfgLabel('economy.periodicInvoicing.periodicities', $periodicity));
        self::addItem($items, $this->texts->text('label.invoiceFrom', 'Invoice from'), $this->date($invoiceFrom));
        self::addItem($items, $this->texts->text('label.invoiceTiming', 'Invoicing'), $this->texts->cfgLabel('economy.periodicInvoicing.invoiceTimings', $settings->timing));
        self::addItem(
            $items,
            $this->texts->text('label.invoiceDocType', 'Document type'),
            $settings->docType !== null ? $this->texts->cfgLabel('docs.core.docTypes', $settings->docType) : null,
        );
        return $items;
    }

    /** @return list<array<string, mixed>> řádky tabulky období */
    private function periodRows(int $recordId): array
    {
        $rows = [];
        foreach (PeriodOverview::load($this->periods, $recordId, $this->texts, $this->language)->entries as $entry) {
            $doc = $entry['doc'];
            $row = [
                'period'   => $entry['label'],
                'state'    => $this->texts->cfgLabel('economy.periodicInvoicing.periodStates', $entry['state']),
                'document' => $entry['documentLabel'],
                'amount'   => $doc !== null && $doc['total_amount'] !== null
                    ? trim(self::amount($doc['total_amount']) . ' ' . strtoupper((string) ($doc['doc_currency'] ?? '')))
                    : '',
                'message'  => (string) ($entry['period']['message'] ?? ''),
            ];
            if ($doc !== null) {
                $row['_action'] = [
                    'id'     => 'openDocument',
                    'kind'   => 'open_detail',
                    'target' => [
                        'viewerId' => InvoiceDocViewers::viewerFor((string) ($doc['doc_type'] ?? '')),
                        'recordId' => $entry['docId'],
                    ],
                ];
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function date(mixed $value): ?string
    {
        return DisplayDate::date($value, $this->language);
    }

    private static function amount(mixed $value): string
    {
        return number_format((float) $value, 2, ',', ' ');
    }

    /** @param list<array{label: string, value: string}> $items */
    private static function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }

    private static function isoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $string = trim((string) ($value ?? ''));
        return $string !== '' ? substr($string, 0, 10) : null;
    }
}
