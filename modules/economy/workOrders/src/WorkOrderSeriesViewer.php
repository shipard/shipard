<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

/** Viewer číselných řad zakázek (Nastavení → Zakázky). */
class WorkOrderSeriesViewer extends WorkOrdersViewerBase
{
    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT s.`id`, s.`kind`, s.`name`, s.`number_code`, s.`number_pattern`, s.`reset_scope`,'
            . ' s.`valid_from`, s.`valid_to`, s.`notice`, s.`docState`, s.`docStateMain`,'
            . ' k.`name` AS `kind_name`, k.`type` AS `kind_type`'
            . ' FROM `' . $this->table . '` s'
            . ' LEFT JOIN `' . KindDocument::TABLE . '` k ON k.`id` = s.`kind`';

        [$conditions, $params] = $this->viewGroupCondition($filters, 's');

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(['name', 'number_code'], $search);
            $conditions[] = str_replace(['`name`', '`number_code`'], ['s.`name`', 's.`number_code`'], $searchSql);
            $params = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY s.`docStateMain` ASC, k.`name` ASC, s.`name` ASC, s.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? 10);
        $row = [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['name'] ?? ''),
            'i1' => !empty($rowData['number_code']) ? (string) $rowData['number_code'] : null,
        ];

        $t2 = [];
        if (!empty($rowData['kind_name'])) {
            $t2[] = ['text' => (string) $rowData['kind_name'], 'class' => 'primary'];
        }
        $type = (string) ($rowData['kind_type'] ?? '');
        if ($type !== '') {
            $t2[] = ['text' => $this->types()->label($type), 'class' => 'muted'];
        }
        if (!empty($rowData['number_pattern'])) {
            $t2[] = ['text' => (string) $rowData['number_pattern']];
        }
        $resetScope = $this->cfgLabel(WorkOrderSeriesForm::RESET_SCOPES_CFG_ITEM, (string) ($rowData['reset_scope'] ?? ''));
        if ($resetScope !== '') {
            $t2[] = ['text' => $resetScope, 'class' => 'muted'];
        }
        $validity = $this->validity($rowData);
        if ($validity !== null) {
            $t2[] = ['text' => $validity, 'class' => 'muted'];
        }
        $badge = $this->stateBadge($docState);
        if ($badge !== null) {
            $t2[] = $badge;
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        if (!empty($rowData['notice'])) {
            $row['t3'] = (string) $rowData['notice'];
        }
        $row['stateStyle'] = $this->stateStyleOf($docState);

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow(
            'SELECT s.*, k.`name` AS `kind_name`, k.`type` AS `kind_type`'
            . ' FROM `' . $this->table . '` s'
            . ' LEFT JOIN `' . KindDocument::TABLE . '` k ON k.`id` = s.`kind`'
            . ' WHERE s.`id` = %i',
            $recordId,
        );
        if ($record === null) {
            return ['tabs' => []];
        }

        $identity = [];
        $this->addItem($identity, $this->text('label.name', 'Name'), $record['name'] ?? null);
        $kind = (string) ($record['kind_name'] ?? '');
        $type = (string) ($record['kind_type'] ?? '');
        if ($kind !== '' && $type !== '') {
            $kind .= ' (' . $this->types()->label($type) . ')';
        }
        $this->addItem($identity, $this->text('label.kind', 'Kind'), $kind);
        $this->addItem($identity, $this->text('label.notice', 'Notice'), $record['notice'] ?? null);

        $numbering = [];
        $this->addItem($numbering, $this->text('label.code', 'Series code'), $record['number_code'] ?? null);
        $this->addItem($numbering, $this->text('label.pattern', 'Number pattern'), $record['number_pattern'] ?? null);
        $this->addItem(
            $numbering,
            $this->text('label.resetScope', 'Counter reset'),
            $this->cfgLabel(WorkOrderSeriesForm::RESET_SCOPES_CFG_ITEM, (string) ($record['reset_scope'] ?? '')),
        );

        $validity = [];
        $this->addItem($validity, $this->text('label.validFrom', 'Valid from'), $this->formatDate($record['valid_from'] ?? null));
        $this->addItem($validity, $this->text('label.validTo', 'Valid to'), $this->formatDate($record['valid_to'] ?? null));

        return $this->overviewDetail([
            ['title' => $this->text('group.identity', 'Identity'), 'items' => $identity],
            ['title' => $this->text('group.numbering', 'Numbering'), 'items' => $numbering],
            ['title' => $this->text('group.validity', 'Validity'), 'items' => $validity],
        ]);
    }

    /** `od – do` z platnosti řady; null bez platnosti. */
    private function validity(array $rowData): ?string
    {
        $from = $this->formatDate($rowData['valid_from'] ?? null);
        $to   = $this->formatDate($rowData['valid_to'] ?? null);
        if ($from === null && $to === null) {
            return null;
        }
        return ($from ?? '…') . ' – ' . ($to ?? '…');
    }
}
