<?php

declare(strict_types=1);

namespace Shipard\Module\Tasks\Core;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\Detail\DetailHeader;
use Shipard\Core\Viewer\TableViewer;

class TasksViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'tasks.core.docStatesTasks';

    // Pozn.: `edit` (Pozastaveno) je záměrně mapováno na `muted`,
    // aby se inline state badge v t2 vizuálně neslévalo s `concept`
    // (Nový → `warning`). Levý pruh řádku a badge v hlavičce formuláře
    // si vlastní barvu drží přes CSS proměnnou --shpd-color-state-edit-*.
    private const STATE_SPAN_CLASS = [
        'concept'   => 'warning',
        'confirmed' => 'primary',
        'done'      => 'success',
        'edit'      => 'muted',
        'archive'   => 'muted',
        'trash'     => 'muted',
        'cancelled' => 'danger',
    ];

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT t.`id`, t.`title`, t.`description`, t.`priority`, t.`due_date`,'
            . ' t.`created`, t.`docState`, t.`docStateMain`, t.`created_by`,'
            . ' u.`full_name` AS `creator_name`, u.`login` AS `creator_login`'
            . ' FROM `' . $this->table . '` t'
            . ' LEFT JOIN `core_system_users` u ON u.`id` = t.`created_by`';

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
                ['title', 'description'],
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

        // Pořadí: stav (Nový/V práci nahoru), pak termín (nejbližší první),
        // pak id pro deterministické řazení.
        $sql .= ' ORDER BY t.`docStateMain` ASC, t.`due_date` IS NULL ASC,'
            . ' t.`due_date` ASC, t.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? 10);
        $stateStyle = $this->resolveStateStyle($docState);

        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => (string) ($rowData['title'] ?? ''),
            'stateStyle' => $stateStyle,
        ];

        $t2 = [];

        $priority = (string) ($rowData['priority'] ?? '');
        if ($priority !== '') {
            $cfg = $this->config?->cfgItem('tasks.core.priorities');
            if (is_array($cfg) && isset($cfg[$priority])) {
                $t2[] = [
                    'text'  => (string) ($cfg[$priority]['name'] ?? $priority),
                    'class' => (string) ($cfg[$priority]['spanClass'] ?? 'muted'),
                ];
            }
        }

        $dueDate = $rowData['due_date'] ?? null;
        if ($dueDate !== null && $dueDate !== '') {
            $formatted = $this->formatDate($dueDate);
            $isOverdue = $this->isOverdue($dueDate, $docState);
            $t2[] = [
                'text'  => $formatted,
                'class' => $isOverdue ? 'danger' : 'muted',
            ];
        }

        if ($docState !== 10) {
            $cfg = DocStateConfig::fromCfgItem(
                $this->config?->cfgItem($this->docStatesCfgItem),
            );
            $stateData = $cfg->getState($docState);
            $t2[] = [
                'text'  => (string) ($stateData['stateName'] ?? ''),
                'class' => self::STATE_SPAN_CLASS[$stateStyle] ?? 'muted',
            ];
        }

        $row['t2'] = $t2 !== [] ? $t2 : null;

        $creator = $this->resolveCreatorLabel($rowData);
        $row['t3'] = $creator !== '' ? 'Vytvořil: ' . $creator : null;

        return $row;
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT t.*, u.`full_name` AS `creator_name`, u.`login` AS `creator_login`'
            . ' FROM `' . $this->table . '` t'
            . ' LEFT JOIN `core_system_users` u ON u.`id` = t.`created_by`'
            . ' WHERE t.`id` = %i',
            $recordId,
        );
    }

    /**
     * Přehled: údaje o vzniku úkolu a popis — název, priorita, termín
     * a stav jsou v hlavičce detailu.
     */
    protected function detailTabs(array $record): array
    {
        $items = [];
        $this->addItem($items, 'Vytvořil', $this->resolveCreatorLabel($record));
        $this->addItem($items, 'Vytvořeno', $this->formatDateTime($record['created'] ?? null));

        $description = trim((string) ($record['description'] ?? ''));

        $groups = [['title' => 'Úkol', 'items' => $items]];
        if ($description !== '') {
            $groups[] = [
                'title' => 'Popis',
                'items' => [['label' => '', 'value' => $description]],
                'wide'  => true,
            ];
        }

        return [$this->propertiesTab($groups)];
    }

    /** Titulek název úkolu, podtitul „Termín …"; štítek stavu přidává builder (#136 D20). */
    protected function detailHeader(array $record): DetailHeader
    {
        $dueDate = $this->formatDate($record['due_date'] ?? null);
        $title = trim((string) ($record['title'] ?? ''));

        return new DetailHeader(
            $title !== '' ? $title : '(bez názvu)',
            $dueDate !== '' ? 'Termín ' . $dueDate : null,
        );
    }

    /** Priorita (badge styly detailu nemají `muted` — mapuje se na `neutral`) a Po termínu. */
    protected function detailBadges(array $record): array
    {
        $docState = (int) ($record['docState'] ?? 10);
        $badges = [];

        $priority = (string) ($record['priority'] ?? '');
        if ($priority !== '') {
            $cfg = $this->config?->cfgItem('tasks.core.priorities');
            if (is_array($cfg) && isset($cfg[$priority])) {
                $spanClass = (string) ($cfg[$priority]['spanClass'] ?? 'muted');
                $badges[] = [
                    'label' => (string) ($cfg[$priority]['name'] ?? $priority),
                    'style' => $spanClass === 'muted' ? 'neutral' : $spanClass,
                ];
            }
        }

        if ($this->isOverdue($record['due_date'] ?? null, $docState)) {
            $badges[] = ['label' => 'Po termínu', 'style' => 'danger'];
        }

        return $badges;
    }

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
    }

    private function resolveCreatorLabel(array $row): string
    {
        $name = trim((string) ($row['creator_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        return trim((string) ($row['creator_login'] ?? ''));
    }

    private function isOverdue(mixed $dueDate, int $docState): bool
    {
        if (in_array($docState, [40, 70, 90], true)) {
            return false;
        }
        $ts = $this->parseDateTs($dueDate);
        if ($ts === null) {
            return false;
        }
        $today = (int) strtotime(date('Y-m-d') . ' 00:00:00');
        return $ts < $today;
    }

    private function formatDate(mixed $value): string
    {
        $ts = $this->parseDateTs($value);
        return $ts !== null ? date('j. n. Y', $ts) : '';
    }

    private function formatDateTime(mixed $value): string
    {
        $ts = $this->parseDateTs($value);
        return $ts !== null ? date('j. n. Y H:i', $ts) : '';
    }

    private function parseDateTs(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        $ts = is_int($value) ? $value : strtotime((string) $value);
        return $ts !== false ? $ts : null;
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
