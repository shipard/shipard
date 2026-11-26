<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Registrace k evidenci tržeb (Nastavení → Evidence tržeb, #171 D3).
 *
 * Seznam ukazuje typ evidence, režim a platnost certifikátu; detail má
 * Přehled (registrace + certifikát) a evidenční jednotky. Soukromý klíč
 * viewer nenačítá ani neukazuje — řádek seznamu i detail čtou jen
 * vyjmenované sloupce.
 */
class RegistrationsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    public const string LABELS_CFG_ITEM = 'economy.salesRegistration.viewerDetailLabels';

    /** Sloupce registrace bez soukromého klíče. */
    private const string COLUMNS = '`id`, `type`, `name`, `taxpayer_id`, `mode`, `valid_from`, `valid_to`, `default_unit`,'
        . ' `notice`, `cert_valid_from`, `cert_valid_to`, `cert_serial`, `cert_uploaded_at`, `cert_uploaded_by`,'
        . ' `docState`, `docStateMain`';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ', (`cert_pem` IS NOT NULL AND `cert_pem` <> \'\') AS `has_certificate`'
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
            [$searchSql, $searchParams] = $this->buildSearchCondition(['name', 'taxpayer_id'], $search);
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY `docStateMain` ASC, `name` ASC, `id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? 10);
        $taxpayerId = trim((string) ($rowData['taxpayer_id'] ?? ''));

        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => (string) ($rowData['name'] ?? ''),
            'i1'         => $taxpayerId !== '' ? $taxpayerId : null,
            'stateStyle' => $this->resolveStateStyle($docState),
        ];

        $t2 = [];
        $typeLabel = $this->types()->labelOf((string) ($rowData['type'] ?? ''));
        if ($typeLabel !== '') {
            $t2[] = ['text' => $typeLabel, 'class' => 'muted'];
        }
        $modeLabel = $this->modeLabel((string) ($rowData['mode'] ?? ''));
        if ($modeLabel !== '') {
            $t2[] = ['text' => $modeLabel, 'class' => 'muted'];
        }
        $stateTag = $this->rowStateTag($docState);
        if ($stateTag !== null) {
            $t2[] = $stateTag;
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        $certTo = $this->formatDate($rowData['cert_valid_to'] ?? null);
        $row['t3'] = !empty($rowData['has_certificate']) && $certTo !== null
            ? $this->text('rowCertificateTo', 'Certificate until {date}', ['date' => $certTo])
            : $this->text('rowNoCertificate', 'no certificate');

        return $row;
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT ' . self::COLUMNS . ', (`cert_pem` IS NOT NULL AND `cert_pem` <> \'\') AS `has_certificate`'
            . ' FROM `' . $this->table . '` WHERE `id` = %i',
            $recordId,
        );
    }

    protected function detailTabs(array $record): array
    {
        $recordId = (int) $record['id'];
        $type = $this->types()->get((string) ($record['type'] ?? ''));
        $units = $this->loadUnits($recordId);

        $registration = [];
        $this->addItem($registration, $this->columnLabel('type', 'Registration type'), $this->types()->labelOf((string) ($record['type'] ?? '')));
        $this->addItem(
            $registration,
            $type?->taxpayerIdLabel ?? $this->columnLabel('taxpayer_id', 'Taxpayer identifier'),
            trim((string) ($record['taxpayer_id'] ?? '')),
        );
        $this->addItem($registration, $this->columnLabel('mode', 'Mode'), $this->modeLabel((string) ($record['mode'] ?? '')));
        $this->addItem($registration, $this->columnLabel('valid_from', 'Valid from'), $this->formatDate($record['valid_from'] ?? null));
        $this->addItem($registration, $this->columnLabel('valid_to', 'Valid to'), $this->formatDate($record['valid_to'] ?? null));
        $this->addItem($registration, $this->columnLabel('default_unit', 'Default registration unit'), $this->unitLabel($units, (int) ($record['default_unit'] ?? 0)));
        $this->addItem($registration, $this->columnLabel('notice', 'Note'), trim((string) ($record['notice'] ?? '')));

        $certificate = [];
        if (!empty($record['has_certificate'])) {
            $this->addItem($certificate, $this->columnLabel('cert_valid_from', 'Certificate valid from'), $this->formatDateTime($record['cert_valid_from'] ?? null));
            $this->addItem($certificate, $this->columnLabel('cert_valid_to', 'Certificate valid to'), $this->formatDateTime($record['cert_valid_to'] ?? null));
            $this->addItem($certificate, $this->columnLabel('cert_serial', 'Certificate serial number'), trim((string) ($record['cert_serial'] ?? '')));
            $this->addItem($certificate, $this->columnLabel('cert_uploaded_at', 'Certificate uploaded'), $this->formatDateTime($record['cert_uploaded_at'] ?? null));
            $this->addItem($certificate, $this->columnLabel('cert_uploaded_by', 'Certificate uploaded by'), $this->userName((int) ($record['cert_uploaded_by'] ?? 0)));
        } else {
            $this->addItem($certificate, $this->text('certificate', 'Certificate'), $this->text('notUploaded', 'not uploaded'));
        }

        $overview = $this->propertiesTab([
            ['title' => $this->text('groupRegistration', 'Registration'), 'items' => $registration],
            ['title' => $this->text('groupCertificate', 'Certificate'), 'items' => $certificate],
        ]);

        return [$overview, $this->unitsTab($units)];
    }

    /**
     * Záložka Evidenční jednotky — tabulka, nebo prázdný stav.
     *
     * @param list<array<string, mixed>> $units
     * @return array{id: string, label: string, content: array<string, mixed>}
     */
    private function unitsTab(array $units): array
    {
        $label = $this->detailTabLabel(self::LABELS_CFG_ITEM, 'units', 'Registration units');
        if ($units === []) {
            return [
                'id'      => 'units',
                'label'   => $label,
                'content' => ['type' => 'empty', 'text' => $this->text('noUnits', 'The registration has no units yet.')],
            ];
        }

        $rows = [];
        foreach ($units as $unit) {
            $rows[] = [
                'unit_id' => (string) ($unit['unit_id'] ?? ''),
                'name'    => (string) ($unit['name'] ?? ''),
            ];
        }

        return [
            'id'      => 'units',
            'label'   => $label,
            'content' => [
                'type'    => 'table',
                'columns' => [
                    ['id' => 'unit_id', 'label' => $this->columnLabel('unit_id', 'Unit number', UnitDocument::TABLE)],
                    ['id' => 'name',    'label' => $this->columnLabel('name', 'Name', UnitDocument::TABLE)],
                ],
                'rows' => $rows,
            ],
        ];
    }

    /**
     * Jednotky registrace (přepsatelné v testech).
     *
     * @return list<array<string, mixed>>
     */
    protected function loadUnits(int $registrationId): array
    {
        return $this->db->fetchAll(
            'SELECT `id`, `unit_id`, `name` FROM `' . UnitDocument::TABLE . '` WHERE `registration` = %i ORDER BY `unit_id` ASC, `id` ASC',
            $registrationId,
        );
    }

    /** @param list<array<string, mixed>> $units */
    private function unitLabel(array $units, int $unitId): ?string
    {
        foreach ($units as $unit) {
            if ((int) $unit['id'] === $unitId) {
                $name = trim((string) ($unit['name'] ?? ''));
                return $name !== '' ? $unit['unit_id'] . ' — ' . $name : (string) $unit['unit_id'];
            }
        }
        return null;
    }

    /** Jméno uživatele, který certifikát nahrál (přepsatelné v testech). */
    protected function userName(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        $name = $this->db->fetchSingle('SELECT `full_name` FROM `core_system_users` WHERE `id` = %i', $userId);
        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    protected function types(): SalesRegistrationTypes
    {
        return SalesRegistrationTypes::fromConfig($this->config);
    }

    private function modeLabel(string $mode): string
    {
        if ($mode === '' || $this->config === null) {
            return $mode;
        }
        $cfg = $this->config->cfgItem(RegistrationDocument::MODES_CFG);
        return is_array($cfg) && isset($cfg[$mode]['name']) ? (string) $cfg[$mode]['name'] : $mode;
    }

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
    }

    /**
     * Text vieweru z katalogu `economy.salesRegistration.viewerDetailLabels`
     * (sekce `texts`).
     *
     * @param array<string, scalar> $params
     */
    private function text(string $key, string $englishFallback, array $params = []): string
    {
        return $this->detailText(self::LABELS_CFG_ITEM, $key, $englishFallback, $params);
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
