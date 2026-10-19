<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates;

use Shipard\Core\Form\SubtableCellFormatter;
use Shipard\Core\Viewer\TableViewer;

/**
 * Nastavení → Účetnictví → Kurzovní lístky (#121 §9): jen ke čtení — bez
 * přidání, úprav a mazání (lístky zapisuje jen importer). Řádek: typ,
 * platnost od (do), číslo, počet měn, zdroj; filtr podle typu; řazení podle
 * platnosti sestupně. Detail: tabulka hodnot — měna, množství, kurz, kurz
 * za jednotku.
 */
class ExchangeRateListsViewer extends TableViewer
{
    public const string LABELS_CFG_ITEM = 'economy.exchangeRates.viewerDetailLabels';
    public const string SOURCES_CFG_ITEM = 'economy.exchangeRates.listSources';

    private ?ExchangeRateListTypes $types = null;

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT l.`id`, l.`list_type`, l.`home_currency`, l.`valid_from`, l.`valid_to`, l.`list_number`, l.`source`,'
            . ' (SELECT COUNT(*) FROM `' . ExchangeRateResolver::TABLE_VALUES . '` v WHERE v.`list` = l.`id`) AS `rate_count`'
            . ' FROM `' . $this->table . '` l';

        $conditions = [];
        $params = [];

        foreach ($filters as $filter) {
            if (($filter['id'] ?? '') !== 'list_type') {
                continue;
            }
            $value = $filter['value'] ?? null;
            if (is_string($value) && $value !== '' && $value !== '0') {
                $conditions[] = 'l.`list_type` = %s';
                $params[] = $value;
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(['list_number'], $search);
            if ($searchSql !== '') {
                $conditions[] = str_replace('`list_number`', 'l.`list_number`', $searchSql);
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY l.`valid_from` DESC, l.`list_type` ASC, l.`id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $listType = (string) ($rowData['list_type'] ?? '');
        $row = [
            'id' => (int) $rowData['id'],
            't1' => $this->types()->labelOf($listType),
            'i1' => !empty($rowData['list_number']) ? 'č. ' . $rowData['list_number'] : null,
        ];

        $t2 = [['text' => 'od ' . (SubtableCellFormatter::date($rowData['valid_from'] ?? null) ?? '')]];
        $validTo = SubtableCellFormatter::date($rowData['valid_to'] ?? null);
        if ($validTo !== null) {
            $t2[] = ['text' => 'do ' . $validTo];
        }
        $row['t2'] = $t2;

        $t3 = [];
        $count = (int) ($rowData['rate_count'] ?? 0);
        $t3[] = ['text' => $count . ' ' . self::pluralCurrencies($count)];
        $source = $this->sourceLabel($rowData['source'] ?? null);
        if ($source !== null) {
            $t3[] = ['text' => $source, 'class' => 'muted'];
        }
        $row['t3'] = $t3;

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow('SELECT * FROM `' . $this->table . '` WHERE `id` = %i', $recordId);
        if ($record === null) {
            return ['tabs' => []];
        }

        $items = [];
        $this->addItem($items, 'Typ', $this->types()->labelOf((string) ($record['list_type'] ?? '')));
        $this->addItem($items, 'Platnost od', SubtableCellFormatter::date($record['valid_from'] ?? null));
        $this->addItem($items, 'Platnost do', SubtableCellFormatter::date($record['valid_to'] ?? null));
        $this->addItem($items, 'Číslo lístku', $record['list_number'] ?? null);
        $this->addItem($items, 'Domácí měna', strtoupper((string) ($record['home_currency'] ?? '')));
        $this->addItem($items, 'Zdroj', $this->sourceLabel($record['source'] ?? null));

        $values = $this->db->fetchAll(
            'SELECT `currency`, `amount`, `rate` FROM `' . ExchangeRateResolver::TABLE_VALUES . '`'
            . ' WHERE `list` = %i ORDER BY `currency` ASC',
            $recordId,
        );
        $rows = [];
        foreach ($values as $value) {
            $amount = (int) ($value['amount'] ?? 1);
            $rows[] = [
                'currency' => strtoupper((string) ($value['currency'] ?? '')),
                'amount'   => (string) $amount,
                'rate'     => SubtableCellFormatter::trimmedNumber($value['rate'] ?? null, 6),
                'per_unit' => SubtableCellFormatter::trimmedNumber(ExchangeRate::perUnit((string) ($value['rate'] ?? '0'), $amount), 6),
            ];
        }
        $this->addItem($items, 'Počet měn', (string) count($rows));

        return ['tabs' => [
            [
                'id'      => 'overview',
                'label'   => $this->defaultOverviewLabel(),
                'content' => ['type' => 'properties', 'groups' => [['title' => 'Kurzovní lístek', 'items' => $items]]],
            ],
            [
                'id'      => 'values',
                'label'   => $this->detailTabLabel(self::LABELS_CFG_ITEM, 'values', 'Rates'),
                'content' => [
                    'type'    => 'table',
                    'columns' => [
                        ['id' => 'currency', 'label' => 'Měna'],
                        ['id' => 'amount',   'label' => 'Množství'],
                        ['id' => 'rate',     'label' => 'Kurz'],
                        ['id' => 'per_unit', 'label' => 'Kurz za jednotku'],
                    ],
                    'rows' => $rows,
                ],
            ],
        ]];
    }

    /** Lístky jsou jen ke čtení — žádné Add/Open. */
    public function getToolbarActions(?array $selectedRow): array
    {
        return [];
    }

    public function getFilters(): array
    {
        $types = $this->types()->all();
        if ($types === []) {
            return [];
        }
        $options = [['value' => '0', 'label' => 'Vše']];
        foreach ($types as $type) {
            $options[] = ['value' => $type->id, 'label' => $type->name];
        }
        return [[
            'id'      => 'list_type',
            'label'   => 'Typ',
            'type'    => 'enum',
            'default' => '0',
            'options' => $options,
        ]];
    }

    private function types(): ExchangeRateListTypes
    {
        return $this->types ??= ExchangeRateListTypes::fromConfig($this->config, $this->dsConfig?->getCountry() ?? 'cz');
    }

    private function sourceLabel(mixed $source): ?string
    {
        if ($source === null || $source === '') {
            return null;
        }
        $cfg = $this->config?->cfgItem(self::SOURCES_CFG_ITEM);
        $entry = is_array($cfg) ? ($cfg[(string) (int) $source] ?? $cfg[(int) $source] ?? null) : null;
        return is_array($entry) && isset($entry['name']) ? (string) $entry['name'] : (string) $source;
    }

    private static function pluralCurrencies(int $count): string
    {
        return match (true) {
            $count === 1 => 'měna',
            $count >= 2 && $count <= 4 => 'měny',
            default => 'měn',
        };
    }

    /** @param list<array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
