<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Mcp;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\Mcp\MailListPendingTool;

/**
 * MCP `mail_list_pending` — položky nesou lidský titulek (D3), partnera
 * dokumentu odděleně od odesílatele (D4/D7) a stránkování
 * (tasks/mail-message-title-partner.md follow-up).
 */
final class MailListPendingToolTest extends TestCase
{
    private ?string $capturedSql = null;

    /** @var list<mixed> */
    private array $capturedParams = [];

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{0: MailListPendingTool, 1: McpInvocationContext}
     */
    private function tool(array $rows, ?ConfigRuntime $config = null): array
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$params) use ($rows): array {
                $this->capturedSql = $sql;
                $this->capturedParams = $params;
                return $rows;
            },
        );
        $ctx = new McpInvocationContext(new AuthContext(true, 1, 'api_key'), $db, [], $config);
        return [new MailListPendingTool(), $ctx];
    }

    private function configWithPatterns(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            // Jen vzory generických předmětů; docStates bez configu → label
            // padá na číslo stavu (netestuje se).
            static fn(string $id): mixed => $id === 'core.mail.genericSubjectPatterns'
                ? ['^Message from ']
                : null,
        );
        return $config;
    }

    /** @return array<string, mixed> */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id'                  => 42,
            'subject'             => 'Message from KM_C258',
            'ai_title'            => 'Faktura 2026-0042 — Dodavatel s.r.o., 13 105 Kč',
            'source_type'         => 2,
            'sender_name'         => 'Kancelářský skener',
            'sender_email'        => 'scanner@example.test',
            'sender_person'       => null,
            'partner_person'      => 77,
            'partner_name'        => 'Dodavatel sro',
            'partner_full_name'   => 'Dodavatel s.r.o.',
            'received_at'         => '2026-09-01 10:00:00',
            'mailbox'             => 1,
            'mailbox_name'        => 'Faktury',
            'docState'            => 20,
            'analysis_status_raw' => 2,
            'has_open_proposal'   => 1,
            'attention'           => null,
            'action_note'         => null,
            'action_due'          => null,
        ], $overrides);
    }

    public function testItemCarriesAttentionFieldsOfOtherMail(): void
    {
        // tasks/mail-other-attention.md D9: pozornost, poznámka a lhůta
        // (Dibi vrací DATE jako DateTime → ISO řetězec); bez pozornosti null.
        [$tool, $ctx] = $this->tool([
            $this->row([
                'id' => 1, 'attention' => 'action', 'action_note' => 'Prodloužit 3 domény.',
                'action_due' => new \DateTimeImmutable('2026-10-15'),
            ]),
            $this->row(['id' => 2, 'attention' => 'info', 'action_due' => '2026-10-15 00:00:00']),
            $this->row(['id' => 3]),
        ]);

        $items = $tool->call([], $ctx)['items'];

        $this->assertSame('action', $items[0]['attention']);
        $this->assertSame('Prodloužit 3 domény.', $items[0]['action_note']);
        $this->assertSame('2026-10-15', $items[0]['action_due']);
        $this->assertSame('info', $items[1]['attention']);
        $this->assertNull($items[1]['action_note']);
        $this->assertSame('2026-10-15', $items[1]['action_due']);
        $this->assertNull($items[2]['attention']);
        $this->assertNull($items[2]['action_note']);
        $this->assertNull($items[2]['action_due']);
        $this->assertStringContainsString('`m`.`attention`, `m`.`action_note`, `m`.`action_due`', (string) $this->capturedSql);
        $this->assertStringContainsString('`attention`', $tool->description());
    }

    public function testItemCarriesTitlePartnerAndSenderSeparately(): void
    {
        [$tool, $ctx] = $this->tool([$this->row()], $this->configWithPatterns());

        $result = $tool->call([], $ctx);
        $item = $result['items'][0];

        // Generický předmět skeneru → full_name je titulek z AI, předmět zůstává holý.
        $this->assertSame('Faktura 2026-0042 — Dodavatel s.r.o., 13 105 Kč', $item['full_name']);
        $this->assertSame('Message from KM_C258', $item['subject']);
        $this->assertSame('Faktura 2026-0042 — Dodavatel s.r.o., 13 105 Kč', $item['ai_title']);

        // Partner = protistrana dokumentu (Osoba má přednost před snapshotem), sender = skener.
        $this->assertSame(['name' => 'Dodavatel s.r.o.', 'person' => ['id' => 77]], $item['partner']);
        $this->assertSame('Kancelářský skener', $item['sender']['name']);
        $this->assertNull($item['sender']['person']);

        $this->assertTrue($item['has_open_proposal']);
        $this->assertSame('success', $item['analysis_status']);
        $this->assertSame('1 čekajících zpráv, 1 s otevřeným návrhem.', $result['summary']);
    }

    public function testNormalEmailKeepsSubjectAsFullName(): void
    {
        [$tool, $ctx] = $this->tool([$this->row(['subject' => 'Faktura za září'])], $this->configWithPatterns());

        $item = $tool->call([], $ctx)['items'][0];

        $this->assertSame('Faktura za září', $item['full_name']);
        $this->assertSame('Faktura 2026-0042 — Dodavatel s.r.o., 13 105 Kč', $item['ai_title']);
    }

    public function testPartnerSnapshotWithoutPersonAndNullWithoutAny(): void
    {
        [$tool, $ctx] = $this->tool([
            $this->row(['id' => 1, 'partner_person' => null, 'partner_full_name' => null]),
            $this->row(['id' => 2, 'partner_person' => null, 'partner_full_name' => null, 'partner_name' => null, 'ai_title' => null]),
        ]);

        $items = $tool->call([], $ctx)['items'];

        $this->assertSame(['name' => 'Dodavatel sro', 'person' => null], $items[0]['partner']);
        $this->assertNull($items[1]['partner']);
        $this->assertNull($items[1]['ai_title']);
        // Bez configu a bez titulku → předmět.
        $this->assertSame('Message from KM_C258', $items[1]['full_name']);
    }

    public function testQuerySelectsPartnerViaSubselectAndPaginates(): void
    {
        $rows = [$this->row(['id' => 1]), $this->row(['id' => 2]), $this->row(['id' => 3])];
        [$tool, $ctx] = $this->tool($rows);

        $result = $tool->call(['limit' => 2, 'offset' => 4, 'only_actionable' => true], $ctx);

        $this->assertStringContainsString('`m`.`ai_title`', (string) $this->capturedSql);
        $this->assertStringContainsString('`base_persons_persons` `p`', (string) $this->capturedSql);
        $this->assertStringContainsString('`t`.`has_open_proposal` = 1', (string) $this->capturedSql);
        $this->assertSame([3, 4], $this->capturedParams, 'limit + 1 pro has_more, offset');
        $this->assertCount(2, $result['items']);
        $this->assertTrue($result['pagination']['has_more']);
    }
}
