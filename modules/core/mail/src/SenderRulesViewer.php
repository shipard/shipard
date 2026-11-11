<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class SenderRulesViewer extends TableViewer
{
    private const LABELS_CFG_ITEM = 'core.mail.viewerDetailLabels';

    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT `id`, `pattern_kind`, `pattern`, `disposition`, `origin`,'
            . ' `hit_count`, `last_hit_at`, `notice`, `docState`, `docStateMain`'
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
                ['pattern', 'notice'],
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

        $sql .= ' ORDER BY `docStateMain` ASC, `pattern` ASC, `id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => (string) ($rowData['pattern'] ?? ''),
            'stateStyle' => $this->resolveStateStyle((int) ($rowData['docState'] ?? 10)),
        ];

        $badges = [];
        if ((string) ($rowData['pattern_kind'] ?? '') === 'domain') {
            $badges[] = ['text' => $this->cfgLabel('core.mail.senderRulePatternKinds', 'domain'), 'class' => 'primary'];
        }
        if ((string) ($rowData['origin'] ?? '') === 'suggested') {
            $badges[] = ['text' => $this->cfgLabel('core.mail.senderRuleOrigins', 'suggested'), 'class' => 'warning'];
        }
        $row['i1'] = $badges !== [] ? $badges : null;

        $notice = (string) ($rowData['notice'] ?? '');
        $row['t2'] = $notice !== '' ? ['text' => $notice, 'class' => 'muted'] : null;

        $t3Parts = [];
        $hitCount = (int) ($rowData['hit_count'] ?? 0);
        if ($hitCount > 0) {
            $t3Parts[] = $hitCount . '×';
        }
        $lastHit = (string) ($rowData['last_hit_at'] ?? '');
        if ($lastHit !== '') {
            $t3Parts[] = $lastHit;
        }
        $row['t3'] = $t3Parts !== [] ? implode(' · ', $t3Parts) : null;

        return $row;
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT `id`, `docState`, `pattern_kind`, `pattern`, `disposition`, `origin`,'
            . ' `hit_count`, `last_hit_at`, `notice`, `created`, `modified`'
            . ' FROM `' . $this->table . '` WHERE `id` = %i',
            $recordId,
        );
    }

    protected function detailTabs(array $record): array
    {
        $rule = [];
        $this->addItem($rule, $this->columnLabel('pattern_kind', 'Pattern kind'), $this->cfgLabel('core.mail.senderRulePatternKinds', (string) ($record['pattern_kind'] ?? '')));
        $this->addItem($rule, $this->columnLabel('disposition', 'Disposition'), $this->cfgLabel('core.mail.senderRuleDispositions', (string) ($record['disposition'] ?? '')));
        $this->addItem($rule, $this->columnLabel('origin', 'Origin'), $this->cfgLabel('core.mail.senderRuleOrigins', (string) ($record['origin'] ?? '')));
        $this->addItem($rule, $this->columnLabel('notice', 'Notice'), $record['notice'] ?? null);

        $stats = [];
        $this->addItem($stats, $this->columnLabel('hit_count', 'Hit count'), (string) (int) ($record['hit_count'] ?? 0));
        $this->addItem($stats, $this->columnLabel('last_hit_at', 'Last hit'), $record['last_hit_at'] ?? null);

        $status = [];
        $this->addItem($status, $this->columnLabel('created', 'Created'), $record['created'] ?? null);
        $this->addItem($status, $this->columnLabel('modified', 'Modified'), $record['modified'] ?? null);

        return [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => [
                'type'   => 'properties',
                'groups' => [
                    ['title' => $this->text('groupRule', 'Rule'), 'items' => $rule],
                    ['title' => $this->text('groupStatistics', 'Statistics'), 'items' => $stats],
                    ['title' => $this->text('status', 'Status'), 'items' => $status],
                ],
            ],
        ]];
    }

    private function cfgLabel(string $cfgItemId, string $key): string
    {
        if ($key === '' || $this->config === null) {
            return $key;
        }
        $cfg = $this->config->cfgItem($cfgItemId);
        if (is_array($cfg) && isset($cfg[$key]['name'])) {
            return (string) $cfg[$key]['name'];
        }
        return $key;
    }

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
    }

    /**
     * Text vieweru z katalogu `core.mail.viewerDetailLabels` (sekce `texts`).
     *
     * @param array<string, scalar> $params
     */
    private function text(string $key, string $englishFallback, array $params = []): string
    {
        return $this->detailText(self::LABELS_CFG_ITEM, $key, $englishFallback, $params);
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
