<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Module\Prints;

use Shipard\Tests\Fixtures\Module\Devices\InMemoryDevicesDb;

/**
 * In-memory databáze pro testy tiskových úloh: tabulky zařízení z
 * `InMemoryDevicesDb` a k nim `core_prints_jobs`. Interpretuje jen SQL, které
 * posílá `PrintJobService`; neznámý dotaz zůstává LogicException rodiče.
 * Že se bajty zapisují modifikátorem `%bin`, tu jde ověřit jen podle klíče
 * vkládaného pole — skutečný zápis hlídá integrační test.
 */
final class InMemoryPrintJobsDb extends InMemoryDevicesDb
{
    public const string JOBS = 'core_prints_jobs';

    /** @var list<string> klíče posledního vkládaného řádku úlohy, jak je služba poslala */
    public array $lastJobInsertKeys = [];

    private ?int $jobsAffected = null;

    public static function create(): self
    {
        /** @var self $db */
        $db = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $db->tables = [
            'core_devices_devices'     => [],
            'core_devices_peripherals' => [],
            'core_devices_challenges'  => [],
            self::JOBS                 => [],
        ];
        $db->writes = [];
        return $db;
    }

    public function fetchRow(mixed ...$args): ?array
    {
        $sql = self::normalized($args);
        if (str_contains($sql, 'FROM core_prints_jobs j')) {
            foreach ($this->tables[self::JOBS] as $job) {
                if ($job['job_id'] === $args[1]) {
                    $peripheral = $this->tables['core_devices_peripherals'][(int) $job['peripheral']] ?? null;
                    return $job + ['peripheral_app_id' => $peripheral['peripheral_id'] ?? null];
                }
            }
            return null;
        }
        return parent::fetchRow(...$args);
    }

    public function fetchSingle(mixed ...$args): mixed
    {
        if (str_contains(self::normalized($args), 'SELECT attempts FROM core_prints_jobs WHERE id = %i')) {
            return $this->tables[self::JOBS][(int) $args[1]]['attempts'] ?? null;
        }
        return parent::fetchSingle(...$args);
    }

    public function execute(mixed ...$args): void
    {
        $sql = self::normalized($args);
        $this->jobsAffected = null;

        if (str_contains($sql, 'UPDATE core_prints_jobs SET state = %s')) {
            [$state, $errorCode, $at, $id] = [$args[1], $args[2], $args[3], (int) $args[4]];
            $this->jobsAffected = 0;
            if (isset($this->tables[self::JOBS][$id])) {
                $job = $this->tables[self::JOBS][$id];
                $this->tables[self::JOBS][$id] = array_merge($job, [
                    'state' => $state, 'error_code' => $errorCode,
                    'attempts' => (int) $job['attempts'] + 1, 'result_at' => $at,
                ]);
                $this->jobsAffected = 1;
            }
            $this->writes[] = 'report';
            return;
        }
        if (str_contains($sql, 'DELETE FROM core_prints_jobs WHERE created_at < %dt')) {
            $this->jobsAffected = 0;
            foreach ($this->tables[self::JOBS] as $id => $job) {
                if ($job['created_at'] < $args[1]) {
                    unset($this->tables[self::JOBS][$id]);
                    $this->jobsAffected++;
                }
            }
            $this->writes[] = 'prune jobs';
            return;
        }
        parent::execute(...$args);
    }

    public function getAffectedRows(): int
    {
        return $this->jobsAffected ?? parent::getAffectedRows();
    }

    public function insertRow(string $table, array $data): int
    {
        if ($table === self::JOBS) {
            $this->lastJobInsertKeys = array_keys($data);
            if (array_key_exists('data%bin', $data)) {
                $data['data'] = $data['data%bin'];
                unset($data['data%bin']);
            }
        }
        return parent::insertRow($table, $data);
    }

    /** @param array<int, mixed> $args */
    private static function normalized(array $args): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) $args[0]);
    }
}
