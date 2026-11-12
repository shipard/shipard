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
    private const LABELS_CFG_ITEM = 'core.mail.viewerDetailLabels';

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
        $badges = [];
        if (empty($rowData['is_active'])) {
            $badges[] = ['text' => $this->sharedText('statusInactive', 'inactive'), 'class' => 'muted'];
        }
        $badges[] = !empty($rowData['has_password'])
            ? ['text' => $this->text('passwordSet', 'password set'), 'class' => 'success']
            : ['text' => $this->text('noPassword', 'no password'), 'class' => 'warning'];

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
        $identity = [
            ['label' => $this->columnLabel('is_active', 'Active'), 'value' => $this->yesNoText(!empty($record['is_active']))],
            ['label' => $this->columnLabel('created', 'Created'), 'value' => $this->formatDateTime($record['created'] ?? null) ?? ''],
        ];

        $transport = [
            ['label' => $this->text('smtp', 'SMTP'), 'value' => $record['smtp_host'] . ':' . $record['smtp_port']],
            ['label' => $this->columnLabel('smtp_security', 'Security'), 'value' => (string) $record['smtp_security']],
            ['label' => $this->text('username', 'Username'), 'value' => (string) ($record['smtp_username'] ?? '')],
            [
                'label' => $this->text('password', 'Password'),
                'value' => !empty($record['has_password'])
                    ? $this->text('valueSet', 'set')
                    : $this->text('valueNotSet', 'not set'),
            ],
        ];

        return [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => [
                'type'   => 'properties',
                'groups' => [
                    ['title' => $this->sharedText('groupIdentity', 'Identity'), 'items' => $identity],
                    ['title' => $this->text('smtp', 'SMTP'), 'items' => $transport],
                ],
            ],
        ]];
    }

    protected function detailActions(array $record): array
    {
        return [[
            'id'      => 'setPassword',
            'label'   => $this->text('setPassword', 'Set password'),
            'kind'    => 'button',
            'variant' => 'secondary',
        ]];
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
}
