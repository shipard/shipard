<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Core\Database\SearchCondition;
use Shipard\Module\Base\Places\PlaceDocument;
use Shipard\Module\Base\Places\PlaceTree;
use Shipard\Module\Economy\PeriodicInvoicing\PeriodRepository;
use Shipard\Module\Economy\PeriodicInvoicing\Source\PeriodicInvoicingSource;
use Shipard\Module\Economy\PeriodicInvoicing\Viewer\InvoicingDetailTab;

/**
 * Viewer smluv (sekce Budovy, #174 D5, D25).
 *
 * Taby podle `viewGroup` z vlastní sady stavů (aktivní / archiv / koš),
 * **žádné spodní taby** — smlouvy se dělí filtrem druh / typ a ikonou typu
 * v řádku. Fulltext: číslo, název, nájemce a názvy míst smlouvy.
 *
 * Řádek: název, číslo, nájemce, místa (první dvě zkráceným názvem a „+N“),
 * platnost a štítek stavu. Detail: Přehled (druh, typ, nájemce, platnost,
 * interní poznámka) a tabulka Místa s umístěním (cesta ve stromu míst)
 * a proklikem do detailu místa; přílohy na konci Přehledu. Smlouva
 * s periodicitou má navíc záložku Fakturace — společnou záložku enginu
 * periodické fakturace (`InvoicingDetailTab`, #174 D34) nad zdrojem smluv;
 * akce Vystavit dlužná období / Přegenerovat / Obnovit jsou akce záznamu
 * (`recordActions` v module.jsonc), ne akce vieweru.
 */
class ContractsViewer extends BuildingsViewerBase
{
    protected ?string $docStatesCfgItem = ContractDocument::DOC_STATES_CFG_ITEM;

    /** Viewer míst — cíl odkazů z tabulky Místa (`open_detail`). */
    public const VIEWER_ID = ContractInvoicingSource::VIEWER_ID;
    public const PLACES_VIEWER = 'base.places.places';
    /** Kolik míst řádek seznamu vypíše, zbytek je „+N“. */
    public const ROW_PLACES = 2;

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT c.`id`, c.`number`, c.`title`, c.`type`, c.`date_start`, c.`date_end`,'
            . ' c.`docState`, c.`docStateMain`,'
            . ' k.`name` AS `kind_name`, p.`full_name` AS `customer_name`'
            . ' FROM `' . $this->table . '` c'
            . ' LEFT JOIN `' . ContractKindDocument::TABLE . '` k ON k.`id` = c.`kind`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = c.`customer`';

        [$conditions, $params] = $this->viewGroupCondition($filters, 'c');

        foreach ($filters as $filter) {
            $id    = $filter['id'] ?? null;
            $value = $filter['value'] ?? null;
            if ($id === 'kind' && ctype_digit((string) $value) && (int) $value > 0) {
                $conditions[] = 'c.`kind` = %i';
                $params[] = (int) $value;
            } elseif ($id === 'type' && is_string($value) && $value !== '' && !$this->types()->isUnknown($value)) {
                $conditions[] = 'c.`type` = %s';
                $params[] = $value;
            }
        }

