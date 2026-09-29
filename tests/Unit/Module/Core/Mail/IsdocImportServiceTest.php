<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Exchange\Enrich\ContentTagResolver;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\IsdocImportService;
use Shipard\Module\Core\Mail\MessagePartnerWriter;
use Shipard\Module\Core\Mail\MessageTitleComposer;

/**
 * Deterministický ISDOC import (tasks/mail-isdoc-import.md, krok 3;
 * message-centric model z tasks/mail-message-centric.md; detect() +
 * obsahové štítky z tasks/mail-isdoc-content-tags.md #81). Reálný
 * IsdocReader + SchemaValidator nad fixtures, mockovaná DB — ověřuje se
 * orchestrace: co se insertne/updatne a kdy se větev vzdá. Canonical
 * návrhu žije přímo na řádku analýzy (`canonical_json`), žádná extracted
 * tabulka.
 */
class IsdocImportServiceTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../../Fixtures/Exchange/isdoc';
    private const MESSAGE_NDX = 42;

    private string $tmpDir;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $inserts = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $updates = [];

    protected function setUp(): void
    {
        $this->inserts = [];
        $this->updates = [];
        $this->tmpDir = sys_get_temp_dir() . '/shpd_isdocimp_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/att/m', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tmpDir . '/att/m/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->tmpDir . '/att/m');
        @rmdir($this->tmpDir . '/att');
        @rmdir($this->tmpDir);
    }

    // ── Testovací infrastruktura ────────────────────────────────────────────

    /**
     * @return array<string, mixed> Návrat AttachmentService::upload (výřez).
     */
    private function storedAttachment(int $id, string $displayName, string $contents, string $mime): array
    {
        $storedName = "stored_{$id}_" . $displayName;
        file_put_contents($this->tmpDir . '/att/m/' . $storedName, $contents);
        return [
            'id' => $id,
            'name' => $displayName,
            'file_name' => $storedName,
            'file_path' => 'm',
            'mime_type' => $mime,
        ];
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }

    private function fluent(): \Dibi\Fluent
    {
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        return $fluent;
    }

    /**
     * @param array<string, mixed>|null $messageRow Řádek vrácený guard SELECT … FOR UPDATE.
     */
    private function makeDibi(?array $messageRow): \Dibi\Connection&\PHPUnit\Framework\MockObject\MockObject
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturn($messageRow !== null ? new \Dibi\Row($messageRow) : null);
        $dibi->method('getInsertId')->willReturn(909);
        $dibi->method('insert')->willReturnCallback(function (string $table, array $data): \Dibi\Fluent {
            $this->inserts[] = [$table, $data];
            return $this->fluent();
        });
        $dibi->method('update')->willReturnCallback(function (string $table, array $data): \Dibi\Fluent {
            $this->updates[] = [$table, $data];
            return $this->fluent();
        });
        return $dibi;
    }

    private function makeDb(\Dibi\Connection $dibi): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getDibiConnection')->willReturn($dibi);
        return $db;
    }

    private function service(DataSourceConnection $db, ?RowEnrichmentPipeline $enricher = null): IsdocImportService
    {
        return new IsdocImportService(
            $db,
            new SchemaValidator(SchemaLoader::default()),
            $enricher,
            $this->tmpDir,
        );
    }

    /** Service s injektovaným zápisem partnera / titulku (mail-message-title-partner.md). */
    private function serviceWithPartnerWriter(
        DataSourceConnection $db,
        ?MessagePartnerWriter $writer,
        ?MessageTitleComposer $composer = null,
    ): IsdocImportService {
        return new IsdocImportService(
            $db,
            new SchemaValidator(SchemaLoader::default()),
            null,
            $this->tmpDir,
            partnerWriter: $writer,
            titleComposer: $composer,
        );
    }

    /**
     * Reálná pipeline (final třída) nad mockovanou DB — Vrstva 0 s partnerem
     * vždy matched, pravidlo IČO → štítek dle $rule, bez LLM classifieru
     * (DS bez AI backendu). Vzor RowEnrichmentPipelineTest::pipeline().
     *
     * @param list<array<string, mixed>> $history řádky historie partnera (docs_core_rows)
     * @param array<string, mixed>|null $rule řádek core_exchange_tag_rules, null = žádné pravidlo
     */
    private function pipeline(array $history, ?array $rule = null): RowEnrichmentPipeline
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetchAll')->willReturnCallback(
            static function (...$args) use ($history): array {
                $sql = (string) ($args[0] ?? '');
                $rows = str_contains($sql, 'docs_core_rows') ? $history : [];
                return array_map(static fn(array $row) => new \Dibi\Row($row), $rows);
            },
        );
        $dibi->method('fetch')->willReturn($rule !== null ? new \Dibi\Row($rule) : null);
        $dibi->method('update')->willReturn($this->fluent()); // markRuleHit
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(77, 'companyId'));

        return new RowEnrichmentPipeline(
            new RowHistoryEnricher($dibi, $party),
            new ContentTagResolver($dibi),
        );
    }

    /**
     * Reálný enricher nad mockovanou DB (final třída) — partner vždy matched.
     *
     * @param list<array<string, mixed>> $history
     */
    private function enricher(array $history): RowHistoryEnricher
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetchAll')->willReturn(array_map(
            static fn(array $row) => new \Dibi\Row($row),
            $history,
        ));
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(77, 'companyId'));
        return new RowHistoryEnricher($dibi, $party);
    }

    /**
     * @return array<string, mixed> Výchozí message row pro guard SELECT.
     */
    private function messageRow(array $overrides = []): array
    {
        return array_merge([
            'analysis_state' => 10,
            'docState' => 10,
            'primary_type_source' => 'mailbox',
            'created_by' => 5,
        ], $overrides);
    }

    // ── Detekce kandidátů ───────────────────────────────────────────────────

    public function testDetectionPositive(): void
    {
        $this->assertTrue(IsdocImportService::isPotentialIsdocAttachment(
            ['name' => 'FAKTURA.ISDOC', 'mime_type' => 'application/octet-stream'],
        ));
        $this->assertTrue(IsdocImportService::isPotentialIsdocAttachment(
            ['name' => 'balik.IsdocX', 'mime_type' => 'application/zip'],
        ));
        $this->assertTrue(IsdocImportService::isPotentialIsdocAttachment(
            ['name' => 'data.xml', 'mime_type' => 'text/xml'],
        ));
        $this->assertTrue(IsdocImportService::isPotentialIsdocAttachment(
            ['name' => 'data.xml', 'mime_type' => 'application/xml'],
        ));
    }

    public function testDetectionNegative(): void
    {
        $this->assertFalse(IsdocImportService::isPotentialIsdocAttachment(
            ['name' => 'faktura.pdf', 'mime_type' => 'application/pdf'],
        ));
        $this->assertFalse(IsdocImportService::isPotentialIsdocAttachment(
            ['name' => 'message.eml', 'mime_type' => 'message/rfc822'],
        ));
        $this->assertFalse(IsdocImportService::isPotentialIsdocAttachment([]));
    }

    // ── Úspěšný import ──────────────────────────────────────────────────────

    public function testSuccessfulImportWritesAnalysisWithProposalAndStates(): void
    {
        $files = [
            $this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf'),
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $dibi->expects($this->once())->method('begin');
        $dibi->expects($this->once())->method('commit');
        $dibi->expects($this->never())->method('rollback');

        $result = $this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files);

        $this->assertTrue($result);
        // Jediný insert — návrh žije na řádku analýzy, žádná extracted tabulka.
        $this->assertCount(1, $this->inserts);

        [$analysesTable, $analysis] = $this->inserts[0];
        $this->assertSame('core_mail_message_analyses', $analysesTable);
        $this->assertSame(self::MESSAGE_NDX, $analysis['message']);
        $this->assertSame(2, $analysis['status']);
        $this->assertSame('isdoc', $analysis['model_name']);
        $this->assertSame('6.0.1', $analysis['model_version']);
        $this->assertSame('isdoc', $analysis['prompt_version']);
        $this->assertSame('invoiceReceived', $analysis['proposed_type']);
        $this->assertSame(1.0, $analysis['confidence']);
        $this->assertArrayHasKey('content_tag', $analysis);
        $this->assertNull($analysis['content_tag']); // bez pipeline žádný štítek
        $this->assertNull($analysis['profile']);
        $this->assertNull($analysis['backend']);
        $this->assertArrayNotHasKey('cost_usd', $analysis);
        $this->assertArrayNotHasKey('tokens_input', $analysis);
        $this->assertArrayNotHasKey('extracted_document_count', $analysis);
        $this->assertSame(5, $analysis['created_by']);

        $canonical = json_decode((string) $analysis['canonical_json'], true);
        $this->assertSame('isdoc', $canonical['source']['kind']);
        $this->assertSame(self::MESSAGE_NDX, $canonical['source']['message']);
        $this->assertSame('FV-2026-0042', $canonical['docNumber']);
        $this->assertSame('att:501', $canonical['attachments'][0]['ref']);
        $this->assertSame('faktura.isdoc', $canonical['attachments'][0]['filename']);
        $this->assertSame('structured', $canonical['attachments'][0]['kind']);

        // 1) analysis_state → 30, 2) primary_type isdoc, 3) docState 10 → 20
        $this->assertCount(3, $this->updates);
        $this->assertSame('core_mail_incoming_messages', $this->updates[0][0]);
        $this->assertSame(30, $this->updates[0][1]['analysis_state']);
        $this->assertSame(0, $this->updates[0][1]['needs_reanalysis']);
        $this->assertSame(
            ['primary_type' => 'invoiceReceived', 'primary_type_source' => 'isdoc'],
            $this->updates[1][1],
        );
        $this->assertSame(['docState' => 20, 'docStateMain' => 2], $this->updates[2][1]);
    }

    public function testImportWritesPartnerFromIsdocSupplier(): void
    {
        // ISDOC obchází /result — partner zprávy si plní sám (P6): jméno
        // dodavatele z ISDOC + Osoba při shodě identifikátorem.
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];
        $dibi = $this->makeDibi($this->messageRow());

        $captured = null;
        $party = $this->createMock(PartyResolver::class);
        $party->expects($this->once())->method('resolve')->willReturnCallback(
            static function (array $ids, mixed $type = null, bool $identifiersOnly = false) use (&$captured): ResolveResult {
                $captured = [$ids, $identifiersOnly];
                return ResolveResult::matched(77, 'companyId');
            },
        );

        $result = $this->serviceWithPartnerWriter($this->makeDb($dibi), new MessagePartnerWriter($party))
            ->tryImport(self::MESSAGE_NDX, $files);

        $this->assertTrue($result);
        // analysis_state, primary_type, docState + partner_name, partner_person
        $this->assertCount(5, $this->updates);
        $this->assertSame(['partner_name' => 'Testovací dodavatel s.r.o.'], $this->updates[3][1]);
        $this->assertSame(['partner_person' => 77], $this->updates[4][1]);
        $this->assertSame('12345678', $captured[0]['companyId']);
        $this->assertTrue($captured[1], 'jen deterministická shoda identifikátorem');
    }

    public function testImportWritesTitleFromComposer(): void
    {
        // ISDOC nemá AI → titulek je serverový composer (D2), primární zdroj.
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];
        $dibi = $this->makeDibi($this->messageRow());

        $result = $this->serviceWithPartnerWriter($this->makeDb($dibi), null, new MessageTitleComposer(null))
            ->tryImport(self::MESSAGE_NDX, $files);

        $this->assertTrue($result);
        // analysis_state, primary_type, docState + ai_title
        $this->assertCount(4, $this->updates);
        $this->assertSame('core_mail_incoming_messages', $this->updates[3][0]);
        $this->assertArrayHasKey('ai_title', $this->updates[3][1]);
        $this->assertStringStartsWith('FV-2026-0042 — Testovací dodavatel s.r.o.', (string) $this->updates[3][1]['ai_title']);
        $this->assertStringEndsWith('CZK', (string) $this->updates[3][1]['ai_title']);
    }

    public function testPartnerWriterFailureDoesNotAbortImport(): void
    {
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];
        $dibi = $this->makeDibi($this->messageRow());
        $dibi->expects($this->once())->method('commit');
        $dibi->expects($this->never())->method('rollback');

        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willThrowException(new \RuntimeException('DB down'));

        $result = $this->serviceWithPartnerWriter($this->makeDb($dibi), new MessagePartnerWriter($party))
            ->tryImport(self::MESSAGE_NDX, $files);

        $this->assertTrue($result);
        // Jméno se zapsalo, Osoba ne — import doběhl.
        $this->assertCount(4, $this->updates);
        $this->assertSame(['partner_name' => 'Testovací dodavatel s.r.o.'], $this->updates[3][1]);
    }

    public function testEnrichmentFillsOurCodeInCanonical(): void
    {
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];
        $pipeline = $this->pipeline([[
            'description' => 'Testovací služba',
            'vat_code' => 'cz-110',
            'doc_head' => 1234,
            'doc_number' => 'FPB-0001',
            'item_code' => 'SRV-TEST',
            'account_number' => null,
        ]]);

        $dibi = $this->makeDibi($this->messageRow());
        $result = $this->service($this->makeDb($dibi), $pipeline)->tryImport(self::MESSAGE_NDX, $files);

        $this->assertTrue($result);
        $canonical = json_decode((string) $this->inserts[0][1]['canonical_json'], true);
        $this->assertSame('SRV-TEST', $canonical['rows'][0]['item']['ourCode']);
        $this->assertSame('cz-110', $canonical['rows'][0]['vat']['code']);
        $this->assertSame('historyExactRaw', $canonical['_resolve']['rows'][0]['enrichment']['matchedBy']);
        // Vrstva 0 pokryla vše → eskalace neběží, štítek není.
        $this->assertNull($this->inserts[0][1]['content_tag']);
    }

    public function testImportPersistsContentTagFromPipelineRule(): void
    {
        // Řádek bez historie → obsahová eskalace: pravidlo IČO dodavatele
        // (12345678 ve fixture) → štítek, denormalizace do content_tag
        // (#81 D1). Karta „Nová kategorie" z toho staví.
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];
        $pipeline = $this->pipeline([], ['id' => 5, 'tag' => 'vehicle.fuel', 'origin' => 'learned']);

        $dibi = $this->makeDibi($this->messageRow());
        $this->assertTrue($this->service($this->makeDb($dibi), $pipeline)->tryImport(self::MESSAGE_NDX, $files));

        $analysis = $this->inserts[0][1];
        $this->assertSame('vehicle.fuel', $analysis['content_tag']);
        $canonical = json_decode((string) $analysis['canonical_json'], true);
        $this->assertSame(
            ['tag' => 'vehicle.fuel', 'tagSource' => 'rule', 'ruleId' => 5],
            $canonical['_resolve']['contentTag'],
        );
        $this->assertSame('contentTag', $canonical['_resolve']['rows'][0]['enrichment']['matchedBy']);
    }

    public function testImportWithoutTagLeavesContentTagNull(): void
    {
        // Pipeline bez pravidla a bez LLM classifieru (DS bez AI backendu)
        // → štítek není, content_tag NULL, import projde jako dřív.
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $this->assertTrue($this->service($this->makeDb($dibi), $this->pipeline([]))->tryImport(self::MESSAGE_NDX, $files));

        $analysis = $this->inserts[0][1];
        $this->assertNull($analysis['content_tag']);
        $canonical = json_decode((string) $analysis['canonical_json'], true);
        $this->assertArrayNotHasKey('contentTag', $canonical['_resolve'] ?? []);
    }

    public function testCreditNoteProducesCreditNoteProposal(): void
    {
        $files = [
            $this->storedAttachment(502, 'dobropis.isdoc', $this->fixture('credit_note.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $result = $this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files);

        $this->assertTrue($result);
        $this->assertSame('creditNote', $this->inserts[0][1]['proposed_type']);
    }

    // ── Guard a ochrana ručních zásahů ──────────────────────────────────────

    public function testGuardSkipsWhenAnalyzerClaimedMeanwhile(): void
    {
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow(['analysis_state' => 20]));
        $dibi->expects($this->once())->method('begin');
        $dibi->expects($this->once())->method('rollback');
        $dibi->expects($this->never())->method('commit');

        $result = $this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files);

        $this->assertFalse($result);
        $this->assertSame([], $this->inserts);
        $this->assertSame([], $this->updates);
    }

    public function testGuardSkipsWhenMessageMissing(): void
    {
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi(null);
        $dibi->expects($this->once())->method('rollback');

        $this->assertFalse($this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files));
        $this->assertSame([], $this->inserts);
    }

    public function testUserPrimaryTypeSourceIsNotOverwritten(): void
    {
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow(['primary_type_source' => 'user']));
        $result = $this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files);

        $this->assertTrue($result);
        foreach ($this->updates as [, $data]) {
            $this->assertArrayNotHasKey('primary_type', $data);
        }
    }

    public function testDocStateOutsideNewIsLeftAlone(): void
    {
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow(['docState' => 40]));
        $result = $this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files);

        $this->assertTrue($result);
        foreach ($this->updates as [, $data]) {
            $this->assertArrayNotHasKey('docState', $data);
        }
    }

    // ── Vzdání se větve → AI fronta beze změny ──────────────────────────────

    public function testNoCandidateDoesNothing(): void
    {
        $files = [
            $this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $dibi->expects($this->never())->method('begin');

        $this->assertFalse($this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files));
        $this->assertSame([], $this->inserts);
        $this->assertSame([], $this->updates);
    }

    public function testMalformedIsdocAbortsWholeBranch(): void
    {
        $files = [
            $this->storedAttachment(501, 'ok.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
            $this->storedAttachment(502, 'vadna.isdoc', '<Invoice xmlns="http://isdoc.cz/n"><ID>1', 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $dibi->expects($this->never())->method('begin');

        $this->assertFalse($this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files));
        $this->assertSame([], $this->inserts);
    }

    public function testUnsupportedDocumentTypeAbortsWholeBranch(): void
    {
        $files = [
            $this->storedAttachment(501, 'zaloha.isdoc', $this->fixture('doctype4.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $dibi->expects($this->never())->method('begin');

        $this->assertFalse($this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files));
    }

    public function testForeignXmlAttachmentIsSilentlySkipped(): void
    {
        // XML bez ISDOC přípony s cizím rootem není kandidát — bez importu,
        // ale taky bez logovaného selhání větve.
        $files = [
            $this->storedAttachment(503, 'objednavka.xml', $this->fixture('foreign_root.xml'), 'text/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $dibi->expects($this->never())->method('begin');

        $this->assertFalse($this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files));
    }

    public function testForeignXmlNextToValidIsdocDoesNotAbort(): void
    {
        $files = [
            $this->storedAttachment(503, 'objednavka.xml', $this->fixture('foreign_root.xml'), 'text/xml'),
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $result = $this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files);

        $this->assertTrue($result);
        $canonical = json_decode((string) $this->inserts[0][1]['canonical_json'], true);
        $this->assertSame('att:501', $canonical['attachments'][0]['ref']);
    }

    public function testTwoIsdocAttachmentsGoToAiQueue(): void
    {
        // Zpráva má nejvýše jeden dokumentový návrh (D1) — víc ISDOC
        // dokumentů deterministicky rozhodnout nejde, AI vybere primární.
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
            $this->storedAttachment(502, 'dobropis.isdoc', $this->fixture('credit_note.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $dibi->expects($this->never())->method('begin');

        $this->assertFalse($this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files));
        $this->assertSame([], $this->inserts);
        $this->assertSame([], $this->updates);
    }

    public function testExceptionDuringWriteRollsBackAndReturnsFalse(): void
    {
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturn(new \Dibi\Row($this->messageRow()));
        $dibi->method('insert')->willThrowException(new \RuntimeException('DB down'));
        $dibi->expects($this->once())->method('rollback');

        $this->assertFalse($this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files));
    }

    // ── Embedded ISDOC v PDF + dedup identitou (Fáze D) ─────────────────────

    /**
     * Service s podvrženými embedded canonicaly — seam přes protected
     * extractEmbeddedCandidates (reálná extrakce vyžaduje pdfdetach + PDF
     * s /EmbeddedFiles; extrakční vrstvu kryje degradační test níže).
     *
     * @param list<array<string, mixed>> $embedded canonicaly per PDF příloha
     */
    private function serviceWithEmbedded(DataSourceConnection $db, array $embedded): IsdocImportService
    {
        return new class(
            $db,
            new SchemaValidator(SchemaLoader::default()),
            null,
            $this->tmpDir,
            null,
            $embedded,
        ) extends IsdocImportService {
            /** @param list<array<string, mixed>> $embeddedCanonicals */
            public function __construct(
                DataSourceConnection $db,
                SchemaValidator $schemaValidator,
                ?RowEnrichmentPipeline $enricher,
                string $dsPath,
                ?\Shipard\Module\Core\Exchange\Isdoc\IsdocReader $reader,
                private readonly array $embeddedCanonicals,
            ) {
                parent::__construct($db, $schemaValidator, $enricher, $dsPath, $reader);
            }

            protected function extractEmbeddedCandidates(int $messageNdx, array $file): array
            {
                return $this->embeddedCanonicals;
            }
        };
    }

    /** @return array<string, mixed> */
    private function parsedCanonical(string $fixtureName): array
    {
        return new \Shipard\Module\Core\Exchange\Isdoc\IsdocReader()
            ->fromXmlString($this->fixture($fixtureName));
    }

    public function testEmbeddedIsdocInPdfImportsWithCarrierAttachment(): void
    {
        $files = [
            $this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $service = $this->serviceWithEmbedded(
            $this->makeDb($dibi),
            [$this->parsedCanonical('invoice_min.isdoc')],
        );

        $this->assertTrue($service->tryImport(self::MESSAGE_NDX, $files));
        $this->assertCount(1, $this->inserts);

        $canonical = json_decode((string) $this->inserts[0][1]['canonical_json'], true);
        // Embedded je transientní — canonical odkazuje na nosné PDF,
        // kind original (strojová forma je uvnitř), ne structured.
        $this->assertSame('att:500', $canonical['attachments'][0]['ref']);
        $this->assertSame('faktura.pdf', $canonical['attachments'][0]['filename']);
        $this->assertSame('original', $canonical['attachments'][0]['kind']);
        $this->assertSame('invoiceReceived', $this->inserts[0][1]['proposed_type']);
    }

    public function testDuplicateIdentityPrefersStandaloneAttachment(): void
    {
        // Táž faktura jako samostatná .isdoc příloha i embedded v PDF
        // (shodné UUID) → dedup na jeden doklad, preferuje se samostatná
        // příloha; přílohy zprávy nedotčeny, druhý návrh nevzniká.
        $files = [
            $this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf'),
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $service = $this->serviceWithEmbedded(
            $this->makeDb($dibi),
            [$this->parsedCanonical('invoice_min.isdoc')],
        );

        $this->assertTrue($service->tryImport(self::MESSAGE_NDX, $files));
        $this->assertCount(1, $this->inserts);

        $canonical = json_decode((string) $this->inserts[0][1]['canonical_json'], true);
        $this->assertSame('att:501', $canonical['attachments'][0]['ref']);
        $this->assertSame('structured', $canonical['attachments'][0]['kind']);
    }

    public function testTwoDistinctIdentitiesFromPdfGoToAiQueue(): void
    {
        // Dvě odlišné identity (faktura + dobropis embedded v jednom PDF)
        // → větev se celá vzdá, AI vybere primární dokument.
        $files = [
            $this->storedAttachment(500, 'balik.pdf', '%PDF-fake', 'application/pdf'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $dibi->expects($this->never())->method('begin');
        $service = $this->serviceWithEmbedded(
            $this->makeDb($dibi),
            [
                $this->parsedCanonical('invoice_min.isdoc'),
                $this->parsedCanonical('credit_note.isdoc'),
            ],
        );

        $this->assertFalse($service->tryImport(self::MESSAGE_NDX, $files));
        $this->assertSame([], $this->inserts);
    }

    public function testEmbeddedFailingSchemaIsIgnored(): void
    {
        // Vadný embedded (schéma) se ignoruje a pokračuje se zbytkem —
        // tady zbytek není, takže větev bez importu končí false.
        $files = [
            $this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf'),
        ];

        $broken = $this->parsedCanonical('invoice_min.isdoc');
        $broken['docType'] = ''; // minLength 1 → schema issue

        $dibi = $this->makeDibi($this->messageRow());
        $dibi->expects($this->never())->method('begin');
        $service = $this->serviceWithEmbedded($this->makeDb($dibi), [$broken]);

        $this->assertFalse($service->tryImport(self::MESSAGE_NDX, $files));
        $this->assertSame([], $this->inserts);
    }

    public function testMissingPdfdetachDegradesGracefully(): void
    {
        // Chybějící binárka → embedded detekce vypnutá (reálná exec cesta,
        // exit 127), intake neselže, zpráva zůstává v AI frontě.
        $files = [
            $this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $dibi->expects($this->never())->method('begin');

        $service = new class(
            $this->makeDb($dibi),
            new SchemaValidator(SchemaLoader::default()),
            null,
            $this->tmpDir,
        ) extends IsdocImportService {
            protected string $pdfdetachBin = 'shpd-nonexistent-pdfdetach';
        };

        $this->assertFalse($service->tryImport(self::MESSAGE_NDX, $files));
        $this->assertSame([], $this->inserts);
    }

    // ── detect() — detekce bez zápisu (#81 D4) ───────────────────────────────
    //
    // MailController po commitu volá jen detect(); import běží až v runneru.
    // Stejná rozhodovací logika jako tryImport (sdílené collectDocument),
    // ale nikdy nesmí sáhnout do DB ani spustit enrichment.

    public function testDetectTrueForValidStandaloneIsdocWithoutWriting(): void
    {
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $dibi->expects($this->never())->method('begin');
        $dibi->expects($this->never())->method('fetch');

        $this->assertTrue($this->service($this->makeDb($dibi))->detect(self::MESSAGE_NDX, $files));
        $this->assertSame([], $this->inserts);
        $this->assertSame([], $this->updates);
    }

    public function testDetectTrueForEmbeddedIsdoc(): void
    {
        $files = [
            $this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $dibi->expects($this->never())->method('begin');
        $service = $this->serviceWithEmbedded($this->makeDb($dibi), [$this->parsedCanonical('invoice_min.isdoc')]);

        $this->assertTrue($service->detect(self::MESSAGE_NDX, $files));
        $this->assertSame([], $this->inserts);
    }

    public function testDetectFalseForPdfWithoutEmbeddedIsdoc(): void
    {
        // Běžné PDF (sken, AI faktura) → false → zpráva jde do AI fronty jako dřív.
        $files = [
            $this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf'),
        ];
        $service = $this->serviceWithEmbedded($this->makeDb($this->makeDibi($this->messageRow())), []);

        $this->assertFalse($service->detect(self::MESSAGE_NDX, $files));
    }

    public function testDetectFalseForMalformedStandaloneIsdoc(): void
    {
        $files = [
            $this->storedAttachment(501, 'ok.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
            $this->storedAttachment(502, 'vadna.isdoc', '<Invoice xmlns="http://isdoc.cz/n"><ID>1', 'application/xml'),
        ];

        $this->assertFalse(
            $this->service($this->makeDb($this->makeDibi($this->messageRow())))->detect(self::MESSAGE_NDX, $files),
        );
    }

    public function testDetectFalseForTwoDistinctIdentities(): void
    {
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
            $this->storedAttachment(502, 'dobropis.isdoc', $this->fixture('credit_note.isdoc'), 'application/xml'),
        ];

        $this->assertFalse(
            $this->service($this->makeDb($this->makeDibi($this->messageRow())))->detect(self::MESSAGE_NDX, $files),
        );
    }

    public function testDetectFalseWithoutCandidate(): void
    {
        $files = [
            $this->storedAttachment(503, 'scan.jpg', 'JFIF', 'image/jpeg'),
        ];

        $this->assertFalse(
            $this->service($this->makeDb($this->makeDibi($this->messageRow())))->detect(self::MESSAGE_NDX, $files),
        );
    }

    public function testDetectSwallowsExceptions(): void
    {
        // Výbuch extrakce (I/O, nástroj) nesmí propadnout do intake ani
        // uploadu — stejný kontrakt jako tryImport.
        $files = [
            $this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf'),
        ];
        $service = new class(
            $this->makeDb($this->makeDibi($this->messageRow())),
            new SchemaValidator(SchemaLoader::default()),
            null,
            $this->tmpDir,
        ) extends IsdocImportService {
            protected function extractEmbeddedCandidates(int $messageNdx, array $file): array
            {
                throw new \RuntimeException('pdfdetach exploded');
            }
        };

        $this->assertFalse($service->detect(self::MESSAGE_NDX, $files));
    }

    public function testPdfDetectionPositiveAndCandidateUnion(): void
    {
        $this->assertTrue(IsdocImportService::isPdfAttachment(
            ['name' => 'faktura.pdf', 'mime_type' => 'application/octet-stream'],
        ));
        $this->assertTrue(IsdocImportService::isPdfAttachment(
            ['name' => 'faktura.bin', 'mime_type' => 'application/pdf'],
        ));
        $this->assertFalse(IsdocImportService::isPdfAttachment(
            ['name' => 'data.xml', 'mime_type' => 'text/xml'],
        ));
        $this->assertTrue(IsdocImportService::isPotentialCandidate(
            ['name' => 'faktura.pdf', 'mime_type' => 'application/pdf'],
        ));
        $this->assertTrue(IsdocImportService::isPotentialCandidate(
            ['name' => 'faktura.isdoc', 'mime_type' => 'application/octet-stream'],
        ));
        $this->assertFalse(IsdocImportService::isPotentialCandidate(
            ['name' => 'message.eml', 'mime_type' => 'message/rfc822'],
        ));
    }
}
