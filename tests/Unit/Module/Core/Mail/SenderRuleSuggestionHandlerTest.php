<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\SenderRuleSuggestionHandler;

/**
 * Testable handler — Connection::query je final, executeSql se přepisuje
 * subclassingem (vzor TestableSupplierCodeCaptureHandler).
 */
class TestableSenderRuleSuggestionHandler extends SenderRuleSuggestionHandler
{
    /** @var list<array> */
    public array $sqlCalls = [];

    protected function executeSql(mixed ...$args): void
    {
        $this->sqlCalls[] = $args;
    }
}

class SenderRuleSuggestionHandlerTest extends TestCase
{
    /** @var list<array<mixed>> argumenty všech fetch() volání */
    private array $fetchCalls = [];

    /**
     * @param array<string, mixed>|null $message  Řádek zprávy (sender_email, auto_disposed_by)
     * @param int $manualCount                    COUNT ručních odklizení
     * @param bool $liveRuleExists                Existuje živé pravidlo pro e-mail/doménu
     * @param bool $senderHasDocuments            D1: od adresy už přišel doklad / dokument
     */
    private function handler(
        ?array $message,
        int $manualCount = 0,
        bool $liveRuleExists = false,
        bool $senderHasDocuments = false,
    ): TestableSenderRuleSuggestionHandler {
        $this->fetchCalls = [];
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(
            function (string $sql, mixed ...$params) use ($message, $manualCount, $liveRuleExists, $senderHasDocuments): ?Row {
                $this->fetchCalls[] = [$sql, ...$params];
                if (str_contains($sql, 'COUNT(*)')) {
                    return new Row(['cnt' => $manualCount]);
                }
                if (str_contains($sql, 'core_mail_sender_rules')) {
                    return $liveRuleExists ? new Row(['id' => 99]) : null;
                }
                if (str_contains($sql, 'target_row')) {
                    return $senderHasDocuments ? new Row([1 => 1]) : null;
                }
                return $message !== null ? new Row($message) : null;
            },
        );

        $handler = new TestableSenderRuleSuggestionHandler();
        $handler->setDb($db);
        return $handler;
    }

    public function testThresholdReachedInsertsDraftSuggestion(): void
    {
        $handler = $this->handler(
            ['sender_email' => 'News@Example.com', 'auto_disposed_by' => null],
            manualCount: 3,
        );

        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 10, 90);

        $this->assertCount(1, $handler->sqlCalls);
        $data = $handler->sqlCalls[0][1];
        $this->assertSame('news@example.com', $data['pattern']);
        $this->assertSame('email', $data['pattern_kind']);
        $this->assertSame('archive', $data['disposition']);
        $this->assertSame('suggested', $data['origin']);
        $this->assertSame(10, $data['docState']);
        $this->assertSame('Navrženo po 3 ručních odklizeních', $data['notice']);
        $this->assertArrayHasKey('created', $data);
    }

    public function testSenderWithDocumentsGetsArchiveIfOtherSuggestion(): void
    {
        // D1/D2: od adresy už přišel doklad nebo dokument → bezpečná dispozice
        // a poznámka to říká.
        $handler = $this->handler(
            ['sender_email' => 'scan@example.com', 'auto_disposed_by' => null],
            manualCount: 3,
            senderHasDocuments: true,
        );

        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 10, 80);

        $this->assertCount(1, $handler->sqlCalls);
        $data = $handler->sqlCalls[0][1];
        $this->assertSame('archiveIfOther', $data['disposition']);
        $this->assertSame('Navrženo po 3 ručních odklizeních; od adresy chodí i doklady', $data['notice']);
        $this->assertSame('scan@example.com', $data['pattern']);
    }

    public function testSenderWithDocumentsQueryShape(): void
    {
        // D1: navázaná entita NEBO typ ≠ other určený ai / isdoc / user;
        // default schránky (`mailbox`) se nepočítá.
        $handler = $this->handler(
            ['sender_email' => 'scan@example.com', 'auto_disposed_by' => null],
            manualCount: 3,
        );

        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 10, 80);

        $d1 = array_values(array_filter($this->fetchCalls, static fn(array $c): bool => str_contains((string) $c[0], 'target_row')));
        $this->assertCount(1, $d1);
        $sql = (string) $d1[0][0];
        $this->assertStringContainsString('[target_row] IS NOT NULL', $sql);
        $this->assertStringContainsString('[primary_type] <> %s', $sql);
        $this->assertStringContainsString('[primary_type_source] IN %in', $sql);
        $this->assertStringContainsString('LIMIT 1', $sql);
        $this->assertContains('scan@example.com', $d1[0]);
        $this->assertContains('other', $d1[0]);
        $this->assertContains(['ai', 'isdoc', 'user'], $d1[0]);
    }

    public function testBelowThresholdDoesNothing(): void
    {
        $handler = $this->handler(
            ['sender_email' => 'news@example.com', 'auto_disposed_by' => null],
            manualCount: 2,
        );

        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 10, 80);

        $this->assertSame([], $handler->sqlCalls);
    }

    public function testExistingLiveRuleBlocksDuplicateSuggestion(): void
    {
        $handler = $this->handler(
            ['sender_email' => 'news@example.com', 'auto_disposed_by' => null],
            manualCount: 5,
            liveRuleExists: true,
        );

        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 10, 90);

        $this->assertSame([], $handler->sqlCalls);
    }

    public function testAutoDisposedMessageIsIgnored(): void
    {
        $handler = $this->handler(
            ['sender_email' => 'news@example.com', 'auto_disposed_by' => 3],
            manualCount: 10,
        );

        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 10, 80);

        $this->assertSame([], $handler->sqlCalls);
    }

    public function testEmptySenderEmailIsIgnored(): void
    {
        $handler = $this->handler(
            ['sender_email' => '', 'auto_disposed_by' => null],
            manualCount: 10,
        );

        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 10, 90);

        $this->assertSame([], $handler->sqlCalls);
    }

    public function testTransitionsOutsideDisposalAreIgnored(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $handler = new TestableSenderRuleSuggestionHandler();
        $handler->setDb($db);

        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 10, 20);
        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 80, 10);
        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 20, 40);

        $this->assertSame([], $handler->sqlCalls);
    }

    public function testMissingMessageRowDoesNothing(): void
    {
        $handler = $this->handler(null, manualCount: 10);

        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 10, 90);

        $this->assertSame([], $handler->sqlCalls);
    }
}
