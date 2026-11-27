<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Module\Devices;

use Shipard\Core\Database\DataSourceConnection;

/**
 * In-memory náhrada DataSourceConnection pro testy modulu core.devices.
 * Interpretuje jen SQL, které služby modulu skutečně posílají (match podle
 * podřetězců); neznámý dotaz je LogicException, aby test nezelenal nad
 * dotazem, který nikdo nevyhodnotil. Atomicitu spotřeby výzvy tu ověřit
 * nejde — tu hlídá integrační test nad skutečnou databází.
 */
class InMemoryDevicesDb extends DataSourceConnection
{
    /** @var array<string, array<int, array<string, mixed>>> tabulka → id → řádek */
    public array $tables = [
        'core_devices_devices'     => [],
        'core_devices_peripherals' => [],
        'core_devices_challenges'  => [],
    ];

    /** @var list<string> provedené zápisy, pro kontrolu „nic se nezapsalo“ */
    public array $writes = [];

    private int $affected = 0;
    private int $nextId = 1;

    public static function create(): self
    {
        /** @var self $db */
        $db = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $db->tables = [
            'core_devices_devices'     => [],
            'core_devices_peripherals' => [],
            'core_devices_challenges'  => [],
        ];
        $db->writes = [];
        return $db;
    }

    /** @param array<string, mixed> $row */
    public function seed(string $table, array $row): int
    {
        $id = (int) ($row['id'] ?? $this->nextId);
        $this->nextId = max($this->nextId, $id) + 1;
        $this->tables[$table][$id] = ['id' => $id] + $row;
        return $id;
    }

    /** @return list<array<string, mixed>> */
    public function rows(string $table): array
    {
        return array_values($this->tables[$table]);
    }

    public function begin(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollback(): void
    {
    }

    public function fetchSingle(mixed ...$args): mixed
    {
        $sql = self::sql($args);
        if (str_contains($sql, 'SELECT COUNT(*) FROM core_devices_challenges')) {
            [$deviceId, $now] = [$args[1], $args[2]];
            return count(array_filter(
                $this->tables['core_devices_challenges'],
                static fn (array $c): bool => $c['device_id'] === $deviceId
                    && ($c['used_at'] ?? null) === null && $c['expires_at'] > $now,
            ));
        }
        throw new \LogicException("InMemoryDevicesDb: unexpected fetchSingle: {$sql}");
    }

    public function fetchRow(mixed ...$args): ?array
    {
        $sql = self::sql($args);
        if (str_contains($sql, 'FROM core_devices_devices WHERE device_id = %s')) {
            foreach ($this->tables['core_devices_devices'] as $row) {
                if ($row['device_id'] === $args[1]) {
                    return $row;
                }
            }
            return null;
        }
        if (str_contains($sql, 'FROM core_devices_devices WHERE id = %i')) {
            return $this->tables['core_devices_devices'][(int) $args[1]] ?? null;
        }
        throw new \LogicException("InMemoryDevicesDb: unexpected fetchRow: {$sql}");
    }

    public function fetchAll(mixed ...$args): array
    {
        $sql = self::sql($args);
        if (str_contains($sql, 'FROM core_devices_peripherals WHERE device = %i')) {
            return array_values(array_filter(
                $this->tables['core_devices_peripherals'],
                static fn (array $p): bool => (int) $p['device'] === (int) $args[1],
            ));
        }
        if (str_contains($sql, 'FROM core_devices_devices')) {
            return array_values($this->tables['core_devices_devices']);
        }
        throw new \LogicException("InMemoryDevicesDb: unexpected fetchAll: {$sql}");
    }

    public function execute(mixed ...$args): void
    {
        $sql = self::sql($args);
        $this->affected = 0;

        if (str_contains($sql, 'UPDATE core_devices_challenges SET used_at = %dt')) {
            [$usedAt, $challenge, $deviceId, $purpose, $now] = [$args[1], $args[2], $args[3], $args[4], $args[5]];
            foreach ($this->tables['core_devices_challenges'] as $id => $c) {
                if ($c['challenge'] === $challenge && $c['device_id'] === $deviceId && $c['purpose'] === $purpose
                    && ($c['used_at'] ?? null) === null && $c['expires_at'] > $now) {
                    $this->tables['core_devices_challenges'][$id]['used_at'] = $usedAt;
                    $this->affected++;
                }
            }
            $this->writes[] = 'consume';
            return;
        }
        if (str_contains($sql, 'DELETE FROM core_devices_challenges WHERE used_at IS NOT NULL OR expires_at < %dt')) {
            $now = $args[1];
            foreach ($this->tables['core_devices_challenges'] as $id => $c) {
                if (($c['used_at'] ?? null) !== null || $c['expires_at'] < $now) {
                    unset($this->tables['core_devices_challenges'][$id]);
                    $this->affected++;
                }
            }
            $this->writes[] = 'prune';
            return;
        }
        throw new \LogicException("InMemoryDevicesDb: unexpected execute: {$sql}");
    }

    public function getAffectedRows(): int
    {
        return $this->affected;
    }

    public function insertRow(string $table, array $data): int
    {
        if (!isset($this->tables[$table])) {
            throw new \LogicException("InMemoryDevicesDb: unexpected insert into {$table}");
        }
        if ($table === 'core_devices_devices') {
            foreach ($this->tables[$table] as $row) {
                if ($row['device_id'] === $data['device_id']) {
                    // Unikátní index `unq_device_id`.
                    throw new \Dibi\UniqueConstraintViolationException('Duplicate entry');
                }
            }
        }
        $id = $this->nextId++;
        $this->tables[$table][$id] = ['id' => $id] + $data;
        $this->writes[] = "insert {$table}";
        return $id;
    }

    public function updateWhere(string $table, array $data, string $where, mixed ...$whereParams): void
    {
        if (!isset($this->tables[$table]) || $where !== 'id = %i') {
            throw new \LogicException("InMemoryDevicesDb: unexpected update of {$table} where {$where}");
        }
        $id = (int) $whereParams[0];
        if (isset($this->tables[$table][$id])) {
            $this->tables[$table][$id] = array_merge($this->tables[$table][$id], $data);
        }
        $this->writes[] = "update {$table}";
    }

    public function deleteWhere(string $table, string $where, mixed ...$whereParams): void
    {
        if ($table !== 'core_devices_challenges' || $where !== 'device_id = %s') {
            throw new \LogicException("InMemoryDevicesDb: unexpected delete from {$table} where {$where}");
        }
        foreach ($this->tables[$table] as $id => $c) {
            if ($c['device_id'] === $whereParams[0]) {
                unset($this->tables[$table][$id]);
            }
        }
        $this->writes[] = "delete {$table}";
    }

    /** @param array<int, mixed> $args */
    private static function sql(array $args): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) $args[0]);
    }
}
