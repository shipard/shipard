<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Ai;

use Shipard\Core\Ai\AiPlatforms;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class AIBackendsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        // api_key se NIKDY neselektuje jako hodnota — jen boolean příznak
        // has_api_key (zašifrovaný klíč se nastavuje výhradně přes CLI).
        $sql = 'SELECT `id`, `backend_id`, `name`, `provider`, `base_url`,'
            . ' `is_default`, `is_active`,'
            . ' `docState`, `docStateMain`,'
            . ' (`api_key` IS NOT NULL AND `api_key` != \'\') AS `has_api_key`'
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
                ['name', 'backend_id', 'provider'],
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

        $sql .= ' ORDER BY `docStateMain` ASC, `is_default` DESC, `name` ASC, `id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => (string) ($rowData['name'] ?? ''),
            'stateStyle' => $this->resolveStateStyle((int) ($rowData['docState'] ?? 10)),
        ];

        $badges = [];
        if (!empty($rowData['is_default'])) {
            $badges[] = ['text' => 'výchozí', 'class' => 'success'];
        }
        $badges[] = !empty($rowData['is_active'])
            ? ['text' => 'aktivní', 'class' => 'primary']
            : ['text' => 'neaktivní', 'class' => 'muted'];
        $row['i1'] = $badges;

        // Jen přístup (D39): platforma a případná vlastní adresa (gateway).
        $provider = (string) ($rowData['provider'] ?? '');
        $baseUrl = trim((string) ($rowData['base_url'] ?? ''));
        $t2 = trim($provider . ($provider !== '' && $baseUrl !== '' ? ' · ' : '') . $baseUrl);
        $row['t2'] = $t2 !== '' ? ['text' => $t2, 'class' => 'muted'] : null;

        $backendId = (string) ($rowData['backend_id'] ?? '');
        $row['t3'] = $backendId !== '' ? $backendId : null;

        return $row;
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT `id`, `docState`, `backend_id`, `name`, `provider`, `base_url`,'
            . ' `is_default`, `is_active`,'
            . ' `created`, `modified`,'
            . ' (`api_key` IS NOT NULL AND `api_key` != \'\') AS `has_api_key`'
            . ' FROM `' . $this->table . '` WHERE `id` = %i',
            $recordId,
        );
    }

    protected function detailTabs(array $record): array
    {
        $identity = [];
        $this->addItem($identity, 'Kód připojení', $record['backend_id'] ?? null);

        $provider = [];
        $platforms = AiPlatforms::fromConfig($this->config);
        $platformId = trim((string) ($record['provider'] ?? ''));
        $this->addItem($provider, 'Platforma', $platformId !== '' ? $platforms->label($platformId) : null);
        $baseUrl = trim((string) ($record['base_url'] ?? ''));
        $defaultUrl = $platformId !== '' ? $platforms->getOrFallback($platformId)->baseUrl : '';
        // Prázdná adresa = výchozí adresa platformy — ukaž, kam se skutečně volá.
        $this->addItem($provider, 'Base URL', $baseUrl !== '' ? $baseUrl : ($defaultUrl !== '' ? "{$defaultUrl} (výchozí platformy)" : null));

        $access = [];
        $this->addItem($access, 'API klíč', !empty($record['has_api_key']) ? 'nastaven' : 'nenastaven');

        $flags = [];
        $this->addItem($flags, 'Výchozí připojení', !empty($record['is_default']) ? 'Ano' : 'Ne');
        $this->addItem($flags, 'Aktivní', !empty($record['is_active']) ? 'Ano' : 'Ne');

        $status = [];
        $this->addItem($status, 'Vytvořeno', $record['created'] ?? null);
        $this->addItem($status, 'Změněno', $record['modified'] ?? null);

        return [$this->propertiesTab([
            ['title' => 'Identifikace', 'items' => $identity],
            ['title' => 'Platforma', 'items' => $provider],
            ['title' => 'Přístup', 'items' => $access],
            ['title' => 'Příznaky', 'items' => $flags],
            ['title' => 'Stav', 'items' => $status],
        ])];
    }

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
    }

    /** Název platformy z cfgItemu core.ai.platforms; bez konfigurace surová hodnota. */
    private function platformLabel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return AiPlatforms::fromConfig($this->config)->label((string) $value);
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
