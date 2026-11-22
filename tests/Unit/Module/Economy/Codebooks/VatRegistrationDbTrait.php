<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use Dibi\Connection;
use Dibi\Row;

/**
 * Databáze registrací DPH v paměti pro testy kódu registrace (#166 D17):
 * odpovídá na dotazy `VatRegistrationCodeGenerator` nad zadanými řádky.
 */
trait VatRegistrationDbTrait
{
    /** @param list<array<string, mixed>> $registrations řádky `economy_codebooks_vat_registrations` */
    private function registrationsDb(array $registrations = []): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static function (string $sql, mixed ...$args) use ($registrations): ?Row {
            if (str_contains($sql, 'MIN([id])')) {
                $ids = [];
                foreach ($registrations as $row) {
                    if (($row['country'] ?? null) === $args[1] && (int) ($row['docState'] ?? 40) !== (int) $args[2]) {
                        $ids[] = (int) $row['id'];
                    }
                }
                return new Row(['first_id' => $ids === [] ? null : min($ids)]);
            }
            if (str_contains($sql, 'SELECT [id], [name], [docState]')) {
                foreach ($registrations as $row) {
                    if ((int) $row['id'] !== (int) $args[2]
                        && strtolower((string) ($row['code'] ?? '')) === strtolower((string) $args[1])
                    ) {
                        return new Row(['id' => $row['id'], 'name' => $row['name'] ?? '', 'docState' => $row['docState'] ?? 40]);
                    }
                }
                return null;
            }
            return null;
        });
        $db->method('fetchAll')->willReturnCallback(static function (string $sql, mixed ...$args) use ($registrations): array {
            $out = [];
            foreach ($registrations as $row) {
                if ((string) ($row['code'] ?? '') !== '' && (int) $row['id'] !== (int) $args[1]) {
                    $out[] = new Row(['code' => $row['code']]);
                }
            }
            return $out;
        });
        return $db;
    }
}
