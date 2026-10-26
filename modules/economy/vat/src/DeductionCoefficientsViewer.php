<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Viewer koeficientů odpočtu DPH (`economy_vat_deduction_coefficients`,
 * #59 D13). Řádek: rok · zálohový / vypořádací · registrace (jen při více
 * než jedné) · poznámka · stav. Filtry: registrace (při více než jedné), rok.
 */
class DeductionCoefficientsViewer extends TableViewer
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
        $sql = 'SELECT `id`, `vat_registration`, `year`, `coefficient_provisional`, `coefficient_settled`,'
            . ' `note`, `docState`, `docStateMain`'
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
                case 'vat_registration':
                    if (is_numeric($value) && (int) $value > 0) {
                        $conditions[] = '`vat_registration` = %i';
                        $params[] = (int) $value;
                    }
                    break;
                case 'year':
                    if (is_numeric($value) && (int) $value > 0) {
                        $conditions[] = '`year` = %i';
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
            [$searchSql, $searchParams] = $this->buildSearchCondition(['note'], $search);
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY `year` DESC, `vat_registration` ASC, `id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $row = [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['year'] ?? ''),
            'i1' => $this->formatPair($rowData),
        ];

        $t2 = [];
        $registrations = $this->registrationNames();
        if (count($registrations) > 1) {
            $regId = (int) ($rowData['vat_registration'] ?? 0);
            $t2[] = ['text' => $registrations[$regId] ?? ('#' . $regId), 'class' => 'muted'];
        }
        if (!empty($rowData['note'])) {
            $t2[] = ['text' => (string) $rowData['note']];
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

        $row['t2'] = $t2 !== [] ? $t2 : null;
        $row['stateStyle'] = $stateStyle;
        return $row;
    }

    protected function detailTabs(array $record): array
    {
        $registrations = $this->registrationNames();
        $items = [
            ['label' => 'Registrace DPH', 'value' => $registrations[(int) ($record['vat_registration'] ?? 0)] ?? ''],
            ['label' => 'Kalendářní rok', 'value' => (string) ($record['year'] ?? '')],
            ['label' => 'Zálohový koeficient', 'value' => $this->formatCoefficient($record['coefficient_provisional'] ?? null) ?? 'odvozený (loňský vypořádací, jinak 1,00)'],
            ['label' => 'Vypořádací koeficient', 'value' => $this->formatCoefficient($record['coefficient_settled'] ?? null) ?? 'nevypořádáno'],
        ];
        if (!empty($record['note'])) {
            $items[] = ['label' => 'Poznámka', 'value' => (string) $record['note']];
        }

        return [$this->propertiesTab([['title' => 'Koeficient odpočtu', 'items' => $items]])];
    }

    public function getFilters(): array
    {
        $filters = [];

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
            'SELECT DISTINCT `year` AS y FROM `' . $this->table . '` WHERE `docState` != 90 ORDER BY y DESC',
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

    /** „zálohový 0,80 · vypořádací —" */
    private function formatPair(array $rowData): string
    {
        $prov = $this->formatCoefficient($rowData['coefficient_provisional'] ?? null) ?? '—';
        $settled = $this->formatCoefficient($rowData['coefficient_settled'] ?? null) ?? '—';
        return "zálohový {$prov} · vypořádací {$settled}";
    }

    private function formatCoefficient(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return number_format((float) $value, 2, ',', ' ');
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
}
