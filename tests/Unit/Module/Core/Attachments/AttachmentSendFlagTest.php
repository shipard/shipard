<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Attachments;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Attachments\AttachmentGuard;
use Shipard\Module\Core\Attachments\AttachmentService;

/**
 * Příznak „odeslat se záznamem“ (`send_with_record`, #94 D6): nová příloha
 * ho nemá, pokud volající výslovně neřekne jinak; přepnutí u existující
 * přílohy jde přes guard cílové tabulky.
 */
class AttachmentSendFlagTest extends TestCase
{
    private string $dsPath;

    protected function setUp(): void
    {
        TestableRefusingGuard::$asked = [];
        $this->dsPath = sys_get_temp_dir() . '/shpd_sendflag_test_' . uniqid();
        mkdir($this->dsPath . '/att', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->dsPath);
    }

    // ── upload ──────────────────────────────────────────────────────────────

    public function testUploadWithoutFlagLeavesColumnToDatabaseDefault(): void
    {
        [$result, $inserted] = $this->upload(sendWithRecord: null);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('send_with_record', $inserted);
        $this->assertSame(0, $result['data']['send_with_record']);
    }

    public function testUploadWithFlagStoresIt(): void
    {
        [$result, $inserted] = $this->upload(sendWithRecord: true);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $inserted['send_with_record']);
        $this->assertSame(1, $result['data']['send_with_record']);
    }

    public function testGuardCanRefuseUploadToRecordWithFrozenContent(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shpd_up_');
        file_put_contents($tmp, 'bytes');

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 5]);
        $db->expects($this->never())->method('insertRow');

        $service = new AttachmentService(
            $db,
            $this->dsPath,
            $this->tableDefinitions(),
            ['core_mail_incoming_messages' => [TestableRefusingGuard::class]],
        );

        try {
            // Guard dostane jen cíl a název — řádek přílohy ještě není.
            $service->upload(303, 5, 'podano-dalsi.xml', $tmp, 3);
            $this->fail('guard měl nahrání odmítnout');
        } catch (\DomainException $e) {
            $this->assertSame('Tenhle soubor je zamčený.', $e->getMessage());
        }
        $this->assertSame([AttachmentGuard::OPERATION_UPLOAD], TestableRefusingGuard::$asked);
        unlink($tmp);

        // Soubor, který guard nehlídá, nahrát jde — a guard se ptá i u něj.
        [$result] = $this->upload(sendWithRecord: null, guards: ['core_mail_incoming_messages' => [TestableRefusingGuard::class]]);
        $this->assertTrue($result['success']);
    }

    // ── copyTo ──────────────────────────────────────────────────────────────

    public function testCopyDoesNotCarryTheFlagToAnotherRecord(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shpd_src_');
        file_put_contents($tmp, 'bytes');
        [$uploaded] = $this->upload(sendWithRecord: true);
        $sourceRow = ['id' => 10, 'is_deleted' => 0, 'mime_type' => 'application/pdf'] + $uploaded['data'];

        $inserted = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static fn (string $sql, mixed ...$params): ?array => match (true) {
                str_contains($sql, 'SELECT * FROM') => $sourceRow,
                str_contains($sql, 'checksum')      => null,
                default                             => ['id' => 1],
            },
        );
        $db->method('insertRow')->willReturnCallback(function (string $table, array $data) use (&$inserted): int {
            $inserted = $data;
            return 556;
        });

        $result = (new AttachmentService($db, $this->dsPath, $this->tableDefinitions()))->copyTo(10, 428, 77);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('send_with_record', $inserted);
    }

    // ── setSendWithRecord ───────────────────────────────────────────────────

    public function testSetSendWithRecordUpdatesTheFlag(): void
    {
        $updates = [];
        $service = $this->serviceWithAttachment('priloha.pdf', [], $updates);

        $this->assertTrue($service->setSendWithRecord(7, true));
        $this->assertTrue($service->setSendWithRecord(7, false));

        $this->assertSame([1, 0], array_column($updates, 'send_with_record'));
    }

    public function testSetSendWithRecordOnMissingAttachmentReturnsFalse(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $db->expects($this->never())->method('updateWhere');

        $this->assertFalse((new AttachmentService($db, $this->dsPath))->setSendWithRecord(7, true));
    }

    public function testGuardIsAskedWithSendFlagOperationAndCanRefuse(): void
    {
        $updates = [];
        $guards  = ['core_mail_incoming_messages' => [TestableRefusingGuard::class]];

        try {
            $this->serviceWithAttachment('podano.xml', $guards, $updates)->setSendWithRecord(7, true);
            $this->fail('guard měl změnu příznaku odmítnout');
        } catch (\DomainException $e) {
            $this->assertSame('Tenhle soubor je zamčený.', $e->getMessage());
        }
        $this->assertSame([AttachmentGuard::OPERATION_SEND_FLAG], TestableRefusingGuard::$asked);
        $this->assertSame([], $updates);

        // Přílohu, kterou guard nehlídá, přepnout jde.
        $this->assertTrue($this->serviceWithAttachment('priloha.pdf', $guards, $updates)->setSendWithRecord(7, true));
        $this->assertCount(1, $updates);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, list<class-string>> $guards
     * @return array{0: array<string, mixed>, 1: ?array<string, mixed>} výsledek uploadu a vložený řádek
     */
    private function upload(?bool $sendWithRecord, array $guards = []): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shpd_up_');
        file_put_contents($tmp, 'attachment bytes ' . uniqid());

        $inserted = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            // recordExists → záznam je; findDuplicate / getNextOrder → nic
            static fn (string $sql): ?array => str_contains($sql, 'SELECT id FROM %n WHERE id = %i') ? ['id' => 5] : null,
        );
        $db->method('insertRow')->willReturnCallback(function (string $table, array $data) use (&$inserted): int {
            $inserted = $data;
            return 555;
        });

        $service = new AttachmentService($db, $this->dsPath, $this->tableDefinitions(), $guards);
        $result  = $sendWithRecord === null
            ? $service->upload(303, 5, 'priloha.pdf', $tmp, 3)
            : $service->upload(303, 5, 'priloha.pdf', $tmp, 3, $sendWithRecord);

        return [$result, $inserted];
    }

    /**
     * @param array<string, list<class-string>> $guards
     * @param list<array<string, mixed>> $updates sem se sbírají data z `updateWhere`
     */
    private function serviceWithAttachment(string $name, array $guards, array &$updates): AttachmentService
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 7, 'table_id' => 303, 'record_id' => 12, 'name' => $name, 'is_deleted' => 0,
        ]);
        $db->method('updateWhere')->willReturnCallback(
            function (string $table, array $data) use (&$updates): void {
                $updates[] = $data;
            },
        );

        return new AttachmentService($db, $this->dsPath, $this->tableDefinitions(), $guards);
    }

    /** @return array<string, TableDefinition> */
    private function tableDefinitions(): array
    {
        $minimal = static fn (int $tableId, string $name): TableDefinition => TableDefinition::fromArray([
            'tableId' => $tableId,
            'name'    => $name,
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
            ],
        ]);

        return [
            'core_mail_incoming_messages' => $minimal(303, 'Incoming messages'),
            'base_registry_documents'     => $minimal(428, 'Registry documents'),
        ];
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
