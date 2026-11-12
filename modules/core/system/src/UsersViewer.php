<?php

declare(strict_types=1);

namespace Shipard\Module\Core\System;

use Shipard\Core\Viewer\Detail\DetailHeader;
use Shipard\Core\Viewer\TableViewer;

/**
 * Uživatelé (core_system_users) — settings viewer, admin only (systémovou
 * tabulku hlídá TableAccessGuard). `password_hash` je sensitive: nikdy se
 * nečte hodnota, jen indikátor `IS NOT NULL`. Detail akce `invite` →
 * `POST /_users/{id}/invite` pošle pozvánkový mail (nastavení hesla);
 * funguje i opakovaně — starý token se zneplatní.
 */
class UsersViewer extends TableViewer
{
    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT `id`, `login`, `full_name`, `email`, `is_active`, `is_admin`,'
            . ' `is_system`, (`password_hash` IS NOT NULL) AS `has_password`'
            . ' FROM `' . $this->table . '`';

        $conditions = [];
        $params = [];

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(
                ['login', 'full_name', 'email'],
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

        $sql .= ' ORDER BY `is_active` DESC, `login` ASC, `id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $badges = [];
        if (!empty($rowData['is_admin'])) {
            $badges[] = ['text' => $this->sharedText('statusAdmin', 'admin'), 'class' => 'info'];
        }
        if (!empty($rowData['is_system'])) {
            $badges[] = ['text' => $this->sharedText('statusSystem', 'system'), 'class' => 'muted'];
        }
        if (empty($rowData['is_active'])) {
            $badges[] = ['text' => $this->sharedText('statusInactive', 'inactive'), 'class' => 'muted'];
        }
        if (empty($rowData['has_password']) && empty($rowData['is_system'])) {
            $badges[] = ['text' => $this->text('noPassword', 'no password'), 'class' => 'warning'];
        }

        return [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['full_name'] ?? ''),
            't2' => [
                'text'  => ($rowData['login'] ?? '')
                    . (!empty($rowData['email']) ? ' · ' . $rowData['email'] : ''),
                'class' => 'muted',
            ],
            'i1' => $badges,
        ];
    }

    /**
     * Záznam pro detail: jen bezpečné sloupce (heslo jako příznak
     * `has_password`); Osoba — sloupec přidává rozšíření z base.persons
     * (#93 D6), zdroj dat bez Osob ho nemá.
     */
    protected function loadDetailRecord(int $recordId): ?array
    {
        $hasPerson = $this->hasColumn('person');
        return $this->db->fetchRow(
            'SELECT `id`, `login`, `full_name`, `email`, `is_active`, `is_admin`,'
            . ' `is_system`, (`password_hash` IS NOT NULL) AS `has_password`'
            . ($hasPerson ? ', `person`' : '')
            . ' FROM `' . $this->table . '` WHERE `id` = %i',
            $recordId,
        );
    }

    /**
     * Titulek jméno, podtitul login; bez jména je titulkem login (#140 §2) —
     * vzor tabulky `{full_name} ({login})` by dal „(login)“. Login je tak
     * v hlavičce vždy a Přehled ho neopakuje.
     */
    protected function detailHeader(array $record): DetailHeader
    {
        $fullName = trim((string) ($record['full_name'] ?? ''));
        $login = trim((string) ($record['login'] ?? ''));
        if ($fullName !== '') {
            return new DetailHeader($fullName, $login !== '' ? $login : null);
        }
        return new DetailHeader($login !== '' ? $login : '#' . (int) ($record['id'] ?? 0));
    }

    protected function detailTabs(array $record): array
    {
        $identity = [
            ['label' => $this->columnLabel('email', 'E-mail'), 'value' => (string) ($record['email'] ?? '')],
        ];
        if (array_key_exists('person', $record)) {
            $identity[] = [
                'label' => $this->sharedText('person', 'Person'),
                'value' => $this->personName($record['person'] ?? null),
            ];
        }

        $account = [
            ['label' => $this->columnLabel('is_active', 'Active'), 'value' => $this->yesNoText(!empty($record['is_active']))],
            ['label' => $this->columnLabel('is_admin', 'Administrator'), 'value' => $this->yesNoText(!empty($record['is_admin']))],
            [
                'label' => $this->text('localPassword', 'Local password'),
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
                    ['title' => $this->text('groupAccount', 'Account'), 'items' => $account],
                ],
            ],
        ]];
    }

    /**
     * Pozvánku lze poslat jen aktivnímu ne-systémovému účtu s e-mailem —
     * stejná pravidla vynucuje POST /_users/{id}/invite.
     */
    protected function detailActions(array $record): array
    {
        if (empty($record['is_active']) || !empty($record['is_system'])
            || trim((string) ($record['email'] ?? '')) === '') {
            return [];
        }

        return [[
            'id'      => 'invite',
            'label'   => $this->text('sendInvitation', 'Send invitation'),
            'kind'    => 'button',
            'variant' => 'secondary',
        ]];
    }

    /**
     * Text vieweru z katalogu `core.system.viewerDetailLabels` (sekce `texts`).
     *
     * @param array<string, scalar> $params
     */
    private function text(string $key, string $englishFallback, array $params = []): string
    {
        return $this->detailText('core.system.viewerDetailLabels', $key, $englishFallback, $params);
    }

    /** Jméno navázané Osoby; prázdné bez vazby i u vazby na neexistující Osobu. */
    private function personName(mixed $personId): string
    {
        if ($personId === null || (int) $personId <= 0) {
            return '';
        }
        $row = $this->db->fetchRow(
            'SELECT `full_name` FROM `base_persons_persons` WHERE `id` = %i',
            (int) $personId,
        );
        return $row !== null ? (string) ($row['full_name'] ?? '') : '';
    }
}
