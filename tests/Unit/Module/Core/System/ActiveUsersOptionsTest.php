<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\System;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\System\ActiveUsersOptions;

/** Nabídka uživatelů pro pole `select` (#93 D12). */
class ActiveUsersOptionsTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param array{sql?: string, params?: list<mixed>} $captured
     */
    private function db(array $rows, array &$captured = []): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql, mixed ...$params) use ($rows, &$captured): array {
                $captured = ['sql' => $sql, 'params' => $params];
                return $rows;
            },
        );
        return $db;
    }

    public function testFormOptionsCarryIntegerIdsAndNames(): void
    {
        $options = ActiveUsersOptions::forForm($this->db([
            ['id' => 4, 'full_name' => 'Jana Příkladová', 'login' => 'jana', 'is_active' => 1],
            ['id' => 3, 'full_name' => 'Karel Vzorný', 'login' => 'karel', 'is_active' => 1],
        ]), null);

        $this->assertSame(
            [['value' => 4, 'label' => 'Jana Příkladová'], ['value' => 3, 'label' => 'Karel Vzorný']],
            $options,
        );
    }

    public function testQueryOffersActiveHumansPlusCurrentValue(): void
    {
        $captured = [];
        ActiveUsersOptions::forForm($this->db([], $captured), '7');

        $this->assertStringContainsString('`is_active` = 1 AND `is_system` = 0', $captured['sql']);
        $this->assertStringContainsString('OR `id` = %i', $captured['sql']);
        $this->assertStringContainsString('ORDER BY `full_name`', $captured['sql']);
        $this->assertSame([7], $captured['params']);
    }

    public function testEmptyCurrentValueAddsNobody(): void
    {
        $captured = [];
        ActiveUsersOptions::forForm($this->db([], $captured), null);
        $this->assertSame([0], $captured['params']);

        ActiveUsersOptions::forForm($this->db([], $captured), '');
        $this->assertSame([0], $captured['params']);
    }

    public function testInactiveCurrentUserStaysInOfferWithNote(): void
    {
        $rows = [['id' => 7, 'full_name' => 'Bývalý Kolega', 'login' => 'byvaly', 'is_active' => 0]];

        $this->assertSame('Bývalý Kolega (neaktivní)', ActiveUsersOptions::forForm($this->db($rows), 7)[0]['label']);
        $this->assertSame('Bývalý Kolega (inactive)', ActiveUsersOptions::forForm($this->db($rows), 7, 'en')[0]['label']);
    }

    public function testUserWithoutNameFallsBackToLogin(): void
    {
        $rows = [['id' => 5, 'full_name' => '  ', 'login' => 'import-12', 'is_active' => 1]];

        $this->assertSame('import-12', ActiveUsersOptions::forForm($this->db($rows), null)[0]['label']);
    }

    public function testSettingsOptionsCarryStringValues(): void
    {
        $options = (new ActiveUsersOptions())->options($this->db([
            ['id' => 4, 'full_name' => 'Jana Příkladová', 'login' => 'jana', 'is_active' => 1],
        ]), 'cs');

        $this->assertSame([['value' => '4', 'label' => 'Jana Příkladová']], $options);
    }
}
