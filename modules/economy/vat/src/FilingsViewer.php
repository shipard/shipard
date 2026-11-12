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
    private const LABELS_CFG_ITEM = 'economy.vat.viewerDetailLabels';

    protected ?string $docStatesCfgItem = 'economy.vat.docStatesFilings';

    private const STATE_SPAN_CLASS = [
        'concept' => 'warning',
        'done'    => 'success',
        'trash'   => 'muted',
    ];

    /** Klíč porovnání dokladové úrovně mezi podáními. */
    private const DIFF_KEY_COLUMNS = ['doc_head', 'vat_code', 'vat_pct'];

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
        $type = (string) ($rowData['report_type'] ?? '');

        $row = [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['name'] ?? ''),
            'i1' => $this->resultSummary($type, $this->decodeJson($rowData['result'] ?? null)),
        ];

        $t2 = [];
        $t2[] = ['text' => $this->typeLabels()[$type] ?? $type, 'class' => 'muted'];
        $t2[] = ['text' => $this->kindLabels()[(string) $rowData['filing_kind']] ?? (string) $rowData['filing_kind']];
        if ($this->isImported($rowData)) {
            $t2[] = [
                'text'  => $this->detailText(self::LABELS_CFG_ITEM, 'importBadge', 'Import'),
                'badge' => 'neutral',
            ];
        }

        $filed = ($this->formatDate($rowData['date_filed'] ?? null) ?? '');
        $t2[] = ['text' => $filed !== ''
            ? $this->text('rowFiled', 'filed {date}', ['date' => $filed])
            : $this->text('rowComposed', 'composed {date}', ['date' => $this->formatDate($rowData['date_issue'] ?? null) ?? ''])];

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

    /**
     * Taby: Přehled, Výstupní řádky podle typu, Hlavička podání (podle
     * `_schema` uložené hodnoty), Rozdíly proti předchozímu podání,
     * Rozdíly importu a Zprávy.
     */
    protected function detailTabs(array $record): array
    {
        $recordId = (int) $record['id'];
        $type = (string) ($record['report_type'] ?? '');

        $tabs = [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => ['type' => 'properties', 'groups' => $this->overviewGroups($record, $type)],
        ]];

        $rowsContent = match ($type) {
            'return' => $this->returnRowsTable($recordId),
            'cs'     => $this->controlStatementRowsTable($recordId),
            'rs'     => $this->recapitulativeRowsTable($recordId),
            default  => null,
        };
        if ($rowsContent !== null) {
            $tabs[] = [
                'id'      => 'rows',
                'label'   => $this->detailTabLabel(self::LABELS_CFG_ITEM, 'outputRows', 'Output rows'),
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
                'label'   => $this->detailTabLabel(self::LABELS_CFG_ITEM, 'header', 'Header'),
                'content' => ['type' => 'properties', 'groups' => $headerGroups],
            ];
        }

        $previousId = (int) ($record['previous_filing'] ?? 0);
        if ($previousId > 0) {
            $tabs[] = [
                'id'      => 'diff',
                'label'   => $this->detailTabLabel(self::LABELS_CFG_ITEM, 'differences', 'Differences'),
                'content' => $this->diffTable($recordId, $previousId),
            ];
        }

        $messages = $this->decodeJson($record['messages'] ?? null);
        $imported = $this->isImported((array) $record);
        // Importované podání (#55 D34): co Shipard sestavil dnes vs. co
        // bylo doopravdy podáno — historický záznam, ne stav k řešení.
        if ($imported) {
            $tabs[] = [
                'id'      => 'import',
                'label'   => $this->detailTabLabel(self::LABELS_CFG_ITEM, 'importDifferences', 'Import differences'),
                'content' => $this->importDiffTable($messages),
            ];
        }
        if ($messages !== []) {
            $tabs[] = [
                'id'      => 'messages',
                'label'   => $this->detailTabLabel(self::LABELS_CFG_ITEM, 'messages', 'Messages'),
                'content' => $this->messagesTable($messages),
            ];
        }

        return $tabs;
    }

    /**
     * Akce podání: přepočet a hlavička z profilu jen u konceptu,
     * zaúčtování a zámek tvrzení po podání, soubory pro daňový portál.
     */
    protected function detailActions(array $record): array
    {
        $recordId = (int) $record['id'];
        $type     = (string) ($record['report_type'] ?? '');
        $messages = $this->decodeJson($record['messages'] ?? null);
        $imported = $this->isImported((array) $record);
        $docState = (int) ($record['docState'] ?? 0);
        $actions  = [];

        // Přepočet je smysluplný jen u konceptu — podané podání je záznam
        // o tom, co odešlo, a composer ho odmítne. Hlavičku přepočet nechává
        // být (ruční úpravy), proto je obnova z profilu zvláštní akce
        // (#55 F3-5). Importované podání nemá co přepočítat — snapshot
        // i hlavička zrcadlí to, co bylo podáno.
        if ($docState === FilingDocument::DOC_STATE_COMPOSED && !$imported) {
            $actions = [
                [
                    'id'      => 'recomposeFiling',
                    'label'   => $this->text('recompose', 'Recompose'),
                    'variant' => 'secondary',
                ],
                [
                    'id'      => 'reloadFilingHeader',
                    'label'   => $this->text('loadHeaderFromProfile', 'Load header from profile'),
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
            $actions[] = [
                'id'      => 'accountFiling',
                'label'   => $accDoc === null
                    ? $this->text('postToAccounting', 'Post to accounting')
                    : $this->text('postAgain', 'Post again'),
                'variant' => 'primary',
            ];
        }
        if ($accDoc !== null && $accDoc['live']) {
            $actions[] = [
                'id'       => 'openAccountingDocument',
                'label'    => $this->text('accountingDocument', 'Accounting document {number}', ['number' => $accDoc['label']]),
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
                $actions[] = ReportPeriodsViewer::lockAction(
                    $periodId,
                    $canAccount ? 'secondary' : 'primary',
                    $this->text('lockPeriod', 'Lock period'),
                );
            }
        }

        // Soubory pro daňový portál (#55 X6): u konceptu si je lze vyrobit
        // dopředu (a opakovaně), u podaného podání jen doplnit, když
        // automatické vytvoření při podání selhalo. Hotové soubory jsou
        // v záložce Přílohy, odkud se i stahují. Importované podání má
        // původní soubory — generátor ho odmítne, akce se nenabízí.
        if ($docState !== FilingDocument::DOC_STATE_CANCELLED && !$imported) {
            $actions[] = [
                'id'      => 'generateFilingFiles',
                'label'   => $this->text('createFiles', 'Create files'),
                'variant' => 'secondary',
            ];
        }

        return $actions;
    }

    public function getFilters(): array
    {
        $all = $this->sharedText('all', 'All');

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
                'label'   => $this->columnLabel('report_type', 'Report type'),
                'type'    => 'enum',
                'default' => 'all',
                'options' => $typeOptions,
            ],
            [
                'id'      => 'filing_kind',
                'label'   => $this->columnLabel('filing_kind', 'Filing kind'),
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
                'label'   => $this->text('year', 'Year'),
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
    private function overviewGroups($record, string $type): array
    {
        $periods    = $this->periods();
        $periodId   = (int) ($record['report_period'] ?? 0);
        $period     = $periods[$periodId] ?? null;
        $previousId = (int) ($record['previous_filing'] ?? 0);

        $items = [
            ['label' => $this->columnLabel('report_period', 'Report period'),
             'value' => $period !== null ? $this->periodLabel($period) : ('#' . $periodId)],
            ['label' => $this->columnLabel('report_type', 'Report type'), 'value' => $this->typeLabels()[$type] ?? $type],
            ['label' => $this->columnLabel('filing_kind', 'Filing kind'),
             'value' => $this->kindLabels()[(string) $record['filing_kind']] ?? (string) $record['filing_kind']],
            ['label' => $this->text('sequenceInPeriod', 'Sequence'), 'value' => (string) ($record['sequence'] ?? '')],
            ['label' => $this->columnLabel('date_issue', 'Composed on'), 'value' => ($this->formatDate($record['date_issue'] ?? null) ?? '')],
        ];
        if ($this->isImported((array) $record)) {
            $items[] = ['label' => $this->columnLabel('origin', 'Origin'), 'value' => $this->importSummary((array) $record)];
        }
        $filed = ($this->formatDate($record['date_filed'] ?? null) ?? '');
        $items[] = ['label' => $this->columnLabel('date_filed', 'Filed on'), 'value' => $filed !== '' ? $filed : '—'];

        $found = ($this->formatDate($record['date_found'] ?? null) ?? '');
        if ($found !== '') {
            $items[] = ['label' => $this->columnLabel('date_found', 'Reasons found on'), 'value' => $found];
        }
        if ($previousId > 0) {
            $previousName = (string) $this->db->fetchSingle(
                'SELECT `name` FROM `' . $this->table . '` WHERE `id` = %i', $previousId,
            );
            $items[] = ['label' => $this->columnLabel('previous_filing', 'Previous filing'), 'value' => $previousName];
        }
        if (!empty($record['note'])) {
            $items[] = ['label' => $this->columnLabel('note', 'Note'), 'value' => (string) $record['note']];
        }
        // Zaúčtování přiznání (#55 F4b) — jen u podaného přiznání; hlášení
        // se neúčtují, koncept ještě nemá co účtovat.
        if ($type === 'return' && (int) ($record['docState'] ?? 0) === FilingDocument::DOC_STATE_FILED) {
            $accDoc = $this->accountingDocument($record);
            $items[] = [
                'label' => $this->text('accounting', 'Accounting'),
                'value' => match (true) {
                    $accDoc === null => $this->text('notPosted', 'Not posted'),
                    $accDoc['live']  => $this->text('accountingDocumentState', 'Accounting document {number} ({state})', ['number' => $accDoc['label'], 'state' => $accDoc['state']]),
                    default          => $this->text('documentCancelled', 'Document {number} is cancelled — post again', ['number' => $accDoc['label']]),
                },
            ];
        }

        $groups = [[
            'title' => $this->text('groupFiling', 'Filing'),
            'items' => $items,
        ]];

        $result = $this->decodeJson($record['result'] ?? null);
        if ($result === []) {
            $groups[] = [
                'title' => $this->text('groupResult', 'Result'),
                'items' => [[
                    'label' => $this->text('snapshot', 'Snapshot'),
                    'value' => $this->text('notComposedHint', 'Not composed — use Recompose.'),
                ]],
            ];
            return $groups;
        }

        $groups[] = ['title' => $this->text('groupResult', 'Result'), 'items' => $this->resultItems($type, $result)];
        return $groups;
    }

    /**
     * @param array<string, mixed> $result
     * @return list<array{label: string, value: string}>
     */
    private function resultItems(string $type, array $result): array
    {
        $items = [];
        if (!empty($result['isEmpty'])) {
            $items[] = [
                'label' => $this->text('content', 'Content'),
                'value' => $this->text('emptyFiling', 'Empty filing'),
            ];
        }
        $items[] = [
            'label' => $this->text('documentsAndRows', 'Documents / rows'),
            'value' => (string) ($result['docCount'] ?? 0) . ' / ' . (string) ($result['itemCount'] ?? 0),
        ];

        if ($type === 'return') {
            foreach ([62, 63, 64, 65, 66] as $row) {
                $value = (float) ($result['return']['row' . $row] ?? 0.0);
                if ($row === 66 && abs($value) < 0.005) {
                    continue;
                }
                $items[] = [
                    'label' => $this->text('resultRow', 'Row {row} — {label}', ['row' => $row, 'label' => $this->returnRowShortLabel($row)]),
                    'value' => $this->formatMoney($value),
                ];
            }
        } elseif ($type === 'cs') {
            foreach ((array) ($result['cs']['sections'] ?? []) as $section => $summary) {
                $items[] = [
                    'label' => $this->text('resultSection', 'Section {section}', ['section' => $section]),
                    'value' => $this->text('sectionSummary', 'Rows: {count} · base {base} · tax {tax}', [
                        'count' => (int) ($summary['rows'] ?? 0),
                        'base'  => $this->formatMoney((float) ($summary['base'] ?? 0.0)),
                        'tax'   => $this->formatMoney((float) ($summary['tax'] ?? 0.0)),
                    ]),
                ];
            }
        } elseif ($type === 'rs') {
            $items[] = [
                'label' => $this->text('supplies', 'Supplies'),
                'value' => (string) ($result['rs']['supplies'] ?? 0) . ' · '
                    . $this->formatMoney((float) ($result['rs']['valueFiled'] ?? 0.0)),
            ];
        }

        if (isset($result['crossCheck'])) {
            $differences = (int) ($result['crossCheck']['differences'] ?? 0);
            $items[] = [
                'label' => $this->text('crossCheck', 'Cross-check'),
                'value' => $differences === 0
                    ? ($this->text('matchesJournal', 'Matches the journal'))
                    : $this->text('journalDifferences', 'Differences against the journal: {count}', ['count' => $differences]),
            ];
        }
        return $items;
    }

    // ── Výstupní řádky ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function returnRowsTable(int $filingId): array
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
            ['id' => 'row',       'label' => $this->text('colRow', 'Row')],
            ['id' => 'label',     'label' => $this->text('colDescription', 'Description')],
            ['id' => 'base',      'label' => $this->text('colBaseFiled', 'Base (filed)'), 'align' => 'right'],
            ['id' => 'tax',       'label' => $this->text('colTaxFiled', 'Tax (filed)'), 'align' => 'right'],
            ['id' => 'reduced',   'label' => $this->text('reduced', 'Reduced'), 'align' => 'right'],
            ['id' => 'baseExact', 'label' => $this->text('colBaseExact', 'Base (exact)'), 'align' => 'right'],
            ['id' => 'taxExact',  'label' => $this->text('colTaxExact', 'Tax (exact)'), 'align' => 'right'],
        ], 'rows' => $tableRows];
    }

    /** @return array<string, mixed> */
    private function controlStatementRowsTable(int $filingId): array
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
                'dppd'      => ($this->formatDate($row['vat_dppd'] ?? null) ?? ''),
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
            ['id' => 'section', 'label' => $this->text('colSection', 'Section')],
            ['id' => 'evid',    'label' => $this->text('colEvidenceNumber', 'Evidence no.')],
            ['id' => 'vatId',   'label' => $this->detailText(self::LABELS_CFG_ITEM, 'colVatId', 'VAT ID')],
            ['id' => 'dppd',    'label' => $this->detailText(self::LABELS_CFG_ITEM, 'colDppd', 'DPPD')],
            ['id' => 'kod',     'label' => $this->text('colReverseChargeCode', 'RC code')],
            ['id' => 'base',    'label' => $this->text('base', 'Base'), 'align' => 'right'],
            ['id' => 'tax',     'label' => $this->text('tax', 'Tax'), 'align' => 'right'],
        ], 'rows' => $tableRows];
    }

    /** @return array<string, mixed> */
    private function recapitulativeRowsTable(int $filingId): array
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
            ['id' => 'vatId',      'label' => $this->text('colCustomerVatId', 'Customer VAT id')],
            ['id' => 'kod',        'label' => $this->text('colSupplyCode', 'Supply code')],
            ['id' => 'count',      'label' => $this->text('colCount', 'Count'), 'align' => 'right'],
            ['id' => 'valueFiled', 'label' => $this->text('colValueFiled', 'Value (filed)'), 'align' => 'right'],
            ['id' => 'value',      'label' => $this->text('colValueExact', 'Value (exact)'), 'align' => 'right'],
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
    private function diffTable(int $filingId, int $previousId): array
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
                    'change'  => $this->text('changeChanged', 'changed'),
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
                    'change' => $this->text('changeAdded', 'added'),
                    'doc'    => $this->itemLabel($now),
                    'code'   => (string) $now['vat_code'],
                    'base'   => $this->formatMoney((float) $now['base_dom']),
                    'tax'    => $this->formatMoney((float) $now['tax_dom']),
                ];
                continue;
            }
            $tableRows[] = [
                'change' => $this->text('changeRemoved', 'removed'),
                'doc'    => $this->itemLabel($was),
                'code'   => (string) $was['vat_code'],
                'base'   => $this->formatMoney(-(float) $was['base_dom']),
                'tax'    => $this->formatMoney(-(float) $was['tax_dom']),
            ];
        }

        if ($tableRows === []) {
            $tableRows[] = [
                'change' => $this->text('changeNone', 'unchanged'),
                'doc'    => $this->text('contentMatchesPrevious', 'Content matches the previous filing.'),
                'code'   => '',
                'base'   => '',
                'tax'    => '',
            ];
        }

        return ['type' => 'table', 'columns' => [
            ['id' => 'change', 'label' => $this->text('colChange', 'Change')],
            ['id' => 'doc',    'label' => $this->text('colDocument', 'Document')],
            ['id' => 'code',   'label' => $this->text('colVatCode', 'VAT code')],
            ['id' => 'base',   'label' => $this->text('colBaseDelta', 'Base (delta)'), 'align' => 'right'],
            ['id' => 'tax',    'label' => $this->text('colTaxDelta', 'Tax (delta)'), 'align' => 'right'],
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
    private function importSummary(array $record): string
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
        if ($rows === 0 && $lines === 0) {
            return $this->text('importSummaryMatches', 'Imported from the old Shipard — filed values match today\'s composition');
        }
        $parts = [];
        if ($rows > 0) {
            $parts[] = $this->text('importReturnRowDifferences', 'return rows filed differently: {count}', ['count' => $rows]);
        }
        if ($lines > 0) {
            $parts[] = $this->text('importLineDifferences', 'statement line differences: {count}', ['count' => $lines]);
        }
        return $this->text('importSummaryDifferences', 'Imported from the old Shipard — {differences}', ['differences' => implode(', ', $parts)]);
    }

    /**
     * Záložka Rozdíly importu: řádky přiznání s jinou podanou hodnotou
     * (per slot), atributy XML mimo dnešní formulář a rozdíly řádků
     * hlášení — všechno z `messages` `imported_*`.
     *
     * @param list<array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    private function importDiffTable(array $messages): array
    {
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
                        'what'     => $this->text('importRow', 'row {row}', ['row' => (string) ($message['row'] ?? '')]),
                        'field'    => $this->slotLabel((string) $slot),
                        'composed' => $this->formatMoney($composed),
                        'filed'    => $this->formatMoney((float) $filed),
                        'kind'     => $this->lineKindLabel('value'),
                        '_class'   => 'error',
                    ];
                }
            } elseif ($code === FilingImportService::MSG_ROW_UNMAPPED) {
                $rows[] = [
                    'what'     => (string) ($message['veta'] ?? '') . '/@' . (string) ($message['attribute'] ?? ''),
                    'field'    => $this->text('rowOutsideForm', 'row outside the current form'),
                    'composed' => '',
                    'filed'    => (string) ($message['value'] ?? ''),
                    'kind'     => $this->text('unmapped', 'unmapped'),
                ];
            } elseif ($code === FilingImportService::MSG_LINE_MISMATCH) {
                $rows[] = [
                    'what'     => trim((string) ($message['section'] ?? '') . ' ' . (string) ($message['key'] ?? '')),
                    'field'    => (string) ($message['field'] ?? ''),
                    'composed' => (string) ($message['composed'] ?? '—'),
                    'filed'    => (string) ($message['filed'] ?? '—'),
                    'kind'     => $this->lineKindLabel((string) ($message['kind'] ?? '')),
                    '_class'   => 'error',
                ];
            }
        }
        if ($rows === []) {
            $rows[] = [
                'what'     => $this->text('importValuesMatch', 'Filed values match today\'s composition.'),
                'field'    => '',
                'composed' => '',
                'filed'    => '',
                'kind'     => '',
            ];
        }
        return ['type' => 'table', 'columns' => [
            ['id' => 'what',     'label' => $this->text('colImportRow', 'Row')],
            ['id' => 'field',    'label' => $this->text('colField', 'Column / field')],
            ['id' => 'composed', 'label' => $this->text('colComposed', 'Composed'), 'align' => 'right'],
            ['id' => 'filed',    'label' => $this->text('colFiled', 'Filed'), 'align' => 'right'],
            ['id' => 'kind',     'label' => $this->text('colDifferenceKind', 'Difference')],
        ], 'rows' => $rows];
    }

    // ── Zprávy ──────────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    private function messagesTable(array $messages): array
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
            ['id' => 'code',   'label' => $this->text('colCode', 'Code')],
            ['id' => 'detail', 'label' => $this->text('colDetails', 'Details')],
        ], 'rows' => $rows];
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $result */
    private function resultSummary(string $type, array $result): string
    {
        if ($result === []) {
            return $this->text('summaryNotComposed', 'not composed');
        }
        if (!empty($result['isEmpty'])) {
            return $this->text('summaryEmptyFiling', 'empty filing');
        }

        if ($type === 'return') {
            $row66 = (float) ($result['return']['row66'] ?? 0.0);
            if (abs($row66) >= 0.005) {
                return $this->text('summaryRow66', 'row 66 {amount}', ['amount' => $this->formatMoney($row66)]);
            }
            $row64 = (float) ($result['return']['row64'] ?? 0.0);
            if ($row64 > 0.005) {
                return $this->text('summaryTaxLiability', 'tax liability {amount}', ['amount' => $this->formatMoney($row64)]);
            }
            $row65 = (float) ($result['return']['row65'] ?? 0.0);
            if ($row65 > 0.005) {
                return $this->text('summaryExcessDeduction', 'excess deduction {amount}', ['amount' => $this->formatMoney($row65)]);
            }
            return $this->text('summaryZeroPosition', 'zero position');
        }

        if ($type === 'cs') {
            $parts = [];
            foreach ((array) ($result['cs']['sections'] ?? []) as $section => $summary) {
                $parts[] = $section . ' ' . (int) ($summary['rows'] ?? 0);
            }
            return $parts === [] ? $this->text('summaryNoRows', 'no rows') : implode(' · ', $parts);
        }

        return $this->text('summarySupplies', 'Supplies: {count} · {value}', [
            'count' => (int) ($result['rs']['supplies'] ?? 0),
            'value' => $this->formatMoney((float) ($result['rs']['valueFiled'] ?? 0.0)),
        ]);
    }

    /** Popisek slotu řádku přiznání v záložce Rozdíly importu. */
    private function slotLabel(string $slot): string
    {
        return match ($slot) {
            'base'    => $this->text('base', 'Base'),
            'full'    => $this->text('slotFull', 'Full'),
            'reduced' => $this->text('reduced', 'Reduced'),
            default   => $slot,
        };
    }

    /** Popisek druhu rozdílu řádku hlášení (`EpoXmlLineComparer`). */
    private function lineKindLabel(string $kind): string
    {
        return match ($kind) {
            'value'   => $this->text('lineKindValue', 'value'),
            'missing' => $this->text('lineKindMissing', 'filed only'),
            'extra'   => $this->text('lineKindExtra', 'composed only'),
            default   => $kind,
        };
    }

    /**
     * Text vieweru z katalogu `economy.vat.viewerDetailLabels`, sekce `texts`.
     *
     * @param array<string, scalar> $params
     */
    private function text(string $key, string $englishFallback, array $params = []): string
    {
        return $this->detailText(self::LABELS_CFG_ITEM, $key, $englishFallback, $params);
    }

    private function returnRowShortLabel(int $row): string
    {
        return match ($row) {
            62      => $this->text('rowOutputTax', 'output tax'),
            63      => $this->text('rowDeduction', 'deduction'),
            64      => $this->text('rowTaxLiability', 'tax liability'),
            65      => $this->text('rowExcessDeduction', 'excess deduction'),
            default => $this->text('rowLiabilityChange', 'change of tax liability'),
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
        return (string) $period['name'] . ' (' . ($this->formatDate($period['date_begin'] ?? null) ?? '')
            . ' – ' . ($this->formatDate($period['date_end'] ?? null) ?? '') . ')';
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
}
