<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Hosting — mail-routery (hosting_core_mail_routers). Settings viewer,
 * tabulka je adminOnly (D9) — přístup hlídá TableAccessGuard.
 */
class MailRoutersViewer extends TableViewer
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

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT `id`, `name`, `domains`, `api_key_prefix`, `last_seen`, `docState`, `docStateMain`'
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
            [$searchSql, $searchParams] = $this->buildSearchCondition(['name', 'domains'], $search);
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

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? 10);
        $stateStyle = $this->resolveStateStyle($docState);
        $isCs = ($this->language ?? 'en') === 'cs';

        $t2 = [
            ['text' => (string) ($rowData['domains'] ?? ''), 'class' => 'muted'],
        ];
        $hasKey = ($rowData['api_key_prefix'] ?? null) !== null;
        $t2[] = $hasKey
            ? ['text' => $isCs ? 'klíč: nastaven' : 'key: set', 'class' => 'success']
            : ['text' => $isCs ? 'klíč: chybí' : 'key: missing', 'class' => 'warning'];
        if ($docState !== 10) {
            $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
            $stateData = $cfg->getState($docState);
            $t2[] = [
                'text'  => $stateData['stateName'] ?? '',
                'class' => self::STATE_SPAN_CLASS[$stateStyle] ?? 'muted',
            ];
        }

        return [
            'id'         => (int) $rowData['id'],
            't1'         => (string) ($rowData['name'] ?? ''),
            't2'         => $t2,
            'stateStyle' => $stateStyle,
        ];
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM `' . $this->table . '` WHERE `id` = %i',
            $recordId,
        );
    }

    protected function detailTabs(array $record): array
    {
        $isCs = ($this->language ?? 'en') === 'cs';

        $items = [
            ['label' => $isCs ? 'Název' : 'Name', 'value' => (string) $record['name']],
            ['label' => $isCs ? 'Domény' : 'Domains', 'value' => (string) $record['domains']],
            [
                'label' => $isCs ? 'API klíč' : 'API key',
                'value' => ($record['api_key_prefix'] ?? null) !== null
                    ? ($isCs ? 'nastaven' : 'set') . ' (' . $record['api_key_prefix'] . '…)'
                    : ($isCs ? 'chybí' : 'missing'),
            ],
        ];
        if (!empty($record['last_seen'])) {
            $items[] = ['label' => $isCs ? 'Naposledy viděn' : 'Last seen', 'value' => (string) $record['last_seen']];
        }
        if (!empty($record['note'])) {
            $items[] = ['label' => $isCs ? 'Poznámka' : 'Note', 'value' => (string) $record['note']];
        }

        return [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => [
                'type'   => 'properties',
                'groups' => [['title' => $isCs ? 'Mail-router' : 'Mail router', 'items' => $items]],
            ],
        ]];
    }

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
    }
}
