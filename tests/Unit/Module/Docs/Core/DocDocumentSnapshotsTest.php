<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

class DocDocumentSnapshotsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_snap_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }

    private function buildConfig(): ConfigRuntime
    {
        $items = [
            'docs.core.docTypes' => [
                'invno' => ['doc_id_code' => '1', 'trade_dir' => 1],
                'invni' => ['doc_id_code' => '2', 'trade_dir' => 2],
            ],
        ];
        $data = ['_meta' => ['language' => 'cs'], 'items' => $items];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode($data),
        );
        return ConfigRuntime::load($this->tmpDir, 'cs');
    }

    /**
     * Helper: build a Connection mock that returns rows in a given sequence.
     *
     * @param list<?Row> $rows
     */
    private function dbReturning(array $rows): Connection
    {
        $db = $this->createMock(Connection::class);
        $iter = 0;
        $db->method('fetch')->willReturnCallback(
            function () use (&$iter, $rows): ?Row {
                return $rows[$iter++] ?? null;
            }
        );
        return $db;
    }

    // ── buildPersonSnapshot ────────────────────────────────────────────────

    public function testBuildPersonSnapshotMinimal(): void
    {
        $db = $this->dbReturning([
            new Row([
                'id' => 5, 'full_name' => 'Beta s.r.o.',
                'company_id' => '12345678', 'tax_id' => 'CZ12345678', 'vat_id' => 'CZ12345678',
                'court_registration' => 'Městský soud v Praze',
                'email' => 'info@beta.cz', 'phone' => '+420 1 23 45 67 89',
            ]),
        ]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $snap = $doc->buildPersonSnapshotPub(5, null, null, null);

        $this->assertSame('Beta s.r.o.', $snap['name']);
        $this->assertSame('12345678', $snap['company_id']);
        $this->assertSame('Městský soud v Praze', $snap['court_registration']);
        $this->assertSame('info@beta.cz', $snap['contact']['email']);
        $this->assertArrayNotHasKey('address', $snap);
        $this->assertArrayNotHasKey('bank_account', $snap);
        $this->assertArrayNotHasKey('vat_registration', $snap);
    }

    public function testBuildPersonSnapshotWithAddressBankAndVatRegistration(): void
    {
        $db = $this->dbReturning([
            new Row(['id' => 5, 'full_name' => 'Naše s.r.o.']),
            new Row(['id' => 11, 'street' => 'Hlavní 1', 'city' => 'Praha', 'zip' => '110 00', 'country' => 'cz']),
            new Row(['id' => 21, 'name' => 'ČSOB', 'account_number' => '123/0300', 'iban' => 'CZ65...', 'bic' => 'CEKO', 'currency' => 'czk']),
            new Row(['id' => 31, 'country' => 'cz', 'vat_id' => 'CZ12345678']),
        ]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $snap = $doc->buildPersonSnapshotPub(5, 11, 21, 31);

        $this->assertSame('Praha', $snap['address']['city']);
        $this->assertSame('123/0300', $snap['bank_account']['account_number']);
        $this->assertSame('cz', $snap['vat_registration']['country']);
        $this->assertSame('CZ12345678', $snap['vat_registration']['vat_id']);
    }

    public function testBuildPersonSnapshotPersonNotFound(): void
    {
        $db = $this->dbReturning([null]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $snap = $doc->buildPersonSnapshotPub(99, null, null, null);
        $this->assertSame([], $snap);
    }

    public function testBuildPersonSnapshotZeroIdReturnsEmpty(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbReturning([]));

        $this->assertSame([], $doc->buildPersonSnapshotPub(0, null, null, null));
    }

    // ── buildSnapshots ─────────────────────────────────────────────────────

    public function testBuildSnapshotsForIssuedInvoice(): void
    {
        // Order of fetch calls inside buildSnapshots:
        // 1) partner person — buildPersonSnapshot
        // 2) own person id (OwnCompanyResolver::getOwnPersonId)
        // 3) own person id (OwnCompanyResolver::getOwnHeadquartersAddress → getOwnPersonId)
        // 4) own HQ address (OwnCompanyResolver::getOwnHeadquartersAddress → SELECT addresses)
        // 5) own person — buildPersonSnapshot
        $db = $this->dbReturning([
            new Row(['id' => 50, 'full_name' => 'Partner s.r.o.']),
            new Row(['id' => 1]),
            new Row(['id' => 1]),
            new Row(['id' => 100, 'street' => 'Vlastní 1', 'city' => 'Brno']),
            new Row(['id' => 1, 'full_name' => 'Naše firma s.r.o.']),
        ]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = [
            'doc_type' => 'invno', // trade_dir = 1 (output, we are supplier)
            'partner' => 50,
            'partner_address' => null,
            'bank_account' => null,
            'vat_registration' => null,
        ];
        $doc->buildSnapshotsPub($data);

        $supplier = json_decode($data['supplier_snapshot'], true);
        $customer = json_decode($data['customer_snapshot'], true);
        $this->assertSame('Naše firma s.r.o.', $supplier['name']);
        $this->assertSame('Partner s.r.o.', $customer['name']);
    }

    // ── adresa partnera bez výběru → sídlo ──────────────────────────────────

    /**
     * Doklad z applieru (akce Vystavit opravný doklad, periodická fakturace)
     * `partner_address` nenese — snapshot odběratele dostane sídlo partnera.
     */
    public function testPartnerWithoutSelectedAddressGetsHeadquartersAddress(): void
    {
        $db = $this->dbReturning([
            new Row(['id' => 50, 'full_name' => 'Partner s.r.o.']),
            new Row(['id' => 77, 'street' => 'Lázeňská', 'house_number' => '5', 'city' => 'Luhačovice', 'zip' => '76326', 'country' => 'cz']),
            new Row(['id' => 1]),
            new Row(['id' => 1]),
            new Row(['id' => 100, 'street' => 'Vlastní 1', 'city' => 'Brno']),
            new Row(['id' => 1, 'full_name' => 'Naše firma s.r.o.']),
        ]);
        $queries = [];
        $db->method('fetchSingle')->willReturnCallback(function (mixed ...$args) use (&$queries): int {
            $queries[] = $args;
            return 77;
        });
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = ['doc_type' => 'invno', 'partner' => 50, 'partner_address' => null];
        $doc->buildSnapshotsPub($data);

        $customer = json_decode($data['customer_snapshot'], true);
        $this->assertSame('Partner s.r.o.', $customer['name']);
        $this->assertSame('Lázeňská', $customer['address']['street']);
        $this->assertSame('Luhačovice', $customer['address']['city']);
        $this->assertArrayNotHasKey('partner_address', array_filter($data), 'hlavička adresu nedostává, jen snapshot');

        // Sídlo napřed, bez něj první adresa; mimo koš a platná k dnešku.
        $this->assertCount(1, $queries);
        [$sql, $personId, $headquarters] = $queries[0];
        $this->assertSame(50, $personId);
        $this->assertSame(1, $headquarters, 'address_type sídla');
        $this->assertStringContainsString('[docState] <> 90', $sql);
        $this->assertStringContainsString('[valid_to] >= CURDATE()', $sql);
        $this->assertStringContainsString('ORDER BY ([address_type] = %i) DESC, [order_pos], [id]', $sql);
    }

    public function testSelectedPartnerAddressIsKept(): void
    {
        $db = $this->dbReturning([
            new Row(['id' => 50, 'full_name' => 'Partner s.r.o.']),
            new Row(['id' => 12, 'street' => 'Provozní 9', 'city' => 'Zlín']),
            new Row(['id' => 1]),
            new Row(['id' => 1]),
            new Row(['id' => 100, 'street' => 'Vlastní 1', 'city' => 'Brno']),
            new Row(['id' => 1, 'full_name' => 'Naše firma s.r.o.']),
        ]);
        $db->expects($this->never())->method('fetchSingle');
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = ['doc_type' => 'invno', 'partner' => 50, 'partner_address' => 12];
        $doc->buildSnapshotsPub($data);

        $this->assertSame('Zlín', json_decode($data['customer_snapshot'], true)['address']['city']);
    }

    public function testPartnerWithoutAnyAddressHasSnapshotWithoutAddress(): void
    {
        $db = $this->dbReturning([
            new Row(['id' => 50, 'full_name' => 'Partner s.r.o.']),
            new Row(['id' => 1]),
            new Row(['id' => 1]),
            new Row(['id' => 100, 'street' => 'Vlastní 1', 'city' => 'Brno']),
            new Row(['id' => 1, 'full_name' => 'Naše firma s.r.o.']),
        ]);
        $db->expects($this->once())->method('fetchSingle')->willReturn(null);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = ['doc_type' => 'invno', 'partner' => 50, 'partner_address' => null];
        $doc->buildSnapshotsPub($data);

        $customer = json_decode($data['customer_snapshot'], true);
        $this->assertSame('Partner s.r.o.', $customer['name']);
        $this->assertArrayNotHasKey('address', $customer);
    }

    public function testBuildSnapshotsForReceivedInvoiceFlipsRoles(): void
    {
        $db = $this->dbReturning([
            new Row(['id' => 50, 'full_name' => 'Partner s.r.o.']),
            new Row(['id' => 1]),                       // getOwnPersonId
            new Row(['id' => 1]),                       // getOwnHeadquartersAddress → getOwnPersonId
            null,                                        // address lookup → null
            new Row(['id' => 1, 'full_name' => 'Naše firma s.r.o.']),
        ]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = [
            'doc_type' => 'invni', // trade_dir = 2 (input, we are customer)
            'partner' => 50,
            'partner_address' => null,
            'bank_account' => null,
            'vat_registration' => null,
        ];
        $doc->buildSnapshotsPub($data);

        $supplier = json_decode($data['supplier_snapshot'], true);
        $customer = json_decode($data['customer_snapshot'], true);
        $this->assertSame('Partner s.r.o.', $supplier['name']);
        $this->assertSame('Naše firma s.r.o.', $customer['name']);
    }

    public function testBuildSnapshotsThrowsWhenOwnCompanyMissing(): void
    {
        $db = $this->dbReturning([
            new Row(['id' => 50, 'full_name' => 'Partner s.r.o.']),
            null, // own person id lookup → null
        ]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = ['doc_type' => 'invno', 'partner' => 50];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Není nastavena vlastní firma');
        $doc->buildSnapshotsPub($data);
    }

    // ── maintainSnapshots ──────────────────────────────────────────────────

    public function testMaintainSnapshotsSkipsKonceptState(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = ['docState' => 10, 'partner' => 5];
        $doc->maintainSnapshotsPub($data, null);

        $this->assertArrayNotHasKey('supplier_snapshot', $data);
    }

    public function testMaintainSnapshotsBuildsOnFirstConfirmedTransition(): void
    {
        $db = $this->dbReturning([
            new Row(['id' => 50, 'full_name' => 'Partner s.r.o.']),
            new Row(['id' => 1]),
            new Row(['id' => 1]),
            null,
            new Row(['id' => 1, 'full_name' => 'Naše firma s.r.o.']),
        ]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = [
            'docState' => 40, 'doc_type' => 'invno', 'partner' => 50,
            'partner_address' => null, 'bank_account' => null, 'vat_registration' => null,
        ];
        $doc->maintainSnapshotsPub($data, ['docState' => 10, 'partner' => 50]);

        $this->assertNotEmpty($data['supplier_snapshot']);
    }

    public function testMaintainSnapshotsRebuildsWhenPartnerChanged(): void
    {
        $db = $this->dbReturning([
            new Row(['id' => 60, 'full_name' => 'Nový Partner']),
            new Row(['id' => 1]),
            new Row(['id' => 1]),
            null,
            new Row(['id' => 1, 'full_name' => 'Naše firma']),
        ]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = [
            'docState' => 80, 'doc_type' => 'invno', 'partner' => 60,
            'partner_address' => null, 'bank_account' => null, 'vat_registration' => null,
            'supplier_snapshot' => ['name' => 'Naše firma'], // pre-existing
            'customer_snapshot' => ['name' => 'Starý Partner'],
        ];
        $original = ['docState' => 80, 'partner' => 50];
        $doc->maintainSnapshotsPub($data, $original);

        $customer = json_decode($data['customer_snapshot'], true);
        $this->assertSame('Nový Partner', $customer['name']);
    }

    public function testMaintainSnapshotsDataSaveWithoutDocStateFallsBackToOriginal(): void
    {
        // Data-save bez docState v payloadu (volání mimo gateway, např.
        // recomputeHeader): efektivní stav je stav z originálu (80) —
        // snapshoty se při změně partnera musí přestavět, ne přeskočit.
        $db = $this->dbReturning([
            new Row(['id' => 60, 'full_name' => 'Nový Partner']),
            new Row(['id' => 1]),
            new Row(['id' => 1]),
            null,
            new Row(['id' => 1, 'full_name' => 'Naše firma']),
        ]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = [
            'doc_type' => 'invno', 'partner' => 60,
            'partner_address' => null, 'bank_account' => null, 'vat_registration' => null,
            'supplier_snapshot' => ['name' => 'Naše firma'],
            'customer_snapshot' => ['name' => 'Starý Partner'],
        ];
        $original = ['docState' => 80, 'partner' => 50];
        $doc->maintainSnapshotsPub($data, $original);

        $customer = json_decode($data['customer_snapshot'], true);
        $this->assertSame('Nový Partner', $customer['name']);
    }

    public function testTransition10To40AssignsNumberAndBuildsSnapshots(): void
    {
        // Zrušením stavu 20 se potvrzení stalo jednokrokovým: 10→40 musí
        // v jednom save přidělit číslo ZE série a zároveň postavit snapshoty.
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(
            function (string $sql): ?Row {
                if (str_contains($sql, 'docs_core_number_series')) {
                    return new Row([
                        'id' => 1, 'doc_type' => 'invno', 'doc_number_code' => 'A',
                        'doc_number_pattern' => '%D%y%C%4', 'reset_scope' => 'fiscal_year',
                    ]);
                }
                if (str_contains($sql, 'last_assigned')) {
                    return new Row(['last_assigned' => 0]);
                }
                if (str_contains($sql, 'doc_number_prefix')) {
                    return new Row(['doc_number_prefix' => '26', 'name' => '2026']);
                }
                if (str_contains($sql, 'fiscal_years')) {
                    return new Row(['id' => 100, 'name' => '2026']);
                }
                if (str_contains($sql, 'base_persons_persons')) {
                    return new Row(['id' => 50, 'full_name' => 'Partner s.r.o.']);
                }
                return null;
            }
        );

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = [
            'id'               => 42,
            'docState'         => 40,
            'doc_type'         => 'invno',
            'partner'          => 50,
            'partner_address'  => null,
            'bank_account'     => null,
            'vat_registration' => null,
            'number_series'    => 1,
            'issue_date'       => '2026-05-06',
            'accounting_date'  => '2026-05-06',
            'doc_currency'     => 'czk',
            'home_currency'    => 'czk',
            'rows'             => [],
        ];
        $doc->beforeSavePub($data, ['docState' => 10, 'partner' => 50]);

        $this->assertSame(1, $data['sequence_number']);
        $this->assertSame('126A0001', $data['doc_number']);
        $this->assertNotEmpty($data['supplier_snapshot']);
        $this->assertNotEmpty($data['customer_snapshot']);
    }

    /**
     * DB mock pro import testy: reset_scope pro applyImportNumber, vlastní
     * firma pro buildOwnSnapshot (OwnCompanyResolver i PersonSnapshotBuilder
     * čtou base_persons_persons), adresa vlastní firmy → null.
     */
    private function dbForImport(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(
            function (string $sql): ?Row {
                if (str_contains($sql, 'reset_scope')) {
                    return new Row(['reset_scope' => 'fiscal_year']);
                }
                if (str_contains($sql, 'base_persons_persons')) {
                    return new Row(['id' => 1, 'full_name' => 'Naše firma s.r.o.']);
                }
                return null;
            }
        );
        return $db;
    }

    public function testImportModePersistsPartnerSnapshotFromPayload(): void
    {
        // Migrace s dobovým snapshotem partnera v payloadu: persistuje se
        // beze změny (nestaví se z dnešního adresáře), vlastní strana se
        // staví standardně. invni (trade_dir 2): partner = supplier sloupec.
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbForImport());
        $doc->setConfig($this->buildConfig());

        $data = [
            'docState'        => 80,
            'doc_type'        => 'invni',
            'partner'         => 50,
            'number_series'   => 1,
            'issue_date'      => '2024-06-01',
            'accounting_date' => '2024-06-01',
            'rows'            => [],
            '_importNumber'   => ['docNumber' => '2024-0042', 'sequenceNumber' => 42],
            '_importPartnerSnapshot' => [
                'name' => 'Dobový Dodavatel s.r.o.', 'company_id' => '999',
                'tax_id' => 'CZ99999999', 'vat_id' => 'CZ99999999',
                'court_registration' => null,
                'contact' => ['email' => null, 'phone' => null],
            ],
        ];
        $doc->beforeSavePub($data, null);

        // Virtuální pole nesmí prosáknout do SQL.
        $this->assertArrayNotHasKey('_importPartnerSnapshot', $data);

        $supplier = json_decode($data['supplier_snapshot'], true);
        $customer = json_decode($data['customer_snapshot'], true);
        $this->assertSame('Dobový Dodavatel s.r.o.', $supplier['name']);
        $this->assertSame('CZ99999999', $supplier['vat_id']);
        $this->assertSame('Naše firma s.r.o.', $customer['name']);
    }

    public function testImportModePartnerSnapshotFlipsForIssuedInvoice(): void
    {
        // invno (trade_dir 1): partner = customer sloupec, my supplier.
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbForImport());
        $doc->setConfig($this->buildConfig());

        $data = [
            'docState'        => 80,
            'doc_type'        => 'invno',
            'partner'         => 50,
            'number_series'   => 1,
            'issue_date'      => '2024-06-01',
            'accounting_date' => '2024-06-01',
            'rows'            => [],
            '_importNumber'   => ['docNumber' => '2024-0042', 'sequenceNumber' => 42],
            '_importPartnerSnapshot' => ['name' => 'Dobový Odběratel a.s.', 'vat_id' => 'CZ11122233'],
        ];
        $doc->beforeSavePub($data, null);

        $supplier = json_decode($data['supplier_snapshot'], true);
        $customer = json_decode($data['customer_snapshot'], true);
        $this->assertSame('Naše firma s.r.o.', $supplier['name']);
        $this->assertSame('Dobový Odběratel a.s.', $customer['name']);
        $this->assertSame('CZ11122233', $customer['vat_id']);
    }

    public function testImportModeWithoutPayloadLeavesPartnerSnapshotNull(): void
    {
        // Import bez `_importPartnerSnapshot` (kanonické zdroje bez stran):
        // partnerský sloupec zůstává NULL — nikdy se nestaví z dnešního
        // adresáře. Vlastní strana se staví i tak.
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbForImport());
        $doc->setConfig($this->buildConfig());

        $data = [
            'docState'        => 80,
            'doc_type'        => 'invni',
            'partner'         => 50,
            'number_series'   => 1,
            'issue_date'      => '2024-06-01',
            'accounting_date' => '2024-06-01',
            'rows'            => [],
            '_importNumber'   => ['docNumber' => '2024-0042', 'sequenceNumber' => 42],
        ];
        $doc->beforeSavePub($data, null);

        $this->assertNull($data['supplier_snapshot']);
        $customer = json_decode($data['customer_snapshot'], true);
        $this->assertSame('Naše firma s.r.o.', $customer['name']);
    }

    public function testImportModeKonceptBuildsNoSnapshots(): void
    {
        // Import na docState 10: state gate platí i pro import — snapshoty
        // se nestaví (stejně jako běžná cesta), payload se jen zkonzumuje.
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbForImport());
        $doc->setConfig($this->buildConfig());

        $data = [
            'docState'        => 10,
            'doc_type'        => 'invni',
            'partner'         => 50,
            'number_series'   => 1,
            'issue_date'      => '2024-06-01',
            'accounting_date' => '2024-06-01',
            'rows'            => [],
            '_importNumber'   => ['docNumber' => '2024-0042', 'sequenceNumber' => 42],
            '_importPartnerSnapshot' => ['name' => 'Dobový Dodavatel s.r.o.'],
        ];
        $doc->beforeSavePub($data, null);

        $this->assertArrayNotHasKey('_importPartnerSnapshot', $data);
        $this->assertArrayNotHasKey('supplier_snapshot', $data);
        $this->assertArrayNotHasKey('customer_snapshot', $data);
    }

    public function testMaintainSnapshotsKeepsExistingWhenUnchanged(): void
    {
        $doc = new TestableDocsHeadsDocument();
        // No DB calls expected — early return path
        $data = [
            'docState' => 80, 'doc_type' => 'invno', 'partner' => 50,
            'supplier_snapshot' => ['name' => 'Naše firma'],
            'customer_snapshot' => ['name' => 'Partner'],
        ];
        $doc->maintainSnapshotsPub($data, ['docState' => 80, 'partner' => 50]);

        // Snapshots untouched
        $this->assertSame('Naše firma', $data['supplier_snapshot']['name']);
        $this->assertSame('Partner', $data['customer_snapshot']['name']);
    }
}
