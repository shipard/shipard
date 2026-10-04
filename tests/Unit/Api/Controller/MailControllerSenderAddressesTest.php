<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\MailController;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentRegistry;

/** `GET /_mail/sender-addresses` — nabídka adres odesílatele (#90 D39). */
class MailControllerSenderAddressesTest extends TestCase
{
    /** @param array<string, TableDefinition> $tables */
    private function controller(array $tables = []): MailController
    {
        $db = $this->createMock(DataSourceConnection::class);
        // SettingsStore čte mail.defaultFrom z core_system_settings.
        $db->method('fetchSingle')->willReturn(json_encode('podatelna@firma.example'));
        $db->method('fetchAll')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'core_mail_senders')
                ? [['email_from' => 'fakturace@firma.example']]
                : [],
        );

        return new MailController($db, sys_get_temp_dir(), $tables, new DocumentRegistry());
    }

    public function testListsDefaultAddressAndActiveSenders(): void
    {
        $response = $this->controller()->senderAddresses(new AuthContext(true, 1, 'session', 'shpd_st_x'));
        $data     = $response->getPayload()['data'];

        // Jen adresa a původ — nic ze SMTP nastavení odesílatelů.
        $this->assertSame([
            ['email' => 'podatelna@firma.example', 'source' => 'default'],
            ['email' => 'fakturace@firma.example', 'source' => 'sender'],
        ], $data['addresses']);
        $this->assertSame('podatelna@firma.example', $data['default']);
    }

    public function testAdminOnlySendersTableHidesAddressesFromNonAdmin(): void
    {
        $def = TableDefinition::fromArray([
            'tableId'   => 423,
            'name'      => 'Mail senders',
            'adminOnly' => true,
            'columns'   => [['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true]],
        ]);

        $response = $this->controller(['core_mail_senders' => $def])
            ->senderAddresses(new AuthContext(true, 2, 'session', 'shpd_st_x'));

        $this->assertSame('FORBIDDEN_ADMIN_ONLY', $response->getPayload()['error']['code']);
    }
}
