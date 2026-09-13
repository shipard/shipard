<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Module\Economy\Vat\Import\FilingImportService;

/**
 * Viewer podání DPH (`economy_vat_filings`, #55 D20). Řádek: název ·
 * podaný výsledek · typ, druh, datumy, stav. Detail: přehled, výstupní
 * řádky (podané vedle přesných), zprávy z okamžiku sestavení a **rozdíly**
 * proti předchozímu podání po dokladech — bez nich by dodatečné podání
 * bylo neověřitelné číslo.
 *
 * Akce „Přepočítat" (jen u konceptu) volá `POST /_vat/filing-compose`.
 *
 * Importované podání (`origin = imported`, #55 D34): čip Import, v přehledu
 * původ s počtem rozdílů, záložka Rozdíly importu (podané hodnoty ze
 * starého XML vs. dnešní sestavení); Přepočítat, Načíst hlavičku
 * a Vytvořit soubory se nenabízejí — původní přílohy jsou pravda.
 */
class FilingsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'economy.vat.docStatesFilings';

    private const STATE_SPAN_CLASS = [
        'concept' => 'warning',
        'done'    => 'success',
        'trash'   => 'muted',
    ];

    /** Klíč porovnání dokladové úrovně mezi podáními. */
    private const DIFF_KEY_COLUMNS = ['doc_head', 'vat_code', 'vat_pct'];

    /** Popisky slotů řádku přiznání v záložce Rozdíly importu. */
    private const SLOT_LABELS = [
        'base'    => ['cs' => 'Základ', 'en' => 'Base'],
        'full'    => ['cs' => 'V plné výši', 'en' => 'Full'],
        'reduced' => ['cs' => 'Krácený', 'en' => 'Reduced'],
    ];

    /** Popisky druhů rozdílu řádků hlášení (`EpoXmlLineComparer`). */
    private const LINE_KIND_LABELS = [
        'value'   => ['cs' => 'jiná hodnota', 'en' => 'value'],
        'missing' => ['cs' => 'jen v podaném', 'en' => 'filed only'],
        'extra'   => ['cs' => 'jen v sestaveném', 'en' => 'composed only'],
    ];

    /** @var ?array<int, array<string, mixed>> */
    private ?array $periods = null;

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT `id`, `report_period`, `report_type`, `filing_kind`, `origin`, `sequence`, `name`,'
            . ' `date_issue`, `date_filed`, `result`, `docState`, `docStateMain`'
            . ' FROM `' . $this->table . '` ';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        foreach ($filters as $filter) {
            $value = $filter['value'] ?? null;
            switch ($filter['id'] ?? '') {
                case 'viewGroup':
                    $viewGroup = (string) $value;
                    break;
                case 'report_type':
                    if (is_string($value) && $value !== '' && $value !== 'all') {
                        $conditions[] = '`report_type` = %s';
                        $params[] = $value;
                    }
                    break;
                case 'filing_kind':
                    if (is_string($value) && $value !== '' && $value !== 'all') {
                        $conditions[] = '`filing_kind` = %s';
                        $params[] = $value;
                    }
                    break;
                case 'year':
                    if (is_numeric($value) && (int) $value > 0) {
                        $conditions[] = 'YEAR(`date_issue`) = %i';
                        $params[] = (int) $value;
                    }
                    break;
            }
        }

        if ($viewGroup !== 'all') {
            [$vgSql, $vgParams] = $this->buildViewGroupFilter($this->docStatesCfgItem, $viewGroup);
            if ($vgSql !== '') {
                $conditions[] = $vgSql;
                $params = array_merge($params, $vgParams);
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(['name', 'note'], $search);
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY `date_issue` DESC, `id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $cs   = $this->language !== 'en';
        $type = (string) ($rowData['report_type'] ?? '');

        $row = [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['name'] ?? ''),
            'i1' => $this->resultSummary($type, $this->decodeJson($rowData['result'] ?? null), $cs),
        ];

        $t2 = [];
        $t2[] = ['text' => $this->typeLabels()[$type] ?? $type, 'class' => 'muted'];
        $t2[] = ['text' => $this->kindLabels()[(string) $rowData['filing_kind']] ?? (string) $rowData['filing_kind']];
        if ($this->isImported($rowData)) {
            $t2[] = ['text' => 'Import', 'badge' => 'neutral'];
        }

        $filed = $this->formatDate($rowData['date_filed'] ?? null);
        $t2[] = ['text' => $filed !== ''
            ? ($cs ? "podáno {$filed}" : "filed {$filed}")
            : ($cs ? 'sestaveno ' : 'composed ') . $this->formatDate($rowData['date_issue'] ?? null)];

        $docState  = (int) ($rowData['docState'] ?? 10);
        $stateData = $this->docStates()->getState($docState);
        $stateStyle = $stateData['stateStyle'] ?? 'concept';
        if ($docState !== FilingDocument::DOC_STATE_FILED) {
            $t2[] = [
                'text'  => $stateData['stateName'] ?? '',
                'class' => self::STATE_SPAN_CLASS[$stateStyle] ?? 'muted',
            ];
        }

        $row['t2'] = $t2;
        $row['stateStyle'] = $stateStyle;
        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow('SELECT * FROM `' . $this->table . '` WHERE `id` = %i', $recordId);
        if ($record === null) {
            return ['tabs' => []];
        }
        $cs   = $this->language !== 'en';
        $type = (string) ($record['report_type'] ?? '');

        $tabs = [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => ['type' => 'properties', 'groups' => $this->overviewGroups($record, $type, $cs)],
        ]];

        $rowsContent = match ($type) {
            'return' => $this->returnRowsTable($recordId, $cs),
            'cs'     => $this->controlStatementRowsTable($recordId, $cs),
            'rs'     => $this->recapitulativeRowsTable($recordId, $cs),
            default  => null,
        };
        if ($rowsContent !== null) {
            $tabs[] = [
                'id'      => 'rows',
                'label'   => $cs ? 'Výstupní řádky' : 'Output rows',
                'content' => $rowsContent,
            ];
        }

        // Hlavička podání (#55 Fáze 3) — zobrazuje se podle `_schema`
        // uložené hodnoty, tedy podle sady polí platné v době sestavení.
        $headerGroups = $this->structuredFieldProperties(
            (array) $record,
            FilingHeaderSchema::COLUMN,
            FilingHeaderSchema::forReportType($type),
        );
        if ($headerGroups !== []) {
            $tabs[] = [
                'id'      => 'header',
                'label'   => $cs ? 'Hlavička' : 'Header',
                'content' => ['type' => 'properties', 'groups' => $headerGroups],
            ];
        }

        $previousId = (int) ($record['previous_filing'] ?? 0);
        if ($previousId > 0) {
            $tabs[] = [
                'id'      => 'diff',
                'label'   => $cs ? 'Rozdíly' : 'Differences',
                'content' => $this->diffTable($recordId, $previousId, $cs),
            ];
        }

        $messages = $this->decodeJson($record['messages'] ?? null);
        $imported = $this->isImported((array) $record);
        // Importované podání (#55 D34): co Shipard sestavil dnes vs. co
        // bylo doopravdy podáno — historický záznam, ne stav k řešení.
        if ($imported) {
            $tabs[] = [
                'id'      => 'import',
                'label'   => $cs ? 'Rozdíly importu' : 'Import differences',
                'content' => $this->importDiffTable($messages, $cs),
            ];
        }
        if ($messages !== []) {
            $tabs[] = [
                'id'      => 'messages',
                'label'   => $cs ? 'Zprávy' : 'Messages',
                'content' => $this->messagesTable($messages, $cs),
            ];
        }

        $detail = ['tabs' => $tabs];

        $docState = (int) ($record['docState'] ?? 0);

        // Přepočet je smysluplný jen u konceptu — podané podání je záznam
        // o tom, co odešlo, a composer ho odmítne. Hlavičku přepočet nechává
        // být (ruční úpravy), proto je obnova z profilu zvláštní akce
        // (#55 F3-5). Importované podání nemá co přepočítat — snapshot
        // i hlavička zrcadlí to, co bylo podáno.
        if ($docState === FilingDocument::DOC_STATE_COMPOSED && !$imported) {
            $detail['actions'] = [
                [
                    'id'      => 'recomposeFiling',
                    'label'   => $cs ? 'Přepočítat' : 'Recompose',
                    'variant' => 'secondary',
                ],
                [
                    'id'      => 'reloadFilingHeader',
                    'label'   => $cs ? 'Načíst hlavičku z profilu' : 'Load header from profile',
                    'variant' => 'secondary',
                ],
            ];
        }

        // Zaúčtování přiznání (#55 D28–D31) — jen podané přiznání: bez
        // živého účetního dokladu akce Zaúčtovat, s dokladem odkaz na něj,
        // po stornu dokladu Zaúčtovat znovu. Explicitní akce, ne automat
        // při podání (D31) — doklad vzniká jako koncept ke kontrole.
        $accDoc     = $this->accountingDocument($record);
        $canAccount = $docState === FilingDocument::DOC_STATE_FILED && $type === 'return' && ($accDoc === null || !$accDoc['live']);
        if ($canAccount) {
            $detail['actions'][] = [
                'id'      => 'accountFiling',
                'label'   => $accDoc === null ? ($cs ? 'Zaúčtovat' : 'Post to accounting') : ($cs ? 'Zaúčtovat znovu' : 'Post again'),
                'variant' => 'primary',
            ];
        }
        if ($accDoc !== null && $accDoc['live']) {
            $detail['actions'][] = [
                'id'       => 'openAccountingDocument',
                'label'    => ($cs ? 'Účetní doklad ' : 'Accounting document ') . $accDoc['label'],
                'variant'  => 'secondary',
                'kind'     => 'open_viewer',
                'viewerId' => 'docs.accountingDocs.heads',
                'recordId' => $accDoc['id'],
            ];
        }

        // Zámek tvrzení po podání (#55 D25) — „jeden klik po podání":
        // podané podání nad neuzamčenou instancí nabídne Uzamknout tvrzení.
        // Přechodový dialog volitelná pole neumí, proto akce, ne checkbox.
        // Pořadí po podání: Zaúčtovat, Uzamknout tvrzení — primární je to,
        // co ještě zbývá udělat první.
        if ($docState === FilingDocument::DOC_STATE_FILED) {
            $periodId = (int) ($record['report_period'] ?? 0);
            $period = $periodId > 0
                ? $this->db->fetchRow('SELECT `locked`, `docState` FROM `economy_vat_report_periods` WHERE `id` = %i', $periodId)
                : null;
            if ($period !== null && empty($period['locked']) && (int) $period['docState'] !== 90) {
                $detail['actions'][] = ReportPeriodsViewer::lockAction(
                    $periodId,
                    $canAccount ? 'secondary' : 'primary',
                    $cs ? 'Uzamknout tvrzení' : 'Lock period',
                );
            }
        }

        // Soubory pro daňový portál (#55 X6): u konceptu si je lze vyrobit
        // dopředu (a opakovaně), u podaného podání jen doplnit, když
        // automatické vytvoření při podání selhalo. Hotové soubory jsou
        // v záložce Přílohy, odkud se i stahují. Importované podání má
        // původní soubory — generátor ho odmítne, akce se nenabízí.
        if ($docState !== FilingDocument::DOC_STATE_CANCELLED && !$imported) {
            $detail['actions'][] = [
                'id'      => 'generateFilingFiles',
                'label'   => $cs ? 'Vytvořit soubory' : 'Create files',
                'variant' => 'secondary',
            ];
        }

        return $detail;
    }

    public function getFilters(): array
    {
        $cs = $this->language !== 'en';
        $all = $cs ? 'Vše' : 'All';

        $typeOptions = [['value' => 'all', 'label' => $all]];
        foreach ($this->typeLabels() as $key => $label) {
            $typeOptions[] = ['value' => $key, 'label' => $label];
        }
        $kindOptions = [['value' => 'all', 'label' => $all]];
        foreach ($this->kindLabels() as $key => $label) {
            $kindOptions[] = ['value' => $key, 'label' => $label];
        }

        $filters = [
            [
                'id'      => 'report_type',
                'label'   => $cs ? 'Typ tvrzení' : 'Report type',
                'type'    => 'enum',
                'default' => 'all',
                'options' => $typeOptions,
            ],
            [
                'id'      => 'filing_kind',
                'label'   => $cs ? 'Druh podání' : 'Filing kind',
                'type'    => 'enum',
                'default' => 'all',
                'options' => $kindOptions,
            ],
        ];

        $years = $this->db->fetchAll(
            'SELECT DISTINCT YEAR(`date_issue`) AS y FROM `' . $this->table . '`'
            . ' WHERE `docState` != %i ORDER BY y DESC',
            FilingDocument::DOC_STATE_CANCELLED,
        );
        if ($years !== []) {
            $yearOptions = [['value' => '0', 'label' => $all]];
            foreach ($years as $row) {
                $yearOptions[] = ['value' => (string) $row['y'], 'label' => (string) $row['y']];
            }
            $filters[] = [
                'id'      => 'year',
                'label'   => $cs ? 'Rok' : 'Year',
                'type'    => 'enum',
                'default' => '0',
                'options' => $yearOptions,
            ];
        }

        return $filters;
    }

    // ── Přehled ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>|\Dibi\Row $record
     * @return list<array{title: string, items: list<array{label: string, value: string}>}>
     */
    private function overviewGroups($record, string $type, bool $cs): array
    {
        $periods    = $this->periods();
        $periodId   = (int) ($record['report_period'] ?? 0);
        $period     = $periods[$periodId] ?? null;
        $previousId = (int) ($record['previous_filing'] ?? 0);

        $items = [
            ['label' => $cs ? 'Daňové tvrzení' : 'Report period',
             'value' => $period !== null ? $this->periodLabel($period) : ('#' . $periodId)],
            ['label' => $cs ? 'Typ tvrzení' : 'Report type', 'value' => $this->typeLabels()[$type] ?? $type],
            ['label' => $cs ? 'Druh podání' : 'Filing kind',
             'value' => $this->kindLabels()[(string) $record['filing_kind']] ?? (string) $record['filing_kind']],
            ['label' => $cs ? 'Pořadí v tvrzení' : 'Sequence', 'value' => (string) ($record['sequence'] ?? '')],
            ['label' => $cs ? 'Sestaveno' : 'Composed on', 'value' => $this->formatDate($record['date_issue'] ?? null)],
        ];
        if ($this->isImported((array) $record)) {
            $items[] = ['label' => $cs ? 'Původ' : 'Origin', 'value' => $this->importSummary((array) $record, $cs)];
        }
        $filed = $this->formatDate($record['date_filed'] ?? null);
        $items[] = ['label' => $cs ? 'Podáno' : 'Filed on', 'value' => $filed !== '' ? $filed : '—'];

        $found = $this->formatDate($record['date_found'] ?? null);
        if ($found !== '') {
            $items[] = ['label' => $cs ? 'Datum zjištění důvodů' : 'Reasons found on', 'value' => $found];
        }
        if ($previousId > 0) {
            $previousName = (string) $this->db->fetchSingle(
                'SELECT `name` FROM `' . $this->table . '` WHERE `id` = %i', $previousId,
            );
            $items[] = ['label' => $cs ? 'Předchozí podání' : 'Previous filing', 'value' => $previousName];
        }
        if (!empty($record['note'])) {
            $items[] = ['label' => $cs ? 'Poznámka' : 'Note', 'value' => (string) $record['note']];
        }
        // Zaúčtování přiznání (#55 F4b) — jen u podaného přiznání; hlášení
        // se neúčtují, koncept ještě nemá co účtovat.
        if ($type === 'return' && (int) ($record['docState'] ?? 0) === FilingDocument::DOC_STATE_FILED) {
            $accDoc = $this->accountingDocument($record);
            $items[] = [
                'label' => $cs ? 'Zaúčtování' : 'Accounting',
                'value' => match (true) {
                    $accDoc === null   => $cs ? 'Nezaúčtováno' : 'Not posted',
                    $accDoc['live']    => ($cs ? 'Účetní doklad ' : 'Accounting document ') . $accDoc['label'] . ' (' . $accDoc['state'] . ')',
                    default            => ($cs ? 'Doklad ' : 'Document ') . $accDoc['label'] . ' ' . ($cs ? 'je stornovaný — zaúčtujte znovu' : 'is cancelled — post again'),
                },
            ];
        }

        $groups = [[
            'title' => $cs ? 'Podání' : 'Filing',
            'items' => $items,
        ]];

        $result = $this->decodeJson($record['result'] ?? null);
        if ($result === []) {
            $groups[] = [
                'title' => $cs ? 'Výsledek' : 'Result',
                'items' => [[
                    'label' => $cs ? 'Snapshot' : 'Snapshot',
                    'value' => $cs
                        ? 'Nesestavený — spusťte Přepočítat.'
                        : 'Not composed — use Recompose.',
                ]],
            ];
            return $groups;
        }

        $groups[] = ['title' => $cs ? 'Výsledek' : 'Result', 'items' => $this->resultItems($type, $result, $cs)];
        return $groups;
    }

    /**
     * @param array<string, mixed> $result
     * @return list<array{label: string, value: string}>
     */
    private function resultItems(string $type, array $result, bool $cs): array
    {
        $items = [];
        if (!empty($result['isEmpty'])) {
            $items[] = [
                'label' => $cs ? 'Obsah' : 'Content',
                'value' => $cs ? 'Prázdné podání' : 'Empty filing',
            ];
        }
        $items[] = [
            'label' => $cs ? 'Doklady' : 'Documents',
            'value' => (string) ($result['docCount'] ?? 0) . ' / '
                . (string) ($result['itemCount'] ?? 0) . ($cs ? ' řádků' : ' rows'),
        ];

        if ($type === 'return') {
            foreach ([62, 63, 64, 65, 66] as $row) {
                $value = (float) ($result['return']['row' . $row] ?? 0.0);
                if ($row === 66 && abs($value) < 0.005) {
                    continue;
                }
                $items[] = [
                    'label' => ($cs ? 'Ř. ' : 'Row ') . $row . ' — ' . $this->returnRowShortLabel($row, $cs),
                    'value' => $this->formatMoney($value),
                ];
            }
        } elseif ($type === 'cs') {
            foreach ((array) ($result['cs']['sections'] ?? []) as $section => $summary) {
                $items[] = [
                    'label' => ($cs ? 'Sekce ' : 'Section ') . $section,
                    'value' => sprintf(
                        $cs ? '%d řádků · základ %s · daň %s' : '%d rows · base %s · tax %s',
                        (int) ($summary['rows'] ?? 0),
                        $this->formatMoney((float) ($summary['base'] ?? 0.0)),
                        $this->formatMoney((float) ($summary['tax'] ?? 0.0)),
                    ),
                ];
            }
        } elseif ($type === 'rs') {
            $items[] = [
                'label' => $cs ? 'Plnění' : 'Supplies',
                'value' => (string) ($result['rs']['supplies'] ?? 0) . ' · '
                    . $this->formatMoney((float) ($result['rs']['valueFiled'] ?? 0.0)),
            ];
        }

        if (isset($result['crossCheck'])) {
            $differences = (int) ($result['crossCheck']['differences'] ?? 0);
            $items[] = [
                'label' => $cs ? 'Křížová kontrola' : 'Cross-check',
                'value' => $differences === 0
                    ? ($cs ? 'Souhlasí s deníkem' : 'Matches the journal')
                    : sprintf($cs ? '%d rozdílů proti deníku' : '%d differences against the journal', $differences),
            ];
        }
        return $items;
    }

    // ── Výstupní řádky ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function returnRowsTable(int $filingId, bool $cs): array
    {
        $mapping = VatOutputsMapping::fromConfig($this->config);
        $rows = $this->db->fetchAll(
            'SELECT * FROM `economy_vat_filing_return_rows` WHERE `filing` = %i ORDER BY `row`',
            $filingId,
        );

        $tableRows = [];
        foreach ($rows as $row) {
            $number = (int) $row['row'];
            $tableRows[] = [
                'row'         => (string) $number,
                'label'       => $mapping?->dp3RowLabel($number) ?? '',
                'base'        => $this->formatMoney((float) $row['base_filed']),
                'tax'         => $this->formatMoney((float) $row['tax_full_filed']),
                'reduced'     => $this->formatNonZeroMoney((float) $row['tax_reduced_filed']),
                'baseExact'   => $this->formatMoney((float) $row['base']),
                'taxExact'    => $this->formatMoney((float) $row['tax_full']),
                '_class'      => (int) $row['is_computed'] === 1 ? 'total' : null,
            ];
        }

        return ['type' => 'table', 'columns' => [
            ['id' => 'row',       'label' => $cs ? 'Ř.' : 'Row'],
            ['id' => 'label',     'label' => $cs ? 'Popis' : 'Description'],
            ['id' => 'base',      'label' => $cs ? 'Základ (podáno)' : 'Base (filed)', 'align' => 'right'],
            ['id' => 'tax',       'label' => $cs ? 'Daň (podáno)' : 'Tax (filed)', 'align' => 'right'],
            ['id' => 'reduced',   'label' => $cs ? 'Krácený' : 'Reduced', 'align' => 'right'],
            ['id' => 'baseExact', 'label' => $cs ? 'Základ (přesně)' : 'Base (exact)', 'align' => 'right'],
            ['id' => 'taxExact',  'label' => $cs ? 'Daň (přesně)' : 'Tax (exact)', 'align' => 'right'],
        ], 'rows' => $tableRows];
    }

    /** @return array<string, mixed> */
    private function controlStatementRowsTable(int $filingId, bool $cs): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM `economy_vat_filing_cs_rows` WHERE `filing` = %i'
            . ' ORDER BY `section`, `doc_number`, `id`',
            $filingId,
        );

        $tableRows = [];
        foreach ($rows as $row) {
            $tableRows[] = [
                'section'   => (string) $row['section'],
                'evid'      => (string) ($row['doc_number'] ?? ''),
                'vatId'     => (string) ($row['partner_vat_id'] ?? ''),
                'dppd'      => $this->formatDate($row['vat_dppd'] ?? null),
                'kod'       => $row['kod_pred_pl'] !== null ? (string) $row['kod_pred_pl'] : '',
                'base'      => $this->formatMoney(
                    (float) $row['base1'] + (float) $row['base2'] + (float) $row['base3'],
                ),
                'tax'       => $this->formatMoney(
                    (float) $row['tax1'] + (float) $row['tax2'] + (float) $row['tax3'],
                ),
                '_class'    => (string) $row['row_kind'] === 'aggregate' ? 'total' : null,
            ];
        }

        return ['type' => 'table', 'columns' => [
            ['id' => 'section', 'label' => $cs ? 'Sekce' : 'Section'],
            ['id' => 'evid',    'label' => $cs ? 'Ev. číslo' : 'Evidence no.'],
            ['id' => 'vatId',   'label' => 'DIČ'],
            ['id' => 'dppd',    'label' => 'DPPD'],
            ['id' => 'kod',     'label' => $cs ? 'Kód PDP' : 'RC code'],
            ['id' => 'base',    'label' => $cs ? 'Základ' : 'Base', 'align' => 'right'],
            ['id' => 'tax',     'label' => $cs ? 'Daň' : 'Tax', 'align' => 'right'],
        ], 'rows' => $tableRows];
    }

    /** @return array<string, mixed> */
    private function recapitulativeRowsTable(int $filingId, bool $cs): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM `economy_vat_filing_rs_rows` WHERE `filing` = %i ORDER BY `partner_vat_id`, `kod`',
            $filingId,
        );

        $tableRows = [];
        foreach ($rows as $row) {
            $tableRows[] = [
                'vatId'      => (string) ($row['partner_vat_id'] ?? ''),
                'kod'        => (string) $row['kod'],
                'count'      => (string) $row['count'],
                'valueFiled' => $this->formatMoney((float) $row['value_filed']),
                'value'      => $this->formatMoney((float) $row['value']),
            ];
        }

        return ['type' => 'table', 'columns' => [
            ['id' => 'vatId',      'label' => $cs ? 'DIČ odběratele' : 'Customer VAT id'],
            ['id' => 'kod',        'label' => $cs ? 'Kód plnění' : 'Supply code'],
            ['id' => 'count',      'label' => $cs ? 'Počet' : 'Count', 'align' => 'right'],
            ['id' => 'valueFiled', 'label' => $cs ? 'Hodnota (podáno)' : 'Value (filed)', 'align' => 'right'],
            ['id' => 'value',      'label' => $cs ? 'Hodnota (přesně)' : 'Value (exact)', 'align' => 'right'],
        ], 'rows' => $tableRows];
    }

    // ── Rozdíly proti předchozímu podání ────────────────────────────────────

    /**
     * Dokladová úroveň proti `previous_filing` podle (doklad, kód, sazba):
     * přidané, odebrané, změněné. Nedotčené řádky se nevypisují — smysl
     * záložky je „co se změnilo", ne opis obsahu.
     *
     * @return array<string, mixed>
     */
    private function diffTable(int $filingId, int $previousId, bool $cs): array
    {
        $current  = $this->itemsByKey($filingId);
        $previous = $this->itemsByKey($previousId);

        $keys = array_unique(array_merge(array_keys($current), array_keys($previous)));
        sort($keys);

        $tableRows = [];
        foreach ($keys as $key) {
            $now  = $current[$key] ?? null;
            $was  = $previous[$key] ?? null;

            if ($now !== null && $was !== null) {
                $baseDelta = round((float) $now['base_dom'] - (float) $was['base_dom'], 2);
                $taxDelta  = round((float) $now['tax_dom'] - (float) $was['tax_dom'], 2);
                if (abs($baseDelta) < 0.005 && abs($taxDelta) < 0.005) {
                    continue;
                }
                $tableRows[] = [
                    'change'  => $cs ? 'změněno' : 'changed',
                    'doc'     => $this->itemLabel($now),
                    'code'    => (string) $now['vat_code'],
                    'base'    => $this->formatMoney($baseDelta),
                    'tax'     => $this->formatMoney($taxDelta),
                    '_class'  => 'error',
                ];
                continue;
            }
            if ($now !== null) {
                $tableRows[] = [
                    'change' => $cs ? 'přidáno' : 'added',
                    'doc'    => $this->itemLabel($now),
                    'code'   => (string) $now['vat_code'],
                    'base'   => $this->formatMoney((float) $now['base_dom']),
                    'tax'    => $this->formatMoney((float) $now['tax_dom']),
                ];
                continue;
            }
            $tableRows[] = [
                'change' => $cs ? 'odebráno' : 'removed',
                'doc'    => $this->itemLabel($was),
                'code'   => (string) $was['vat_code'],
                'base'   => $this->formatMoney(-(float) $was['base_dom']),
                'tax'    => $this->formatMoney(-(float) $was['tax_dom']),
            ];
        }

        if ($tableRows === []) {
            $tableRows[] = [
                'change' => $cs ? 'bez změny' : 'unchanged',
                'doc'    => $cs ? 'Obsah období je proti předchozímu podání shodný.' : 'Content matches the previous filing.',
                'code'   => '',
                'base'   => '',
                'tax'    => '',
            ];
        }

        return ['type' => 'table', 'columns' => [
            ['id' => 'change', 'label' => $cs ? 'Změna' : 'Change'],
            ['id' => 'doc',    'label' => $cs ? 'Doklad' : 'Document'],
            ['id' => 'code',   'label' => $cs ? 'Kód DPH' : 'VAT code'],
            ['id' => 'base',   'label' => $cs ? 'Základ (rozdíl)' : 'Base (delta)', 'align' => 'right'],
            ['id' => 'tax',    'label' => $cs ? 'Daň (rozdíl)' : 'Tax (delta)', 'align' => 'right'],
        ], 'rows' => $tableRows];
    }

    /** @return array<string, array<string, mixed>> klíč → řádek items */
    private function itemsByKey(int $filingId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT `doc_head`, `doc_number`, `partner_doc_number`, `vat_code`, `vat_pct`,'
            . ' `base_dom`, `tax_dom` FROM `economy_vat_filing_items` WHERE `filing` = %i',
            $filingId,
        );
        $out = [];
        foreach ($rows as $row) {
            $key = implode('|', array_map(
                static fn (string $column): string => (string) $row[$column],
                self::DIFF_KEY_COLUMNS,
            ));
            // Doklad může mít dva řádky téhož kódu i sazby (různé operace) —
            // v rozdílu je pak sečteme, klíč zůstává dokladovou úrovní.
            if (isset($out[$key])) {
                $out[$key]['base_dom'] += (float) $row['base_dom'];
                $out[$key]['tax_dom']  += (float) $row['tax_dom'];
                continue;
            }
            $out[$key] = [
                'doc_head'           => (int) $row['doc_head'],
                'doc_number'         => (string) $row['doc_number'],
                'partner_doc_number' => (string) $row['partner_doc_number'],
                'vat_code'           => (string) $row['vat_code'],
                'vat_pct'            => (float) $row['vat_pct'],
                'base_dom'           => (float) $row['base_dom'],
                'tax_dom'            => (float) $row['tax_dom'],
            ];
        }
        return $out;
    }

    /** @param array<string, mixed> $item */
    private function itemLabel(array $item): string
    {
        $number = (string) $item['doc_number'];
        if ($number === '') {
            $number = (string) $item['partner_doc_number'];
        }
        $pct = rtrim(rtrim(number_format((float) $item['vat_pct'], 2, ',', ''), '0'), ',');
        return trim($number . ' · ' . $pct . ' %');
    }

    // ── Import ze starého systému (#55 D34) ─────────────────────────────────

    /** @param array<string, mixed> $row */
    private function isImported(array $row): bool
    {
        return (string) ($row['origin'] ?? '') === FilingDocument::ORIGIN_IMPORTED;
    }

    /**
     * Původ pro přehled: odkud + kolik rozdílů proti dnešnímu sestavení.
     *
     * @param array<string, mixed> $record
     */
    private function importSummary(array $record, bool $cs): string
    {
        $rows  = 0;
        $lines = 0;
        foreach ($this->decodeJson($record['messages'] ?? null) as $message) {
            match ($message['code'] ?? '') {
                FilingImportService::MSG_ROW_MISMATCH  => $rows++,
                FilingImportService::MSG_LINE_MISMATCH => $lines++,
                default                                => null,
            };
        }
        $text = $cs ? 'Importováno ze starého Shipardu' : 'Imported from the old Shipard';
        if ($rows === 0 && $lines === 0) {
            return $text . ($cs ? ' — podané hodnoty odpovídají dnešnímu sestavení' : ' — filed values match today\'s composition');
        }
        $parts = [];
        if ($rows > 0) {
            $parts[] = ($cs ? 'rozdílné řádky přiznání: ' : 'return rows filed differently: ') . $rows;
        }
        if ($lines > 0) {
            $parts[] = ($cs ? 'rozdíly v řádcích hlášení: ' : 'statement line differences: ') . $lines;
        }
        return $text . ' — ' . implode(', ', $parts);
    }

    /**
     * Záložka Rozdíly importu: řádky přiznání s jinou podanou hodnotou
     * (per slot), atributy XML mimo dnešní formulář a rozdíly řádků
     * hlášení — všechno z `messages` `imported_*`.
     *
     * @param list<array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    private function importDiffTable(array $messages, bool $cs): array
    {
        $lang = $cs ? 'cs' : 'en';
        $rows = [];
        foreach ($messages as $message) {
            $code = (string) ($message['code'] ?? '');
            if ($code === FilingImportService::MSG_ROW_MISMATCH) {
                foreach ((array) ($message['filed'] ?? []) as $slot => $filed) {
                    $composed = (float) ($message['composed'][$slot] ?? 0.0);
                    if (abs((float) $filed - $composed) < 0.005) {
                        continue;
                    }
                    $rows[] = [
                        'what'     => ($cs ? 'ř. ' : 'row ') . (string) ($message['row'] ?? ''),
                        'field'    => self::SLOT_LABELS[(string) $slot][$lang] ?? (string) $slot,
                        'composed' => $this->formatMoney($composed),
                        'filed'    => $this->formatMoney((float) $filed),
                        'kind'     => self::LINE_KIND_LABELS['value'][$lang],
                        '_class'   => 'error',
                    ];
                }
            } elseif ($code === FilingImportService::MSG_ROW_UNMAPPED) {
                $rows[] = [
                    'what'     => (string) ($message['veta'] ?? '') . '/@' . (string) ($message['attribute'] ?? ''),
                    'field'    => $cs ? 'řádek mimo dnešní formulář' : 'row outside the current form',
                    'composed' => '',
                    'filed'    => (string) ($message['value'] ?? ''),
                    'kind'     => $cs ? 'nemapováno' : 'unmapped',
                ];
            } elseif ($code === FilingImportService::MSG_LINE_MISMATCH) {
                $rows[] = [
                    'what'     => trim((string) ($message['section'] ?? '') . ' ' . (string) ($message['key'] ?? '')),
                    'field'    => (string) ($message['field'] ?? ''),
                    'composed' => (string) ($message['composed'] ?? '—'),
                    'filed'    => (string) ($message['filed'] ?? '—'),
                    'kind'     => self::LINE_KIND_LABELS[(string) ($message['kind'] ?? '')][$lang] ?? (string) ($message['kind'] ?? ''),
                    '_class'   => 'error',
                ];
            }
        }
        if ($rows === []) {
            $rows[] = [
                'what'     => $cs ? 'Podané hodnoty odpovídají dnešnímu sestavení.' : 'Filed values match today\'s composition.',
                'field'    => '',
                'composed' => '',
                'filed'    => '',
                'kind'     => '',
            ];
        }
        return ['type' => 'table', 'columns' => [
            ['id' => 'what',     'label' => $cs ? 'Řádek' : 'Row'],
            ['id' => 'field',    'label' => $cs ? 'Sloupec / pole' : 'Column / field'],
            ['id' => 'composed', 'label' => $cs ? 'Sestaveno' : 'Composed', 'align' => 'right'],
            ['id' => 'filed',    'label' => $cs ? 'Podáno' : 'Filed', 'align' => 'right'],
            ['id' => 'kind',     'label' => $cs ? 'Druh rozdílu' : 'Difference'],
        ], 'rows' => $rows];
    }

    // ── Zprávy ──────────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    private function messagesTable(array $messages, bool $cs): array
    {
        $rows = [];
        foreach ($messages as $message) {
            $code = (string) ($message['code'] ?? '');
            $detail = $message;
            unset($detail['code']);
            $rows[] = [
                'code'   => $code,
                'detail' => $detail === []
                    ? ''
                    : (string) json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }
        return ['type' => 'table', 'columns' => [
            ['id' => 'code',   'label' => $cs ? 'Kód' : 'Code'],
            ['id' => 'detail', 'label' => $cs ? 'Podrobnosti' : 'Details'],
        ], 'rows' => $rows];
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $result */
    private function resultSummary(string $type, array $result, bool $cs): string
    {
        if ($result === []) {
            return $cs ? 'nesestaveno' : 'not composed';
        }
        if (!empty($result['isEmpty'])) {
            return $cs ? 'prázdné podání' : 'empty filing';
        }

        if ($type === 'return') {
            $row66 = (float) ($result['return']['row66'] ?? 0.0);
            if (abs($row66) >= 0.005) {
                return ($cs ? 'ř. 66 ' : 'row 66 ') . $this->formatMoney($row66);
            }
            $row64 = (float) ($result['return']['row64'] ?? 0.0);
            if ($row64 > 0.005) {
                return ($cs ? 'vlastní daň ' : 'tax liability ') . $this->formatMoney($row64);
            }
            $row65 = (float) ($result['return']['row65'] ?? 0.0);
            if ($row65 > 0.005) {
                return ($cs ? 'nadměrný odpočet ' : 'excess deduction ') . $this->formatMoney($row65);
            }
            return $cs ? 'nulová povinnost' : 'zero position';
        }

        if ($type === 'cs') {
            $parts = [];
            foreach ((array) ($result['cs']['sections'] ?? []) as $section => $summary) {
                $parts[] = $section . ' ' . (int) ($summary['rows'] ?? 0);
            }
            return $parts === [] ? ($cs ? 'bez řádků' : 'no rows') : implode(' · ', $parts);
        }

        return sprintf(
            $cs ? '%d plnění · %s' : '%d supplies · %s',
            (int) ($result['rs']['supplies'] ?? 0),
            $this->formatMoney((float) ($result['rs']['valueFiled'] ?? 0.0)),
        );
    }

    private function returnRowShortLabel(int $row, bool $cs): string
    {
        return match ($row) {
            62      => $cs ? 'daň na výstupu' : 'output tax',
            63      => $cs ? 'odpočet' : 'deduction',
            64      => $cs ? 'vlastní daň' : 'tax liability',
            65      => $cs ? 'nadměrný odpočet' : 'excess deduction',
            default => $cs ? 'změna daňové povinnosti' : 'change of tax liability',
        };
    }

    private function docStates(): DocStateConfig
    {
        return DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
    }

    /**
     * Účetní doklad přiznání z `acc_document`: id, popisek (číslo dokladu,
     * u konceptu #id), název stavu a zda „žije" (mimo Storno/Smazáno).
     * Null = podání účetní doklad nemá (nebo je FK visící).
     *
     * @param array<string, mixed>|\Dibi\Row $record
     * @return ?array{id: int, label: string, state: string, live: bool}
     */
    private function accountingDocument($record): ?array
    {
        $docId = (int) ($record['acc_document'] ?? 0);
        if ($docId <= 0) {
            return null;
        }
        $head = $this->db->fetchRow(
            'SELECT `id`, `doc_number`, `docState` FROM `docs_core_heads` WHERE `id` = %i',
            $docId,
        );
        if ($head === null) {
            return null;
        }
        $state  = (int) $head['docState'];
        $cfg    = DocStateConfig::fromCfgItem($this->config?->cfgItem('docs.core.docStates'));
        $number = (string) ($head['doc_number'] ?? '');
        return [
            'id'    => $docId,
            'label' => $number !== '' ? $number : "#{$docId}",
            'state' => (string) ($cfg->getState($state)['stateName'] ?? $state),
            'live'  => !in_array($state, FilingDocument::ACC_DOCUMENT_DEAD_STATES, true),
        ];
    }

    /** @return array<string, string> */
    private function typeLabels(): array
    {
        return $this->enumLabels('economy.vat.reportTypes');
    }

    /** @return array<string, string> */
    private function kindLabels(): array
    {
        return $this->enumLabels('economy.vat.filingKinds');
    }

    /** @return array<string, string> */
    private function enumLabels(string $cfgItem): array
    {
        $cfgData = $this->config?->cfgItem($cfgItem);
        if (!is_array($cfgData)) {
            return [];
        }
        $labels = [];
        foreach (EnumOptionsHelper::fromCfgData($cfgData, 'enumString', $cfgItem) as $option) {
            $labels[(string) $option['value']] = (string) $option['label'];
        }
        return $labels;
    }

    /** @return array<int, array<string, mixed>> */
    private function periods(): array
    {
        if ($this->periods === null) {
            $this->periods = [];
            $rows = $this->db->fetchAll(
                'SELECT `id`, `name`, `report_type`, `date_begin`, `date_end`'
                . ' FROM `economy_vat_report_periods`',
            );
            foreach ($rows as $row) {
                $this->periods[(int) $row['id']] = $row;
            }
        }
        return $this->periods;
    }

    /** @param array<string, mixed> $period */
    private function periodLabel($period): string
    {
        return (string) $period['name'] . ' (' . $this->formatDate($period['date_begin'] ?? null)
            . ' – ' . $this->formatDate($period['date_end'] ?? null) . ')';
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }

    private function formatNonZeroMoney(float $value): string
    {
        return abs($value) < 0.005 ? '' : $this->formatMoney($value);
    }

    private function formatDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return substr((string) $value, 0, 10);
    }
}
