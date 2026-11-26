<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Economy\SalesRegistration\RegistrationsViewer;
use Shipard\Module\Economy\SalesRegistration\Safety\SalesRegistrationServerConfig;

/**
 * Viewer Registrace (Nastavení → Evidence tržeb): řádek s typem, režimem
 * a platností certifikátu, detail Přehled + Evidenční jednotky. Soukromý
 * klíč viewer nečte.
 */
class RegistrationsViewerTest extends TestCase
{
    use SalesRegistrationConfig;

    private const TABLE = 'economy_sales_registration_registrations';
    private const UNITS = 'economy_sales_registration_units';

    private const RECORD = [
        'id'               => 7,
        'type'             => 'cz.eet2',
        'name'             => 'Prodejna Brno',
        'taxpayer_id'      => 'CZ00000019',
        'mode'             => 'verification',
        'valid_from'       => '2027-01-01',
        'valid_to'         => null,
        'default_unit'     => 3,
        'notice'           => null,
        'cert_valid_from'  => '2026-05-14 10:00:00',
        'cert_valid_to'    => '2027-05-14 10:00:00',
        'cert_serial'      => '1A2B',
        'cert_uploaded_at' => '2026-11-26 09:30:00',
        'cert_uploaded_by' => 5,
        'has_certificate'  => 1,
        'docState'         => 40,
        'docStateMain'     => 3,
    ];

