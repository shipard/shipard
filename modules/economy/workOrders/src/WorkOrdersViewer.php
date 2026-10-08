<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Core\Database\SearchCondition;

/**
 * Viewer zakázek (sekce Zakázky, tasks/work-orders-phase1.md §5).
 *
 * Taby podle `viewGroup` z vlastní sady stavů (aktivní / archiv / koš),
 * spodní taby = číselné řady V pořádku (P6, vzor DocsHeadsViewer): tab
 * filtruje zakázky řady a Přidat z něj předvyplní řadu (`newRecordDefaults`).
 * Filtry pravého panelu: druh, typ. Fulltext: číslo, název, zákazník.
 * Detail: Přehled (identita, zákazník, zařazení, platnost) — strom
 * a Deník doplní fáze detailu.
 */
class WorkOrdersViewer extends WorkOrdersViewerBase
{
    protected ?string $docStatesCfgItem = 'economy.workOrders.docStates';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT w.`id`, w.`number`, w.`title`, w.`type`, w.`date_start`, w.`date_end`,'
            . ' w.`docState`, w.`docStateMain`,'
            . ' k.`name` AS `kind_name`, p.`full_name` AS `customer_name`'
            . ' FROM `' . $this->table . '` w'
            . ' LEFT JOIN `' . KindDocument::TABLE . '` k ON k.`id` = w.`kind`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = w.`customer`';

        [$conditions, $params] = $this->viewGroupCondition($filters, 'w');

