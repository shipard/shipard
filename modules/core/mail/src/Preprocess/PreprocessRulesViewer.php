<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Viewer pravidel předzpracování (Nastavení → Pošta). Rozsah = sender
 * rules (D13): seznam s podmínkami a hit statistikou, detail s akcemi.
 */
class PreprocessRulesViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT `id`, `rule_id`, `origin`, `notice`, `sender_email`, `sender_domain`,'
            . ' `subject_regex`, `body_regex`, `hit_count`, `last_hit_at`, `docState`, `docStateMain`'
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
                ['rule_id', 'notice', 'sender_email', 'sender_domain', 'subject_regex', 'body_regex'],
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

        $sql .= ' ORDER BY `docStateMain` ASC, `rule_id` ASC, `id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $notice = trim((string) ($rowData['notice'] ?? ''));
        $ruleId = (string) ($rowData['rule_id'] ?? '');

        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => $notice !== '' ? $notice : $ruleId,
            'stateStyle' => $this->resolveStateStyle((int) ($rowData['docState'] ?? 10)),
        ];

        $badges = [];
        if ((string) ($rowData['origin'] ?? '') === 'system') {
            $badges[] = ['text' => $this->cfgLabel('core.mail.preprocessRuleOrigins', 'system'), 'class' => 'primary'];
        }
        $row['i1'] = $badges !== [] ? $badges : null;

        $conditions = $this->describeConditions($rowData);
        $row['t2'] = $conditions !== '' ? ['text' => $conditions, 'class' => 'muted'] : null;

        $t3Parts = [];
        if ($notice !== '') {
            $t3Parts[] = $ruleId;
        }
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
            'SELECT `id`, `docState`, `rule_id`, `origin`, `notice`, `sender_email`, `sender_domain`,'
            . ' `subject_regex`, `body_regex`, `actions`, `hit_count`, `last_hit_at`, `created`, `modified`'
            . ' FROM `' . $this->table . '` WHERE `id` = %i',
            $recordId,
        );
    }

    protected function detailTabs(array $record): array
    {
        $rule = [];
        $this->addItem($rule, 'Původ', $this->cfgLabel('core.mail.preprocessRuleOrigins', (string) ($record['origin'] ?? '')));
        $this->addItem($rule, 'Poznámka', $record['notice'] ?? null);

        $match = [];
        $this->addItem($match, 'E-mail odesílatele', $record['sender_email'] ?? null);
        $this->addItem($match, 'Doména odesílatele', $record['sender_domain'] ?? null);
        $this->addItem($match, 'Regex předmětu', $record['subject_regex'] ?? null);
        $this->addItem($match, 'Regex těla', $record['body_regex'] ?? null);

        $actions = [];
        foreach (PreprocessRuleMatcher::decodeActions($record['actions'] ?? null) ?? [] as $i => $action) {
            $key = (string) ($action['action'] ?? '');
            $params = $action;
            unset($params['action']);
            $this->addItem(
                $actions,
                ($i + 1) . '. ' . $this->cfgLabel('core.mail.preprocessActions', $key),
                $params !== [] ? (string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '—',
            );
        }
        if ($actions === []) {
            $this->addItem($actions, 'Akce', (string) ($record['actions'] ?? ''));
        }

        $stats = [];
        $this->addItem($stats, 'Počet zásahů', (string) (int) ($record['hit_count'] ?? 0));
        $this->addItem($stats, 'Poslední zásah', $record['last_hit_at'] ?? null);

        $status = [];
        $this->addItem($status, 'Vytvořeno', $record['created'] ?? null);
        $this->addItem($status, 'Změněno', $record['modified'] ?? null);

        return [$this->propertiesTab([
            ['title' => 'Pravidlo', 'items' => $rule],
            ['title' => 'Podmínky shody', 'items' => $match],
            ['title' => 'Akce', 'items' => $actions],
            ['title' => 'Statistiky', 'items' => $stats],
            ['title' => 'Stav', 'items' => $status],
        ])];
    }

    /** Krátký popis podmínek pro řádek seznamu. */
    private function describeConditions(array $row): string
    {
        $parts = [];
        foreach ([
            'sender_email' => 'e-mail',
            'sender_domain' => 'doména',
            'subject_regex' => 'předmět',
            'body_regex' => 'tělo',
        ] as $column => $label) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '') {
                $parts[] = $label . ': ' . $value;
            }
        }
        return implode(' · ', $parts);
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

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
