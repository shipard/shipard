<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class MailboxesViewer extends TableViewer
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
        $sql = 'SELECT `id`, `mailbox_id`, `name`, `email_address`, `default_primary_type`,'
            . ' `is_default`, `docState`, `docStateMain`'
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
                ['name', 'email_address', 'mailbox_id'],
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

        $row['i1'] = !empty($rowData['is_default'])
            ? [['text' => 'výchozí', 'class' => 'success']]
            : null;

        $email = (string) ($rowData['email_address'] ?? '');
        $row['t2'] = $email !== '' ? ['text' => $email, 'class' => 'muted'] : null;

        $t3 = (string) ($rowData['mailbox_id'] ?? '');
        $typeLabel = $this->resolvePrimaryTypeLabel($rowData['default_primary_type'] ?? null);
        if ($typeLabel !== '') {
            $t3 = $t3 !== '' ? $t3 . ' · ' . $typeLabel : $typeLabel;
        }
        $row['t3'] = $t3 !== '' ? $t3 : null;

        return $row;
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT `id`, `docState`, `mailbox_id`, `name`, `email_address`, `description`,'
            . ' `default_primary_type`, `is_default`, `created`, `modified`'
            . ' FROM `' . $this->table . '` WHERE `id` = %i',
            $recordId,
        );
    }

    protected function detailTabs(array $record): array
    {
        $identity = [];
        $this->addItem($identity, 'Kód schránky', $record['mailbox_id'] ?? null);
        $this->addItem($identity, 'Název', $record['name'] ?? null);
        $this->addItem($identity, 'E-mailová adresa', $record['email_address'] ?? null);
        $this->addItem($identity, 'Popis', $record['description'] ?? null);

        $config = [];
        $this->addItem($config, 'Výchozí primární typ', $this->resolvePrimaryTypeLabel($record['default_primary_type'] ?? null));
        $this->addItem($config, 'Výchozí schránka', !empty($record['is_default']) ? 'Ano' : 'Ne');

        $status = [];
        $this->addItem($status, 'Vytvořeno', $record['created'] ?? null);
        $this->addItem($status, 'Změněno', $record['modified'] ?? null);

        return [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => [
                'type'   => 'properties',
                'groups' => [
                    ['title' => 'Identifikace', 'items' => $identity],
                    ['title' => 'Konfigurace', 'items' => $config],
                    ['title' => 'Stav', 'items' => $status],
                ],
            ],
        ]];
    }

    private function resolvePrimaryTypeLabel(mixed $key): string
    {
        $key = (string) ($key ?? '');
        if ($key === '' || $this->config === null) {
            return $key;
        }
        $cfg = $this->config->cfgItem('core.mail.primaryTypes');
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