    /**
     * @param list<array> $calls
     * @param list<array<string, mixed>> $units
     */
    private function viewer(array &$calls, ?array $record = null, array $units = [], string $safetyMode = 'drop'): RegistrationsViewer
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$args) use (&$calls, $units): array {
            $calls[] = $args;
            return str_contains((string) $args[0], self::UNITS) ? $units : [];
        });
        $db->method('fetchRow')->willReturnCallback(function (...$args) use (&$calls, $record): ?array {
            $calls[] = $args;
            return $record;
        });
        $db->method('fetchSingle')->willReturn('Jana Nováková');

        $viewer = new class($db, self::TABLE) extends RegistrationsViewer {
            public ?SalesRegistrationServerConfig $testSafety = null;

            protected function safety(): SalesRegistrationServerConfig
            {
                return $this->testSafety ?? parent::safety();
            }
        };
        $viewer->testSafety = SalesRegistrationServerConfig::ofMode($safetyMode);
        $viewer->setConfig($this->salesRegistrationConfig());
        $viewer->setLanguage('cs');
        $viewer->setTables([
            self::TABLE => TableDefinition::fromArray(self::repoJsonc('modules/economy/salesRegistration/tables/' . self::TABLE . '.jsonc')),
            self::UNITS => TableDefinition::fromArray(self::repoJsonc('modules/economy/salesRegistration/tables/' . self::UNITS . '.jsonc')),
        ]);
        return $viewer;
    }

    public function testSelectRowsNeverReadsThePrivateKey(): void
    {
        $calls = [];
        $this->viewer($calls)->selectRows('brno', [], 1);

        $sql = (string) $calls[0][0];
        $this->assertStringNotContainsString('cert_key', $sql);
        $this->assertStringNotContainsString('SELECT *', $sql);
        $this->assertStringContainsString('ORDER BY `docStateMain` ASC, `name` ASC', $sql);
    }

    public function testRowShowsTypeModeAndCertificateValidity(): void
    {
        $calls = [];
        $row = $this->viewer($calls)->renderRow(self::RECORD);

        $this->assertSame(7, $row['id']);
        $this->assertSame('Prodejna Brno', $row['t1']);
        $this->assertSame('CZ00000019', $row['i1']);
        $this->assertSame(['EET 2.0 (Česko)', 'Ověřovací'], array_column($row['t2'], 'text'), 'stav V pořádku štítek nemá');
        $this->assertSame('Certifikát do 14.05.2027', $row['t3']);
    }

    public function testRowWithoutCertificateAndInDraft(): void
    {
        $calls = [];
        $row = $this->viewer($calls)->renderRow(['taxpayer_id' => null, 'has_certificate' => 0, 'cert_valid_to' => null, 'docState' => 70] + self::RECORD);

        $this->assertNull($row['i1']);
        $this->assertSame('bez certifikátu', $row['t3']);
        $this->assertCount(3, $row['t2'], 'archivovaná registrace nese štítek stavu');
    }

    public function testDetailOverviewShowsRegistrationAndCertificate(): void
    {
        $calls = [];
        $units = [['id' => 3, 'unit_id' => '181', 'name' => 'Prodejna'], ['id' => 4, 'unit_id' => '200', 'name' => null]];
        $detail = $this->viewer($calls, self::RECORD, $units)->renderDetail(7);

        $this->assertStringNotContainsString('cert_key', (string) $calls[0][0], 'detail klíč nenačítá');
        $this->assertSame(['overview', 'units'], array_column($detail['tabs'], 'id'));

        $overview = $detail['tabs'][0]['content'];
        $this->assertSame('composite', $overview['type']);
        $this->assertSame(['notice', 'properties'], array_column($overview['blocks'], 'type'));
        [$registration, $certificate] = $overview['blocks'][1]['groups'];
        $this->assertSame('Registrace', $registration['title']);
        $this->assertSame(
            [
                ['label' => 'Typ evidence', 'value' => 'EET 2.0 (Česko)'],
                ['label' => 'EIČ', 'value' => 'CZ00000019'],
                ['label' => 'Režim', 'value' => 'Ověřovací'],
                ['label' => 'Platnost od', 'value' => '01.01.2027'],
                ['label' => 'Výchozí evidenční jednotka', 'value' => '181 — Prodejna'],
            ],
            $registration['items'],
        );
        $this->assertSame('Certifikát', $certificate['title']);
        $this->assertSame(
            [
                ['label' => 'Certifikát platí od', 'value' => '14.05.2026 10:00'],
                ['label' => 'Certifikát platí do', 'value' => '14.05.2027 10:00'],
                ['label' => 'Sériové číslo certifikátu', 'value' => '1A2B'],
                ['label' => 'Certifikát nahrán', 'value' => '26.11.2026 09:30'],
                ['label' => 'Certifikát nahrál', 'value' => 'Jana Nováková'],
            ],
            $certificate['items'],
        );

        foreach ($registration['items'] as $item) {
            $this->assertNotSame('Prodejna Brno', $item['value'], 'Přehled neopakuje titulek (#140 D26)');
        }
    }

    public function testDetailListsRegistrationUnits(): void
    {
        $calls = [];
        $units = [['id' => 3, 'unit_id' => '181', 'name' => 'Prodejna'], ['id' => 4, 'unit_id' => '200', 'name' => null]];
        $tab = $this->viewer($calls, self::RECORD, $units)->renderDetail(7)['tabs'][1];

        $this->assertSame('Evidenční jednotky', $tab['label']);
        $this->assertSame('table', $tab['content']['type']);
        $this->assertSame(['Číslo jednotky', 'Název'], array_column($tab['content']['columns'], 'label'));
        $this->assertSame(
            [['unit_id' => '181', 'name' => 'Prodejna'], ['unit_id' => '200', 'name' => '']],
            $tab['content']['rows'],
        );
    }

    public function testDetailWithoutCertificateAndUnits(): void
    {
        $calls = [];
        $record = ['taxpayer_id' => null, 'has_certificate' => 0, 'default_unit' => null, 'cert_valid_to' => null] + self::RECORD;
        $detail = $this->viewer($calls, $record)->renderDetail(7);

        $this->assertSame(
            [['label' => 'Certifikát', 'value' => 'není nahraný']],
            $detail['tabs'][0]['content']['blocks'][1]['groups'][1]['items'],
        );
        $this->assertSame(
            ['type' => 'empty', 'text' => 'Registrace zatím nemá žádnou evidenční jednotku.'],
            $detail['tabs'][1]['content'],
        );
    }

    public function testMissingRecordHasNoTabs(): void
    {
        $calls = [];
        $this->assertSame(['tabs' => []], $this->viewer($calls)->renderDetail(99));
    }

    /** @return array<string, mixed> hláška „kam zprávy jdou“ z Přehledu */
    private function routeNotice(string $safetyMode, string $registrationMode): array
    {
        $calls = [];
        $detail = $this->viewer($calls, ['mode' => $registrationMode] + self::RECORD, [], $safetyMode)->renderDetail(7);
        return $detail['tabs'][0]['content']['blocks'][0];
    }

    public function testOverviewSaysWhereMessagesReallyGo(): void
    {
        $dropped = $this->routeNotice('drop', 'production');
        $this->assertSame('notice', $dropped['type']);
        $this->assertSame('warning', $dropped['tone']);
        $this->assertSame('Zprávy se neodesílají', $dropped['title']);
        $this->assertStringContainsString('Pojistka serveru je v režimu drop', $dropped['text']);

        $playground = $this->routeNotice('playground', 'production');
        $this->assertSame('info', $playground['tone']);
        $this->assertSame('Zprávy jdou na playground', $playground['title']);
        $this->assertStringContainsString('CZ00000019', $playground['text'], 'jmenuje testovací identitu, ne EIČ registrace');
        $this->assertStringContainsString('nikdy s certifikátem této registrace', $playground['text']);

        $this->assertSame('Zprávy jdou do produkce v ověřovacím módu', $this->routeNotice('off', 'verification')['title']);
        $this->assertSame('Zprávy jdou do produkce', $this->routeNotice('off', 'production')['title']);
        $this->assertSame('Zprávy jdou na playground', $this->routeNotice('off', 'playground')['title']);
    }
}
