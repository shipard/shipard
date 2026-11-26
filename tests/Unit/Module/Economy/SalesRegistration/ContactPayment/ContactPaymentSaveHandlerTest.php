<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\ContactPayment;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentPolicy;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentSaveHandler;
use Shipard\Module\Economy\SalesRegistration\Evaluation\RegistrationFacts;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;

/**
 * Kontaktní platba při uložení dokladu (#171 U16, U21, U22): příznak jen
 * u typů a úhrad podle dat typu `cz.eet2`, čas platby ne v budoucnu (v zóně
 * místa tržby), změna nastaví čas změny, doklad bez příznaku se nemění.
 */
class ContactPaymentSaveHandlerTest extends TestCase
{
    use SalesRegistrationConfig;

    /** „Teď“ testů: 5. 2. 2027 10:00 světového času = 11:00 v Praze. */
    private const NOW = '2027-02-05 10:00:00';

    private function handler(bool $withRegistration = true): ContactPaymentSaveHandler
    {
        $config = $this->salesRegistrationConfig();
        $registrations = [];
        if ($withRegistration) {
            $type = SalesRegistrationTypes::fromConfig($config)->get('cz.eet2');
            $this->assertNotNull($type);
            $registrations[] = new RegistrationFacts(1, $type, '2027-01-01', null, 2, [2 => '181']);
        }

        $handler = new class extends ContactPaymentSaveHandler {
            /** @var list<RegistrationFacts> */
            public array $registrations = [];
            public int $policyReads = 0;

            protected function policy(): ContactPaymentPolicy
            {
                $this->policyReads++;
                return new ContactPaymentPolicy($this->registrations);
            }

            protected function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(ContactPaymentSaveHandlerTest::NOW_PUBLIC, new \DateTimeZone('UTC'));
            }
        };
        $handler->registrations = $registrations;
        $handler->setConfig($config);
        return $handler;
    }

    public const string NOW_PUBLIC = self::NOW;

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $original
     */
    private function error(ContactPaymentSaveHandler $handler, array &$data, ?array $original = null): ?string
    {
        try {
            $handler->onBeforeSave(ContactPaymentSaveHandler::TABLE, $data, $original);
        } catch (\DomainException $e) {
            return $e->getMessage();
        }
        return null;
    }

    /** @param array<string, mixed> $override */
    private function invoice(array $override = []): array
    {
        return $override + ['id' => 7, 'doc_type' => 'invno', 'payment_method' => 1, 'docState' => 10];
    }

    public function testDocumentWithoutTheFlagIsLeftAlone(): void
    {
        $handler = $this->handler();

        // Uložení, které sloupce vůbec neposílá (doklad z API, jiný typ dokladu).
        $data = $this->invoice(['doc_type' => 'cashreg', 'payment_method' => 0]);
        $before = $data;
        $this->assertNull($this->error($handler, $data));
        $this->assertSame($before, $data, 'nic se nezapisuje — ani výchozí hodnoty, ani čas změny');

        // Formulář posílá nezaškrtnutý příznak a prázdný čas.
        $data = $this->invoice(['contact_payment' => 0, 'contact_payment_at' => '']);
        $this->assertNull($this->error($handler, $data, $this->invoice(['contact_payment' => 0, 'contact_payment_at' => null])));
        $this->assertNull($data['contact_payment_at']);
        $this->assertArrayNotHasKey('contact_payment_changed_at', $data);
        $this->assertSame(0, $handler->policyReads, 'registrace se kvůli dokladu bez příznaku nečtou');
    }

    public function testSettingTheFlagStampsTheChange(): void
    {
        $handler = $this->handler();
        $data = $this->invoice(['contact_payment' => 1, 'contact_payment_at' => '2027-02-05T10:45']);

        $this->assertNull($this->error($handler, $data, $this->invoice(['contact_payment' => 0])));

        $this->assertSame('2027-02-05 10:45:00', $data['contact_payment_at'], 'čas, jak ho uživatel zadal (zóna místa tržby)');
        $this->assertSame(self::NOW, $data['contact_payment_changed_at']);
    }

    /** U16: příznak bez času je v pořádku — čas tržby bude čas přechodu. */
    public function testFlagWithoutTimeIsAllowed(): void
    {
        $handler = $this->handler();
        $data = $this->invoice(['contact_payment' => 1, 'contact_payment_at' => '']);

        $this->assertNull($this->error($handler, $data, $this->invoice(['contact_payment' => 0])));

        $this->assertNull($data['contact_payment_at']);
        $this->assertSame(self::NOW, $data['contact_payment_changed_at']);
    }

    public function testFlagIsAllowedOnlyWhereTheTypeDataSay(): void
    {
        $handler = $this->handler();
        $allowed = [['invno', 1], ['invpo', 1], ['invpo', 2]];
        $refused = [
            ['invno', 0], ['invno', 2],              // spadá pravidlem
            ['invno', 3], ['invno', 4], ['invno', 5], // dobírka, zápočet, brána
            ['invpo', 0], ['invpo', 5],
            ['cash', 0], ['cashreg', 0], ['invni', 1],
        ];
        foreach ($allowed as [$docType, $method]) {
            $data = $this->invoice(['doc_type' => $docType, 'payment_method' => $method, 'contact_payment' => 1]);
            $this->assertNull($this->error($handler, $data), "{$docType} × {$method}");
        }
        foreach ($refused as [$docType, $method]) {
            $data = $this->invoice(['doc_type' => $docType, 'payment_method' => $method, 'contact_payment' => 1]);
            $this->assertStringContainsString('nastavit nejde', (string) $this->error($handler, $data), "{$docType} × {$method}");
        }
    }

    public function testChangingThePaymentMethodOfAFlaggedDocumentIsChecked(): void
    {
        $handler = $this->handler();
        $original = $this->invoice(['contact_payment' => 1, 'contact_payment_at' => '2027-02-05 10:45:00']);

        // Formulář posílá jen změněný způsob úhrady.
        $data = ['id' => 7, 'payment_method' => 5];
        $this->assertStringContainsString('nastavit nejde', (string) $this->error($handler, $data, $original));

        // Beze změny hlavičky se doklad s příznakem znovu neposuzuje.
        $data = ['id' => 7, 'doc_text' => 'jiný text'];
        $this->assertNull($this->error($handler, $data, $original));
        $this->assertArrayNotHasKey('contact_payment_changed_at', $data);
    }

    public function testWithoutAConfirmedRegistrationTheFlagCannotBeSet(): void
    {
        $handler = $this->handler(withRegistration: false);
        $data = $this->invoice(['contact_payment' => 1]);

        $this->assertStringContainsString('žádná registrace', (string) $this->error($handler, $data));
    }

    /** U23: 11:30 v Praze je půl hodiny po „teď“ (10:00 UTC = 11:00 v Praze). */
    public function testPaymentTimeInTheFutureIsRefusedInTheSaleZone(): void
    {
        $handler = $this->handler();

        $future = $this->invoice(['contact_payment' => 1, 'contact_payment_at' => '2027-02-05T11:30']);
        $this->assertStringContainsString('v budoucnu', (string) $this->error($handler, $future));

        // 10:30 v Praze je minulost, i když na hodinách serveru (UTC) ještě nebylo.
        $past = $this->invoice(['contact_payment' => 1, 'contact_payment_at' => '2027-02-05T10:30']);
        $this->assertNull($this->error($handler, $past));

        // Pár minut napřed (hodiny prohlížeče) projde a zapíše se „teď“.
        $skewed = $this->invoice(['contact_payment' => 1, 'contact_payment_at' => '2027-02-05T11:03']);
        $this->assertNull($this->error($handler, $skewed));
        $this->assertSame('2027-02-05 11:00:00', $skewed['contact_payment_at']);
    }

    public function testInvalidTimeIsRefused(): void
    {
        $handler = $this->handler();
        $data = $this->invoice(['contact_payment' => 1, 'contact_payment_at' => 'zítra']);

        $this->assertStringContainsString('není platné datum', (string) $this->error($handler, $data));
    }

    public function testClearingTheFlagDropsTheTimeAndStampsTheChange(): void
    {
        $handler = $this->handler();
        $original = $this->invoice(['contact_payment' => 1, 'contact_payment_at' => '2027-02-04 09:00:00']);
        $data = $this->invoice(['contact_payment' => 0, 'contact_payment_at' => '2027-02-04T09:00']);

        $this->assertNull($this->error($handler, $data, $original));

        $this->assertNull($data['contact_payment_at']);
        $this->assertSame(self::NOW, $data['contact_payment_changed_at']);
        $this->assertSame(0, $handler->policyReads, 'zrušení příznaku pravidla U14 nečte');
    }

    public function testChangingOnlyTheTimeStampsTheChange(): void
    {
        $handler = $this->handler();
        $original = $this->invoice(['contact_payment' => 1, 'contact_payment_at' => '2027-02-04 09:00:00']);

        $same = $this->invoice(['contact_payment' => 1, 'contact_payment_at' => '2027-02-04T09:00']);
        $this->assertNull($this->error($handler, $same, $original));
        $this->assertArrayNotHasKey('contact_payment_changed_at', $same, 'týž čas v jiném zápisu není změna');

        $moved = $this->invoice(['contact_payment' => 1, 'contact_payment_at' => '2027-02-04T09:20']);
        $this->assertNull($this->error($handler, $moved, $original));
        $this->assertSame('2027-02-04 09:20:00', $moved['contact_payment_at']);
        $this->assertSame(self::NOW, $moved['contact_payment_changed_at']);
    }

    /** Uložení s přechodem stavu: čas změny = čas přechodu, tržba vzniká přechodem. */
    public function testChangeWithinAStateTransitionTakesTheTransitionTime(): void
    {
        $handler = $this->handler();
        $original = $this->invoice(['contact_payment' => 0, 'docState' => 10, 'doc_state_changed_at' => '2027-02-01 08:00:00']);
        $data = $this->invoice(['contact_payment' => 1, 'docState' => 40, 'doc_state_changed_at' => '2027-02-05 09:59:58']);

        $this->assertNull($this->error($handler, $data, $original));

        $this->assertSame('2027-02-05 09:59:58', $data['contact_payment_changed_at']);
    }

    public function testOtherTablesAreIgnored(): void
    {
        $handler = $this->handler();
        $data = ['contact_payment' => 1, 'doc_type' => 'cashreg'];

        $handler->onBeforeSave('docs_core_rows', $data, null);

        $this->assertSame(['contact_payment' => 1, 'doc_type' => 'cashreg'], $data);
    }
}
