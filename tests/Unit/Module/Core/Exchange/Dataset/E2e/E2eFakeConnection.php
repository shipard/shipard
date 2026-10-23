<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\E2e;

use Shipard\Core\Database\DataSourceConnection;

/** Spojení se skriptovaným uživatelem a záznamem zápisů (bez databáze). */
final class E2eFakeConnection extends DataSourceConnection
{
    /** @var array<string, mixed>|null řádek uživatele `e2e`; null = neexistuje */
    public ?array $userRow = null;
    /** @var list<array{table: string, data: array<string, mixed>}> */
    public array $inserted = [];
    /** @var list<array<int, mixed>> */
    public array $executed = [];
    /** @var list<array<int, mixed>> */
    public array $fetchedRows = [];
    public mixed $single = 0;
    public int $nextId = 500;

    public static function create(): self
    {
        $db = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        return $db;
    }

    public function fetchRow(mixed ...$args): ?array
    {
        $this->fetchedRows[] = $args;
        return $this->userRow;
    }

    public function fetchSingle(mixed ...$args): mixed
    {
        return $this->single;
    }

    public function insertRow(string $table, array $data): int
    {
        $this->inserted[] = ['table' => $table, 'data' => $data];
        return $this->nextId++;
    }

    public function execute(mixed ...$args): void
    {
        $this->executed[] = $args;
    }

    public function getAffectedRows(): int
    {
        return 1;
    }

    /** @return list<string> tokeny založených sessions */
    public function sessionTokens(): array
    {
        $tokens = [];
        foreach ($this->inserted as $row) {
            if ($row['table'] === 'core_system_sessions') {
                $tokens[] = (string) $row['data']['token'];
            }
        }
        return $tokens;
    }

    /** @return list<string> SQL DELETE sessions podle uživatele */
    public function sessionDeletes(): array
    {
        return array_values(array_filter(
            array_map(static fn (array $q): string => (string) $q[0], $this->executed),
            static fn (string $sql): bool => str_contains($sql, 'DELETE FROM core_system_sessions WHERE user_id'),
        ));
    }
}
