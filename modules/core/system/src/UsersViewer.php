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
        $isCs = ($this->language ?? 'en') === 'cs';

        $badges = [];
        if (!empty($rowData['is_admin'])) {
            $badges[] = ['text' => 'admin', 'class' => 'info'];
        }
        if (!empty($rowData['is_system'])) {
            $badges[] = ['text' => $isCs ? 'systémový' : 'system', 'class' => 'muted'];
        }
        if (empty($rowData['is_active'])) {
            $badges[] = ['text' => $isCs ? 'neaktivní' : 'inactive', 'class' => 'muted'];
        }
        if (empty($rowData['has_password']) && empty($rowData['is_system'])) {
            $badges[] = ['text' => $isCs ? 'bez hesla' : 'no password', 'class' => 'warning'];
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
        $isCs = ($this->language ?? 'en') === 'cs';
        $yes = $isCs ? 'Ano' : 'Yes';
        $no  = $isCs ? 'Ne' : 'No';

        $identity = [
            ['label' => 'E-mail', 'value' => (string) ($record['email'] ?? '')],
        ];
        if (array_key_exists('person', $record)) {
            $identity[] = [
                'label' => $isCs ? 'Osoba' : 'Person',
                'value' => $this->personName($record['person'] ?? null),
            ];
        }

        $account = [
            ['label' => $isCs ? 'Aktivní' : 'Active', 'value' => !empty($record['is_active']) ? $yes : $no],
            ['label' => $isCs ? 'Administrátor' : 'Administrator', 'value' => !empty($record['is_admin']) ? $yes : $no],
            [
                'label' => $isCs ? 'Lokální heslo' : 'Local password',
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
                    ['title' => $isCs ? 'Účet' : 'Account', 'items' => $account],
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
        $isCs = ($this->language ?? 'en') === 'cs';

        return [[
            'id'      => 'invite',
            'label'   => $isCs ? 'Poslat pozvánku' : 'Send invitation',
            'kind'    => 'button',
            'variant' => 'secondary',
        ]];
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
