<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Core\Settings;

use Shipard\Core\Settings\KeyValueStore;

/** Nastavení v paměti pro služby stavěné nad KeyValueStore; `writes` počítá zápisy. */
final class InMemoryKeyValueStore implements KeyValueStore
{
    public int $writes = 0;

    /** @param array<string, mixed> $values */
    public function __construct(public array $values = [])
    {
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function getMany(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key);
        }
        return $out;
    }

    public function set(string $key, mixed $value): void
    {
        $this->writes++;
        if ($value === null) {
            unset($this->values[$key]);
            return;
        }
        $this->values[$key] = $value;
    }

    public function delete(string $key): void
    {
        $this->writes++;
        unset($this->values[$key]);
    }
}
