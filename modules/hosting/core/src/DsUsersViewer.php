<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Hosting — vazby uživatel ↔ zdroj dat (hosting_core_ds_users). Settings
 * viewer, tabulka je adminOnly (D9). Labely uživatelů a DS se dotahují
 * druhými dotazy — buildViewGroupFilter/buildSearchCondition neumí aliasy,
 * JOIN by způsobil ambiguitu `docState`.
 */
class DsUsersViewer extends TableViewer
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

    /** @var array<int, string> */
    private array $userNames = [];

    /** @var array<int, string> */
    private array $dsNames = [];

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT `id`, `user`, `data_source`, `role`, `last_entered`,'
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

        // Fulltext hledání jde přes labely z vazebních tabulek — nejdřív se
        // najdou odpovídající id, pak se filtruje IN podmínkou.
        if ($search !== null && $search !== '') {
            [$userSql, $userParams] = SearchCondition::anyContains(['`full_name`', '`login`'], $search);
            $userIds = $this->db->fetchAll(
                'SELECT `id` FROM `core_system_users` WHERE ' . $userSql,
                ...$userParams,
            );
            $dsIds = $this->db->fetchAll(
                'SELECT `id` FROM `hosting_core_data_sources` WHERE ' . SearchCondition::contains('`name`'),
                $search,
            );
            $userIdList = array_map(static fn ($r) => (int) $r['id'], $userIds);
            $dsIdList   = array_map(static fn ($r) => (int) $r['id'], $dsIds);

            if ($userIdList === [] && $dsIdList === []) {
                $conditions[] = '1=0';
            } else {
                $parts = [];
                if ($userIdList !== []) {
                    $parts[] = '`user` IN %in';
                    $params[] = $userIdList;
                }
                if ($dsIdList !== []) {
                    $parts[] = '`data_source` IN %in';
                    $params[] = $dsIdList;
                }
                $conditions[] = '(' . implode(' OR ', $parts) . ')';
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY `docStateMain` ASC, `data_source` ASC, `user` ASC, `id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        $rows = $this->db->fetchAll($sql, ...$params);
        $this->loadLabels($rows);

        return $rows;
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? 10);
        $stateStyle = $this->resolveStateStyle($docState);

        $t2 = [
            [
                'text'  => $this->dsNames[(int) ($rowData['data_source'] ?? 0)] ?? '#' . $rowData['data_source'],
                'class' => 'muted',
            ],
        ];
        if ($docState !== 10) {
            $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
            $stateData = $cfg->getState($docState);
            $t2[] = [
                'text'  => $stateData['stateName'] ?? '',
                'class' => self::STATE_SPAN_CLASS[$stateStyle] ?? 'muted',
            ];
        }

        $badges = [];
        if ((string) ($rowData['role'] ?? 'member') === 'admin') {
            $badges[] = ['text' => $this->resolveRoleLabel('admin'), 'class' => 'info'];
        }

        return [
            'id'         => (int) $rowData['id'],
            't1'         => $this->userNames[(int) ($rowData['user'] ?? 0)] ?? '#' . $rowData['user'],
            't2'         => $t2,
            'i1'         => $badges !== [] ? $badges : null,
            'stateStyle' => $stateStyle,
        ];
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow(
            'SELECT du.*, u.`full_name` AS `user_name`, u.`login` AS `user_login`,'
            . ' ds.`name` AS `ds_name`'
            . ' FROM `' . $this->table . '` AS du'
            . ' LEFT JOIN `core_system_users` AS u ON u.`id` = du.`user`'
            . ' LEFT JOIN `hosting_core_data_sources` AS ds ON ds.`id` = du.`data_source`'
            . ' WHERE du.`id` = %i',
            $recordId,
        );

        if ($record === null) {
            return ['tabs' => []];
        }

        $isCs = ($this->language ?? 'en') === 'cs';

        $items = [
            [
                'label' => $isCs ? 'Uživatel' : 'User',
                'value' => (string) ($record['user_name'] ?? '')
                    . (!empty($record['user_login']) ? ' (' . $record['user_login'] . ')' : ''),
            ],
            ['label' => $isCs ? 'Zdroj dat' : 'Data source', 'value' => (string) ($record['ds_name'] ?? '')],
            ['label' => $isCs ? 'Role' : 'Role', 'value' => $this->resolveRoleLabel((string) ($record['role'] ?? 'member'))],
        ];
        if (!empty($record['last_entered'])) {
            $items[] = ['label' => $isCs ? 'Poslední vstup' : 'Last entered', 'value' => (string) $record['last_entered']];
        }

        return [
            'tabs' => [[
                'id'      => 'overview',
                'label'   => $this->defaultOverviewLabel(),
                'content' => [
                    'type'   => 'properties',
                    'groups' => [['title' => $isCs ? 'Vazba' : 'Link', 'items' => $items]],
                ],
            ]],
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function loadLabels(array $rows): void
    {
        $userIds = [];
        $dsIds = [];
        foreach ($rows as $row) {
            if (!empty($row['user'])) {
                $userIds[(int) $row['user']] = true;
            }
            if (!empty($row['data_source'])) {
                $dsIds[(int) $row['data_source']] = true;
            }
        }

        $this->userNames = [];
        if ($userIds !== []) {
            $userRows = $this->db->fetchAll(
                'SELECT `id`, `full_name`, `login` FROM `core_system_users` WHERE `id` IN %in',
                array_keys($userIds),
            );
            foreach ($userRows as $row) {
                $name = trim((string) ($row['full_name'] ?? ''));
                $this->userNames[(int) $row['id']] = $name !== '' ? $name : (string) $row['login'];
            }
        }

        $this->dsNames = [];
        if ($dsIds !== []) {
            $dsRows = $this->db->fetchAll(
                'SELECT `id`, `name` FROM `hosting_core_data_sources` WHERE `id` IN %in',
                array_keys($dsIds),
            );
            foreach ($dsRows as $row) {
                $this->dsNames[(int) $row['id']] = (string) $row['name'];
            }
        }
    }

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
    }

    private function resolveRoleLabel(string $key): string
    {
        $cfg = $this->config?->cfgItem('hosting.core.dsUserRoles');
        if (is_array($cfg) && isset($cfg[$key]['name'])) {
            return (string) $cfg[$key]['name'];
        }
        return $key;
    }
}
