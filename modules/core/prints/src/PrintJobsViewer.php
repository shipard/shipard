<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Prints;

use Shipard\Core\Config\ConfigCompiler;
use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Viewer\Detail\DetailHeader;
use Shipard\Core\Viewer\TableViewer;

/**
 * Přehled Tiskové úlohy (Nastavení → Zařízení, #177 U42): co se kde
 * tisklo a jak to dopadlo — čas, zařízení, druh, tisk a záznam, stav,
 * kód chyby, pokusy a uživatel. Jen ke čtení a jen pro administrátora
 * (tabulka je `adminOnly`).
 *
 * Úlohy nemají sadu stavů dokladů (`docState`); stav je vlastní sloupec
 * `state`, popisky a styl z `core.prints.jobStates`. Záznamy zakládá jen
 * `PrintJobService` — bez akce Přidat i Otevřít. Zkusit znovu tu není:
 * funguje jen na zařízení, které úlohu tisklo.
 *
 * Sloupec `data` (bajty pro tiskárnu) se do seznamu ani detailu nenačítá.
 */
class PrintJobsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = null;

    public const string LABELS_CFG_ITEM = 'core.prints.viewerDetailLabels';
    public const string KINDS_CFG = 'core.prints.jobKinds';
    public const string STATES_CFG = 'core.prints.jobStates';

    /** Sloupce úlohy pro seznam i detail — bez `data`. */
    private const string COLUMNS = 'j.`id`, j.`job_id`, j.`kind`, j.`print_id`, j.`record_table`, j.`record_id`,'
        . ' j.`device`, j.`peripheral`, j.`role`, j.`format`, j.`profile_id`, j.`data_size`, j.`warnings`,'
        . ' j.`state`, j.`error_code`, j.`attempts`, j.`created_by`, j.`created_at`, j.`result_at`';

    private const string JOINS = ' LEFT JOIN `core_devices_devices` d ON d.`id` = j.`device`'
        . ' LEFT JOIN `core_devices_peripherals` p ON p.`id` = j.`peripheral`'
        . ' LEFT JOIN `core_system_users` u ON u.`id` = j.`created_by`';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ', d.`name` AS `device_name`, u.`full_name` AS `user_name`, u.`login` AS `user_login`'
            . ' FROM `' . $this->table . '` j' . self::JOINS;

        $conditions = [];
        $params = [];
        foreach ($filters as $filter) {
            $id = $filter['id'] ?? null;
            $value = $filter['value'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($id === 'state' || $id === 'kind') {
                $conditions[] = 'j.`' . $id . '` = %s';
                $params[] = (string) $value;
            } elseif ($id === 'device') {
                $conditions[] = 'j.`device` = %i';
                $params[] = (int) $value;
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = SearchCondition::anyContains(
                ['j.`print_id`', 'j.`error_code`', 'j.`job_id`', 'd.`name`'],
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
        $sql .= ' ORDER BY j.`created_at` DESC, j.`id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $state = (string) ($rowData['state'] ?? '');
        $stateStyle = $this->stateStyle($state);

        $t2 = [];
        $device = trim((string) ($rowData['device_name'] ?? ''));
        if ($device !== '') {
            $t2[] = ['text' => $device, 'class' => 'muted'];
        }
        $t2[] = ['text' => $this->stateLabel($state), 'class' => $this->rowStateClass($stateStyle)];
        $errorCode = trim((string) ($rowData['error_code'] ?? ''));
        if ($errorCode !== '') {
            $t2[] = ['text' => $errorCode, 'class' => 'muted'];
        }

        $t3 = [];
        $user = $this->userLabel($rowData);
        if ($user !== '') {
            $t3[] = $user;
        }
        $t3[] = $this->text('jobAttempts', 'Attempts: {count}', ['count' => (int) ($rowData['attempts'] ?? 0)]);

        return [
            'id'         => (int) $rowData['id'],
            't1'         => $this->title($rowData),
            'i1'         => $this->formatDateTime($rowData['created_at'] ?? null),
            't2'         => $t2,
            't3'         => implode(' • ', $t3),
            'stateStyle' => $stateStyle,
        ];
    }

    public function getFilters(): array
    {
        $filters = [
            [
                'id'      => 'state',
                'label'   => $this->columnLabel('state', 'State'),
                'type'    => 'select',
                'options' => self::options($this->states()),
            ],
            [
                'id'      => 'kind',
                'label'   => $this->columnLabel('kind', 'Kind'),
                'type'    => 'select',
                'options' => self::options($this->kinds()),
            ],
        ];

        $devices = $this->deviceRows();
        if (count($devices) > 1) {
            $filters[] = [
                'id'      => 'device',
                'label'   => $this->text('jobFilterDevice', 'Device'),
                'type'    => 'select',
                'options' => array_map(
                    static fn (array $row): array => ['value' => (int) $row['id'], 'label' => (string) $row['name']],
                    $devices,
                ),
            ];
        }

        return $filters;
    }

    /** Úlohy zakládá jen tisk — bez akce Přidat i Otevřít (formulář nemají). */
    public function getToolbarActions(?array $selectedRow): array
    {
        return [];
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT ' . self::COLUMNS . ', d.`name` AS `device_name`, p.`name` AS `peripheral_name`,'
            . ' p.`peripheral_id` AS `peripheral_app_id`, u.`full_name` AS `user_name`, u.`login` AS `user_login`'
            . ' FROM `' . $this->table . '` j' . self::JOINS
            . ' WHERE j.`id` = %i',
            $recordId,
        );
    }

    protected function detailHeader(array $record): DetailHeader
    {
        // Podtitulek je zařízení, ne čas: výsledek přichází během téže minuty
        // jako vznik úlohy a čas v hlavičce by se v Přehledu opakoval.
        $device = trim((string) ($record['device_name'] ?? ''));
        return new DetailHeader($this->title($record), $device !== '' ? $device : null);
    }

    protected function detailBadges(array $record): array
    {
        $state = (string) ($record['state'] ?? '');
        return [['label' => $this->stateLabel($state), 'style' => $this->stateStyle($state)]];
    }

    protected function detailTabs(array $record): array
    {
        $target = [];
        $peripheral = trim((string) ($record['peripheral_name'] ?? ''));
        $this->addItem(
            $target,
            $this->columnLabel('peripheral', 'Peripheral'),
            $peripheral !== '' ? $peripheral : $this->text('jobPeripheralGone', 'peripheral no longer exists'),
        );
        $this->addItem($target, $this->columnLabel('role', 'Role'), $this->roleLabel((string) ($record['role'] ?? '')));
        $this->addItem($target, $this->columnLabel('profile_id', 'Printer profile'), trim((string) ($record['profile_id'] ?? '')));

        $result = [];
        $this->addItem($result, $this->columnLabel('error_code', 'Error code'), trim((string) ($record['error_code'] ?? '')));
        $this->addItem($result, $this->columnLabel('attempts', 'Attempts'), (string) (int) ($record['attempts'] ?? 0));
        $this->addItem($result, $this->columnLabel('result_at', 'Result reported'), $this->formatDateTime($record['result_at'] ?? null));

        $job = [];
        $this->addItem($job, $this->columnLabel('created_at', 'Created'), $this->formatDateTime($record['created_at'] ?? null));
        $this->addItem($job, $this->columnLabel('created_by', 'User'), $this->userLabel($record));
        $this->addItem($job, $this->columnLabel('format', 'Format'), trim((string) ($record['format'] ?? '')));
        $this->addItem(
            $job,
            $this->columnLabel('data_size', 'Data size'),
            $this->text('jobBytes', '{count} bytes', ['count' => (int) ($record['data_size'] ?? 0)]),
        );
        $this->addItem($job, $this->columnLabel('job_id', 'Job ID'), trim((string) ($record['job_id'] ?? '')));

        $warnings = [];
        foreach (self::warnings($record['warnings'] ?? null) as $warning) {
            $this->addItem($warnings, (string) ($warning['code'] ?? ''), trim((string) ($warning['text'] ?? '')));
        }

        return [
            $this->propertiesTab([
                ['title' => $this->text('jobGroupTarget', 'Printer'), 'items' => $target],
                ['title' => $this->text('jobGroupResult', 'Result'), 'items' => $result],
                ['title' => $this->text('jobGroupJob', 'Job'), 'items' => $job],
                ['title' => $this->text('jobGroupWarnings', 'Warnings'), 'items' => $warnings],
            ]),
        ];
    }

    /**
     * Zařízení pro nabídku filtru (přepsatelné v testech).
     *
     * @return list<array<string, mixed>>
     */
    protected function deviceRows(): array
    {
        return $this->db->fetchAll('SELECT `id`, `name` FROM `core_devices_devices` ORDER BY `name` ASC, `id` ASC');
    }

    /**
     * Co úloha tiskla: druh, u tisku záznamu název tisku a id záznamu.
     *
     * @param array<string, mixed> $row
     */
    private function title(array $row): string
    {
        $kind = (string) ($row['kind'] ?? '');
        $printId = trim((string) ($row['print_id'] ?? ''));
        if ($printId === '') {
            return $this->kindLabel($kind);
        }
        return $this->text('jobRecord', '{print} · record {record}', [
            'print'  => $this->printName($printId),
            'record' => (int) ($row['record_id'] ?? 0),
        ]);
    }

    /** Název tisku z deklarací; neznámý tisk (vypnutý modul) zůstane jako id. */
    private function printName(string $printId): string
    {
        $prints = $this->config?->cfgItem(ConfigCompiler::PRINTS_ITEM);
        return is_array($prints) && isset($prints[$printId]['name']) ? (string) $prints[$printId]['name'] : $printId;
    }

    private function roleLabel(string $role): string
    {
        $roles = $this->config?->cfgItem('core.devices.peripheralRoles');
        return is_array($roles) && isset($roles[$role]['name']) ? (string) $roles[$role]['name'] : $role;
    }

    /** @param array<string, mixed> $row */
    private function userLabel(array $row): string
    {
        $name = trim((string) ($row['user_name'] ?? ''));
        return $name !== '' ? $name : trim((string) ($row['user_login'] ?? ''));
    }

    /** @return array<string, array<string, mixed>> */
    private function states(): array
    {
        $cfg = $this->config?->cfgItem(self::STATES_CFG);
        return is_array($cfg) ? $cfg : [];
    }

    /** @return array<string, array<string, mixed>> */
    private function kinds(): array
    {
        $cfg = $this->config?->cfgItem(self::KINDS_CFG);
        return is_array($cfg) ? $cfg : [];
    }

    private function stateLabel(string $state): string
    {
        return (string) ($this->states()[$state]['name'] ?? $state);
    }

    private function stateStyle(string $state): string
    {
        return (string) ($this->states()[$state]['stateStyle'] ?? 'concept');
    }

    private function kindLabel(string $kind): string
    {
        return (string) ($this->kinds()[$kind]['name'] ?? $kind);
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $definitions): array
    {
        $options = [];
        foreach ($definitions as $id => $definition) {
            $options[] = ['value' => (string) $id, 'label' => (string) ($definition['name'] ?? $id)];
        }
        return $options;
    }

    /** @return list<array<string, mixed>> */
    private static function warnings(mixed $raw): array
    {
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** @param array<string, scalar> $params */
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
