<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\SubtableCellFormatter;
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
            $t2[] = ['text' => 'Uzamčeno', 'class' => 'warning', 'icon' => 'lock'];
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
        $items = [
            ['label' => 'Název', 'value' => (string) ($record['name'] ?? '')],
            ['label' => 'Typ tvrzení', 'value' => $typeLabels[$type] ?? $type],
            ['label' => 'Registrace DPH', 'value' => $registrations[(int) ($record['vat_registration'] ?? 0)] ?? ''],
            ['label' => 'Začátek období', 'value' => $this->formatDate($record['date_begin'] ?? null)],
            ['label' => 'Konec období', 'value' => $this->formatDate($record['date_end'] ?? null)],
            ['label' => 'Uzamčeno', 'value' => $locked ? $this->lockLabel($record) : 'Ne'],
        ];

        $column = ReportPeriodDocument::HEAD_COLUMN_BY_TYPE[$type] ?? null;
        if ($column !== null) {
            $count = (int) $this->db->fetchSingle(
                'SELECT COUNT(*) FROM `docs_core_heads` WHERE %n = %i AND `docState` != 90',
                $column, $recordId,
            );
            $items[] = ['label' => 'Přiřazené doklady', 'value' => (string) $count];
        }

        $groups = [['title' => 'Tvrzení', 'items' => $items]];

        $filings = $this->filings($recordId);

        // Zůstatky DPH (#55 D31): jen u přiznání s podaným podáním — Σ deníku
        // na 343 analytikách (mimo 801/802) přes doklady instance musí být
        // nula; nenulový = chybí zaúčtování přiznání, nebo se DPH po podání
        // změnila.
        if ($type === VatPeriodAssigner::TYPE_RETURN && $this->hasFiledFiling($filings)) {
            $groups[] = ['title' => 'Zůstatky DPH', 'items' => $this->balanceItems($recordId)];
        }

        $content = [['type' => 'properties', 'groups' => $groups]];
        if ($filings !== []) {
            $content[] = $this->filingsTable($filings);
        }

        return [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => $content,
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
                'label'   => 'Sestavit podání',
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
                ? self::unlockAction($recordId)
                : self::lockAction($recordId, $this->hasFiledFiling($filings) ? 'primary' : 'secondary');
        }
        if ($filings !== []) {
            $actions[] = [
                'id'       => 'openFilings',
                'label'    => 'Podání DPH',
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
     * (FilingsViewer); klient volá POST /_vat/report-period-lock.
     *
     * @return array<string, mixed>
     */
    public static function lockAction(int $periodId, string $variant = 'secondary', ?string $label = null): array
    {
        return [
            'id'      => 'lockReportPeriod',
            'label'   => $label ?? 'Uzamknout',
            'variant' => $variant,
            'target'  => ['periodId' => $periodId],
        ];
    }

    /** @return array<string, mixed> */
    public static function unlockAction(int $periodId): array
    {
        return [
            'id'      => 'unlockReportPeriod',
            'label'   => 'Odemknout',
            'variant' => 'secondary',
            'confirm' => 'Odemknout tvrzení? Doklady podaného období půjde znovu měnit — odemknutí je krok'
                . ' před dodatečným nebo opravným podáním.',
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
            return 'Ano (import)';
        }
        $label = 'Ano, ' . $at;
        $userId = (int) ($record['locked_by'] ?? 0);
        if ($userId > 0) {
            $name = $this->db->fetchSingle('SELECT `full_name` FROM `core_system_users` WHERE `id` = %i', $userId);
            if (is_string($name) && $name !== '') {
                $label .= " ({$name})";
            }
        }
        return $label;
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
            return [['label' => 'Analytiky 343', 'value' => 'Vypořádáno — zůstatky jsou nulové']];
        }
        $items = [];
        foreach ($balances as $b) {
            $items[] = [
                'label' => (string) $b['account'],
                'value' => (SubtableCellFormatter::money($b['balance']) ?? '0,00') . ' Kč',
            ];
        }
        $items[] = ['label' => 'Stav', 'value' => 'Nevypořádáno — chybí zaúčtování přiznání, nebo se DPH po podání změnila'];
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
            ['id' => 'sequence', 'label' => 'Pořadí'],
            ['id' => 'kind',     'label' => 'Druh podání'],
            ['id' => 'issued',   'label' => 'Sestaveno'],
            ['id' => 'filed',    'label' => 'Podáno'],
            ['id' => 'state',    'label' => 'Stav'],
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
        $typeOptions = [['value' => 'all', 'label' => 'Vše']];
        foreach ($this->resolveTypeLabels() as $key => $label) {
            $typeOptions[] = ['value' => $key, 'label' => $label];
        }

        $filters = [[
            'id'      => 'report_type',
            'label'   => 'Typ tvrzení',
            'type'    => 'enum',
            'default' => 'all',
            'options' => $typeOptions,
        ]];

        $registrations = $this->registrationNames();
        if (count($registrations) > 1) {
            $regOptions = [['value' => '0', 'label' => 'Vše']];
            foreach ($registrations as $id => $name) {
                $regOptions[] = ['value' => (string) $id, 'label' => $name];
            }
            $filters[] = [
                'id'      => 'vat_registration',
                'label'   => 'Registrace DPH',
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
            $yearOptions = [['value' => '0', 'label' => 'Vše']];
            foreach ($years as $row) {
                $yearOptions[] = ['value' => (string) $row['y'], 'label' => (string) $row['y']];
            }
            $filters[] = [
                'id'      => 'year',
                'label'   => 'Rok',
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
