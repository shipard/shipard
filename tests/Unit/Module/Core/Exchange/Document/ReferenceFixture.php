<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Dibi\Connection;
use Dibi\Row;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Exchange\Resolve\ReferenceResolver;

/**
 * Skutečný `ReferenceResolver` nad vyjmenovanými záznamy — pro testy
 * applieru, které posílají reference formátu dokladu (#166 D30). Tabulky
 * nesou tytéž přirozené klíče jako definice v modulech
 * (`ExchangeKeyDefinitionsTest` hlídá, že klíč v modulu existuje).
 */
trait ReferenceFixture
{
    /** Tabulka → [přirozený klíč, sloupce pro jmenované hledání]. */
    private const REFERENCE_TABLES = [
        'base_persons_persons'                => ['person_id', ['company_id', 'vat_id']],
        'economy_items'                       => ['code', ['ean', 'sku']],
        'docs_core_number_series'             => ['code', []],
        'docs_core_heads'                     => ['doc_number', []],
        'economy_codebooks_cash_desks'        => ['code', []],
        'economy_codebooks_bank_accounts'     => ['code', []],
        'economy_codebooks_vat_registrations' => ['code', []],
        'economy_codebooks_cost_centers'      => ['code', []],
        'economy_accounting_accounts'         => ['number', []],
        // Účet partnera přirozený klíč nemá — reference je jen id (#166 D61).
        'base_persons_bank_accounts'          => [null, []],
    ];

    /**
     * Výchozí záznamy resolveru referencí: osoba 42, položka 18
     * (tatáž id vrací hledání návrhu podle údajů), registrace DPH `cz` (5).
     */
    private const REFERENCE_RECORDS = [
        'base_persons_persons'                => ['person_id=F00042' => 42, 'company_id=12345678' => 42],
        'economy_items'                       => ['code=SLUZBA' => 18],
        'economy_codebooks_vat_registrations' => ['code=cz' => 5],
    ];

    /**
     * Payload testu jako **pokyn** (#166 D31, D39): payloady testů nesou
     * papírovou podobu dokladu (strana a položka údaji, země registrace) —
     * pokyn chce reference a signály návrhu odmítá. Strana a položka
     * dostanou `ref` na výchozí záznamy, signály zmizí; ostatní beze změny.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function asInstruction(array $payload): array
    {
        foreach (['supplier', 'customer'] as $side) {
            if (is_array($payload[$side] ?? null) && $payload[$side] !== [] && ($payload['selfParty'] ?? null) !== $side) {
                $payload[$side] = ['ref' => 42] + $payload[$side];
            }
        }
        foreach ($payload['rows'] ?? [] as $i => $row) {
            if (is_array($row['item'] ?? null) && $row['item'] !== []) {
                unset($row['item']['ourCode']);
                $payload['rows'][$i]['item'] = ['ref' => 18] + $row['item'];
            }
        }
        unset($payload['vat']['registrationCountry']);
        return $payload;
    }

    /** @return array<string, TableDefinition> */
    private static function referenceTables(): array
    {
        $out = [];
        foreach (self::REFERENCE_TABLES as $table => [$key, $lookup]) {
            $columns = [['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true]];
            foreach (array_filter([$key, ...$lookup]) as $column) {
                $columns[] = ['id' => $column, 'name' => $column, 'type' => 'varchar', 'length' => 40, 'nullable' => true];
            }
            $out[$table] = TableDefinition::fromArray([
                'tableId'               => 1,
                'name'                  => $table,
                'columns'               => $columns,
                'exchangeKey'           => $key,
                'exchangeLookupColumns' => $lookup,
            ]);
        }
        return $out;
    }

    /**
     * Resolver účtů pro testy applieru: účet, na který hotový doklad odkáže
     * referencí (#166 D61), patří dodavateli dokladu.
     */
    private function bankAccountResolverMock(): \Shipard\Module\Core\Exchange\Resolve\BankAccountResolver
    {
        $bank = $this->createMock(\Shipard\Module\Core\Exchange\Resolve\BankAccountResolver::class);
        $bank->method('partnerAccountBelongsTo')->willReturn(true);
        return $bank;
    }

    /**
     * @param array<string, array<string, int|list<int>>> $records tabulka →
     *        `"sloupec=hodnota"` → id (seznam = víc záznamů téže hodnoty);
     *        hledání podle id projde pro každé id, které se v tabulce vyskytuje
     */
    private function referenceResolver(array $records): ReferenceResolver
    {
        $ids = static function (string $table, string $column, mixed $value) use ($records): array {
            $rows = $records[$table] ?? [];
            if ($column === 'id' && $table === 'base_persons_bank_accounts' && !isset($records[$table])) {
                // Účty partnerů testy nevyjmenovávají — existuje každý, na
                // který hotový doklad odkáže (id dává mock resolveru účtů).
                return [(int) $value];
            }
            if ($column === 'id') {
                foreach ($rows as $found) {
                    if (in_array((int) $value, (array) $found, true)) {
                        return [(int) $value];
                    }
                }
                return [];
            }
            return array_values((array) ($rows["{$column}={$value}"] ?? []));
        };
        $parse = static function (string $sql): array {
            preg_match('/FROM \[([a-z_]+)\] WHERE \[([a-z_]+)\] = /', $sql, $m);
            return [$m[1] ?? '', $m[2] ?? ''];
        };

        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturnCallback(static function (string $sql, mixed $arg) use ($ids, $parse): array {
            [$table, $column] = $parse($sql);
            return array_map(static fn (int $id): Row => new Row(['id' => $id]), $ids($table, $column, $arg));
        });
        // keyOf(): přirozený klíč záznamu podle id.
        $db->method('fetch')->willReturnCallback(static function (string $sql, mixed $arg) use ($records, $parse): ?Row {
            [$table] = $parse($sql);
            foreach ($records[$table] ?? [] as $key => $found) {
                [$column, $value] = explode('=', (string) $key, 2);
                if (in_array((int) $arg, (array) $found, true) && $column === self::REFERENCE_TABLES[$table][0]) {
                    return new Row([$column => $value]);
                }
            }
            return null;
        });
        return new ReferenceResolver($db, self::referenceTables());
    }
}
