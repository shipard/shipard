<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core\Defaults;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Konfigurace a databáze pro testy výchozích hodnot dokladu — skutečné
 * cfgItems (`docTypes`, `rowOperations`, `applyRowOperations`, `vat-cz`),
 * protože pravidla stojí právě na nich; databáze je mock rozlišující dotazy
 * podle tabulky.
 */
trait DefaultsFixtures
{
    /** @var list<array{sql: string, params: list<mixed>}> */
    protected array $queries = [];

    protected function realConfig(): ConfigRuntime
    {
        $modules = dirname(__DIR__, 6) . '/modules';
        return ConfigRuntimeFactory::fromItems([
            'docs.core.docTypes'           => JsoncParser::parseFile($modules . '/docs/core/config/docTypes.jsonc'),
            'docs.core.rowOperations'      => JsoncParser::parseFile($modules . '/docs/core/config/rowOperations.jsonc'),
            'docs.core.applyRowOperations' => JsoncParser::parseFile($modules . '/docs/core/config/applyRowOperations.jsonc'),
            'docs.core.vatPlaces'          => JsoncParser::parseFile($modules . '/docs/core/config/vatPlaces.jsonc'),
            'world.vat.cz'                 => JsoncParser::parseFile($modules . '/world/vat/config/vat-cz.jsonc'),
        ]);
    }

    /**
     * @param array<string, mixed> $settings klíč nastavení → hodnota
     * @param array<string, mixed>|null $registration řádek výchozí registrace DPH
     * @param array<int, array<string, mixed>> $items id položky → řádek `economy_items`
     * @param array<string, mixed>|null $cashDesk řádek pokladny (z `cash_desk` i z řady)
     * @param array<int, ?string> $units id jednotky → systémový kód
     */
    protected function db(
        array $settings = [],
        ?array $registration = ['id' => 7, 'country' => 'cz'],
        ?int $bankAccount = 3,
        array $items = [],
        ?array $cashDesk = null,
        array $units = [],
    ): DataSourceConnection {
        $this->queries = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturnCallback(
            static fn (mixed ...$args): mixed => array_key_exists((string) ($args[1] ?? ''), $settings)
                ? json_encode($settings[(string) $args[1]])
                : null,
        );
        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, mixed ...$params) use ($registration, $bankAccount, $items, $cashDesk, $units): ?array {
                $this->queries[] = ['sql' => $sql, 'params' => $params];
                return match (true) {
                    str_contains($sql, 'economy_codebooks_cash_desks')        => $cashDesk,
                    str_contains($sql, 'economy_codebooks_vat_registrations') => $registration,
                    str_contains($sql, 'economy_codebooks_bank_accounts')     => $bankAccount !== null ? ['id' => $bankAccount] : null,
                    str_contains($sql, 'economy_items')                       => $items[(int) ($params[0] ?? 0)] ?? null,
                    str_contains($sql, 'core_units')                          => array_key_exists((int) ($params[0] ?? 0), $units)
                        ? ['system_code' => $units[(int) $params[0]]]
                        : null,
                    default                                                   => null,
                };
            },
        );
        return $db;
    }

    /** @return list<string> dotazy na tabulku */
    protected function queriesOn(string $table): array
    {
        return array_values(array_filter(
            array_column($this->queries, 'sql'),
            static fn (string $sql): bool => str_contains($sql, $table),
        ));
    }
}
