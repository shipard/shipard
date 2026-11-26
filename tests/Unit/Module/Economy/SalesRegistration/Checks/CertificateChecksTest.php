<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Checks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\SalesRegistration\Checks\CertificateCheck;
use Shipard\Module\Economy\SalesRegistration\Checks\CertificateExpiredCheck;
use Shipard\Module\Economy\SalesRegistration\Checks\CertificateExpiringCheck;
use Shipard\Module\Economy\SalesRegistration\Checks\CertificateMissingCheck;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;

/**
 * Upozornění na pokladní certifikát (#171 D13): vyprší do 30 dnů (warning),
 * vypršel (error), platná registrace bez certifikátu (warning). Jen
 * u registrací V pořádku v době jejich platnosti.
 */
class CertificateChecksTest extends TestCase
{
    use SalesRegistrationConfig;

    private const NOW = '2027-03-10 12:00:00';

    /**
     * @param class-string<CertificateCheck> $class
     * @param list<array<string, mixed>> $registrations
     */
    private function check(string $class, array $registrations, string $language = 'cs'): CertificateCheck
    {
        $config = $this->salesRegistrationConfig($language === 'cs' ? [] : [
            'economy.salesRegistration.texts' => self::repoJsonc('modules/economy/salesRegistration/config/texts.jsonc', 'en'),
            'world.cz.salesRegistrationTypes' => self::repoJsonc('modules/world/cz/config/salesRegistrationTypes.jsonc', 'en'),
        ]);
        $db = $this->createMock(DataSourceConnection::class);

        $check = match ($class) {
            CertificateExpiringCheck::class => new class($db, $config, $language) extends CertificateExpiringCheck {
                use FixedRegistrations;
            },
            CertificateExpiredCheck::class => new class($db, $config, $language) extends CertificateExpiredCheck {
                use FixedRegistrations;
            },
            default => new class($db, $config, $language) extends CertificateMissingCheck {
                use FixedRegistrations;
            },
        };
        $check->rows = $registrations;
        $check->fixedNow = self::NOW;
        return $check;
    }

    /** @return array<string, mixed> registrace V pořádku, platná, s certifikátem */
    private static function registration(int $id, ?string $certValidTo, array $override = []): array
    {
        return $override + [
            'id'              => $id,
            'type'            => 'cz.eet2',
            'name'            => 'Prodejna ' . $id,
            'taxpayer_id'     => $certValidTo !== null ? 'CZ12345678' : null,
            'valid_from'      => '2027-01-01',
            'valid_to'        => null,
            'cert_valid_to'   => $certValidTo,
            'has_certificate' => $certValidTo !== null ? 1 : 0,
        ];
    }

    /** @return list<int> id registrací s nálezem */
    private function ids(CertificateCheck $check): array
    {
        return array_map(static fn(AlertFinding $f): int => (int) $f->subjectRowId, $check->run());
    }

    public function testExpiringWithin30DaysIsAWarning(): void
    {
        $registrations = [
            self::registration(1, '2027-03-11 12:00:00'),   // zítra
            self::registration(2, '2027-04-09 11:59:59'),   // těsně pod 30 dní
            self::registration(3, '2027-04-09 12:00:01'),   // těsně nad 30 dní
            self::registration(4, '2027-03-10 11:59:59'),   // už vypršel — jiné upozornění
            self::registration(5, null),                    // bez certifikátu — jiné upozornění
            self::registration(6, '2028-01-01 00:00:00'),
        ];

        $findings = $this->check(CertificateExpiringCheck::class, $registrations)->run();

        $this->assertSame([1, 2], array_map(static fn(AlertFinding $f): int => (int) $f->subjectRowId, $findings));
        $first = $findings[0];
        $this->assertSame('warning', $first->severity);
        $this->assertSame('1', $first->findingKey);
        $this->assertSame(CertificateCheck::SUBJECT_TABLE_ID, $first->subjectTableId);
        $this->assertSame('Certifikát registrace k evidenci tržeb Prodejna 1 vyprší 11.03.2027', $first->title);
        $this->assertSame(
            'Pokladní certifikát (EIČ CZ12345678) platí do 11.03.2027 — zbývá dní: 1. Vystavte nový a nahrajte ho v detailu registrace; po vypršení tržby evidovat nepůjde.',
            $first->message,
        );
        $this->assertStringContainsString('zbývá dní: 29', $findings[1]->message);
        $this->assertSame('open_viewer', $first->actions[0]['kind']);
        $this->assertSame(['viewerId' => 'economy.salesRegistration.registrations'], $first->actions[0]['target']);
        $this->assertSame('Otevřít registrace', $first->actions[0]['label']);
        $this->assertSame(['registration' => 1, 'certValidTo' => '2027-03-11 12:00:00'], $first->context);
    }

    public function testExpiredIsAnError(): void
    {
        $registrations = [
            self::registration(1, '2027-03-10 11:59:59'),
            self::registration(2, '2026-12-31 00:00:00'),
            self::registration(3, '2027-03-10 12:00:01'),   // ještě platí
            self::registration(4, null),
        ];

        $findings = $this->check(CertificateExpiredCheck::class, $registrations)->run();

        $this->assertSame([1, 2], array_map(static fn(AlertFinding $f): int => (int) $f->subjectRowId, $findings));
        $this->assertSame('error', $findings[0]->severity);
        $this->assertSame('Certifikát registrace k evidenci tržeb Prodejna 1 vypršel', $findings[0]->title);
        $this->assertStringContainsString('(EIČ CZ12345678) vypršel 10.03.2027', $findings[0]->message);
    }

