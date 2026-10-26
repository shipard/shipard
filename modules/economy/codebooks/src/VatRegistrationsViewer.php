<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class VatRegistrationsViewer extends TableViewer
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

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT `id`, `name`, `region`, `country`, `taxpayer_kind`, `vat_id`,'
            . ' `tax_period_kind`, `cs_period_kind`, `rs_period_kind`, `valid_from`, `valid_to`,'
            . ' `docState`, `docStateMain`'
            . ' FROM `' . $this->table . '`';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        foreach ($filters as $filter) {
            if ($filter['id'] === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
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
            [$searchSql, $searchParams] = $this->buildSearchCondition(['name', 'vat_id'], $search);
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY `docStateMain` ASC, `valid_from` DESC, `id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $row = [
            'id' => (int) $rowData['id'],
            't1' => $rowData['name'] ?? '',
            'i1' => $rowData['vat_id'] ?? null,
        ];

        $t2 = [];

        if (!empty($rowData['country'])) {
            $t2[] = ['text' => strtoupper((string) $rowData['country'])];
        }

        $taxpayerKindLabels = $this->resolveTaxpayerKindLabels();
        $taxpayerKind = (int) ($rowData['taxpayer_kind'] ?? 0);
        if (isset($taxpayerKindLabels[$taxpayerKind])) {
            $t2[] = ['text' => $taxpayerKindLabels[$taxpayerKind]];
        }

        $validFrom = $this->formatDate($rowData['valid_from'] ?? null);
        $validTo = $this->formatDate($rowData['valid_to'] ?? null);
        if ($validFrom !== null && $validTo !== null) {
            $t2[] = ['text' => $validFrom . ' – ' . $validTo];
        } elseif ($validFrom !== null) {
            $t2[] = ['text' => 'od ' . $validFrom];
        }

        $periodKindLabels = $this->resolvePeriodKindLabels();
        $taxPeriodKind = (int) ($rowData['tax_period_kind'] ?? 0);
        if (isset($periodKindLabels[$taxPeriodKind])) {
            $t2[] = ['text' => $periodKindLabels[$taxPeriodKind], 'class' => 'muted'];
        }

        $docState = (int) ($rowData['docState'] ?? 10);
        $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
        $stateData = $cfg->getState($docState);
        $stateStyle = $stateData['stateStyle'] ?? 'concept';

        if ($docState !== 10) {
            $t2[] = [
                'text'  => $stateData['stateName'] ?? '',
                'class' => self::STATE_SPAN_CLASS[$stateStyle] ?? 'muted',
            ];
        }

        $row['t2'] = $t2 !== [] ? $t2 : null;
        $row['stateStyle'] = $stateStyle;

        return $row;
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT r.*, p.`full_name` AS `tax_office_person_name`'
            . ' FROM `' . $this->table . '` r'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = r.`tax_office_person`'
            . ' WHERE r.`id` = %i',
            $recordId,
        );
    }

    protected function detailTabs(array $record): array
    {
        $tabs = [];

        $tabs[] = [
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => $this->buildOverviewContent($record),
        ];

        // Podací údaje — strukturované pole ze extension modulu economy.vat
        // (#74). Prázdný profil (nebo neaktivní modul) záložku nepřidá.
        $filingGroups = $this->structuredFieldProperties(
            $record,
            'filing_profile',
            'economy.vat.filingProfileCz',
        );
        if ($filingGroups !== []) {
            $tabs[] = [
                'id'      => 'filing',
                'label'   => 'Podací údaje',
                'content' => ['type' => 'properties', 'groups' => $filingGroups],
            ];
        }

        // Instance daňových tvrzení registrace zobrazuje viewer „Daňová
        // tvrzení" modulu economy.vat (filtr registrace) — codebooks na něm nezávisí.

        return $tabs;
    }

    private function buildOverviewContent(array $record): array
    {
        $regionLabels = $this->resolveRegionLabels();
        $countryLabels = $this->resolveCountryLabels();
        $taxpayerKindLabels = $this->resolveTaxpayerKindLabels();
        $periodKindLabels = $this->resolvePeriodKindLabels();

        $identityItems = [];
        $this->addItem($identityItems, 'Název', $record['name'] ?? null);

        $regionKey = (string) ($record['region'] ?? '');
        if ($regionKey !== '') {
            $this->addItem($identityItems, 'Region', $regionLabels[$regionKey] ?? $regionKey);
        }

        $countryKey = (string) ($record['country'] ?? '');
        if ($countryKey !== '') {
            $countryDisplay = strtoupper($countryKey);
            if (isset($countryLabels[$countryKey])) {
                $countryDisplay .= ' (' . $countryLabels[$countryKey] . ')';
            }
            $this->addItem($identityItems, 'Země', $countryDisplay);
        }

        $this->addItem($identityItems, 'DIČ', $record['vat_id'] ?? null);

        $taxpayerKind = (int) ($record['taxpayer_kind'] ?? 0);
        $this->addItem(
            $identityItems,
            'Druh plátce',
            $taxpayerKindLabels[$taxpayerKind] ?? (string) $taxpayerKind,
        );

        // Správce daně (#55 D30) — chybějící se ukáže výslovně: bez něj vzniká
        // účetní doklad přiznání se saldo řádkem bez partnera.
        $this->addItem(
            $identityItems,
            'Správce daně',
            $record['tax_office_person_name'] ?? (empty($record['tax_office_person']) ? 'nevyplněn' : null),
        );

        $periodItems = [];
        $taxPeriodKind = (int) ($record['tax_period_kind'] ?? 0);
        $this->addItem(
            $periodItems,
            'Frekvence přiznání DPH',
            $periodKindLabels[$taxPeriodKind] ?? (string) $taxPeriodKind,
        );

        $csPeriodKind = (int) ($record['cs_period_kind'] ?? 0);
        $this->addItem(
            $periodItems,
            'Frekvence kontrolního hlášení',
            $periodKindLabels[$csPeriodKind] ?? (string) $csPeriodKind,
        );

        $rsPeriodKind = (int) ($record['rs_period_kind'] ?? 0);
        $this->addItem(
            $periodItems,
            'Frekvence souhrnného hlášení',
            $periodKindLabels[$rsPeriodKind] ?? (string) $rsPeriodKind,
        );

        $this->addItem($periodItems, 'Platí od', $this->formatDate($record['valid_from'] ?? null));
        $validTo = $this->formatDate($record['valid_to'] ?? null);
        $this->addItem($periodItems, 'Platí do', $validTo ?? 'bez konce');

        $groups = [];
        if ($identityItems !== []) {
            $groups[] = ['title' => 'Identifikace', 'items' => $identityItems];
        }
        if ($periodItems !== []) {
            $groups[] = ['title' => 'Periodicita a platnost', 'items' => $periodItems];
        }

        return ['type' => 'properties', 'groups' => $groups];
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return (string) $value;
    }

    /** @return array<string, string> */
    private function resolveRegionLabels(): array
    {
        return $this->resolveStringLabels('world.trade.unions');
    }

    /** @return array<string, string> */
    private function resolveCountryLabels(): array
    {
        return $this->resolveStringLabels('world.base.countries');
    }

    /** @return array<int, string> */
    private function resolveTaxpayerKindLabels(): array
    {
        return $this->resolveIntLabels('economy.codebooks.vatTaxpayerKinds');
    }

    /** @return array<int, string> */
    private function resolvePeriodKindLabels(): array
    {
        return $this->resolveIntLabels('economy.codebooks.vatPeriodKinds');
    }

    /** @return array<string, string> */
    private function resolveStringLabels(string $cfgItemId): array
    {
        if ($this->config === null) {
            return [];
        }
        $cfgData = $this->config->cfgItem($cfgItemId);
        if (!is_array($cfgData)) {
            return [];
        }
        $labels = [];
        foreach ($cfgData as $key => $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $labels[(string) $key] = (string) $entry['name'];
            }
        }
        return $labels;
    }

    /** @return array<int, string> */
    private function resolveIntLabels(string $cfgItemId): array
    {
        if ($this->config === null) {
            return [];
        }
        $cfgData = $this->config->cfgItem($cfgItemId);
        if (!is_array($cfgData)) {
            return [];
        }
        $labels = [];
        foreach ($cfgData as $key => $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $labels[(int) $key] = (string) $entry['name'];
            }
        }
        return $labels;
    }
}
