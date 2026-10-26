<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Viewer\TableViewer;

/**
 * Odesílatelé odchozí pošty (core_mail_senders) — settings viewer.
 * `password_enc` je sensitive: nikdy se nečte hodnota, jen indikátor
 * `IS NOT NULL`. Heslo se nastavuje detail akcí `setPassword` →
 * `POST /_mail/senders/{id}/password` (admin only).
 */
class SendersViewer extends TableViewer
{
    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT `id`, `email_from`, `smtp_host`, `smtp_port`, `smtp_security`,'
            . ' `smtp_username`, `is_active`, `created`,'
            . ' (`password_enc` IS NOT NULL) AS `has_password`'
            . ' FROM `' . $this->table . '`';

        $conditions = [];
        $params = [];

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(
                ['email_from', 'smtp_host', 'smtp_username'],
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

        $sql .= ' ORDER BY `is_active` DESC, `email_from` ASC, `id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $isCs = ($this->language ?? 'en') === 'cs';

        $badges = [];
        if (empty($rowData['is_active'])) {
            $badges[] = ['text' => $isCs ? 'neaktivní' : 'inactive', 'class' => 'muted'];
        }
        $badges[] = !empty($rowData['has_password'])
            ? ['text' => $isCs ? 'heslo nastaveno' : 'password set', 'class' => 'success']
            : ['text' => $isCs ? 'bez hesla' : 'no password', 'class' => 'warning'];

        return [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['email_from'] ?? ''),
            't2' => [
                'text'  => ($rowData['smtp_host'] ?? '') . ':' . ($rowData['smtp_port'] ?? '')
                    . ' · ' . ($rowData['smtp_security'] ?? ''),
                'class' => 'muted',
            ],
            'i1' => $badges,
        ];
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT `id`, `email_from`, `smtp_host`, `smtp_port`, `smtp_security`,'
            . ' `smtp_username`, `is_active`, `created`,'
            . ' (`password_enc` IS NOT NULL) AS `has_password`'
            . ' FROM `' . $this->table . '` WHERE `id` = %i',
            $recordId,
        );
    }

    protected function detailTabs(array $record): array
    {
        $isCs = ($this->language ?? 'en') === 'cs';
        $yes = $isCs ? 'Ano' : 'Yes';
        $no  = $isCs ? 'Ne' : 'No';

        $identity = [
            ['label' => $isCs ? 'Adresa odesílatele' : 'From address', 'value' => (string) $record['email_from']],
            ['label' => $isCs ? 'Aktivní' : 'Active', 'value' => !empty($record['is_active']) ? $yes : $no],
            ['label' => $isCs ? 'Vytvořeno' : 'Created', 'value' => (string) ($record['created'] ?? '')],
        ];

        $transport = [
            ['label' => 'SMTP', 'value' => $record['smtp_host'] . ':' . $record['smtp_port']],
            ['label' => $isCs ? 'Zabezpečení' : 'Security', 'value' => (string) $record['smtp_security']],
            ['label' => $isCs ? 'Uživatel' : 'Username', 'value' => (string) ($record['smtp_username'] ?? '')],
            [
                'label' => $isCs ? 'Heslo' : 'Password',
                'value' => !empty($record['has_password'])
                    ? ($isCs ? 'nastaveno' : 'set')
                    : ($isCs ? 'nenastaveno' : 'not set'),
            ],
        ];

        return [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => [
                'type'   => 'properties',
                'groups' => [
                    ['title' => $isCs ? 'Identifikace' : 'Identity', 'items' => $identity],
                    ['title' => 'SMTP', 'items' => $transport],
                ],
            ],
        ]];
    }

    protected function detailActions(array $record): array
    {
        $isCs = ($this->language ?? 'en') === 'cs';

        return [[
            'id'      => 'setPassword',
            'label'   => $isCs ? 'Nastavit heslo' : 'Set password',
            'kind'    => 'button',
            'variant' => 'secondary',
        ]];
    }
}
