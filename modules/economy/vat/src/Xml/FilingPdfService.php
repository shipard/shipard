<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Render\PdfOptions;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Render\RenderProfile;
use Shipard\Module\Economy\Vat\Reports\VatControlStatementLiveBuilder;
use Shipard\Module\Economy\Vat\VatOutputsMapping;

/**
 * Opis podání a jeho obsah v PDF (issue #55, X7).
 *
 * - **Opis** je to, co jde do XML, jen čitelně: hlavička a hodnoty vět
 *   jednou tabulkou. Slouží ke kontrole před odesláním a do složky.
 * - **Obsah** rozepisuje čísla po dokladech (`economy_vat_filing_items`) —
 *   odpověď na otázku „z čeho je ten řádek". XML dokladovou úroveň nenese,
 *   proto ji renderer čte ze snapshotu sám.
 *
 * Selhání renderu **není chyba podání**: vrátí se míň souborů a důvod ve
 * `warnings()`; XML je povinné, PDF ne. Bez nakonfigurované render služby
 * (`RenderClient::isConfigured()`) se nic negeneruje a warning to řekne.
 */
final class FilingPdfService implements FilingPdfRenderer
{
    private const TEMPLATES = __DIR__ . '/templates';

    /** Oddíly formuláře přiznání — věta schématu → nadpis v opisu. */
    private const SENTENCE_TITLES = [
        'Veta1' => 'I. Zdanitelná plnění',
        'Veta2' => 'II. Ostatní plnění a plnění mimo tuzemsko s nárokem na odpočet',
        'Veta3' => 'III. Doplňující údaje',
        'Veta4' => 'IV. Nárok na odpočet daně',
        'Veta5' => 'V. Krácení nároku na odpočet daně',
        'Veta6' => 'VI. Výpočet daňové povinnosti',
    ];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly \Dibi\Connection $db,
        private readonly RenderClient $render,
        private readonly ?ConfigRuntime $config = null,
        private readonly string $language = 'cs',
    ) {}

    /** @return list<FilingFile> */
    public function render(int $filingId, FilingXmlInput $input, string $baseName): array
    {
        $this->warnings = [];

        if (!$this->render->isConfigured()) {
            $this->warnings[] = 'Tisková služba není nakonfigurovaná — vznikl jen soubor XML.';
            return [];
        }

        $files = [];
        foreach ([
            FilingFilesService::KIND_PREVIEW => ['preview', 'opis', $this->previewModel($input)],
            FilingFilesService::KIND_CONTENT => ['content', 'obsah', $this->contentModel($filingId, $input)],
        ] as $kind => [$template, $suffix, $model]) {
            $pdf = $this->renderTemplate($template, $model);
            if ($pdf === null) {
                continue;
            }
            $files[] = new FilingFile($kind, "{$baseName}-{$suffix}.pdf", $pdf, 'application/pdf');
        }
        return $files;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    // ── Render ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $model */
    private function renderTemplate(string $template, array $model): ?string
    {
        $result = $this->render->renderHtml(
            FilingPdfTemplate::render(self::TEMPLATES . "/{$template}.html.php", $model),
            [],
            RenderProfile::Report,
            new PdfOptions(paperFormat: 'A4', printBackground: true),
        );

        if (!$result->ok) {
            $this->warnings[] = sprintf(
                'PDF „%s" se nepodařilo vytvořit (%s) — soubor XML je uložený.',
                $template,
                $result->errorKind?->value ?? 'unknown',
            );
            return null;
        }
        return $result->pdfContent;
    }

    // ── Opis ────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function previewModel(FilingXmlInput $input): array
    {
        return [
            'title'    => $this->title($input),
            'subtitle' => $this->subtitle($input),
            'header'   => $this->headerItems($input),
            'tables'   => match ($input->reportType) {
                'return' => $this->returnTables($input),
                'cs'     => $this->controlTables($input),
                default  => $this->recapTables($input),
            },
        ];
    }

    /**
     * Řádky přiznání po větách formuláře — jen ty, které něco nesou.
     * Prázdné řádky by opis udělaly nečitelným; kdo chce formulář se
     * všemi, otevře XML v aplikaci EPO.
     *
     * Vypisují se **jen sloupce, které daná věta v XML má** (mapování je
     * autorita) — jinak by opis ukazoval hodnotu, kterou podání nenese:
     * ř. 62 nese jen daň, přestože snapshot má u součtu i základ.
     *
     * @return list<array<string, mixed>>
     */
    private function returnTables(FilingXmlInput $input): array
    {
        $labels  = VatOutputsMapping::fromConfig($this->config);
        $mapping = VatXmlMapping::forReportType($this->config, 'return');
        $tables  = [];

        foreach ($mapping?->rows() ?? [] as $row => $definition) {
            $row    = (int) $row;
            $values = $input->returnRows[$row] ?? null;
            if ($values === null) {
                continue;
            }

            $sentence = (string) $definition['veta'];
            $cells    = [];
            $empty    = true;
            foreach (['base', 'full', 'reduced'] as $slot) {
                if (!isset($definition[$slot])) {
                    $cells[] = '';
                    continue;
                }
                $cells[] = self::money($values[$slot]);
                $empty   = $empty && abs($values[$slot]) < 0.005;
            }
            if (isset($definition['percent'])) {
                $coefficient = $input->coefficients[(string) $definition['percent']['source']] ?? null;
                $cells[]     = $coefficient !== null && !$empty
                    ? number_format((float) $coefficient * 100, 2, ',', '')
                    : '';
            } else {
                $cells[] = '';
            }
            if ($empty) {
                continue;
            }

            $tables[$sentence]['title']   = self::SENTENCE_TITLES[$sentence] ?? $sentence;
            $tables[$sentence]['columns'] = self::sentenceColumns($sentence);
            $tables[$sentence]['rows'][]  = [
                'no'    => (string) $row,
                'label' => $labels?->dp3RowLabel($row) ?? '',
                'cells' => $cells,
                'total' => in_array($row, [46, 62, 63, 64, 65, 66], true),
            ];
        }

        return array_values($tables);
    }

    /**
     * Hlavičky hodnotových sloupců per věta — tatáž hodnota má ve větě 1
     * význam „daň na výstupu" a ve větě 4 „odpočet v plné výši".
     *
     * @return list<string>
     */
    private static function sentenceColumns(string $sentence): array
    {
        return match ($sentence) {
            'Veta1' => ['Ř.', 'Popis', 'Základ daně', 'Daň na výstupu', '', ''],
            'Veta4' => ['Ř.', 'Popis', 'Základ daně', 'V plné výši', 'Krácený odpočet', ''],
            'Veta5' => ['Ř.', 'Popis', 'S nárokem', 'Bez nároku / odpočet', '', 'Koeficient (%)'],
            'Veta6' => ['Ř.', 'Popis', '', 'Daň', '', ''],
            default => ['Ř.', 'Popis', 'Hodnota', '', '', ''],
        };
    }

    /** @return list<array<string, mixed>> */
    private function controlTables(FilingXmlInput $input): array
    {
        $tables = [];
        foreach (VatControlStatementLiveBuilder::SECTION_TITLES as $section => $titles) {
            $rows = [];
            foreach ($input->controlRows as $row) {
                if ((string) $row['section'] !== (string) $section) {
                    continue;
                }
                $rows[] = [
                    'no'    => (string) ($row['doc_number'] ?? ''),
                    'label' => trim(implode(' · ', array_filter([
                        (string) ($row['partner_vat_id'] ?? ''),
                        self::date($row['vat_dppd'] ?? null),
                        trim((string) ($row['reverse_charge_code'] ?? '')) !== ''
                            ? 'kód ' . trim((string) $row['reverse_charge_code'])
                            : '',
                    ]))),
                    'cells' => [
                        self::money((float) $row['base1'] + (float) $row['base2'] + (float) $row['base3']),
                        self::money((float) $row['tax1'] + (float) $row['tax2'] + (float) $row['tax3']),
                        '',
                        '',
                    ],
                    'total' => false,
                ];
            }
            if ($rows !== []) {
                $tables[] = [
                    'title'   => $titles[$this->language === 'en' ? 'en' : 'cs'],
                    'columns' => ['Doklad', 'Protistrana · datum · kód', 'Základ daně', 'Daň', '', ''],
                    'rows'    => $rows,
                ];
            }
        }
        return $tables;
    }

    /** @return list<array<string, mixed>> */
    private function recapTables(FilingXmlInput $input): array
    {
        $rows = [];
        foreach ($input->recapRows as $row) {
            [$country, $number] = EpoXmlFormat::euVatId($row['partner_vat_id'] ?? null);
            $rows[] = [
                'no'    => (string) ((int) $row['kod'] === 0 ? 'zboží' : 'služby'),
                'label' => trim(((string) $country) . ' ' . ((string) $number)),
                'cells' => [(string) (int) $row['count'], self::money((float) $row['value_filed']), '', ''],
                'total' => false,
            ];
        }
        return [[
            'title'   => 'Řádky souhrnného hlášení',
            'columns' => ['Plnění', 'DIČ pořizovatele', 'Počet', 'Hodnota', '', ''],
            'rows'    => $rows,
        ]];
    }

    // ── Obsah ───────────────────────────────────────────────────────────────

    /**
     * Doklady, ze kterých se čísla poskládala, seskupené tak, jak je čte
     * formulář: přiznání po řádcích, hlášení po sekcích, souhrnné po kódu
     * plnění.
     *
     * @return array<string, mixed>
     */
    private function contentModel(int $filingId, FilingXmlInput $input): array
    {
        $mapping = VatOutputsMapping::fromConfig($this->config);
        $groups  = [];

        foreach ($this->items($filingId) as $item) {
            [$key, $title] = match ($input->reportType) {
                'return' => [
                    sprintf('%03d', (int) $item['dp3_row']),
                    'Řádek ' . (int) $item['dp3_row']
                        . ($mapping?->dp3RowLabel((int) $item['dp3_row']) !== null
                            ? ' — ' . $mapping->dp3RowLabel((int) $item['dp3_row'])
                            : ''),
                ],
                'cs' => [
                    (string) ($item['kh_section'] ?? ''),
                    VatControlStatementLiveBuilder::SECTION_TITLES[(string) ($item['kh_section'] ?? '')]
                        [$this->language === 'en' ? 'en' : 'cs'] ?? (string) $item['kh_section'],
                ],
                default => [
                    (string) ($item['sh_kod'] ?? ''),
                    (int) ($item['sh_kod'] ?? 0) === 0 ? 'Dodání zboží' : 'Poskytnutí služeb',
                ],
            };
            if ($key === '' || $key === '000') {
                continue; // doklad, který do této písemnosti nespadá
            }

            $groups[$key]['title'] = $title;
            $groups[$key]['rows'][] = [
                'number'  => (string) $item['doc_number'],
                'partner' => (string) $item['partner_vat_id'],
                'date'    => self::date($item['vat_dppd'] ?? $item['vat_duzp'] ?? null),
                'code'    => (string) $item['vat_code'],
                'base'    => self::money((float) $item['base_dom']),
                'tax'     => self::money((float) $item['tax_dom']),
            ];
            $groups[$key]['base'] = ($groups[$key]['base'] ?? 0.0) + (float) $item['base_dom'];
            $groups[$key]['tax']  = ($groups[$key]['tax'] ?? 0.0) + (float) $item['tax_dom'];
        }

        ksort($groups);
        foreach ($groups as $key => $group) {
            $groups[$key]['baseTotal'] = self::money((float) $group['base']);
            $groups[$key]['taxTotal']  = self::money((float) $group['tax']);
        }

        return [
            'title'    => 'Obsah podání — ' . $this->title($input),
            'subtitle' => $this->subtitle($input),
            'groups'   => array_values($groups),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function items(int $filingId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [doc_number], [partner_vat_id], [vat_duzp], [vat_dppd], [vat_code],'
            . ' [base_dom], [tax_dom], [dp3_row], [kh_section], [sh_kod]'
            . ' FROM [economy_vat_filing_items] WHERE [filing] = %i'
            . ' ORDER BY [dp3_row], [kh_section], [doc_number]',
            $filingId,
        );
        return array_map(static fn ($row): array => $row->toArray(), $rows);
    }

    // ── Popisky a formátování ───────────────────────────────────────────────

    private function title(FilingXmlInput $input): string
    {
        return match ($input->reportType) {
            'return' => 'Přiznání k dani z přidané hodnoty',
            'cs'     => 'Kontrolní hlášení DPH',
            default  => 'Souhrnné hlášení DPH',
        };
    }

    private function subtitle(FilingXmlInput $input): string
    {
        $period = $input->period->month !== null
            ? sprintf('%02d/%d', $input->period->month, $input->period->year)
            : sprintf('%d. čtvrtletí %d', (int) $input->period->quarter, $input->period->year);

        $kinds = $this->config?->cfgItem('economy.vat.filingKinds');
        $kind  = is_array($kinds) ? (string) ($kinds[$input->filingKind]['name'] ?? '') : '';

        return trim($period . ' · ' . ($kind !== '' ? $kind : $input->filingKind));
    }

    /** @return list<array{label: string, value: string}> */
    private function headerItems(FilingXmlInput $input): array
    {
        $header = $input->header;
        $name   = (string) ($header['zkrobchjm'] ?? trim(
            (string) ($header['prijmeni'] ?? '') . ' ' . (string) ($header['jmeno'] ?? ''),
        ));

        $address = trim(implode(' ', array_filter([
            (string) ($header['ulice'] ?? ''),
            (string) ($header['c_pop'] ?? ''),
        ]))) . ', ' . trim(
            (string) ($header['psc'] ?? '') . ' ' . (string) ($header['naz_obce'] ?? ''),
        );

        $items = [
            ['label' => 'Daňový subjekt', 'value' => $name],
            ['label' => 'DIČ', 'value' => 'CZ' . (string) ($header['dic'] ?? '')],
            ['label' => 'Sídlo', 'value' => trim($address, ' ,')],
            ['label' => 'Datum podání', 'value' => (string) EpoXmlFormat::date($input->filingDate())],
        ];
        if ($input->dateFound !== null) {
            $items[] = ['label' => 'Zjištěno', 'value' => (string) EpoXmlFormat::date($input->dateFound)];
        }
        return $items;
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', "\u{00A0}");
    }

    private static function date(mixed $value): string
    {
        return (string) EpoXmlFormat::date($value);
    }
}