        foreach ($filters as $filter) {
            $id    = $filter['id'] ?? null;
            $value = $filter['value'] ?? null;
            if ($id === 'bottomTab' && ctype_digit((string) $value) && (int) $value > 0) {
                // Spodní tab = id číselné řady (getBottomTabs).
                $conditions[] = 'w.`number_series` = %i';
                $params[] = (int) $value;
            } elseif ($id === 'kind' && ctype_digit((string) $value) && (int) $value > 0) {
                $conditions[] = 'w.`kind` = %i';
                $params[] = (int) $value;
            } elseif ($id === 'type' && is_string($value) && $value !== '' && !$this->types()->isUnknown($value)) {
                $conditions[] = 'w.`type` = %s';
                $params[] = $value;
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = SearchCondition::anyContains(
                ['w.`number`', 'w.`title`', 'p.`full_name`'],
                $search,
            );
            $conditions[] = $searchSql;
            $params = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY w.`docStateMain` ASC, w.`number` DESC, w.`id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    /**
     * Spodní taby = číselné řady V pořádku; Přidat z tabu založí zakázku
     * v té řadě. Bez řad žádný tab bar.
     *
     * @return list<array{id: int, label: string, newRecordDefaults: array{number_series: int}}>
     */
    public function getBottomTabs(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT s.`id`, s.`name` FROM `' . WorkOrderDocument::SERIES_TABLE . '` s'
            . ' WHERE s.`docState` = 40'
            . ' ORDER BY s.`name` ASC',
        );
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $out[] = [
                'id'                => $id,
                'label'             => (string) $row['name'],
                'newRecordDefaults' => ['number_series' => $id],
            ];
        }
        return $out;
    }

    public function getFilters(): array
    {
        $kinds = [];
        foreach ($this->db->fetchAll(
            'SELECT `id`, `name` FROM `' . KindDocument::TABLE . '` WHERE `docState` IN (10, 40, 80) ORDER BY `name` ASC',
        ) as $row) {
            $kinds[] = ['value' => (int) $row['id'], 'label' => (string) $row['name']];
        }
        return [
            [
                'id'      => 'kind',
                'label'   => $this->text('label.kind', 'Kind'),
                'type'    => 'select',
                'options' => $kinds,
            ],
            [
                'id'      => 'type',
                'label'   => $this->text('label.type', 'Type'),
                'type'    => 'select',
                'options' => $this->types()->options(),
            ],
        ];
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? 10);
        $row = [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['title'] ?? ''),
            'i1' => !empty($rowData['number']) ? (string) $rowData['number'] : null,
        ];

        $t2 = [];
        if (!empty($rowData['customer_name'])) {
            $t2[] = ['text' => (string) $rowData['customer_name'], 'class' => 'primary'];
        }
        if (!empty($rowData['kind_name'])) {
            $t2[] = ['text' => (string) $rowData['kind_name'], 'class' => 'muted'];
        }
        $start = $this->formatDate($rowData['date_start'] ?? null);
        if ($start !== null) {
            $t2[] = ['text' => $start];
        }
        $end = $this->formatDate($rowData['date_end'] ?? null);
        if ($end !== null) {
            $t2[] = ['text' => '– ' . $end, 'class' => 'muted'];
        }
        $badge = $this->stateBadge($docState);
        if ($badge !== null) {
            $t2[] = $badge;
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;
        $row['stateStyle'] = $this->stateStyleOf($docState);

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->loadDetailRecord($recordId);
        if ($record === null) {
            return ['tabs' => []];
        }
        return $this->overviewDetail($this->overviewGroups($record));
    }

    /**
     * Záznam detailu s popisky vazeb (druh, řada, zákazník, středisko,
     * nadřazená), null = neexistuje.
     *
     * @return array<string, mixed>|null
     */
    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT w.*, k.`name` AS `kind_name`, s.`name` AS `series_name`, p.`full_name` AS `customer_name`,'
            . ' cc.`code` AS `cost_center_code`, cc.`name` AS `cost_center_name`,'
            . ' pw.`number` AS `parent_number`, pw.`title` AS `parent_title`'
            . ' FROM `' . $this->table . '` w'
            . ' LEFT JOIN `' . KindDocument::TABLE . '` k ON k.`id` = w.`kind`'
            . ' LEFT JOIN `' . WorkOrderDocument::SERIES_TABLE . '` s ON s.`id` = w.`number_series`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = w.`customer`'
            . ' LEFT JOIN `economy_codebooks_cost_centers` cc ON cc.`id` = w.`cost_center`'
            . ' LEFT JOIN `' . $this->table . '` pw ON pw.`id` = w.`parent`'
            . ' WHERE w.`id` = %i',
            $recordId,
        );
    }

    /**
     * Skupiny Přehledu zakázky.
     *
     * @param array<string, mixed> $record
     * @return list<array{title: string, items: list<array{label: string, value: string}>}>
     */
    protected function overviewGroups(array $record): array
    {
        $types = $this->types();
        $type = (string) ($record['type'] ?? '');
        $external = $type !== '' && $types->isExternal($type);
        $periodic = $type !== '' && $types->invoicing($type) === WorkOrderTypes::INVOICING_PERIODIC;

        $identity = [];
        $this->addItem($identity, $this->text('label.number', 'Number'), $record['number'] ?? null);
        $this->addItem($identity, $this->text('label.title', 'Title'), $record['title'] ?? null);
        $this->addItem($identity, $this->text('label.kind', 'Kind'), $record['kind_name'] ?? null);
        $this->addItem($identity, $this->text('label.type', 'Type'), $type !== '' ? $types->label($type) : null);
        $this->addItem($identity, $this->text('label.series', 'Number series'), $record['series_name'] ?? null);
        $badge = $this->stateBadge((int) ($record['docState'] ?? 10));
        $this->addItem($identity, $this->text('label.state', 'State'), $badge['text'] ?? null);

        $customer = [];
        if ($external) {
            $this->addItem($customer, $this->text('label.customer', 'Customer'), $record['customer_name'] ?? null);
            $this->addItem($customer, $this->text('label.currency', 'Currency'), strtoupper((string) ($record['currency'] ?? '')));
            $this->addItem($customer, $this->text('label.paymentReference', 'Payment reference'), $record['payment_reference'] ?? null);
        }

        $tree = [];
        if (!empty($record['parent'])) {
            $this->addItem(
                $tree,
                $this->text('label.parent', 'Parent work order'),
                WorkOrdersLookup::label(['number' => $record['parent_number'] ?? null, 'title' => $record['parent_title'] ?? null]),
            );
        }
        $costCenter = trim(((string) ($record['cost_center_code'] ?? '')) . ' — ' . ((string) ($record['cost_center_name'] ?? '')), ' —');
        $this->addItem($tree, $this->text('label.costCenter', 'Cost center'), $costCenter);

        $validity = [];
        $this->addItem($validity, $this->text('label.dateStart', 'Start'), $this->formatDate($record['date_start'] ?? null));
        $this->addItem(
            $validity,
            $periodic ? $this->text('label.validUntil', 'Valid until') : $this->text('label.dateEnd', 'End'),
            $this->formatDate($record['date_end'] ?? null),
        );

        $note = [];
        $this->addItem($note, $this->text('label.internalNote', 'Internal note'), $record['internal_note'] ?? null);

        return [
            ['title' => $this->text('group.identity', 'Identity'), 'items' => $identity],
            ['title' => $this->text('group.customer', 'Customer'), 'items' => $customer],
            ['title' => $this->text('group.tree', 'Hierarchy'), 'items' => $tree],
            ['title' => $this->text('group.validity', 'Validity'), 'items' => $validity],
            ['title' => $this->text('group.note', 'Note'), 'items' => $note],
        ];
    }
}
