<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\SubtableCellFormatter;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Core\Viewer\TableViewer;

/**
 * Viewer instancí daňových tvrzení (`economy_vat_report_periods`). Filtry:
 * typ tvrzení, registrace (jen při více než jedné), rok začátku období.
 *
 * Detail instance nese seznam podání za tvrzení a akci **Sestavit podání**
 * (#55 D20) — odsud vede cesta od živého výpočtu k trvalému záznamu.
 */
class ReportPeriodsViewer extends TableViewer
{
    private const LABELS_CFG_ITEM = 'economy.vat.viewerDetailLabels';

    /** Mezera + symbol domácí měny za zůstatkem; null = ještě nenačteno. */
    private ?string $moneySuffix = null;

    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    private const STATE_SPAN_CLASS = [
        'concept'   => 'warning',
        'confirmed' => 'primary',
        'done'      => 'success',
        'edit'      => 'warning',
        'archive'   => 'muted',
        'trash'     => 'muted',
        'cancelled' => 'danger',
    ];

    /** @var ?array<int, string> */
    private ?array $registrationNames = null;

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT `id`, `vat_registration`, `report_type`, `name`,'
            . ' `date_begin`, `date_end`, `locked`, `docState`, `docStateMain`'
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
                case 'vat_registration':
                    if (is_numeric($value) && (int) $value > 0) {
                        $conditions[] = '`vat_registration` = %i';
                        $params[] = (int) $value;
                    }
                    break;
                case 'year':
                    if (is_numeric($value) && (int) $value > 0) {
                        $conditions[] = 'YEAR(`date_begin`) = %i';
                        $params[] = (int) $value;
                    }
                    break;
            }
        }

        if ($viewGroup !== 'all') {
            [$vgSql, $vgParams] = $this->buildViewGroupFilter($this->docStatesCfgItem, $viewGroup);
            if ($vgSql !== '') {
                $conditions[] = str_replace('`docState', '`docState', $vgSql);
                $params = array_merge($params, $vgParams);
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(['name'], $search);
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY `date_begin` DESC, `report_type` ASC, `id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $typeLabels = $this->resolveTypeLabels();
        $type = (string) ($rowData['report_type'] ?? '');

        $row = [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['name'] ?? ''),
            'i1' => $typeLabels[$type] ?? $type,
        ];

        $t2 = [];
        $range = $this->formatDate($rowData['date_begin'] ?? null) . ' – ' . $this->formatDate($rowData['date_end'] ?? null);
        $t2[] = ['text' => $range];

        $registrations = $this->registrationNames();
        if (count($registrations) > 1) {
            $regId = (int) ($rowData['vat_registration'] ?? 0);
            $t2[] = ['text' => $registrations[$regId] ?? ('#' . $regId), 'class' => 'muted'];
        }

        if (!empty($rowData['locked'])) {
            $t2[] = ['text' => $this->columnLabel('locked', 'Locked'), 'class' => 'warning', 'icon' => 'lock'];
        }

        $docState = (int) ($rowData['docState'] ?? 10);
        $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
        $stateData = $cfg->getState($docState);
        $stateStyle = $stateData['stateStyle'] ?? 'concept';
        if ($docState !== 40) {
            $t2[] = [
                'text'  => $stateData['stateName'] ?? '',
                'class' => self::STATE_SPAN_CLASS[$stateStyle] ?? 'muted',
            ];
        }

        $row['t2'] = $t2;
        $row['stateStyle'] = $stateStyle;
        return $row;
    }

    /** Přehled: vlastnosti tvrzení, zůstatky DPH po podání a tabulka podání. */
    protected function detailTabs(array $record): array
    {
        $recordId = (int) $record['id'];
        $typeLabels = $this->resolveTypeLabels();
        $type = (string) ($record['report_type'] ?? '');
        $registrations = $this->registrationNames();

        $locked = !empty($record['locked']);
        // Název nese titulek hlavičky — Přehled ho neopakuje (#140 D26).
        $items = [
            ['label' => $this->columnLabel('report_type', 'Report type'), 'value' => $typeLabels[$type] ?? $type],
            [
                'label' => $this->columnLabel('vat_registration', 'VAT registration'),
                'value' => $registrations[(int) ($record['vat_registration'] ?? 0)] ?? '',
            ],
            ['label' => $this->columnLabel('date_begin', 'Begin date'), 'value' => $this->formatDate($record['date_begin'] ?? null)],
            ['label' => $this->columnLabel('date_end', 'End date'), 'value' => $this->formatDate($record['date_end'] ?? null)],
            [
                'label' => $this->columnLabel('locked', 'Locked'),
                'value' => $locked ? $this->lockLabel($record) : $this->yesNoText(false),
            ],
        ];

        $column = ReportPeriodDocument::HEAD_COLUMN_BY_TYPE[$type] ?? null;
        if ($column !== null) {
            $count = (int) $this->db->fetchSingle(
                'SELECT COUNT(*) FROM `docs_core_heads` WHERE %n = %i AND `docState` != 90',
                $column, $recordId,
            );
            $items[] = ['label' => $this->text('assignedDocuments', 'Assigned documents'), 'value' => (string) $count];
        }

        $groups = [['title' => $this->text('groupReportPeriod', 'Report period'), 'items' => $items]];

        $filings = $this->filings($recordId);

        // Zůstatky DPH (#55 D31): jen u přiznání s podaným podáním — Σ deníku
        // na 343 analytikách (mimo 801/802) přes doklady instance musí být
        // nula; nenulový = chybí zaúčtování přiznání, nebo se DPH po podání
        // změnila.
        if ($type === VatPeriodAssigner::TYPE_RETURN && $this->hasFiledFiling($filings)) {
            $groups[] = [
                'title' => $this->text('groupVatBalances', 'VAT balances'),
                'items' => $this->balanceItems($recordId),
            ];
        }

        $blocks = [['type' => 'properties', 'groups' => $groups]];
        if ($filings !== []) {
            $blocks[] = $this->filingsTable($filings);
        }

        // Víc bloků = `composite` (do #137 task 3 šel holý seznam bloků bez
        // typu a frontend Přehled nekreslil; odhalil kontrakt typů obsahu).
        return [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => count($blocks) === 1 ? $blocks[0] : ['type' => 'composite', 'blocks' => $blocks],
        ]];
    }

    /**
     * Akce instance: „Sestavit podání" otevře formulář podání
     * s předvyplněnou instancí (povolené druhy dopočítá formulář z typu
     * tvrzení; sestavit lze i nad zamčenou instancí — snapshot je nad
     * neměnnými daty, D25), zámek instance a odkaz na podání.
     */
    protected function detailActions(array $record): array
    {
        $recordId = (int) $record['id'];
        $locked   = !empty($record['locked']);
        $filings  = $this->filings($recordId);
        $actions  = [];

        if ((int) ($record['docState'] ?? 0) !== 90) {
            $actions[] = [
                'id'      => 'composeFiling',
                'label'   => $this->text('composeFiling', 'Compose filing'),
                'variant' => 'primary',
                'kind'    => 'open_form',
                'target'  => [
                    'table'  => 'economy_vat_filings',
                    'preset' => ['report_period' => $recordId],
                ],
            ];
            // Zámek instance (#55 D25): po podání „jeden klik"; odemknutí je
            // vědomý krok před dodatečným podáním, proto potvrzení.
            $actions[] = $locked
                ? self::unlockAction(
                    $recordId,
                    $this->text('unlock', 'Unlock'),
                    $this->text(
                        'unlockConfirm',
                        'Unlock the report period? Documents of the filed period can be changed again — unlocking is'
                        . ' the step before an additional or corrective filing.',
                    ),
                )
                : self::lockAction(
                    $recordId,
                    $this->hasFiledFiling($filings) ? 'primary' : 'secondary',
                    $this->text('lock', 'Lock'),
                );
        }
        if ($filings !== []) {
            $actions[] = [
                'id'       => 'openFilings',
                'label'    => $this->text('vatFilings', 'VAT filings'),
                'variant'  => 'secondary',
                'kind'     => 'open_viewer',
                'viewerId' => 'economy.vat.filings',
                'recordId' => (int) $filings[0]['id'],
            ];
        }

        return $actions;
    }

    /**
     * Podání za instanci, nejnovější první (zrušená taky — patří k historii
     * tvrzení a vysvětlují mezery v pořadí).
     *
     * @return list<array<string, mixed>>
     */
    private function filings(int $periodId): array
    {
        return $this->db->fetchAll(
            'SELECT `id`, `filing_kind`, `sequence`, `date_issue`, `date_filed`, `docState`'
            . ' FROM `economy_vat_filings` WHERE `report_period` = %i'
            . ' ORDER BY `sequence` DESC, `id` DESC',
            $periodId,
        );
    }

    /**
     * Akce „Uzamknout" — sdílí ji detail instance i detail podaného podání
     * (FilingsViewer); klient volá POST /_vat/report-period-lock. Popisek
     * dodává volající ze svého katalogu (statická metoda katalog nečte).
     *
     * @return array<string, mixed>
     */
    public static function lockAction(int $periodId, string $variant, string $label): array
    {
        return [
            'id'      => 'lockReportPeriod',
            'label'   => $label,
            'variant' => $variant,
            'target'  => ['periodId' => $periodId],
        ];
    }

    /**
     * Akce „Odemknout" s potvrzením — texty dodává volající z katalogu.
     *
     * @return array<string, mixed>
     */
    public static function unlockAction(int $periodId, string $label, string $confirm): array
    {
        return [
            'id'      => 'unlockReportPeriod',
            'label'   => $label,
            'variant' => 'secondary',
            'confirm' => $confirm,
            'target'  => ['periodId' => $periodId],
        ];
    }

    /**
     * „Ano, 11. 9. 2026 14:05 (Jan Novák)" / „Ano (import)" — zámek bez
     * času přišel importem ze starého systému (#55 D26).
     *
     * @param array<string, mixed> $record
     */
    private function lockLabel(array $record): string
    {
        $at = SubtableCellFormatter::dateTime($record['locked_at'] ?? null);
        if ($at === null) {
            return $this->text('lockedImport', 'Yes (import)');
        }
        $userId = (int) ($record['locked_by'] ?? 0);
        if ($userId > 0) {
            $name = $this->db->fetchSingle('SELECT `full_name` FROM `core_system_users` WHERE `id` = %i', $userId);
            if (is_string($name) && $name !== '') {
                return $this->text('lockedAtBy', 'Yes, {at} ({name})', ['at' => $at, 'name' => $name]);
            }
        }
        return $this->text('lockedAt', 'Yes, {at}', ['at' => $at]);
    }

    /** @param list<array<string, mixed>> $filings */
    private function hasFiledFiling(array $filings): bool
    {
        foreach ($filings as $filing) {
            if ((int) ($filing['docState'] ?? 0) === FilingDocument::DOC_STATE_FILED) {
                return true;
            }
        }
        return false;
    }

    /**
     * Položky sekce „Zůstatky DPH": účet → zůstatek; bez nálezu jediná
     * položka „Vypořádáno".
     *
     * @return list<array{label: string, value: string}>
     */
    private function balanceItems(int $recordId): array
    {
        $balances = (new ClosedPeriodBalanceService($this->db))->balancesForPeriod($recordId);
        if ($balances === []) {
            return [[
                'label' => $this->text('balanceAccounts', 'Sub-accounts of 343'),
                'value' => $this->text('balancesSettled', 'Settled — balances are zero'),
            ]];
        }
        $items = [];
        foreach ($balances as $b) {
            $items[] = [
                'label' => (string) $b['account'],
                'value' => (SubtableCellFormatter::money($b['balance']) ?? '0,00') . $this->moneySuffix(),
            ];
        }
        $items[] = [
            'label' => $this->text('state', 'State'),
            'value' => $this->text(
                'balancesUnsettled',
                'Not settled — the VAT return is not posted, or VAT changed after filing',
            ),
        ];
        return $items;
    }

    /**
     * @param list<array<string, mixed>> $filings
     * @return array<string, mixed>
     */
    private function filingsTable(array $filings): array
    {
        $kindLabels  = $this->filingKindLabels();
        $stateConfig = DocStateConfig::fromCfgItem($this->config?->cfgItem('economy.vat.docStatesFilings'));

        $rows = [];
        foreach ($filings as $filing) {
            $kind = (string) $filing['filing_kind'];
            $rows[] = [
                'sequence' => (string) $filing['sequence'],
                'kind'     => $kindLabels[$kind] ?? $kind,
                'issued'   => $this->formatDate($filing['date_issue'] ?? null),
                'filed'    => $this->formatDate($filing['date_filed'] ?? null),
                'state'    => (string) ($stateConfig->getState((int) $filing['docState'])['stateName'] ?? ''),
            ];
        }

        return ['type' => 'table', 'columns' => [
            ['id' => 'sequence', 'label' => $this->columnLabel('sequence', 'Sequence', 'economy_vat_filings')],
            ['id' => 'kind',     'label' => $this->columnLabel('filing_kind', 'Filing kind', 'economy_vat_filings')],
            ['id' => 'issued',   'label' => $this->columnLabel('date_issue', 'Composed on', 'economy_vat_filings')],
            ['id' => 'filed',    'label' => $this->columnLabel('date_filed', 'Filed on', 'economy_vat_filings')],
            ['id' => 'state',    'label' => $this->text('state', 'State')],
        ], 'rows' => $rows];
    }

    /** @return array<string, string> */
    private function filingKindLabels(): array
    {
        $cfgData = $this->config?->cfgItem('economy.vat.filingKinds');
        if (!is_array($cfgData)) {
            return [];
        }
        $labels = [];
        foreach (EnumOptionsHelper::fromCfgData($cfgData, 'enumString', 'economy.vat.filingKinds') as $option) {
            $labels[(string) $option['value']] = (string) $option['label'];
        }
        return $labels;
    }

    public function getFilters(): array
    {
        $typeOptions = [['value' => 'all', 'label' => $this->sharedText('all', 'All')]];
        foreach ($this->resolveTypeLabels() as $key => $label) {
            $typeOptions[] = ['value' => $key, 'label' => $label];
        }

        $filters = [[
            'id'      => 'report_type',
            'label'   => $this->columnLabel('report_type', 'Report type'),
            'type'    => 'enum',
            'default' => 'all',
            'options' => $typeOptions,
        ]];

        $registrations = $this->registrationNames();
        if (count($registrations) > 1) {
            $regOptions = [['value' => '0', 'label' => $this->sharedText('all', 'All')]];
            foreach ($registrations as $id => $name) {
                $regOptions[] = ['value' => (string) $id, 'label' => $name];
            }
            $filters[] = [
                'id'      => 'vat_registration',
                'label'   => $this->columnLabel('vat_registration', 'VAT registration'),
                'type'    => 'enum',
                'default' => '0',
                'options' => $regOptions,
            ];
        }

        $years = $this->db->fetchAll(
            'SELECT DISTINCT YEAR(`date_begin`) AS y FROM `' . $this->table . '`'
            . ' WHERE `docState` != 90 ORDER BY y DESC',
        );
        if ($years !== []) {
            $yearOptions = [['value' => '0', 'label' => $this->sharedText('all', 'All')]];
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

    /** @return array<string, string> */
    private function resolveTypeLabels(): array
    {
        $cfgData = $this->config?->cfgItem('economy.vat.reportTypes');
        if (!is_array($cfgData)) {
            return [];
        }
        $labels = [];
        foreach (EnumOptionsHelper::fromCfgData($cfgData, 'enumString', 'economy.vat.reportTypes') as $opt) {
            $labels[(string) $opt['value']] = (string) $opt['label'];
        }
        return $labels;
    }

    /** @return array<int, string> id → název registrace (živé registrace) */
    private function registrationNames(): array
    {
        if ($this->registrationNames === null) {
            $this->registrationNames = [];
            $rows = $this->db->fetchAll(
                'SELECT `id`, `name` FROM `economy_codebooks_vat_registrations` WHERE `docState` != 90 ORDER BY `name`, `id`',
            );
            foreach ($rows as $row) {
                $this->registrationNames[(int) $row['id']] = (string) $row['name'];
            }
        }
        return $this->registrationNames;
    }

    /** @param array<string, scalar> $params */
    private function text(string $key, string $englishFallback, array $params = []): string
    {
        return $this->detailText(self::LABELS_CFG_ITEM, $key, $englishFallback, $params);
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

    /**
     * Mezera + symbol domácí měny za zůstatkem účtu (deník je v domácí
     * měně): kód z nastavení `economy.homeCurrency`, symbol z
     * `world.base.currencies` (bez symbolu kód měny velkými písmeny). Bez
     * nastavené domácí měny prázdný řetězec — zůstatek jen jako číslo.
     * Stejné pravidlo má `ItemsViewer::priceSuffix()` (#133 D66).
     */
    private function moneySuffix(): string
    {
        if ($this->moneySuffix === null) {
            $value = (new SettingsStore($this->db))->get('economy.homeCurrency');
            $code = is_string($value) ? strtolower(trim($value)) : '';
            if ($code === '') {
                $this->moneySuffix = '';
            } else {
                $currencies = $this->config?->cfgItem('world.base.currencies');
                $symbol = is_array($currencies) ? ($currencies[$code]['symbol'] ?? null) : null;
                $this->moneySuffix = ' ' . (is_string($symbol) && $symbol !== '' ? $symbol : strtoupper($code));
            }
        }
        return $this->moneySuffix;
    }
}
