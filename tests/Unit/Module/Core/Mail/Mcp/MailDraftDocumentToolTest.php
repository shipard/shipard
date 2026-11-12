<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Mcp;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Mail\Mcp\MailDraftDocumentTool;
use Shipard\Module\Core\Mail\MessageProposalApplier;

/**
 * MCP `mail_draft_document` — založení konceptu z dokumentového návrhu
 * zprávy. Nástroj běží nad skutečným {@see MessageProposalApplier}
 * (mockovaná jen DB a `DocumentApplier`), aby test pokryl i to, že výsledek
 * apply nese issues při úspěchu (#89 D18).
 */
final class MailDraftDocumentToolTest extends TestCase
{
    private const MESSAGE_NDX = 100;

    /** @return array<string, mixed> */
    private function canonical(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
    }

    /**
     * Zpráva s analýzou a otevřeným návrhem; `$target` = doklad už založený
     * dříve (idempotentní cesta).
     */
    private function db(?int $targetRow = null): DataSourceConnection
    {
        $message = [
            'id' => self::MESSAGE_NDX, 'docState' => 20, 'docStateMain' => 2, 'analysis_state' => 30,
            'target_table_id' => $targetRow !== null ? 'docs_core_heads' : null,
            'target_row' => $targetRow,
        ];
        $analysis = [
            'id' => 55, 'message' => self::MESSAGE_NDX, 'status' => 2,
            'resolution' => $targetRow !== null ? 40 : null,
            'resolved_at' => null,
            'proposed_type' => 'invoiceReceived',
            'canonical_json' => json_encode($this->canonical()),
        ];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static fn (mixed ...$args): ?array => str_contains((string) $args[0], 'status') ? $analysis : $message,
        );
        return $db;
    }

    /** @return array<string, mixed> výsledek nástroje */
    private function call(?DocumentApplier $applier, ?DataSourceConnection $db = null): array
    {
        $db ??= $this->db();
        $tool = new MailDraftDocumentTool(new MessageProposalApplier($db, $applier));
        $ctx = new McpInvocationContext(new AuthContext(true, 7, 'api_key'), $db, [], null);
        return $tool->call(['message_id' => self::MESSAGE_NDX], $ctx);
    }

    /**
     * @param list<array<string, string>> $issues
     */
    private function applierReturning(array $issues, bool $success = true): DocumentApplier
    {
        $canonical = $this->canonical();
        $canonical['_resolve'] = ['issues' => $issues];
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())->method('apply')->willReturn(
            $success
                ? ApplyResult::ok($canonical, savedId: 9999)
                : ApplyResult::error('unresolved_required', 'Reference vyžadují rozhodnutí.', $canonical),
        );
        return $applier;
    }

    public function testDraftedDocumentCarriesApplierWarnings(): void
    {
        $result = $this->call($this->applierReturning([
            ['severity' => 'warning', 'path' => 'vat.mode', 'code' => 'vat_special_place', 'message' => 'Služba se zvláštním místem plnění.'],
            ['severity' => 'info', 'path' => 'exchangeRate', 'code' => 'exchange_rate_resolved', 'message' => 'Kurz z lístku.'],
            ['severity' => 'warning', 'path' => 'totals', 'code' => 'computed_total_mismatch', 'message' => 'Celkem nesedí.'],
        ]));

        $item = $result['items'][0];
        $this->assertTrue($item['drafted']);
        $this->assertSame(['type' => 'document', 'id' => 9999], $item['document_ref']);
        // Jen varování, v pořadí applieru; cesta + text, bez kódu a závažnosti.
        $this->assertSame([
            ['path' => 'vat.mode', 'message' => 'Služba se zvláštním místem plnění.'],
            ['path' => 'totals', 'message' => 'Celkem nesedí.'],
        ], $item['warnings']);
        $this->assertStringContainsString('varování k dokladu: 2', $result['summary']);
    }

    public function testDraftedDocumentWithoutWarningsHasEmptyList(): void
    {
        $result = $this->call($this->applierReturning([
            ['severity' => 'info', 'path' => 'vat.mode', 'code' => 'vat_foreign_tax', 'message' => 'Daň dodavatele v cenách.'],
        ]));

        $this->assertTrue($result['items'][0]['drafted']);
        $this->assertSame([], $result['items'][0]['warnings']);
        $this->assertSame('Z pošty #100: založen koncept dokladu.', $result['summary']);
    }

    public function testUnresolvedReferencesListOnlyErrors(): void
    {
        $result = $this->call($this->applierReturning([
            ['severity' => 'error', 'path' => 'supplier', 'code' => 'unresolved_required', 'message' => 'Dodavatel nenalezen.'],
            ['severity' => 'warning', 'path' => 'vat.mode', 'code' => 'vat_special_place', 'message' => 'Služba se zvláštním místem plnění.'],
        ], success: false));

        $item = $result['items'][0];
        $this->assertFalse($item['drafted']);
        $this->assertTrue($item['needs_resolution']);
        $this->assertSame([['path' => 'supplier', 'message' => 'Dodavatel nenalezen.']], $item['unresolved']);
        $this->assertArrayNotHasKey('warnings', $item);
    }

    public function testAlreadyDraftedDocumentIsSkippedWithoutWarnings(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $result = $this->call($applier, $this->db(targetRow: 9999));

        $item = $result['items'][0];
        $this->assertFalse($item['drafted']);
        $this->assertTrue($item['skipped']);
        $this->assertSame(['type' => 'document', 'id' => 9999], $item['document_ref']);
        $this->assertArrayNotHasKey('warnings', $item);
    }

    public function testWithoutApplierDegradesGracefully(): void
    {
        $db = $this->db();
        $tool = new MailDraftDocumentTool(null);
        $result = $tool->call(
            ['message_id' => self::MESSAGE_NDX],
            new McpInvocationContext(new AuthContext(true, 7, 'api_key'), $db, [], null),
        );

        $this->assertSame([], $result['items']);
    }

    public function testDescriptionTellsAssistantToRelayWarnings(): void
    {
        $this->assertStringContainsString('warnings', (new MailDraftDocumentTool(null))->description());
    }
}
