<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Enrich;

use Shipard\Core\Viewer\TableViewer;

/**
 * Viewer pravidel IČO → obsahový štítek (`core_exchange_tag_rules`) —
 * settings sekce Položky (tasks/content-tag-ui.md D28). Bezstavová tabulka
 * (bez docStates): žádné view groups, mazání detail akcí (hard DELETE —
 * unique(company_id) nesmí blokovat re-learning a learning handler sám
 * pravidla při konfliktu maže).
 *
 * Žádné ruční zakládání v v1 (pravidla vznikají učením, seed importem
 * později) — toolbar nenabízí Přidat.
 */
class TagRulesViewer extends TableViewer
{
    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        // Jméno partnera dle IČO — best-effort subselect (víc osob se
        // stejným IČO by JOINem duplikoval řádky pravidel; bere se živá
        // osoba s nejnižším stavem/ID).
        $partnerName = '(SELECT `p`.`full_name` FROM `base_persons_persons` `p`'
            . ' WHERE `p`.`company_id` = `r`.`company_id` AND `p`.`docState` IN (10, 40, 80)'
            . ' ORDER BY `p`.`docStateMain` ASC, `p`.`id` ASC LIMIT 1)';

        $sql = 'SELECT `r`.`id`, `r`.`company_id`, `r`.`tag`, `r`.`origin`,'
            . ' `r`.`confirmed`, `r`.`hit_count`, `r`.`last_hit_at`,'
            . ' ' . $partnerName . ' AS `partner_name`'
            . ' FROM `' . $this->table . '` `r`';

        $conditions = [];
        $params = [];

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(
                ['r.company_id', 'r.tag'],
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

        $sql .= ' ORDER BY `r`.`tag` ASC, `r`.`company_id` ASC, `r`.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $companyId = (string) ($rowData['company_id'] ?? '');
        $partner = trim((string) ($rowData['partner_name'] ?? ''));

        $row = [
            'id' => (int) $rowData['id'],
            't1' => $this->cfgLabel('core.exchange.contentTags', (string) ($rowData['tag'] ?? '')),
            't2' => $partner !== '' ? "{$companyId} · {$partner}" : $companyId,
        ];

        $origin = (string) ($rowData['origin'] ?? '');
        if ($origin !== '') {
            $row['i1'] = [[
                'text'  => $this->cfgLabel('core.exchange.tagRuleOrigins', $origin),
                'class' => $origin === 'user' ? 'primary' : 'muted',
            ]];
        }

        $t3Parts = [];
        $hitCount = (int) ($rowData['hit_count'] ?? 0);
        if ($hitCount > 0) {
            $t3Parts[] = $hitCount . '×';
        }
        $lastHit = $this->formatDateTime($rowData['last_hit_at'] ?? null) ?? '';
        if ($lastHit !== '') {
            $t3Parts[] = $lastHit;
        }
        $row['t3'] = $t3Parts !== [] ? implode(' · ', $t3Parts) : null;

        return $row;
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT `id`, `company_id`, `tag`, `origin`, `confirmed`,'
            . ' `hit_count`, `last_hit_at`, `created`, `modified`'
            . ' FROM `' . $this->table . '` WHERE `id` = %i',
            $recordId,
        );
    }

    protected function detailTabs(array $record): array
    {
        $companyId = (string) ($record['company_id'] ?? '');

        $partner = $this->db->fetchRow(
            'SELECT `full_name` FROM `base_persons_persons`'
            . ' WHERE `company_id` = %s AND `docState` IN %in'
            . ' ORDER BY `docStateMain` ASC, `id` ASC LIMIT 1',
            $companyId,
            [10, 40, 80],
        );

        $rule = [];
        $this->addItem($rule, $this->text('tag', 'Tag'), $this->cfgLabel('core.exchange.contentTags', (string) ($record['tag'] ?? '')));
        $this->addItem($rule, $this->columnLabel('company_id', 'Company ID'), $companyId);
        $this->addItem($rule, $this->text('partner', 'Partner'), $partner['full_name'] ?? null);
        $this->addItem($rule, $this->columnLabel('origin', 'Origin'), $this->cfgLabel('core.exchange.tagRuleOrigins', (string) ($record['origin'] ?? '')));

        $stats = [];
        $this->addItem($stats, $this->columnLabel('hit_count', 'Hit count'), (string) (int) ($record['hit_count'] ?? 0));
        $this->addItem($stats, $this->columnLabel('last_hit_at', 'Last hit'), $this->formatDateTime($record['last_hit_at'] ?? null));

        $status = [];
        $this->addItem($status, $this->columnLabel('created', 'Created'), $this->formatDateTime($record['created'] ?? null));
        $this->addItem($status, $this->columnLabel('modified', 'Modified'), $this->formatDateTime($record['modified'] ?? null));

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

    protected function detailActions(array $record): array
    {
        return [[
            'id'      => 'deleteTagRule',
            'label'   => $this->text('deleteRule', 'Delete rule'),
            'kind'    => 'button',
            'variant' => 'danger',
        ]];
    }

    /**
     * Bez „Přidat" — pravidla vznikají učením (D28: žádné ruční zakládání
     * v v1); vybraný řádek jde otevřít do formu (změna štítku).
     */
    public function getToolbarActions(?array $selectedRow): array
    {
        if ($selectedRow === null) {
            return [];
        }
        $defs = ($this->config?->cfgItem('core.system.viewerDefaults') ?? [])['toolbarActions'] ?? [];
        $editDef = $defs['edit'] ?? ['name' => 'Open', 'variant' => 'secondary'];
        return [[
            'id'      => 'edit',
            'label'   => $editDef['name'] ?? 'Open',
            'variant' => $editDef['variant'] ?? 'secondary',
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

    /**
     * Text vieweru z katalogu `core.exchange.viewerDetailLabels` (sekce `texts`).
     *
     * @param array<string, scalar> $params
     */
    private function text(string $key, string $englishFallback, array $params = []): string
    {
        return $this->detailText('core.exchange.viewerDetailLabels', $key, $englishFallback, $params);
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
