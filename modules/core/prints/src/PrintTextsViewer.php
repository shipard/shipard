<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Prints;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Agenda Texty na tiscích (#90 D47) v Nastavení. Řádek ukazuje název,
 * umístění, na které tisky a jazyk text cílí a platnost; štítek „Platí
 * dnes“ má text, který by se dnes opravdu vytiskl — stav V pořádku a dnešek
 * uvnitř platnosti.
 */
class PrintTextsViewer extends TableViewer
{
    /** Stav, ve kterém se text tiskne. */
    private const ACTIVE_DOC_STATE = 40;

    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    private ?PrintTextChoices $choices = null;

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT `id`, `name`, `slot`, `prints`, `doc_types`, `number_series`, `language`,'
            . ' `valid_from`, `valid_to`, `order_pos`, `docState`, `docStateMain`'
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
            [$searchSql, $searchParams] = $this->buildSearchCondition(['name', 'text', 'note'], $search);
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY `docStateMain` ASC, `slot` ASC, `order_pos` ASC, `id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $choices  = $this->choices();
        $docState = (int) ($rowData['docState'] ?? 10);

        $badges = [['text' => $choices->slotName((string) ($rowData['slot'] ?? '')), 'class' => 'primary']];
        if (self::appliesOn($rowData, new \DateTimeImmutable('today'))) {
            $badges[] = ['text' => 'Platí dnes', 'class' => 'success'];
        }

        $target = [$this->printsLabel(self::listValue($rowData['prints'] ?? null))];
        $language = (string) ($rowData['language'] ?? '');
        if ($language !== '') {
            $target[] = $choices->languages()[$language] ?? $language;
        }
        if (self::listValue($rowData['doc_types'] ?? null) !== []
            || self::listValue($rowData['number_series'] ?? null) !== []
        ) {
            $target[] = 'jen vybrané typy a řady';
        }

        $validity = self::validityLabel($rowData['valid_from'] ?? null, $rowData['valid_to'] ?? null);

        return [
            'id'         => (int) $rowData['id'],
            't1'         => (string) ($rowData['name'] ?? ''),
            'i1'         => $badges,
            't2'         => ['text' => implode(' · ', $target), 'class' => 'muted'],
            't3'         => $validity !== '' ? $validity : null,
            'stateStyle' => $this->resolveStateStyle($docState),
        ];
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow('SELECT * FROM `' . $this->table . '` WHERE `id` = %i', $recordId);
        if ($record === null) {
            return ['tabs' => []];
        }
        $choices = $this->choices();
        $slot    = (string) ($record['slot'] ?? '');

        $text = [];
        $this->addItem($text, 'Název', $record['name'] ?? null);
        $this->addItem($text, 'Umístění', $choices->slotName($slot));
        $this->addItem($text, 'Text', $record['text'] ?? null);
        $this->addItem($text, 'Poznámka', $record['note'] ?? null);

        $printIds  = array_map('strval', self::listValue($record['prints'] ?? null));
        $targeting = $choices->targeting($printIds, $slot);
        $docTypes  = self::listValue($record['doc_types'] ?? null);
        $series    = self::listValue($record['number_series'] ?? null);
        $language  = (string) ($record['language'] ?? '');

        $target = [];
        $this->addItem($target, 'Tisky', $this->printsLabel($printIds));
        if ($docTypes !== []) {
            $labels = $targeting === null ? [] : $choices->docTypes($targeting, $printIds, $slot);
            $this->addItem($target, 'Typy dokladů', implode(', ', array_map(
                static fn (mixed $type): string => $labels[(string) $type] ?? (string) $type,
                $docTypes,
            )));
        }
        if ($series !== []) {
            $this->addItem($target, 'Číselné řady', implode(', ', $this->seriesNames($targeting, $series)));
        }
        $this->addItem($target, 'Jazyk', $language === '' ? 'Všechny jazyky' : ($choices->languages()[$language] ?? $language));

        $validity = [];
        $this->addItem($validity, 'Platí od', self::dateLabel($record['valid_from'] ?? null));
        $this->addItem($validity, 'Platí do', self::dateLabel($record['valid_to'] ?? null));
        $this->addItem($validity, 'Pořadí', (string) (int) ($record['order_pos'] ?? 0));
        $this->addItem(
            $validity,
            'Dnes',
            self::appliesOn($record, new \DateTimeImmutable('today')) ? 'Platí' : 'Neplatí',
        );

        return [
            'tabs' => [[
                'id'      => 'overview',
                'label'   => $this->defaultOverviewLabel(),
                'content' => [
                    'type'   => 'properties',
                    'groups' => [
                        ['title' => 'Text', 'items' => $text],
                        ['title' => 'Kde se text použije', 'items' => $target],
                        ['title' => 'Platnost', 'items' => $validity],
                    ],
                ],
            ]],
        ];
    }

    /**
     * Vytiskl by se text daný den? Stav V pořádku a den uvnitř platnosti
     * (oba kraje včetně). Cílení na tisk, typ, řadu a jazyk se tu neřeší —
     * to záleží na tištěném záznamu.
     *
     * @param array<string, mixed> $row
     */
    public static function appliesOn(array $row, \DateTimeImmutable $day): bool
    {
        if ((int) ($row['docState'] ?? 0) !== self::ACTIVE_DOC_STATE) {
            return false;
        }
        $date = $day->format('Y-m-d');
        $from = self::dateValue($row['valid_from'] ?? null);
        $to   = self::dateValue($row['valid_to'] ?? null);

        return ($from === null || $from <= $date) && ($to === null || $date <= $to);
    }

    private function choices(): PrintTextChoices
    {
        return $this->choices ??= new PrintTextChoices($this->config);
    }

    /** @param list<mixed> $printIds */
    private function printsLabel(array $printIds): string
    {
        if ($printIds === []) {
            return 'Všechny tisky';
        }
        $prints = $this->choices()->prints();
        return implode(', ', array_map(
            static fn (mixed $id): string => $prints[(string) $id]['name'] ?? (string) $id,
            $printIds,
        ));
    }

    /**
     * @param list<mixed> $ids
     * @return list<string>
     */
    private function seriesNames(?PrintTextTargeting $targeting, array $ids): array
    {
        $ids = array_map('intval', $ids);
        if ($targeting === null) {
            return array_map('strval', $ids);
        }
        $names = [];
        foreach ($this->db->fetchAll('SELECT `id`, `name` FROM %n WHERE `id` IN %in', $targeting->seriesTable, $ids) as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }
        return array_map(static fn (int $id): string => $names[$id] ?? (string) $id, $ids);
    }

    private static function validityLabel(mixed $from, mixed $to): string
    {
        $from = self::dateLabel($from);
        $to   = self::dateLabel($to);
        if ($from !== null && $to !== null) {
            return $from . ' – ' . $to;
        }
        if ($from !== null) {
            return 'od ' . $from;
        }
        return $to !== null ? 'do ' . $to : '';
    }

    private static function dateLabel(mixed $value): ?string
    {
        $date = self::dateValue($value);
        if ($date === null) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof \DateTimeImmutable ? $parsed->format('j. n. Y') : null;
    }

    private static function dateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    /** @return list<mixed> */
    private static function listValue(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }
        return is_array($value) ? array_values($value) : [];
    }

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
