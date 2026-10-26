<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Hosting — zdroje dat (hosting_core_data_sources). Settings viewer,
 * tabulka je adminOnly (D9). Lifecycle mimo `active` se zobrazuje jako
 * badge (labely z cfgItem hosting.core.dsLifecycle). Názvy serverů se
 * dotahují druhým dotazem — buildViewGroupFilter/buildSearchCondition
 * neumí aliasy, JOIN by způsobil ambiguitu `docState`.
 */
class DataSourcesViewer extends TableViewer
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

    private const LIFECYCLE_BADGE_CLASS = [
        'request'   => 'warning',
        'creating'  => 'info',
        'suspended' => 'danger',
        'failed'    => 'danger',
    ];

    /** @var array<int, string> */
    private array $serverNames = [];

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT `id`, `ds_id`, `name`, `web_id`, `server`, `url_app`,'
            . ' `lifecycle`, `docState`, `docStateMain`'
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
            [$searchSql, $searchParams] = $this->buildSearchCondition(
                ['name', 'ds_id', 'web_id'],
                $search,
            );
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY `docStateMain` ASC, `name` ASC, `id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        $rows = $this->db->fetchAll($sql, ...$params);
        $this->serverNames = $this->fetchServerNames($rows);

        return $rows;
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? 10);
        $stateStyle = $this->resolveStateStyle($docState);

        $t2 = [
            ['text' => (string) ($rowData['ds_id'] ?? ''), 'class' => 'muted'],
        ];
        $serverName = $this->serverNames[(int) ($rowData['server'] ?? 0)] ?? null;
        if ($serverName !== null) {
            $t2[] = ['text' => $serverName, 'class' => 'muted'];
        }
        if ($docState !== 10) {
            $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
            $stateData = $cfg->getState($docState);
            $t2[] = [
                'text'  => $stateData['stateName'] ?? '',
                'class' => self::STATE_SPAN_CLASS[$stateStyle] ?? 'muted',
            ];
        }

        $badges = [];
        $lifecycle = (string) ($rowData['lifecycle'] ?? 'active');
        if ($lifecycle !== 'active') {
            $badges[] = [
                'text'  => $this->resolveLifecycleLabel($lifecycle),
                'class' => self::LIFECYCLE_BADGE_CLASS[$lifecycle] ?? 'muted',
            ];
        }

        return [
            'id'         => (int) $rowData['id'],
            't1'         => (string) ($rowData['name'] ?? ''),
            't2'         => $t2,
            'i1'         => $badges !== [] ? $badges : null,
            'stateStyle' => $stateStyle,
        ];
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT ds.*, srv.`name` AS `server_name`'
            . ' FROM `' . $this->table . '` AS ds'
            . ' LEFT JOIN `hosting_core_servers` AS srv ON srv.`id` = ds.`server`'
            . ' WHERE ds.`id` = %i',
            $recordId,
        );
    }

    protected function detailTabs(array $record): array
    {
        $isCs = ($this->language ?? 'en') === 'cs';

        $identity = [
            ['label' => $isCs ? 'Název' : 'Name', 'value' => (string) $record['name']],
            ['label' => $isCs ? 'ID zdroje dat' : 'Data source ID', 'value' => (string) $record['ds_id']],
        ];
        if (!empty($record['web_id'])) {
            $identity[] = ['label' => 'Web ID', 'value' => (string) $record['web_id']];
        }
        $identity[] = [
            'label' => $isCs ? 'Jazyk' : 'Language',
            'value' => $this->resolveCfgLabel('world.base.languages', (string) ($record['language'] ?? '')),
        ];
        $identity[] = [
            'label' => $isCs ? 'Země' : 'Country',
            'value' => $this->resolveCfgLabel('world.base.countries', (string) ($record['country'] ?? '')),
        ];

        $placement = [
            ['label' => $isCs ? 'URL aplikace' : 'Application URL', 'value' => (string) $record['url_app']],
            [
                'label' => 'Lifecycle',
                'value' => $this->resolveLifecycleLabel((string) ($record['lifecycle'] ?? 'active')),
            ],
        ];
        if (!empty($record['server_name'])) {
            $placement[] = ['label' => 'Server', 'value' => (string) $record['server_name']];
        }
        if (!empty($record['install_module'])) {
            $placement[] = ['label' => $isCs ? 'Install modul' : 'Install module', 'value' => (string) $record['install_module']];
        }
        if (!empty($record['owner'])) {
            $ownerRow = $this->db->fetchRow(
                'SELECT `full_name`, `login` FROM `core_system_users` WHERE `id` = %i',
                (int) $record['owner'],
            );
            if ($ownerRow !== null) {
                $ownerName = trim((string) ($ownerRow['full_name'] ?? ''));
                $placement[] = [
                    'label' => $isCs ? 'Vlastník' : 'Owner',
                    'value' => $ownerName !== '' ? $ownerName . ' (' . $ownerRow['login'] . ')' : (string) $ownerRow['login'],
                ];
            }
        }
        if (!empty($record['provision_error'])) {
            $placement[] = [
                'label' => $isCs ? 'Chyba provisioningu' : 'Provisioning error',
                'value' => (string) $record['provision_error'],
            ];
        }

        return [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => [
                'type'   => 'properties',
                'groups' => [
                    ['title' => $isCs ? 'Identifikace' : 'Identity', 'items' => $identity],
                    ['title' => $isCs ? 'Umístění' : 'Placement', 'items' => $placement],
                ],
            ],
        ]];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function fetchServerNames(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (!empty($row['server'])) {
                $ids[(int) $row['server']] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $names = [];
        $serverRows = $this->db->fetchAll(
            'SELECT `id`, `name` FROM `hosting_core_servers` WHERE `id` IN %in',
            array_keys($ids),
        );
        foreach ($serverRows as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }
        return $names;
    }

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
    }

    private function resolveLifecycleLabel(string $key): string
    {
        return $this->resolveCfgLabel('hosting.core.dsLifecycle', $key);
    }

    /** Lokalizovaný název položky cfgItemu; fallback na samotný kód. */
    private function resolveCfgLabel(string $cfgItemId, string $key): string
    {
        $cfg = $this->config?->cfgItem($cfgItemId);
        if (is_array($cfg) && isset($cfg[$key]['name'])) {
            return (string) $cfg[$key]['name'];
        }
        return $key;
    }
}
