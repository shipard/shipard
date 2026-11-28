<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Viewer zařízení (Nastavení → Zařízení, #177 D13, U18): název, režim,
 * platforma a poslední kontakt; detail s registrací a periferiemi.
 *
 * Bez Přidat — zařízení vzniká jen registrací z nativní aplikace (panel
 * Toto zařízení). Řádek nese span se značkou `nativeDevice`: frontend ho
 * ukáže jen na zařízení, jehož `deviceId` hlásí most — server neví, ze
 * kterého zařízení se uživatel dívá.
 */
class DevicesViewer extends TableViewer
{
    public const LABELS_CFG_ITEM = 'core.devices.viewerLabels';

    protected ?string $docStatesCfgItem = 'core.devices.docStates';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT d.`id`, d.`name`, d.`mode`, d.`platform`, d.`device_id`, d.`last_seen_at`,'
            . ' d.`docState`, d.`docStateMain`'
            . ' FROM `' . $this->table . '` d';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        foreach ($filters as $filter) {
            if (($filter['id'] ?? null) === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
            }
        }
        if ($viewGroup !== 'all') {
            [$stateSql, $stateParams] = $this->buildViewGroupFilter((string) $this->docStatesCfgItem, $viewGroup);
            if ($stateSql !== '') {
                $conditions[] = 'd.' . $stateSql;
                $params = array_merge($params, $stateParams);
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(['name'], $search);
            $conditions[] = str_replace('`name`', 'd.`name`', $searchSql);
            $params = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY d.`docStateMain` ASC, d.`name` ASC, d.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? DeviceDocument::STATE_ACTIVE);
        $row = [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['name'] ?? ''),
        ];

        $t2 = [
            ['text' => $this->modes()->label((string) ($rowData['mode'] ?? '')), 'class' => 'primary'],
            ['text' => $this->platformLabel((string) ($rowData['platform'] ?? ''))],
        ];
        $lastSeen = $this->formatDateTime($rowData['last_seen_at'] ?? null);
        $t2[] = [
            'text'  => $lastSeen !== null
                ? $this->text('row.lastContact', 'last contact {date}', ['date' => $lastSeen])
                : $this->text('row.noContact', 'no contact yet'),
            'class' => 'muted',
        ];
        $deviceId = (string) ($rowData['device_id'] ?? '');
        if ($deviceId !== '' && $docState === DeviceDocument::STATE_ACTIVE) {
            // Jen pro zařízení, na kterém se uživatel právě dívá — filtruje frontend.
            $t2[] = [
                'text'         => $this->text('row.thisDevice', 'this device'),
                'badge'        => 'primary',
                'nativeDevice' => $deviceId,
            ];
        }
        $badge = $this->rowStateTag($docState);
        if ($badge !== null) {
            $t2[] = $badge;
        }
        $row['t2'] = $t2;
        $row['stateStyle'] = $this->stateStyleOf($docState);

        return $row;
    }

    /** Bez Přidat — zařízení vzniká jen registrací. */
    public function getToolbarActions(?array $selectedRow): array
    {
        return array_values(array_filter(
            parent::getToolbarActions($selectedRow),
            static fn (array $action): bool => ($action['id'] !== 'create'),
        ));
    }

    protected function detailBadges(array $record): array
    {
        $mode = (string) ($record['mode'] ?? '');
        return $mode !== '' ? [['label' => $this->modes()->label($mode), 'style' => 'neutral']] : [];
    }

    protected function detailTabs(array $record): array
    {
        $devices = DeviceDocument::TABLE;

        $settings = [];
        $autoLock = $record['auto_lock_minutes'] ?? null;
        $this->addItem(
            $settings,
            $this->columnLabel('auto_lock_minutes', 'Auto-lock after (minutes)', $devices),
            $autoLock === null
                ? $this->text('value.noAutoLock', 'off')
                : $this->text('value.minutes', '{count} min', ['count' => (int) $autoLock]),
        );

        $registration = [];
        $this->addItem($registration, $this->columnLabel('platform', 'Platform', $devices), $this->platformLabel((string) ($record['platform'] ?? '')));
        $this->addItem($registration, $this->columnLabel('app_version', 'App version', $devices), $record['app_version'] ?? null);
        $this->addItem($registration, $this->columnLabel('key_storage', 'Key storage', $devices), $record['key_storage'] ?? null);
        $this->addItem($registration, $this->columnLabel('device_id', 'Device ID', $devices), $record['device_id'] ?? null);
        $this->addItem($registration, $this->columnLabel('registered_at', 'Registered', $devices), $this->formatDateTime($record['registered_at'] ?? null));
        $this->addItem($registration, $this->columnLabel('registered_by', 'Registered by', $devices), $this->userName($record['registered_by'] ?? null));
        $this->addItem($registration, $this->columnLabel('last_seen_at', 'Last contact', $devices), $this->formatDateTime($record['last_seen_at'] ?? null));
        if (($record['revoked_at'] ?? null) !== null) {
            $this->addItem($registration, $this->columnLabel('revoked_at', 'Revoked', $devices), $this->formatDateTime($record['revoked_at']));
            $this->addItem(
                $registration,
                $this->columnLabel('revoked_by', 'Revoked by', $devices),
                $this->userName($record['revoked_by'] ?? null) ?? $this->text('value.commandLine', 'command line'),
            );
        }

        return [
            $this->propertiesTab([
                ['title' => $this->text('group.settings', 'Settings'), 'items' => $settings],
                ['title' => $this->text('group.registration', 'Registration'), 'items' => $registration],
            ]),
            [
                'id'      => 'peripherals',
                'label'   => $this->text('tab.peripherals', 'Peripherals'),
                'content' => $this->peripheralsContent((int) ($record['id'] ?? 0)),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function peripheralsContent(int $deviceId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT `name`, `kind`, `transport`, `status`, `present`, `role`'
            . ' FROM `' . PeripheralDocument::TABLE . '`'
            . ' WHERE `device` = %i ORDER BY `present` DESC, `kind` ASC, `name` ASC, `id` ASC',
            $deviceId,
        );
        if ($rows === []) {
            return ['type' => 'empty', 'text' => $this->text('peripherals.empty', 'The device has not reported any peripherals yet.')];
        }

        $peripherals = PeripheralDocument::TABLE;
        $roles = new PeripheralRoles($this->config);
        $columns = [
            ['id' => 'name',      'label' => $this->columnLabel('name', 'Name', $peripherals)],
            ['id' => 'kind',      'label' => $this->columnLabel('kind', 'Kind', $peripherals)],
            ['id' => 'transport', 'label' => $this->columnLabel('transport', 'Connection', $peripherals)],
            ['id' => 'state',     'label' => $this->text('peripherals.state', 'State')],
            ['id' => 'role',      'label' => $this->columnLabel('role', 'Role', $peripherals)],
        ];

        $tableRows = [];
        foreach ($rows as $r) {
            $role = (string) ($r['role'] ?? '');
            $tableRows[] = [
                'name'      => (string) ($r['name'] ?? ''),
                'kind'      => $this->kindLabel((string) ($r['kind'] ?? '')),
                'transport' => (string) ($r['transport'] ?? ''),
                'state'     => (int) ($r['present'] ?? 0) === 1
                    ? $this->statusLabel((string) ($r['status'] ?? ''))
                    : $this->text('peripherals.absent', 'not connected'),
                'role'      => $role !== '' ? $roles->label($role) : '',
            ];
        }

        return ['type' => 'table', 'columns' => $columns, 'rows' => $tableRows];
    }

    /**
     * Text z katalogu `core.devices.viewerLabels`.
     *
     * @param array<string, scalar> $params
     */
    protected function text(string $key, string $englishFallback, array $params = []): string
    {
        return $this->labelText(self::LABELS_CFG_ITEM, $key, $englishFallback, $params);
    }

    private function modes(): DeviceModes
    {
        return DeviceModes::fromConfig($this->config);
    }

    /** Hodnoty hlásí aplikace — neznámá se ukáže, jak přišla (kontrakt §2.4). */
    private function platformLabel(string $platform): string
    {
        return match ($platform) {
            'macos'  => $this->text('platform.macos', 'macOS'),
            'ipados' => $this->text('platform.ipados', 'iPadOS'),
            'ios'    => $this->text('platform.ios', 'iOS'),
            'mock'   => $this->text('platform.mock', 'bridge simulation'),
            default  => $platform,
        };
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            'printer' => $this->text('kind.printer', 'Printer'),
            'scanner' => $this->text('kind.scanner', 'Scanner'),
            default   => $kind,
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'ready'   => $this->text('status.ready', 'ready'),
            'offline' => $this->text('status.offline', 'offline'),
            'error'   => $this->text('status.error', 'error'),
            default   => $status,
        };
    }

    private function userName(mixed $userId): ?string
    {
        if ($userId === null || (int) $userId <= 0) {
            return null;
        }
        $name = $this->db->fetchSingle('SELECT `full_name` FROM `core_system_users` WHERE `id` = %i', (int) $userId);
        return is_string($name) && $name !== '' ? $name : '#' . (int) $userId;
    }

    /** @param list<array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }

    private function stateStyleOf(int $docState): string
    {
        if ($this->config === null) {
            return 'done';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem((string) $this->docStatesCfgItem));
        return (string) ($cfg->getState($docState)['stateStyle'] ?? 'done');
    }
}
