<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\IncomingMessagesViewer;

/**
 * Fulltext Došlé pošty: hlavička zprávy přes LIKE včetně kódu zprávy
 * `message_id` — krátký tvar `YYMMDD-NNNN` z náhledu dokladu je podřetězcem
 * plného kódu, takže ho najde totéž hledání (tasks/mail-source-message-link.md D4).
 */
final class IncomingMessagesViewerSearchTest extends TestCase
{
    /** @return array{0: string, 1: array<int, mixed>} zachycené (sql, params) */
    private function selectWithSearch(?string $search): array
    {
        $capturedSql = '';
        $capturedParams = [];

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$params) use (&$capturedSql, &$capturedParams) {
                $capturedSql = $sql;
                $capturedParams = $params;
                return [];
            },
        );

        $viewer = new IncomingMessagesViewer($db, 'core_mail_incoming_messages');
        // viewGroup=all → žádný stavový filtr, test se soustředí na search
        $viewer->selectRows($search, [['id' => 'viewGroup', 'value' => 'all']], 1);

        return [$capturedSql, $capturedParams];
    }

    public function testSearchCoversMessageCodeNextToSubjectAndSender(): void
    {
        [$sql, $params] = $this->selectWithSearch('260905-0012');

        $this->assertStringContainsString('m.`subject` LIKE %~like~ COLLATE utf8mb4_uca1400_ai_ci', $sql);
        $this->assertStringContainsString('m.`sender_email` LIKE %~like~ COLLATE utf8mb4_uca1400_ai_ci', $sql);
        $this->assertStringContainsString('m.`body_plain` LIKE %~like~ COLLATE utf8mb4_uca1400_ai_ci', $sql);
        $this->assertStringContainsString('m.`message_id` LIKE %~like~ COLLATE utf8mb4_uca1400_ai_ci', $sql);

        // 8 sloupců = 8× surový term (wildcards přidá Dibi)
        $this->assertSame(array_fill(0, 8, '260905-0012'), $params);
    }

    public function testNoSearchOmitsFulltextCondition(): void
    {
        [$sql, $params] = $this->selectWithSearch(null);

        $this->assertStringNotContainsString('LIKE', $sql);
        $this->assertSame([], $params);
    }
}
