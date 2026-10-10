<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Ai;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/**
 * Lookup pro AI připojení — používají ho formuláře AI funkce
 * (`connection`) a AI profilu pošty (`backend`). Malá konfigurační
 * tabulka, search přes název / kód připojení.
 *
 * Primary: name. Secondary: backend_id · platforma (připojení model nenese,
 * D39). resolve() vrací popis pro libovolné ID (i archivované), aby form
 * uměl zobrazit dříve vybrané připojení; search řadí výchozí nahoru.
 */
class AIBackendLookup extends TableLookup
{
    public function search(string $q, array $filter, int $limit): array
    {
        if ($this->db === null) {
            return [];
        }

        $sql = 'SELECT `id`, `backend_id`, `name`, `provider`, `is_active`'
            . ' FROM `core_ai_backends`';
        $params = [];
        if ($q !== '') {
            [$searchSql, $searchParams] = SearchCondition::anyContains(['`name`', '`backend_id`'], $q);
            $sql .= ' WHERE ' . $searchSql;
            $params = array_merge($params, $searchParams);
        }
        $sql .= ' ORDER BY `is_default` DESC, `name` ASC, `id` ASC LIMIT %i';
        $params[] = $limit;

        $rows = $this->db->fetchAll($sql, ...$params);
        return array_map(fn(array $r) => self::buildItem($r), $rows);
    }

    public function resolve(array $ids): array
    {
        if ($this->db === null || $ids === []) {
            return [];
        }
        $intIds = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        if ($intIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `backend_id`, `name`, `provider`, `is_active`'
            . ' FROM `core_ai_backends` WHERE `id` IN %in',
            $intIds,
        );
        return array_map(fn(array $r) => self::buildItem($r), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function buildItem(array $row): LookupItem
    {
        $name = trim((string) ($row['name'] ?? ''));
        $backendId = trim((string) ($row['backend_id'] ?? ''));
        $provider = trim((string) ($row['provider'] ?? ''));

        $primary = $name !== '' ? $name : ($backendId !== '' ? $backendId : '#' . (string) $row['id']);

        $parts = [];
        if ($backendId !== '') {
            $parts[] = $backendId;
        }
        if ($provider !== '') {
            $parts[] = $provider;
        }
        if (array_key_exists('is_active', $row) && empty($row['is_active'])) {
            $parts[] = 'neaktivní';
        }
        $secondary = $parts !== [] ? implode(' · ', $parts) : null;

        return new LookupItem(
            id: (int) $row['id'],
            primary: $primary,
            secondary: $secondary,
        );
    }
}
