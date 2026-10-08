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
 *
 * Detail: Přehled (identita, zákazník — u interní jednorázové z nejbližší
 * externí zakázky v řetězci předků, platnost, poznámka) s tabulkou
 * nadřazené zakázky a podzakázek (odkazy `open_detail`), tab Deník
 * (WorkOrderJournalService: obraty po letech a posledních 200 řádků,
 * jen když zakázka nějaké má) a akce Otevřít v deníku s filtrem
 * `dim_workOrder=#id` přes všechny roky.
 */
class WorkOrdersViewer extends WorkOrdersViewerBase
{
    protected ?string $docStatesCfgItem = 'economy.workOrders.docStates';

    public const VIEWER_ID = 'economy.workOrders.heads';
    /** Souhrnný viewer dokladů — cíl odkazů na doklad (`open_detail`). */
    public const DOCUMENTS_VIEWER = 'docs.core.heads';
    public const JOURNAL_VIEWER = 'economy.accounting.journal';
    /** Filtr deníku podle dimenze `workOrder` (JournalViewer: `dim_{id dimenze}`). */
    public const JOURNAL_DIMENSION_FILTER = 'dim_workOrder';

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
        $tree = $this->treeService();
        $detail = $this->overviewDetail($this->overviewGroups($record, $tree->effectiveCustomer($record)));

        $treeTable = $this->treeTable($record, $tree->children($recordId));
        if ($treeTable !== null) {
            $detail['tabs'][0]['content'] = ['type' => 'composite', 'blocks' => [
                $detail['tabs'][0]['content'],
                ['type' => 'heading', 'text' => $this->text('heading.tree', 'Parent and sub-orders')],
                $treeTable,
            ]];
        }

