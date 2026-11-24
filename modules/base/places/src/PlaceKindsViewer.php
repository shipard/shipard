<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Places;

/**
 * Viewer druhů míst (Nastavení → Místa, #169 D2, D14): název, typ a ikona
 * druhu — vlastní, nebo ikona typu.
 */
class PlaceKindsViewer extends PlacesViewerBase
{
    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT k.`id`, k.`name`, k.`type`, k.`icon`, k.`docState`, k.`docStateMain`'
            . ' FROM `' . $this->table . '` k';

        [$conditions, $params] = $this->viewGroupCondition($filters, 'k');

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(['name'], $search);
            $conditions[] = str_replace('`name`', 'k.`name`', $searchSql);
            $params = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY k.`docStateMain` ASC, k.`name` ASC, k.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? 10);
        $type = (string) ($rowData['type'] ?? '');
        $row = [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['name'] ?? ''),
        ];

        $t2 = [];
        if ($type !== '') {
            $t2[] = ['text' => $this->types()->label($type), 'class' => 'primary'];
        }
        $badge = $this->rowStateTag($docState);
        if ($badge !== null) {
            $t2[] = $badge;
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        $icon = $this->kindIcon($rowData['icon'] ?? null, $type);
        if ($icon !== null) {
            $row['icon'] = $icon;
        }
        $row['stateStyle'] = $this->stateStyleOf($docState);

        return $row;
    }

    protected function detailTabs(array $record): array
    {
        $type = (string) ($record['type'] ?? '');
        $icon = trim((string) ($record['icon'] ?? ''));

        $identity = [];
        $this->addItem(
            $identity,
            $this->columnLabel('type', 'Place type'),
            $type !== '' ? $this->types()->label($type) : null,
        );
        $this->addItem(
            $identity,
            $this->columnLabel('icon', 'Icon'),
            $icon !== ''
                ? $this->cfgLabel(PlaceKindDocument::ICONS_CFG_ITEM, $icon)
                : $this->text('value.iconByType', 'By type'),
        );

        return [$this->propertiesTab([
            ['title' => $this->sharedText('groupIdentity', 'Identity'), 'items' => $identity],
        ])];
    }
}
