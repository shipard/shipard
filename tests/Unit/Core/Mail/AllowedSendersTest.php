<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\AllowedSenders;
use Shipard\Core\Settings\SettingsStore;

class AllowedSendersTest extends TestCase
{
    /** @param list<string>|\Throwable $senders Adresy aktivních odesílatelů, nebo chyba dotazu. */
    private function allowed(?string $defaultFrom, array|\Throwable $senders): AllowedSenders
    {
        $settings = $this->createMock(SettingsStore::class);
        $settings->method('get')->with('mail.defaultFrom')->willReturn($defaultFrom);

        $db = $this->createMock(DataSourceConnection::class);
        if ($senders instanceof \Throwable) {
            $db->method('fetchAll')->willThrowException($senders);
        } else {
            $db->method('fetchAll')->willReturn(array_map(
                static fn (string $email): array => ['email_from' => $email],
                $senders,
            ));
        }

        return new AllowedSenders($db, $settings);
    }

    public function testDefaultAddressComesFirstThenActiveSenders(): void
    {
        $allowed = $this->allowed('podatelna@firma.example', ['fakturace@firma.example', 'ucet@firma.example']);

        $this->assertSame([
            ['email' => 'podatelna@firma.example', 'source' => 'default'],
            ['email' => 'fakturace@firma.example', 'source' => 'sender'],
            ['email' => 'ucet@firma.example', 'source' => 'sender'],
        ], $allowed->addresses());
        $this->assertSame('podatelna@firma.example', $allowed->defaultFrom());
    }

    public function testDefaultAddressThatIsAlsoSenderIsListedOnce(): void
    {
        $allowed = $this->allowed('Fakturace@Firma.example', ['fakturace@firma.example']);

        $this->assertSame(['Fakturace@Firma.example'], $allowed->emails());
    }

    public function testIsAllowedIgnoresCaseAndRejectsUnknownAddress(): void
    {
        $allowed = $this->allowed('podatelna@firma.example', ['fakturace@firma.example']);

        $this->assertTrue($allowed->isAllowed(' FAKTURACE@firma.example '));
        $this->assertTrue($allowed->isAllowed('podatelna@firma.example'));
        $this->assertFalse($allowed->isAllowed('nekdo@jinde.example'));
        $this->assertFalse($allowed->isAllowed(''));
    }

    public function testWithoutDefaultAndSendersNothingIsAllowed(): void
    {
        $allowed = $this->allowed(null, []);

        $this->assertSame([], $allowed->addresses());
        $this->assertNull($allowed->defaultFrom());
    }

    public function testDataSourceWithoutSendersTableKeepsDefaultAddress(): void
    {
        $allowed = $this->allowed('podatelna@firma.example', new \Dibi\DriverException("Table 'core_mail_senders' doesn't exist"));

        $this->assertSame(['podatelna@firma.example'], $allowed->emails());
    }
}
