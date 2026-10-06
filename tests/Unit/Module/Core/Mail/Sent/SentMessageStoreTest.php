<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Sent;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\Sent\SentMessageStore;

class SentMessageStoreTest extends TestCase
{
    public function testSourceRefRoundTrip(): void
    {
        $this->assertSame('sentMessage:42', SentMessageStore::sourceRef(42));
        $this->assertSame(42, SentMessageStore::idFromSourceRef('sentMessage:42'));
        $this->assertNull(SentMessageStore::idFromSourceRef('send-test'));
        $this->assertNull(SentMessageStore::idFromSourceRef('sentMessage:'));
        $this->assertNull(SentMessageStore::idFromSourceRef('sentMessage:abc'));
    }

    public function testCreateStartsAsSentAndQueued(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('insertRow')->willReturnCallback(function (string $table, array $data) use (&$captured): int {
            $captured = [$table, $data];
            return 7;
        });

        $id = (new SentMessageStore($db))->create([
            'subject'  => 'Faktura 2260011',
            'email_to' => 'ucetni@odberatel.example',
            // Stav si volající neurčí — zpráva nemá Koncept ani jiný start.
            'docState'        => 10,
            'transport_state' => 'sent',
            'send_count'      => 5,
        ], new \DateTimeImmutable('2026-10-04 14:30:00'));

        $this->assertSame(7, $id);
        [$table, $data] = $captured;
        $this->assertSame('core_mail_sent_messages', $table);
        $this->assertSame(40, $data['docState']);
        $this->assertSame('queued', $data['transport_state']);
        $this->assertSame(0, $data['send_count']);
        $this->assertNull($data['sent_at']);
        $this->assertSame('email', $data['channel']);
        $this->assertSame('2026-10-04 14:30:00', $data['created']);
    }

    public function testImportInsertsTheRowAsGiven(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('insertRow')->willReturnCallback(function (string $table, array $data) use (&$captured): int {
            $captured = [$table, $data];
            return 8;
        });

        // Importovaná zpráva nese vlastní čas i transport (#104 D3, D4) —
        // store je na rozdíl od create() nepřepisuje.
        $fields = [
            'subject'         => 'Faktura 2160011',
            'email_to'        => 'ucetni@odberatel.example',
            'transport_state' => 'sent',
            'sent_at'         => '2021-06-01 09:00:00',
            'send_count'      => 1,
            'send_trigger'    => 'import',
            'import_ref'      => 'oldShipard:4711',
            'created'         => '2021-06-01 09:00:00',
            'docState'        => 70,
        ];
        $id = (new SentMessageStore($db))->import($fields);

        $this->assertSame(8, $id);
        $this->assertSame(['core_mail_sent_messages', $fields], $captured);
    }

    public function testFindByImportRefReturnsIdOrNull(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturnCallback(
            static fn (string $sql, string $table, string $ref): ?int => $ref === 'oldShipard:4711' ? 12 : null,
        );
        $store = new SentMessageStore($db);

        $this->assertSame(12, $store->findByImportRef('oldShipard:4711'));
        $this->assertNull($store->findByImportRef('oldShipard:9999'));
    }

    public function testMarkSentCountsEverySendButStateFollowsLastOutboxRow(): void
    {
        $executed = [];
        $updates  = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('execute')->willReturnCallback(function (mixed ...$args) use (&$executed): void {
            $executed[] = $args;
        });
        $db->method('updateWhere')->willReturnCallback(
            function (string $table, array $data, string $where, mixed ...$params) use (&$updates): void {
                $updates[] = [$data, $where, $params];
            },
        );

        (new SentMessageStore($db))->markSent(7, 31, new \DateTimeImmutable('2026-10-04 14:31:00'));

        $this->assertStringContainsString('[send_count] = [send_count] + 1', $executed[0][0]);
        // Stav přepíše jen průchod, který je u zprávy poslední.
        $this->assertSame('sent', $updates[0][0]['transport_state']);
        $this->assertSame('id = %i AND last_outbox_id = %i', $updates[0][1]);
        $this->assertSame([7, 31], $updates[0][2]);
    }

    public function testMarkFailedKeepsErrorAndTouchesOnlyLastOutboxRow(): void
    {
        $updates = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('updateWhere')->willReturnCallback(
            function (string $table, array $data, string $where, mixed ...$params) use (&$updates): void {
                $updates[] = [$data, $where, $params];
            },
        );

        (new SentMessageStore($db))->markFailed(7, 31, 'Mailbox unavailable', new \DateTimeImmutable('2026-10-04 20:00:00'));

        $this->assertSame('failed', $updates[0][0]['transport_state']);
        $this->assertSame('Mailbox unavailable', $updates[0][0]['last_error']);
        $this->assertSame([7, 31], $updates[0][2]);
    }

    public function testMarkSafetyStoresTraceOfLastOutboxRow(): void
    {
        $updates = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('updateWhere')->willReturnCallback(
            function (string $table, array $data, string $where, mixed ...$params) use (&$updates): void {
                $updates[] = [$data, $where, $params];
            },
        );
        $store = new SentMessageStore($db);

        $store->markSafety(7, 31, 'redirected', 'testy@firma.example');
        // Bez zásahu se stopa dřívějšího odeslání smaže — i s adresou.
        $store->markSafety(7, 32, null, 'testy@firma.example');

        $this->assertSame(['safety_action' => 'redirected', 'safety_target' => 'testy@firma.example'], $updates[0][0]);
        $this->assertSame('id = %i AND last_outbox_id = %i', $updates[0][1]);
        $this->assertSame([7, 31], $updates[0][2]);
        $this->assertSame(['safety_action' => null, 'safety_target' => null], $updates[1][0]);
    }

    public function testMarkSafetyToleratesMissingColumnsOnlyWithoutTrace(): void
    {
        // Zdroj dat před `ds-upgrade` sloupce pojistky nemá.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('updateWhere')->willThrowException(new \Dibi\DriverException("Unknown column 'safety_action'"));
        $store = new SentMessageStore($db);

        $store->markSafety(7, 31, null, null);

        $this->expectException(\Dibi\Exception::class);
        $store->markSafety(7, 31, 'dropped', null);
    }
}
