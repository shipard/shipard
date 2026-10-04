<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons\Send;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigCompiler;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Persons\Send\Recipient;
use Shipard\Module\Base\Persons\Send\RecipientResolution;
use Shipard\Module\Base\Persons\Send\RecipientResolver;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Příjemci odeslání (#90 D35). Platnost, stav a pořadí kontaktů vybírá SQL
 * — test hlídá, že je dotaz nese; výběr podle účelu, duplicity, zálohu na
 * e-mail osoby a neplatné adresy řeší resolver v PHP.
 */
class RecipientResolverTest extends TestCase
{
    private const TODAY = '2026-10-04';

    /** @var array{sql: string, args: list<mixed>}|null Dotaz na kontakty. */
    private ?array $contactsQuery = null;

    /**
     * @param ?array<string, mixed> $person
     * @param list<array<string, mixed>> $contacts Řádky, které vrátí dotaz na kontakty.
     */
    private function resolver(?array $person, array $contacts): RecipientResolver
    {
        /** @var DataSourceConnection&MockObject $db */
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($person);
        $db->method('fetchAll')->willReturnCallback(function (string $sql, mixed ...$args) use ($contacts): array {
            $this->contactsQuery = ['sql' => $sql, 'args' => $args];
            return $contacts;
        });

        $config = ConfigRuntimeFactory::fromItems([
            ConfigCompiler::SEND_PURPOSES_ITEM => [
                'invoices'  => ['name' => 'Faktury a daňové doklady'],
                'reminders' => ['name' => 'Upomínky'],
            ],
            'base.persons.formLabels' => [
                'recipientContact'        => ['name' => 'Kontakt {name} — {purpose}'],
                'recipientPerson'         => ['name' => 'E-mail osoby'],
                'recipientInvalidAddress' => ['name' => 'Adresa „{email}“ ({source}) není platná.'],
                'recipientNone'           => ['name' => 'Žádný příjemce.'],
                'recipientNoPerson'       => ['name' => 'Záznam nemá osobu.'],
            ],
        ]);

        return new RecipientResolver(
            $db,
            $config,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable(self::TODAY),
        );
    }

    /** @return array<string, mixed> */
    private function person(?string $email = 'info@odberatel.example'): array
    {
        return ['id' => 7, 'full_name' => 'Odběratel s.r.o.', 'email' => $email];
    }

    /** @return array<string, mixed> */
    private function contact(int $id, string $name, string $email, ?array $purposes): array
    {
        return [
            'id' => $id, 'name' => $name, 'email' => $email,
            'send_purposes' => $purposes === null ? null : json_encode($purposes),
        ];
    }

    public function testAllContactsWithPurposeGoToRecipientsInQueryOrder(): void
    {
        $resolution = $this->resolver($this->person(), [
            $this->contact(11, 'Účtárna', 'ucetni@odberatel.example', ['invoices']),
            $this->contact(12, 'Jana Ukázková', 'jana@odberatel.example', ['reminders', 'invoices']),
        ])->resolve(7, 'invoices');

        $this->assertSame(['ucetni@odberatel.example', 'jana@odberatel.example'], $resolution->emails());
        $this->assertSame([], $resolution->messages);

        $first = $resolution->recipients[0];
        $this->assertSame(Recipient::SOURCE_CONTACT, $first->source);
        $this->assertSame(11, $first->contactId);
        $this->assertSame('Účtárna', $first->name);
        $this->assertSame('Kontakt Účtárna — Faktury a daňové doklady', $first->label);
    }

    public function testQuerySelectsOnlyValidActiveContactsOfPerson(): void
    {
        $this->resolver($this->person(), [])->resolve(7, 'invoices');

        $sql = (string) $this->contactsQuery['sql'];
        $this->assertStringContainsString('[docState] IN %in', $sql);
        $this->assertStringContainsString('[valid_from] IS NULL OR [valid_from] <= %s', $sql);
        $this->assertStringContainsString('[valid_to] IS NULL OR [valid_to] >= %s', $sql);
        $this->assertStringContainsString('ORDER BY [order_pos], [id]', $sql);
        // Koncept, V pořádku, V opravě — archiv a koš ne.
        $this->assertSame([7, [10, 40, 80], self::TODAY, self::TODAY], $this->contactsQuery['args']);
    }

    public function testContactWithOtherPurposeIsNotUsed(): void
    {
        $resolution = $this->resolver($this->person(), [
            $this->contact(11, 'Upomínky', 'upominky@odberatel.example', ['reminders']),
        ])->resolve(7, 'invoices');

        // Kontakt s jiným účelem nic nedostane; nastupuje e-mail osoby.
        $this->assertSame(['info@odberatel.example'], $resolution->emails());
        $this->assertSame(Recipient::SOURCE_PERSON, $resolution->recipients[0]->source);
        $this->assertSame('E-mail osoby', $resolution->recipients[0]->label);
        $this->assertSame('Odběratel s.r.o.', $resolution->recipients[0]->name);
    }

    public function testSameAddressOnTwoContactsIsListedOnce(): void
    {
        $resolution = $this->resolver($this->person(), [
            $this->contact(11, 'Účtárna', 'Ucetni@Odberatel.example', ['invoices']),
            $this->contact(12, 'Fakturace', 'ucetni@odberatel.example', ['invoices']),
        ])->resolve(7, 'invoices');

        $this->assertSame(['Ucetni@Odberatel.example'], $resolution->emails());
    }

    public function testInvalidContactAddressIsSkippedWithWarning(): void
    {
        $resolution = $this->resolver($this->person(), [
            $this->contact(11, 'Účtárna', 'ucetni(at)odberatel', ['invoices']),
            $this->contact(12, 'Fakturace', 'fakturace@odberatel.example', ['invoices']),
        ])->resolve(7, 'invoices');

        $this->assertSame(['fakturace@odberatel.example'], $resolution->emails());
        $this->assertCount(1, $resolution->messages);
        $this->assertSame('warning', $resolution->messages[0]['severity']);
        $this->assertSame(RecipientResolution::INVALID_ADDRESS, $resolution->messages[0]['code']);
        $this->assertStringContainsString('ucetni(at)odberatel', $resolution->messages[0]['text']);
    }

    public function testPersonWithoutContactsAndEmailHasNoRecipient(): void
    {
        $resolution = $this->resolver($this->person(email: ''), [])->resolve(7, 'invoices');

        $this->assertFalse($resolution->hasRecipients());
        $this->assertSame('error', $resolution->messages[0]['severity']);
        $this->assertSame(RecipientResolution::NO_RECIPIENT, $resolution->messages[0]['code']);
        $this->assertSame('Žádný příjemce.', $resolution->messages[0]['text']);
    }

    public function testInvalidPersonEmailIsReportedAndLeavesNoRecipient(): void
    {
        $resolution = $this->resolver($this->person(email: 'neni-adresa'), [])->resolve(7, 'invoices');

        $this->assertFalse($resolution->hasRecipients());
        $this->assertSame(
            [RecipientResolution::INVALID_ADDRESS, RecipientResolution::NO_RECIPIENT],
            array_column($resolution->messages, 'code'),
        );
    }

    public function testRecordWithoutPersonHasNoRecipient(): void
    {
        foreach ([null, 0] as $personId) {
            $resolution = $this->resolver(null, [])->resolve($personId, 'invoices');

            $this->assertFalse($resolution->hasRecipients());
            $this->assertSame(RecipientResolution::NO_RECIPIENT, $resolution->messages[0]['code']);
            $this->assertSame('Záznam nemá osobu.', $resolution->messages[0]['text']);
        }
        $this->assertNull($this->contactsQuery, 'bez osoby se kontakty nehledají');
    }

    public function testAddressIsReadLiveOnEveryResolve(): void
    {
        // Oprava adresy na osobě platí pro další odeslání — resolver si nic nepamatuje.
        $this->assertSame(
            ['stara@odberatel.example'],
            $this->resolver($this->person(email: 'stara@odberatel.example'), [])->resolve(7, 'invoices')->emails(),
        );
        $this->assertSame(
            ['nova@odberatel.example'],
            $this->resolver($this->person(email: 'nova@odberatel.example'), [])->resolve(7, 'invoices')->emails(),
        );
    }

    public function testLabelsFallBackToEnglishWithoutCompiledConfig(): void
    {
        /** @var DataSourceConnection&MockObject $db */
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($this->person());
        $db->method('fetchAll')->willReturn([$this->contact(11, 'Accounting', 'a@odberatel.example', ['invoices'])]);

        $resolution = (new RecipientResolver($db))->resolve(7, 'invoices');

        $this->assertSame('Contact Accounting — invoices', $resolution->recipients[0]->label);
    }
}
