<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\AllowedSenders;
use Shipard\Core\Mail\RecordSender;
use Shipard\Core\Mail\RecordSenderProvider;
use Shipard\Core\Mail\SenderResolution;
use Shipard\Core\Mail\SenderResolver;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/** Odesílatel ze sloupců záznamu `sender_email` / `sender_name`. */
final class RecordColumnsSenderProvider implements RecordSenderProvider
{
    public function recordSender(array $record, DataSourceConnection $db): ?RecordSender
    {
        if (!array_key_exists('sender_email', $record)) {
            return null;
        }
        return new RecordSender($record['sender_email'], $record['sender_name'] ?? null);
    }
}

class SenderResolverTest extends TestCase
{
    private const TABLE = 'docs_core_heads';

    /** @param list<string> $senders Adresy aktivních odesílatelů. */
    private function resolver(?string $defaultFrom, array $senders = [], ?string $ownName = 'Naše firma s.r.o.'): SenderResolver
    {
        $allowed = $this->createMock(AllowedSenders::class);
        $all     = array_merge($defaultFrom === null ? [] : [$defaultFrom], $senders);
        $allowed->method('defaultFrom')->willReturn($defaultFrom);
        $allowed->method('isAllowed')->willReturnCallback(
            static fn (string $email): bool => in_array(strtolower($email), array_map('strtolower', $all), true),
        );

        return new SenderResolver(
            $allowed,
            $this->createMock(DataSourceConnection::class),
            [self::TABLE => RecordColumnsSenderProvider::class],
            static fn (): ?string => $ownName,
            ConfigRuntimeFactory::fromItems(['core.mail.sendLabels' => [
                'noSender'               => ['name' => 'Chybí adresa odesílatele.'],
                'senderNotAllowed'       => ['name' => 'Adresa „{email}“ není povolená.'],
                'recordSenderNotAllowed' => ['name' => 'Adresa řady „{email}“ už není povolená.'],
            ]]),
        );
    }

    public function testChosenAddressWinsOverRecordAndDefault(): void
    {
        $resolution = $this->resolver('podatelna@firma.example', ['fakturace@firma.example', 'ucet@firma.example'])
            ->resolve(self::TABLE, ['sender_email' => 'fakturace@firma.example'], 'ucet@firma.example');

        $this->assertTrue($resolution->isResolved());
        $this->assertSame('ucet@firma.example', $resolution->email);
        $this->assertSame(SenderResolution::SOURCE_CHOSEN, $resolution->source);
    }

    public function testChosenAddressOutsideAllowedListIsRejected(): void
    {
        $resolution = $this->resolver('podatelna@firma.example')
            ->resolve(self::TABLE, [], 'nekdo@jinde.example');

        $this->assertFalse($resolution->isResolved());
        $this->assertNull($resolution->email);
        $this->assertSame(SenderResolution::SENDER_NOT_ALLOWED, $resolution->errorCode);
        $this->assertSame('Adresa „nekdo@jinde.example“ není povolená.', $resolution->errorText);
    }

    public function testRecordSenderIsUsedBeforeDefault(): void
    {
        $resolution = $this->resolver('podatelna@firma.example', ['fakturace@firma.example'])
            ->resolve(self::TABLE, ['sender_email' => 'fakturace@firma.example', 'sender_name' => 'Fakturace']);

        $this->assertSame('fakturace@firma.example', $resolution->email);
        $this->assertSame('Fakturace', $resolution->name);
        $this->assertSame(SenderResolution::SOURCE_RECORD, $resolution->source);
    }

    public function testDeactivatedRecordSenderIsErrorNotSilentFallback(): void
    {
        // Řada ukazuje na odesílatele, který už není aktivní.
        $resolution = $this->resolver('podatelna@firma.example')
            ->resolve(self::TABLE, ['sender_email' => 'fakturace@firma.example']);

        $this->assertFalse($resolution->isResolved());
        $this->assertSame(SenderResolution::SENDER_NOT_ALLOWED, $resolution->errorCode);
        $this->assertSame('Adresa řady „fakturace@firma.example“ už není povolená.', $resolution->errorText);
    }

    public function testDefaultAddressIsUsedWhenRecordSetsNone(): void
    {
        $resolver = $this->resolver('podatelna@firma.example');

        // Řada s volbou „Automaticky“ i tabulka bez poskytovatele.
        foreach ([[self::TABLE, ['sender_email' => null]], ['base_persons_persons', []]] as [$table, $record]) {
            $resolution = $resolver->resolve($table, $record);

            $this->assertSame('podatelna@firma.example', $resolution->email);
            $this->assertSame(SenderResolution::SOURCE_DEFAULT, $resolution->source);
        }
    }

    public function testNoAddressAnywhereIsNoSender(): void
    {
        $resolution = $this->resolver(null)->resolve(self::TABLE, ['sender_email' => null]);

        $this->assertFalse($resolution->isResolved());
        $this->assertSame(SenderResolution::NO_SENDER, $resolution->errorCode);
        $this->assertSame('Chybí adresa odesílatele.', $resolution->errorText);
    }

    public function testNameComesFromRecordThenOwnCompanyThenNone(): void
    {
        $record = ['sender_email' => null, 'sender_name' => 'Fakturační oddělení'];

        $this->assertSame(
            'Fakturační oddělení',
            $this->resolver('podatelna@firma.example')->resolve(self::TABLE, $record)->name,
        );
        // Jméno z řady platí i pro adresu zvolenou v dialogu.
        $this->assertSame(
            'Fakturační oddělení',
            $this->resolver('podatelna@firma.example')->resolve(self::TABLE, $record, 'podatelna@firma.example')->name,
        );
        $this->assertSame(
            'Naše firma s.r.o.',
            $this->resolver('podatelna@firma.example')->resolve(self::TABLE, ['sender_email' => null])->name,
        );
        $this->assertNull(
            $this->resolver('podatelna@firma.example', ownName: null)->resolve('base_persons_persons', [])->name,
        );
    }

    public function testProviderThatDoesNotImplementInterfaceIsConfigurationError(): void
    {
        $resolver = new SenderResolver(
            $this->createMock(AllowedSenders::class),
            $this->createMock(DataSourceConnection::class),
            [self::TABLE => \stdClass::class],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not implement RecordSenderProvider/');

        $resolver->resolve(self::TABLE, []);
    }
}