    public function testValidRegistrationWithoutCertificateIsAWarning(): void
    {
        $registrations = [
            self::registration(1, null),
            self::registration(2, '2028-01-01 00:00:00'),
            // Certifikát v řádku je, ale bez konce platnosti (poškozená data) — bere se jako chybějící.
            self::registration(3, null, ['has_certificate' => 1]),
        ];

        $findings = $this->check(CertificateMissingCheck::class, $registrations)->run();

        $this->assertSame([1, 3], array_map(static fn(AlertFinding $f): int => (int) $f->subjectRowId, $findings));
        $this->assertSame('warning', $findings[0]->severity);
        $this->assertSame('Registrace k evidenci tržeb Prodejna 1 nemá certifikát', $findings[0]->title);
        $this->assertStringContainsString('Nahrajte certifikát v detailu registrace', $findings[0]->message);
    }

    public function testExpiringAndExpiredNeverOverlap(): void
    {
        $registrations = [];
        foreach (['2027-03-09 00:00:00', '2027-03-10 12:00:00', '2027-03-10 12:00:01', '2027-04-09 12:00:00', '2027-05-01 00:00:00'] as $i => $validTo) {
            $registrations[] = self::registration($i + 1, $validTo);
        }

        $expiring = $this->ids($this->check(CertificateExpiringCheck::class, $registrations));
        $expired = $this->ids($this->check(CertificateExpiredCheck::class, $registrations));

        $this->assertSame([], array_intersect($expiring, $expired));
        $this->assertSame([1, 2], $expired);
        $this->assertSame([3, 4], $expiring);
    }

    public function testRegistrationsOutsideTheirValidityAreNotWatched(): void
    {
        $registrations = [
            self::registration(1, null, ['valid_from' => '2027-03-11']),                      // ještě neplatí
            self::registration(2, null, ['valid_from' => '2026-01-01', 'valid_to' => '2027-03-09']), // už neplatí
            self::registration(3, null, ['valid_from' => '2027-03-10', 'valid_to' => '2027-03-10']), // platí právě dnes
            self::registration(4, null, ['valid_from' => null, 'valid_to' => null]),          // bez omezení
            self::registration(5, null, ['valid_from' => new \DateTimeImmutable('2027-01-01'), 'valid_to' => new \DateTimeImmutable('2027-12-31')]),
        ];

        $this->assertSame([3, 4, 5], $this->ids($this->check(CertificateMissingCheck::class, $registrations)));
    }

    public function testOnlyConfirmedRegistrationsAreLoadedAndThePrivateKeyIsNeverRead(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->once())->method('fetchAll')->willReturnCallback(function (string $sql, int $state): array {
            $this->assertSame(40, $state, 'jen registrace V pořádku — koncept, oprava a archiv upozornění nenesou');
            $this->assertStringContainsString('`docState` = %i', $sql);
            $this->assertStringNotContainsString('cert_key', $sql);
            $this->assertStringNotContainsString('SELECT *', $sql);
            return [];
        });

        $this->assertSame([], (new CertificateMissingCheck($db, $this->salesRegistrationConfig(), 'cs'))->run());
    }

    public function testEnglishTexts(): void
    {
        $finding = $this->check(CertificateExpiringCheck::class, [self::registration(1, '2027-03-20 08:00:00')], 'en')->run()[0];

        $this->assertSame('Certificate of sales registration Prodejna 1 expires on 2027-03-20', $finding->title);
        $this->assertStringContainsString('(EIC CZ12345678) is valid until 2027-03-20 — days left: 9.', $finding->message);
        $this->assertSame('Open registrations', $finding->actions[0]['label']);
    }

    public function testChecksAreDeclaredWithLowercaseIdsAndSeverities(): void
    {
        $raw = JsoncParser::parseFile(__DIR__ . '/../../../../../../modules/economy/salesRegistration/module.jsonc');
        // Definice modulu deklarace přijme (tvar id, závažnost, interval hlídá AlertCheckDefinition).
        $this->assertCount(7, ModuleDefinition::fromArray($raw)->alertChecks);
        $checks = [];
        foreach ($raw['alertChecks'] as $definition) {
            $this->assertTrue(is_subclass_of($definition['class'], AlertCheck::class), $definition['id']);
            if (!str_contains($definition['id'], '.cert_')) {
                continue; // upozornění na tržby hlídá SaleChecksTest
            }
            $checks[$definition['id']] = [$definition['class'], $definition['severity']];
            $this->assertTrue(is_subclass_of($definition['class'], CertificateCheck::class), $definition['id']);
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_.]*$/', $definition['id']);
            foreach (['name', 'name:cs', 'name:en', 'description', 'description:cs', 'description:en'] as $key) {
                $this->assertNotSame('', trim((string) ($definition[$key] ?? '')), "{$definition['id']}: {$key}");
            }
        }

        $this->assertSame([
            'economy.salesregistration.cert_expiring' => [CertificateExpiringCheck::class, 'warning'],
            'economy.salesregistration.cert_expired'  => [CertificateExpiredCheck::class, 'error'],
            'economy.salesregistration.cert_missing'  => [CertificateMissingCheck::class, 'warning'],
        ], $checks);
        $this->assertTrue(class_exists(AlertCheckRegistry::class));
    }

    public function testSubjectTableIdMatchesTheRegistrationTable(): void
    {
        $table = JsoncParser::parseFile(__DIR__ . '/../../../../../../modules/economy/salesRegistration/tables/economy_sales_registration_registrations.jsonc');

        $this->assertSame($table['tableId'], CertificateCheck::SUBJECT_TABLE_ID);
    }
}