        $journalTab = $this->journalTab($recordId);
        if ($journalTab !== null) {
            $detail['tabs'][] = $journalTab;
            $detail['actions'] = [$this->journalAction($recordId)];
        }
        return $detail;
    }

    /**
     * Tabulka nadřazené zakázky a podzakázek s odkazy do detailu; null bez
     * obojího.
     *
     * @param array<string, mixed> $record
     * @param list<array{id: int, number: ?string, title: string, type: string, docState: int, customerName: ?string}> $children
     * @return array<string, mixed>|null
     */
    private function treeTable(array $record, array $children): ?array
    {
        $rows = [];
        if (!empty($record['parent'])) {
            $rows[] = $this->treeRow(
                $this->text('relation.parent', 'Parent'),
                (int) $record['parent'],
                $record['parent_number'] ?? null,
                (string) ($record['parent_title'] ?? ''),
                null,
                (int) ($record['parent_doc_state'] ?? 10),
            );
        }
        foreach ($children as $child) {
            $rows[] = $this->treeRow(
                $this->text('relation.child', 'Sub-order'),
                $child['id'],
                $child['number'],
                $child['title'],
                $child['customerName'],
                $child['docState'],
            );
        }
        if ($rows === []) {
            return null;
        }
        return [
            'type'    => 'table',
            'columns' => [
                ['id' => 'relation', 'label' => $this->text('column.relation', 'Relation')],
                ['id' => 'number', 'label' => $this->text('column.number', 'Number'), 'link' => true],
                ['id' => 'title', 'label' => $this->text('column.title', 'Title'), 'link' => true],
                ['id' => 'customer', 'label' => $this->text('column.customer', 'Customer')],
                ['id' => 'state', 'label' => $this->text('column.state', 'State')],
            ],
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function treeRow(string $relation, int $id, ?string $number, string $title, ?string $customer, int $docState): array
    {
        $state = $this->stateBadge($docState);
        return [
            'relation' => $relation,
            'number'   => $number !== null && $number !== '' ? $number : '—',
            'title'    => $title,
            'customer' => $customer ?? '',
            'state'    => $state['text'] ?? '',
            '_action'  => [
                'id'     => 'openWorkOrder',
                'kind'   => 'open_detail',
                'target' => ['viewerId' => self::VIEWER_ID, 'recordId' => $id],
            ],
        ];
    }

    /**
     * Tab Deník: souhrn po účetních letech a řádky deníku s dimenzí
     * zakázky, od nejnovějších; nejvýš `WorkOrderJournalService::ROW_LIMIT`
     * řádků, zbytek je v deníku. Null = zakázka v deníku není.
     *
     * @return array<string, mixed>|null
     */
    private function journalTab(int $workOrderId): ?array
    {
        $journal = $this->journalService()->overview($workOrderId);
        if ($journal['rows'] === []) {
            return null;
        }

        $years = [];
        foreach ($journal['years'] as $year) {
            $years[] = [
                'year'     => $year['year'] !== '' ? $year['year'] : '—',
                'expenses' => $this->formatAmount($year['expenses']),
                'revenues' => $this->formatAmount($year['revenues']),
                'otherDr'  => $this->formatAmount($year['otherDr']),
                'otherCr'  => $this->formatAmount($year['otherCr']),
            ];
        }
        $rows = [];
        foreach ($journal['rows'] as $row) {
            $entry = [
                'date'     => $this->formatDate($row['date']) ?? '',
                'document' => $row['docNumber'] !== '' ? $row['docNumber'] : '#' . $row['docId'],
                'account'  => $row['accountNumber'],
                'text'     => $row['text'],
                'moneyDr'  => $row['moneyDr'] != 0.0 ? $this->formatAmount($row['moneyDr']) : '',
                'moneyCr'  => $row['moneyCr'] != 0.0 ? $this->formatAmount($row['moneyCr']) : '',
            ];
            if ($row['docId'] > 0) {
                $entry['_action'] = [
                    'id'     => 'openDocument',
                    'kind'   => 'open_detail',
                    'target' => ['viewerId' => self::DOCUMENTS_VIEWER, 'recordId' => $row['docId']],
                ];
            }
            $rows[] = $entry;
        }

        $blocks = [
            [
                'type'    => 'table',
                'columns' => [
                    ['id' => 'year', 'label' => $this->text('column.fiscalYear', 'Fiscal year')],
                    ['id' => 'expenses', 'label' => $this->text('column.expenses', 'Expenses'), 'align' => 'right'],
                    ['id' => 'revenues', 'label' => $this->text('column.revenues', 'Revenues'), 'align' => 'right'],
                    ['id' => 'otherDr', 'label' => $this->text('column.otherDr', 'Other accounts — debit'), 'align' => 'right'],
                    ['id' => 'otherCr', 'label' => $this->text('column.otherCr', 'Other accounts — credit'), 'align' => 'right'],
                ],
                'rows' => $years,
            ],
            ['type' => 'heading', 'text' => $this->text('heading.journalRows', 'Journal entries')],
            [
                'type'    => 'table',
                'columns' => [
                    ['id' => 'date', 'label' => $this->text('column.date', 'Date')],
                    ['id' => 'document', 'label' => $this->text('column.document', 'Document'), 'link' => true],
                    ['id' => 'account', 'label' => $this->text('column.account', 'Account')],
                    ['id' => 'text', 'label' => $this->text('column.text', 'Text')],
                    ['id' => 'moneyDr', 'label' => $this->text('column.moneyDr', 'Debit'), 'align' => 'right'],
                    ['id' => 'moneyCr', 'label' => $this->text('column.moneyCr', 'Credit'), 'align' => 'right'],
                ],
                'rows' => $rows,
            ],
        ];
        if ($journal['more']) {
            $blocks[] = [
                'type' => 'heading',
                'text' => $this->text(
                    'text.journalRowsLimit',
                    'Showing the latest {limit} entries — open the journal for the rest.',
                    ['limit' => WorkOrderJournalService::ROW_LIMIT],
                ),
            ];
        }

        return [
            'id'      => 'journal',
            'label'   => $this->text('tab.journal', 'Journal'),
            'content' => ['type' => 'composite', 'blocks' => $blocks],
        ];
    }

    /**
     * Akce „Otevřít v deníku“: deník s filtrem dimenze na tuto zakázku
     * (přesná shoda `#id`) přes všechny účetní roky — prázdný `fiscal_year`
     * ruší výchozí rok deníku.
     *
     * @return array<string, mixed>
     */
    private function journalAction(int $workOrderId): array
    {
        return [
            'id'      => 'openJournal',
            'label'   => $this->text('action.openJournal', 'Open in journal'),
            'kind'    => 'open_viewer',
            'variant' => 'secondary',
            'target'  => [
                'viewerId' => self::JOURNAL_VIEWER,
                'filters'  => ['fiscal_year' => '', self::JOURNAL_DIMENSION_FILTER => '#' . $workOrderId],
            ],
        ];
    }

    protected function treeService(): WorkOrderTreeService
    {
        return new WorkOrderTreeService($this->db->getDibiConnection(), $this->types());
    }

    protected function journalService(): WorkOrderJournalService
    {
        return new WorkOrderJournalService($this->db->getDibiConnection());
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
            . ' pw.`number` AS `parent_number`, pw.`title` AS `parent_title`, pw.`docState` AS `parent_doc_state`'
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
     * Skupiny Přehledu zakázky. Zákazník externí zakázky je vlastní, u interní
     * jednorázové z nadřazené (`$customer['from']`, D14).
     *
     * @param array<string, mixed> $record
     * @param array{id: int, name: string, from: ?array{id: int, number: ?string, title: string}}|null $customer
     * @return list<array{title: string, items: list<array{label: string, value: string}>}>
     */
    protected function overviewGroups(array $record, ?array $customer = null): array
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

        $party = [];
        if ($external) {
            $this->addItem($party, $this->text('label.customer', 'Customer'), $record['customer_name'] ?? null);
            $this->addItem($party, $this->text('label.currency', 'Currency'), strtoupper((string) ($record['currency'] ?? '')));
            $this->addItem($party, $this->text('label.paymentReference', 'Payment reference'), $record['payment_reference'] ?? null);
        } elseif ($customer !== null && $customer['from'] !== null) {
            $this->addItem($party, $this->text('label.customer', 'Customer'), $customer['name']);
            $this->addItem($party, $this->text('label.customerFrom', 'Customer from'), WorkOrdersLookup::label($customer['from']));
        }

        $tree = [];
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
            ['title' => $this->text('group.customer', 'Customer'), 'items' => $party],
            ['title' => $this->text('group.tree', 'Hierarchy'), 'items' => $tree],
            ['title' => $this->text('group.validity', 'Validity'), 'items' => $validity],
            ['title' => $this->text('group.note', 'Note'), 'items' => $note],
        ];
    }
}