        if ($search !== null && $search !== '') {
            [$headSql, $headParams] = SearchCondition::anyContains(
                ['c.`number`', 'c.`title`', 'p.`full_name`'],
                $search,
            );
            [$placeSql, $placeParams] = SearchCondition::anyContains(['pl.`name`', 'pl.`short_name`'], $search);
            $conditions[] = '(' . $headSql
                . ' OR EXISTS (SELECT 1 FROM `' . ContractDocument::PLACES_TABLE . '` cp'
                . ' JOIN `' . PlaceDocument::TABLE . '` pl ON pl.`id` = cp.`place`'
                . ' WHERE cp.`contract` = c.`id` AND ' . $placeSql . '))';
            $params = array_merge($params, $headParams, $placeParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY c.`docStateMain` ASC, c.`number` DESC, c.`id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        $rows = $this->db->fetchAll($sql, ...$params);
        if ($rows === []) {
            return [];
        }

        // Místa všech smluv stránky jedním dotazem.
        $labels = $this->placeLabels(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        foreach ($rows as &$row) {
            $row['place_labels'] = $labels[(int) $row['id']] ?? [];
        }
        unset($row);

        return $rows;
    }

    /**
     * Zkrácené názvy míst smluv v pořadí vazby: id smlouvy → seznam.
     *
     * @param list<int> $contractIds
     * @return array<int, list<string>>
     */
    protected function placeLabels(array $contractIds): array
    {
        $labels = [];
        foreach ($this->db->fetchAll(
            'SELECT cp.`contract`, pl.`name`, pl.`short_name`'
            . ' FROM `' . ContractDocument::PLACES_TABLE . '` cp'
            . ' JOIN `' . PlaceDocument::TABLE . '` pl ON pl.`id` = cp.`place`'
            . ' WHERE cp.`contract` IN %in'
            . ' ORDER BY cp.`contract`, cp.`order_pos`, cp.`id`',
            $contractIds,
        ) as $row) {
            $labels[(int) $row['contract']][] = PlaceTree::labelOf($row);
        }
        return $labels;
    }

    public function getFilters(): array
    {
        $kinds = [];
        foreach ($this->db->fetchAll(
            'SELECT `id`, `name` FROM `' . ContractKindDocument::TABLE . '` WHERE `docState` IN %in ORDER BY `name` ASC',
            ContractKindDocument::LIVE_STATES,
        ) as $row) {
            $kinds[] = ['value' => (int) $row['id'], 'label' => (string) $row['name']];
        }
        return [
            [
                'id'      => 'kind',
                'label'   => $this->columnLabel('kind', 'Kind'),
                'type'    => 'select',
                'options' => $kinds,
            ],
            [
                'id'      => 'type',
                'label'   => $this->columnLabel('type', 'Contract type'),
                'type'    => 'select',
                'options' => $this->types()->options(),
            ],
        ];
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? 10);
        $type = (string) ($rowData['type'] ?? '');
        $row = [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['title'] ?? ''),
            'i1' => !empty($rowData['number']) ? (string) $rowData['number'] : null,
        ];

        $t2 = [];
        if (!empty($rowData['customer_name'])) {
            $t2[] = ['text' => (string) $rowData['customer_name'], 'class' => 'primary'];
        }
        $places = $this->placesText(is_array($rowData['place_labels'] ?? null) ? $rowData['place_labels'] : []);
        if ($places !== null) {
            $t2[] = ['text' => $places];
        }
        $validity = $this->validityText($rowData['date_start'] ?? null, $rowData['date_end'] ?? null);
        if ($validity !== null) {
            $t2[] = ['text' => $validity, 'class' => 'muted'];
        }
        $badge = $this->rowStateTag($docState);
        if ($badge !== null) {
            $t2[] = $badge;
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        $icon = $type !== '' ? $this->types()->icon($type) : null;
        if ($icon !== null) {
            $row['icon'] = $icon;
        }
        $row['stateStyle'] = $this->stateStyleOf($docState);

        return $row;
    }

    /**
     * Místa v řádku: první dvě zkráceným názvem, zbytek počtem
     * („201, 202 +1“); null bez míst.
     *
     * @param list<string> $labels
     */
    private function placesText(array $labels): ?string
    {
        if ($labels === []) {
            return null;
        }
        $text = implode(', ', array_slice($labels, 0, self::ROW_PLACES));
        $rest = count($labels) - self::ROW_PLACES;
        return $rest > 0 ? $text . ' +' . $rest : $text;
    }

    /** „od 01.01.2027“ / „01.01.2027 – 31.12.2028“ / „do 31.12.2028“; null bez dat. */
    private function validityText(mixed $start, mixed $end): ?string
    {
        $from = $this->formatDate($start);
        $to = $this->formatDate($end);
        return match (true) {
            $from !== null && $to !== null => $from . ' – ' . $to,
            $from !== null => $this->sharedText('fromDate', 'from {date}', ['date' => $from]),
            $to !== null => $this->sharedText('toDate', 'to {date}', ['date' => $to]),
            default => null,
        };
    }

    /**
     * Záznam detailu s popisky vazeb (druh, nájemce), null = neexistuje.
     *
     * @return array<string, mixed>|null
     */
    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT c.*, k.`name` AS `kind_name`, p.`full_name` AS `customer_name`'
            . ' FROM `' . $this->table . '` c'
            . ' LEFT JOIN `' . ContractKindDocument::TABLE . '` k ON k.`id` = c.`kind`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = c.`customer`'
            . ' WHERE c.`id` = %i',
            $recordId,
        );
    }

    /** Přehled a pod ním tabulka míst smlouvy; u smlouvy s periodicitou záložka Fakturace. */
    protected function detailTabs(array $record): array
    {
        $overview = $this->propertiesTab($this->overviewGroups($record));

        $placesTable = $this->placesTable((int) $record['id']);
        if ($placesTable !== null) {
            $overview['content'] = ['type' => 'composite', 'blocks' => [
                $overview['content'],
                ['type' => 'heading', 'text' => $this->text('heading.places', 'Places')],
                $placesTable,
            ]];
        }

        $tabs = [$overview];
        if (trim((string) ($record['inv_periodicity'] ?? '')) !== '') {
            $tabs[] = $this->invoicingTab($record);
        }
        return $tabs;
    }

    /**
     * Záložka Fakturace smlouvy s periodicitou — evidence období a doklady.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed> tab detailu
     */
    private function invoicingTab(array $record): array
    {
        return (new InvoicingDetailTab(
            $this->invoicingSource(),
            $this->periodRepository(),
            $this->config,
            $this->language ?? 'en',
            $this->today(),
        ))->build($record);
    }

    /** Evidence období smluv (přepsatelné v testech). */
    protected function periodRepository(): PeriodRepository
    {
        return new PeriodRepository(
            $this->db->getDibiConnection(),
            ContractInvoicingSource::PERIODS_TABLE,
            ContractInvoicingSource::PERIODS_RECORD_COLUMN,
        );
    }

    /** Zdroj fakturace smluv (přepsatelné v testech). */
    protected function invoicingSource(): PeriodicInvoicingSource
    {
        $source = new ContractInvoicingSource();
        $source->setDb($this->db->getDibiConnection());
        if ($this->config !== null) {
            $source->setConfig($this->config);
        }
        return $source;
    }

    protected function today(): string
    {
        return date('Y-m-d');
    }

    /**
     * Skupiny Přehledu. Název je titulkem a číslo podtitulkem hlavičky,
     * stav štítkem — tady se neopakují (#140 D26).
     *
     * @param array<string, mixed> $record
     * @return list<array{title: string, items: list<array{label: string, value: string}>, wide?: bool}>
     */
    protected function overviewGroups(array $record): array
    {
        $type = (string) ($record['type'] ?? '');

        $identity = [];
        $this->addItem($identity, $this->columnLabel('kind', 'Kind'), $record['kind_name'] ?? null);
        $this->addItem($identity, $this->columnLabel('type', 'Contract type'), $type !== '' ? $this->types()->label($type) : null);
        $this->addItem($identity, $this->columnLabel('customer', 'Tenant'), $record['customer_name'] ?? null);

        $validity = [];
        $this->addItem($validity, $this->columnLabel('date_start', 'Start date'), $this->formatDate($record['date_start'] ?? null));
        $this->addItem(
            $validity,
            $this->columnLabel('date_end', 'End date'),
            $this->formatDate($record['date_end'] ?? null)
                ?? (!empty($record['date_start']) ? $this->sharedText('noEnd', 'no end') : null),
        );

        $note = [];
        $this->addItem($note, $this->columnLabel('internal_note', 'Internal note'), $record['internal_note'] ?? null);

        return [
            ['title' => $this->sharedText('groupIdentity', 'Identity'), 'items' => $identity],
            ['title' => $this->sharedText('groupValidity', 'Validity'), 'items' => $validity],
            ['title' => $this->text('group.note', 'Note'), 'items' => $note, 'wide' => true],
        ];
    }

    /**
     * Tabulka míst smlouvy: místo s proklikem do jeho detailu a umístění
     * (cesta ve stromu míst). Null = smlouva místa nemá.
     *
     * @return array<string, mixed>|null
     */
    private function placesTable(int $contractId): ?array
    {
        $tree = $this->placeTree();
        $rows = [];
        foreach ($this->db->fetchAll(
            'SELECT cp.`place`, pl.`name` FROM `' . ContractDocument::PLACES_TABLE . '` cp'
            . ' LEFT JOIN `' . PlaceDocument::TABLE . '` pl ON pl.`id` = cp.`place`'
            . ' WHERE cp.`contract` = %i ORDER BY cp.`order_pos`, cp.`id`',
            $contractId,
        ) as $row) {
            $placeId = (int) $row['place'];
            $rows[] = [
                'place'    => (string) ($row['name'] ?? ('#' . $placeId)),
                'location' => $tree->pathText($placeId),
                '_action'  => [
                    'id'     => 'openPlace',
                    'kind'   => 'open_detail',
                    'target' => ['viewerId' => self::PLACES_VIEWER, 'recordId' => $placeId],
                ],
            ];
        }
        if ($rows === []) {
            return null;
        }
        return [
            'type'    => 'table',
            'columns' => [
                ['id' => 'place', 'label' => $this->text('column.place', 'Place'), 'link' => true],
                ['id' => 'location', 'label' => $this->text('column.location', 'Location')],
            ],
            'rows' => $rows,
        ];
    }

    /** Strom míst pro cestu umístění (přepsatelné v testech). */
    protected function placeTree(): PlaceTree
    {
        return new PlaceTree($this->db);
    }
}
