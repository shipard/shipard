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
            'SELECT `id`, `company_id`, `tag`, `origin`, `confirmed`,'
            . ' `hit_count`, `last_hit_at`, `created`, `modified`'
            . ' FROM `' . $this->table . '` WHERE `id` = %i',
            $recordId,
        );
    }

    protected function detailTabs(array $record): array
    {
        $isCs = ($this->language ?? 'en') === 'cs';
        $companyId = (string) ($record['company_id'] ?? '');

        $partner = $this->db->fetchRow(
            'SELECT `full_name` FROM `base_persons_persons`'
            . ' WHERE `company_id` = %s AND `docState` IN %in'
            . ' ORDER BY `docStateMain` ASC, `id` ASC LIMIT 1',
            $companyId,
            [10, 40, 80],
        );

        $rule = [];
        $this->addItem($rule, $isCs ? 'Štítek' : 'Tag', $this->cfgLabel('core.exchange.contentTags', (string) ($record['tag'] ?? '')));
        $this->addItem($rule, 'IČO', $companyId);
        $this->addItem($rule, $isCs ? 'Partner' : 'Partner', $partner['full_name'] ?? null);
        $this->addItem($rule, $isCs ? 'Původ' : 'Origin', $this->cfgLabel('core.exchange.tagRuleOrigins', (string) ($record['origin'] ?? '')));

        $stats = [];
        $this->addItem($stats, $isCs ? 'Počet zásahů' : 'Hit count', (string) (int) ($record['hit_count'] ?? 0));
        $this->addItem($stats, $isCs ? 'Poslední zásah' : 'Last hit', $record['last_hit_at'] ?? null);

        $status = [];
        $this->addItem($status, $isCs ? 'Vytvořeno' : 'Created', $record['created'] ?? null);
        $this->addItem($status, $isCs ? 'Změněno' : 'Modified', $record['modified'] ?? null);

        return [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => [
                'type'   => 'properties',
                'groups' => [
                    ['title' => $isCs ? 'Pravidlo' : 'Rule', 'items' => $rule],
                    ['title' => $isCs ? 'Statistiky' : 'Statistics', 'items' => $stats],
                    ['title' => $isCs ? 'Stav' : 'Status', 'items' => $status],
                ],
            ],
        ]];
    }

    protected function detailActions(array $record): array
    {
        $isCs = ($this->language ?? 'en') === 'cs';

        return [[
            'id'      => 'deleteTagRule',
            'label'   => $isCs ? 'Smazat pravidlo' : 'Delete rule',
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

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
