<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Enrich\ContentTagResolver;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Mail\MessageProposalApplier;
use Shipard\Module\Core\Mail\PrimaryTypes;
use Shipard\Module\Core\Mail\ProposalTargetApplier;
use Shipard\Module\Core\Mail\TargetApplyResult;
use Shipard\Module\Core\Mail\TargetUnapplyResult;

/**
 * Message-centrické jádro apply/reject/unapply nad dokumentovým návrhem
 * poslední úspěšné analýzy (tasks/mail-message-centric.md D2/D3/D6).
 * Pokrývá guardy apply(), recovery přes message.target_row, zápis verdiktu
 * na řádek analýzy, reverz unapply, target seam (registry) a expand/merge
 * userAction helpery. HTTP-level regrese zůstává
 * v AnalysisControllerExchangeTest / *ResolveBodyTest.
 */
class MessageProposalApplierTest extends TestCase
{
    private const MESSAGE_NDX = 100;
    private const ANALYSIS_NDX = 55;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $updates = [];

    protected function setUp(): void
    {
        $this->updates = [];
    }

    // ── Testovací infrastruktura ────────────────────────────────────────────

    private function happyCanonical(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
    }

    /** @return array<string, mixed> Výchozí řádek core_mail_incoming_messages. */
    private function messageRow(array $overrides = []): array
    {
        return array_merge([
            'id' => self::MESSAGE_NDX,
            'docState' => 20,
            'docStateMain' => 2,
            'analysis_state' => 30,
            'target_table_id' => null,
            'target_row' => null,
        ], $overrides);
    }

    /** @return array<string, mixed> Výchozí řádek core_mail_message_analyses. */
    private function analysisRow(array $overrides = []): array
    {
        return array_merge([
            'id' => self::ANALYSIS_NDX,
            'message' => self::MESSAGE_NDX,
            'status' => 2,
            'resolution' => null,
            'resolved_at' => null,
            'proposed_type' => 'invoiceReceived',
            'canonical_json' => json_encode($this->happyCanonical()),
        ], $overrides);
    }

    /**
     * DB mock routující fetchRow podle tvaru SQL: dotaz na poslední analýzu
     * (WHERE message = … AND status = …) vs. dotaz na zprávu (WHERE id = …).
     */
    private function db(?array $message, ?array $analysis): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static fn(mixed ...$args): ?array => str_contains((string) $args[0], 'status')
                ? $analysis
                : $message,
        );
        return $db;
    }

    /**
     * Dibi mock, na kterém transakční zápisy projdou — update volání se
     * zaznamenávají do $this->updates (fluent řetězení where/execute vrací self).
     */
    private function workingDibi(): \Dibi\Connection
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('update')->willReturnCallback(function (string $table, array $data): \Dibi\Fluent {
            $this->updates[] = [$table, $data];
            $fluent = $this->createMock(\Dibi\Fluent::class);
            $fluent->method('__call')->willReturnSelf();
            return $fluent;
        });
        return $dibi;
    }

    private function withWorkingDibi(DataSourceConnection $db): void
    {
        $db->method('getDibiConnection')->willReturn($this->workingDibi());
    }

    /** Dibi mock, na kterém transakční zápis spadne (rollback cesta). */
    private function withFailingDibi(DataSourceConnection $db): void
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('update')->willThrowException(new \RuntimeException('DB down'));
        $db->method('getDibiConnection')->willReturn($dibi);
    }

    private function service(DataSourceConnection $db, ?DocumentApplier $applier): MessageProposalApplier
    {
        return new MessageProposalApplier($db, $applier);
    }

    private function unapplyService(DataSourceConnection $db, TableGateway $gw): MessageProposalApplier
    {
        return new MessageProposalApplier($db, null, null, null, [], $gw);
    }

    /** ConfigRuntime mock s primaryTypes cfg: contract→registry, invoiceReceived→docs. */
    private function configWithTargets(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['core.mail.primaryTypes', [
                'invoiceReceived' => ['target' => 'docs'],
                'contract'        => ['target' => 'registry', 'docKind' => 'contract'],
                'legacy'          => [],
            ]],
        ]);
        return $config;
    }

    // ── apply() — docs cesta ────────────────────────────────────────────────

    public function testApplyHappyReturnsOkOutcome(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->db($this->messageRow(), $this->analysisRow());

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())->method('apply')
            ->willReturn(ApplyResult::ok($canonical, savedId: 9999));

        // Zápis verdiktu je po úspěšném uložení dokladu warn-only — outcome
        // je ok bez ohledu na jeho výsledek (recovery cesta ho doběhne).
        $outcome = $this->service($db, $applier)
            ->apply(self::MESSAGE_NDX, 7, null, ['autoCreateMode' => 'safe', 'targetDocState' => 10]);

        $this->assertTrue($outcome->ok);
        $this->assertSame(9999, $outcome->savedDocId);
        $this->assertSame(self::MESSAGE_NDX, $outcome->messageNdx);
        $this->assertSame(self::ANALYSIS_NDX, $outcome->analysisNdx);
        $this->assertFalse($outcome->idempotent);
        $this->assertFalse($outcome->recovered);
    }

    public function testApplyWritesPartnerOfCreatedDocumentOnMessage(): void
    {
        // Vrstva 2 (tasks/mail-message-title-partner.md D5/D8): po úspěšném
        // apply se partner_person přepíše partnerem založeného záznamu —
        // čte se přes target_table_id/target_row zprávy, které applier
        // zapsal ve své transakci.
        $message = $this->messageRow();
        $analysis = $this->analysisRow();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static function (mixed ...$args) use ($message, $analysis): ?array {
                $sql = (string) $args[0];
                return match (true) {
                    str_contains($sql, 'status')          => $analysis,
                    str_contains($sql, 'target_table_id') => ['target_table_id' => 'docs_core_heads', 'target_row' => 9999],
                    str_contains($sql, 'partner')         => ['partner' => 55],
                    default                               => $message,
                };
            },
        );
        $this->withWorkingDibi($db);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturn(ApplyResult::ok($this->happyCanonical(), savedId: 9999));

        $outcome = $this->service($db, $applier)->apply(self::MESSAGE_NDX, 7, null);

        $this->assertTrue($outcome->ok);
        $partnerUpdates = array_values(array_filter(
            $this->updates,
            static fn(array $u): bool => array_key_exists('partner_person', $u[1]),
        ));
        $this->assertCount(1, $partnerUpdates);
        $this->assertSame(['core_mail_incoming_messages', ['partner_person' => 55]], $partnerUpdates[0]);
    }

    public function testApplyWithoutTargetPartnerLeavesMessagePartnerUntouched(): void
    {
        // Cíl bez partnera (např. účtenka bez dodavatele) → žádný UPDATE
        // partner_person; verdikt se zapíše normálně.
        $db = $this->db($this->messageRow(), $this->analysisRow());
        $this->withWorkingDibi($db);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturn(ApplyResult::ok($this->happyCanonical(), savedId: 9999));

        $outcome = $this->service($db, $applier)->apply(self::MESSAGE_NDX, 7, null);

        $this->assertTrue($outcome->ok);
        foreach ($this->updates as [$table, $data]) {
            $this->assertArrayNotHasKey('partner_person', $data);
        }
        $this->assertNotEmpty($this->updates, 'verdikt se zapsal');
    }

    public function testApplyNotFound(): void
    {
        $db = $this->db(null, null);
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $outcome = $this->service($db, $applier)->apply(999, 7, null);
        $this->assertFalse($outcome->ok);
        $this->assertSame('NOT_FOUND', $outcome->errorCode);
        $this->assertSame(404, $outcome->statusCode);
    }

    public function testApplyRejectsArchivedOrTrashedMessage(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        foreach ([80, 90] as $docState) {
            $db = $this->db($this->messageRow(['docState' => $docState]), $this->analysisRow());
            $outcome = $this->service($db, $applier)->apply(self::MESSAGE_NDX, 7, null);

            $this->assertFalse($outcome->ok, "docState={$docState}");
            $this->assertSame('INVALID_STATE', $outcome->errorCode);
            $this->assertSame(409, $outcome->statusCode);
        }
    }

    public function testApplyRejectsMessageWithoutSuccessfulAnalysis(): void
    {
        $db = $this->db($this->messageRow(), null);
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $outcome = $this->service($db, $applier)->apply(self::MESSAGE_NDX, 7, null);
        $this->assertFalse($outcome->ok);
        $this->assertSame('INVALID_STATE', $outcome->errorCode);
        $this->assertSame(409, $outcome->statusCode);
    }

    public function testApplyRejectsWhenAnalysisStateNotAnalyzed(): void
    {
        // Analýza existuje, ale zpráva je zpět ve frontě (reanalýza běží).
        $db = $this->db($this->messageRow(['analysis_state' => 20]), $this->analysisRow());
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $outcome = $this->service($db, $applier)->apply(self::MESSAGE_NDX, 7, null);
        $this->assertFalse($outcome->ok);
        $this->assertSame('INVALID_STATE', $outcome->errorCode);
        $this->assertSame(409, $outcome->statusCode);
    }

    public function testApplyIdempotentWhenAlreadyApplied(): void
    {
        // Recovery cesta: target_row obsazený + resolution=40 → idempotent.
        $db = $this->db(
            $this->messageRow(['target_row' => 1234]),
            $this->analysisRow(['resolution' => 40]),
        );
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $outcome = $this->service($db, $applier)->apply(self::MESSAGE_NDX, 7, null);
        $this->assertTrue($outcome->ok);
        $this->assertTrue($outcome->idempotent);
        $this->assertFalse($outcome->recovered);
        $this->assertSame(1234, $outcome->savedDocId);
    }

    public function testApplyRecoversLaggedResolutionWrite(): void
    {
        // target_row obsazený, resolution NULL (zápis verdiktu dřív selhal)
        // → apply verdikt doběhne a hlásí recovered.
        $db = $this->db(
            $this->messageRow(['target_row' => 1234]),
            $this->analysisRow(),
        );
        $this->withWorkingDibi($db);
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $outcome = $this->service($db, $applier)->apply(self::MESSAGE_NDX, 7, null);

        $this->assertTrue($outcome->ok);
        $this->assertTrue($outcome->recovered);
        $this->assertFalse($outcome->idempotent);
        $this->assertSame(1234, $outcome->savedDocId);

        // Verdikt: resolution=40 na analýze + zpráva → Hotovo.
        $this->assertSame('core_mail_message_analyses', $this->updates[0][0]);
        $this->assertSame(40, $this->updates[0][1]['resolution']);
        $this->assertSame(7, $this->updates[0][1]['resolved_by']);
        $this->assertSame('core_mail_incoming_messages', $this->updates[1][0]);
        $this->assertSame(40, $this->updates[1][1]['docState']);
        $this->assertSame(3, $this->updates[1][1]['docStateMain']);
    }

    public function testApplyRecoveryFailsWhenResolutionWriteFails(): void
    {
        // Recovery se selhávajícím zápisem verdiktu → INTERNAL_ERROR (na
        // rozdíl od čerstvého apply, kde je zápis verdiktu warn-only).
        $db = $this->db(
            $this->messageRow(['target_row' => 1234]),
            $this->analysisRow(),
        );
        $this->withFailingDibi($db);

        $outcome = $this->service($db, $this->createMock(DocumentApplier::class))
            ->apply(self::MESSAGE_NDX, 7, null);

        $this->assertFalse($outcome->ok);
        $this->assertSame('INTERNAL_ERROR', $outcome->errorCode);
        $this->assertSame(500, $outcome->statusCode);
    }

    public function testApplyRejectsAlreadyResolvedProposal(): void
    {
        // resolution=50 (zamítnuto) bez target_row → 409.
        $db = $this->db($this->messageRow(), $this->analysisRow(['resolution' => 50]));
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $outcome = $this->service($db, $applier)->apply(self::MESSAGE_NDX, 7, null);
        $this->assertFalse($outcome->ok);
        $this->assertSame('INVALID_STATE', $outcome->errorCode);
        $this->assertSame(409, $outcome->statusCode);
    }

    public function testApplyNoProposal(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        foreach ([null, ''] as $canonicalJson) {
            $db = $this->db($this->messageRow(), $this->analysisRow(['canonical_json' => $canonicalJson]));
            $outcome = $this->service($db, $applier)->apply(self::MESSAGE_NDX, 7, null);

            $this->assertFalse($outcome->ok);
            $this->assertSame('NO_PROPOSAL', $outcome->errorCode);
            $this->assertSame(422, $outcome->statusCode);
        }
    }

    public function testApplyCorruptedJson(): void
    {
        $db = $this->db($this->messageRow(), $this->analysisRow(['canonical_json' => 'not-json']));
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $outcome = $this->service($db, $applier)->apply(self::MESSAGE_NDX, 7, null);
        $this->assertFalse($outcome->ok);
        $this->assertSame('CORRUPTED_DATA', $outcome->errorCode);
        $this->assertSame(500, $outcome->statusCode);
    }

    public function testApplyAiFailedWrapperReturnsAiOutputInvalid(): void
    {
        $db = $this->db($this->messageRow(), $this->analysisRow([
            'canonical_json' => json_encode(['_validationError' => ['issues' => ['x']]]),
        ]));
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $outcome = $this->service($db, $applier)->apply(self::MESSAGE_NDX, 7, null);
        $this->assertFalse($outcome->ok);
        $this->assertSame('AI_OUTPUT_INVALID', $outcome->errorCode);
        $this->assertSame(422, $outcome->statusCode);
    }

    public function testApplyDocsWithoutApplierFails(): void
    {
        $db = $this->db($this->messageRow(), $this->analysisRow());

        $outcome = $this->service($db, null)->apply(self::MESSAGE_NDX, 7, null);
        $this->assertFalse($outcome->ok);
        $this->assertSame('INTERNAL_ERROR', $outcome->errorCode);
        $this->assertSame(500, $outcome->statusCode);
    }

    public function testApplyForwardsUnresolvedRequired(): void
    {
        $db = $this->db($this->messageRow(), $this->analysisRow());
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())->method('apply')->willReturn(
            ApplyResult::error('unresolved_required', 'Doplň userAction', ['_resolve' => ['issues' => [['x' => 1]]]], 422),
        );

        $outcome = $this->service($db, $applier)
            ->apply(self::MESSAGE_NDX, 7, null, ['autoCreateMode' => 'safe', 'targetDocState' => 10]);
        $this->assertFalse($outcome->ok);
        $this->assertSame('unresolved_required', $outcome->errorCode);
        $this->assertSame(422, $outcome->statusCode);
        $this->assertNotNull($outcome->canonical);
    }

    // ── safety: server-controlled source + safe mode + targetDocState=10 ────

    public function testApplyInjectsSourceAndPassesSafeModeToApplier(): void
    {
        $db = $this->db($this->messageRow(), $this->analysisRow());

        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturnCallback(function (array $passed) use (&$captured) {
            $captured = $passed;
            return ApplyResult::error('unresolved_required', 'X', [], 422);
        });

        $this->service($db, $applier)
            ->apply(self::MESSAGE_NDX, 7, null, ['autoCreateMode' => 'safe', 'targetDocState' => 10]);

        $this->assertNotNull($captured);
        // Server-controlled injection — klientem dodanému source se nevěří.
        $this->assertSame(self::MESSAGE_NDX, $captured['source']['message']);
        $this->assertSame('aiExtraction', $captured['source']['kind']);
        $this->assertSame('safe', $captured['applyOptions']['autoCreateMode']);
        $this->assertSame(10, $captured['applyOptions']['targetDocState']);
    }

    public function testApplyDefaultsToSafeWithoutClientResolve(): void
    {
        $db = $this->db($this->messageRow(), $this->analysisRow());
        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturnCallback(function (array $passed) use (&$captured) {
            $captured = $passed;
            return ApplyResult::error('unresolved_required', 'X', [], 422);
        });

        // Bez override, bez klientského _resolve → safe, targetDocState default 10.
        $this->service($db, $applier)->apply(self::MESSAGE_NDX, 7, null);
        $this->assertSame('safe', $captured['applyOptions']['autoCreateMode']);
        $this->assertSame(10, $captured['applyOptions']['targetDocState']);
    }

    public function testApplySwitchesToStrictWithClientResolve(): void
    {
        $db = $this->db($this->messageRow(), $this->analysisRow());
        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturnCallback(function (array $passed) use (&$captured) {
            $captured = $passed;
            return ApplyResult::error('unresolved_required', 'X', [], 422);
        });

        // Non-null klientský _resolve → strict + merge userAction do canonical.
        $this->service($db, $applier)->apply(self::MESSAGE_NDX, 7, ['supplier' => 'useExisting:42']);
        $this->assertSame('strict', $captured['applyOptions']['autoCreateMode']);
        $this->assertSame('useExisting:42', $captured['_resolve']['supplier']['userAction']);
    }

    // ── row history enrichment (D2c/D3) ─────────────────────────────────────

    public function testApplyRunsEnrichmentBeforeUserActionMerge(): void
    {
        // Enrichment doplní řádek z historie; klientův pin se merguje až po
        // něm a v _resolve zůstává vedle enrichment auditu — reconcile fáze
        // DocumentApplieru mu pak dá přednost.
        $db = $this->db($this->messageRow(), $this->analysisRow());

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetchAll')->willReturn([new \Dibi\Row([
            'description'    => 'Hodinová sazba senior konzultanta',
            'vat_code'       => 'cz-110',
            'item_code'      => 'KONZ01',
            'account_number' => '518100',
            'doc_head'       => 777,
        ])]);
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));
        $enricher = new RowEnrichmentPipeline(new RowHistoryEnricher($dibi, $party), new ContentTagResolver($dibi));

        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturnCallback(function (array $passed) use (&$captured) {
            $captured = $passed;
            return ApplyResult::error('unresolved_required', 'X', [], 422);
        });

        $service = new MessageProposalApplier($db, $applier, $enricher);
        $service->apply(self::MESSAGE_NDX, 7, ['rows[0].item' => 'useExisting:55']);

        $this->assertNotNull($captured);
        $this->assertSame('KONZ01', $captured['rows'][0]['item']['ourCode']);
        $this->assertSame('518100', $captured['rows'][0]['account']);
        $rowResolve = $captured['_resolve']['rows'][0];
        $this->assertSame('useExisting:55', $rowResolve['item']['userAction']);
        $this->assertSame('historyExactRaw', $rowResolve['enrichment']['matchedBy']);
    }

    public function testApplyContinuesWhenEnrichmentFails(): void
    {
        $db = $this->db($this->messageRow(), $this->analysisRow());

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetchAll')->willThrowException(new \RuntimeException('db down'));
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));
        $enricher = new RowEnrichmentPipeline(new RowHistoryEnricher($dibi, $party), new ContentTagResolver($dibi));

        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturnCallback(function (array $passed) use (&$captured) {
            $captured = $passed;
            return ApplyResult::ok($passed, savedId: 9999);
        });

        $outcome = (new MessageProposalApplier($db, $applier, $enricher))
            ->apply(self::MESSAGE_NDX, 7, null);

        $this->assertTrue($outcome->ok);
        $this->assertNull($captured['rows'][0]['item']['ourCode']); // neobohaceno
    }

    // ── PrimaryTypes ────────────────────────────────────────────────────────

    public function testPrimaryTypesTargetForFallsBackToDocs(): void
    {
        $config = $this->configWithTargets();
        $this->assertSame('docs', PrimaryTypes::targetFor(null, 'contract'));
        $this->assertSame('docs', PrimaryTypes::targetFor($config, 'unknownType'));
        $this->assertSame('docs', PrimaryTypes::targetFor($config, 'legacy'));
        $this->assertSame('docs', PrimaryTypes::targetFor($config, 'invoiceReceived'));
        $this->assertSame('registry', PrimaryTypes::targetFor($config, 'contract'));
        $this->assertSame('contract', PrimaryTypes::docKindFor($config, 'contract'));
        $this->assertNull(PrimaryTypes::docKindFor($config, 'invoiceReceived'));
    }

    public function testPrimaryTypesIsKnown(): void
    {
        $config = $this->configWithTargets();
        $this->assertTrue(PrimaryTypes::isKnown($config, 'invoiceReceived'));
        $this->assertTrue(PrimaryTypes::isKnown($config, 'legacy'));
        $this->assertFalse(PrimaryTypes::isKnown($config, 'unknownType'));
        $this->assertFalse(PrimaryTypes::isKnown($config, ''));
        $this->assertFalse(PrimaryTypes::isKnown(null, 'invoiceReceived'));
    }

    // ── target seam (registry) ──────────────────────────────────────────────

    public function testApplyRoutesRegistryTargetToTargetApplier(): void
    {
        $canonical = ['schema' => 'shpd.registry.document.v1', 'docType' => 'contract', 'title' => 'Smlouva'];
        $message = $this->messageRow();
        $db = $this->db($message, $this->analysisRow([
            'proposed_type' => 'contract',
            'canonical_json' => json_encode($canonical),
        ]));

        $docsApplier = $this->createMock(DocumentApplier::class);
        $docsApplier->expects($this->never())->method('apply');

        $targetApplier = $this->createMock(ProposalTargetApplier::class);
        $targetApplier->expects($this->once())->method('apply')
            ->with($canonical, $message, 'contract', 7)
            ->willReturn(TargetApplyResult::ok(4242));

        // Enrichment/_resolve/applyOptions se u registry targetu přeskakují —
        // ověřeno passthrough canonicalem (beze změn) v with() výše.
        $service = new MessageProposalApplier(
            $db, $docsApplier, null, $this->configWithTargets(), ['registry' => $targetApplier],
        );
        // Zápis verdiktu je warn-only (stejné chování jako docs cesta) —
        // úspěch target applieru stačí na ok outcome.
        $outcome = $service->apply(self::MESSAGE_NDX, 7, null);

        $this->assertTrue($outcome->ok);
        $this->assertSame(4242, $outcome->savedDocId);
        $this->assertSame($canonical, $outcome->canonical);
    }

    public function testApplyRegistryTargetErrorPassesThrough(): void
    {
        $canonical = ['schema' => 'shpd.registry.document.v1', 'docType' => 'contract', 'title' => 'X'];
        $db = $this->db($this->messageRow(), $this->analysisRow([
            'proposed_type' => 'contract',
            'canonical_json' => json_encode($canonical),
        ]));
        $targetApplier = $this->createMock(ProposalTargetApplier::class);
        $targetApplier->method('apply')->willReturn(
            TargetApplyResult::error('VALIDATION_ERROR', 'title missing', 422),
        );

        $service = new MessageProposalApplier(
            $db, null, null, $this->configWithTargets(), ['registry' => $targetApplier],
        );
        $outcome = $service->apply(self::MESSAGE_NDX, 7, null);

        $this->assertFalse($outcome->ok);
        $this->assertSame('VALIDATION_ERROR', $outcome->errorCode);
        $this->assertSame(422, $outcome->statusCode);
    }

    public function testApplyRegistryTargetWithoutWiredApplierFails(): void
    {
        $canonical = ['schema' => 'shpd.registry.document.v1', 'docType' => 'contract', 'title' => 'X'];
        $db = $this->db($this->messageRow(), $this->analysisRow([
            'proposed_type' => 'contract',
            'canonical_json' => json_encode($canonical),
        ]));

        $service = new MessageProposalApplier($db, null, null, $this->configWithTargets());
        $outcome = $service->apply(self::MESSAGE_NDX, 7, null);

        $this->assertFalse($outcome->ok);
        $this->assertSame('INTERNAL_ERROR', $outcome->errorCode);
        $this->assertSame(500, $outcome->statusCode);
    }

    // ── reject() ────────────────────────────────────────────────────────────

    public function testRejectWritesResolutionOnAnalysisAndClosesMessage(): void
    {
        $db = $this->db($this->messageRow(), $this->analysisRow());
        $this->withWorkingDibi($db);

        $outcome = $this->service($db, null)->reject(self::MESSAGE_NDX, 7, 'Duplicitní zpráva');

        $this->assertTrue($outcome->ok);
        $this->assertSame(self::ANALYSIS_NDX, $outcome->analysisNdx);
        $this->assertNull($outcome->savedDocId);

        // Verdikt jde na řádek analýzy, ne na zprávu.
        [$table, $data] = $this->updates[0];
        $this->assertSame('core_mail_message_analyses', $table);
        $this->assertSame(50, $data['resolution']);
        $this->assertSame('Duplicitní zpráva', $data['rejected_reason']);
        $this->assertSame(7, $data['resolved_by']);
        $this->assertArrayHasKey('resolved_at', $data);

        // Zpráva → Hotovo (symetricky s apply).
        [$table, $data] = $this->updates[1];
        $this->assertSame('core_mail_incoming_messages', $table);
        $this->assertSame(40, $data['docState']);
        $this->assertSame(3, $data['docStateMain']);
    }

    public function testRejectNotFound(): void
    {
        $outcome = $this->service($this->db(null, null), null)->reject(999, 7, 'x');
        $this->assertFalse($outcome->ok);
        $this->assertSame('NOT_FOUND', $outcome->errorCode);
        $this->assertSame(404, $outcome->statusCode);
    }

    public function testRejectRejectsArchivedMessage(): void
    {
        $db = $this->db($this->messageRow(['docState' => 80]), $this->analysisRow());
        $outcome = $this->service($db, null)->reject(self::MESSAGE_NDX, 7, 'x');
        $this->assertFalse($outcome->ok);
        $this->assertSame('INVALID_STATE', $outcome->errorCode);
        $this->assertSame(409, $outcome->statusCode);
    }

    public function testRejectRejectsMessageWithoutAnalysis(): void
    {
        $db = $this->db($this->messageRow(), null);
        $outcome = $this->service($db, null)->reject(self::MESSAGE_NDX, 7, 'x');
        $this->assertFalse($outcome->ok);
        $this->assertSame('INVALID_STATE', $outcome->errorCode);
        $this->assertSame(409, $outcome->statusCode);
    }

    public function testRejectRejectsAlreadyResolvedProposal(): void
    {
        $db = $this->db($this->messageRow(), $this->analysisRow(['resolution' => 40]));
        $outcome = $this->service($db, null)->reject(self::MESSAGE_NDX, 7, 'x');
        $this->assertFalse($outcome->ok);
        $this->assertSame('INVALID_STATE', $outcome->errorCode);
        $this->assertSame(409, $outcome->statusCode);
    }

    // ── saveUserActions() — průběžné ukládání rozhodnutí z review (#76) ────

    /**
     * @param array<string, mixed>|null $expectedData  null = updateWhere se nesmí volat
     */
    private function expectUserActionsUpdate(DataSourceConnection $db, ?array $expectedData): void
    {
        if ($expectedData === null) {
            $db->expects($this->never())->method('updateWhere');
            return;
        }
        $db->expects($this->once())->method('updateWhere')
            ->with('core_mail_message_analyses', $expectedData, 'id = %i', self::ANALYSIS_NDX);
    }

    public function testSaveUserActionsWritesSanitizedJsonOnAnalysis(): void
    {
        $db = $this->db($this->messageRow(), $this->analysisRow());
        $this->expectUserActionsUpdate($db, [
            'user_actions_json' => '{"supplier":"useExisting:42","rows[0].item":"skip"}',
        ]);

        $outcome = $this->service($db, null)->saveUserActions(self::MESSAGE_NDX, 7, [
            'supplier' => 'useExisting:42',
            'bogus' => 'create',        // neznámá cesta — zahozena
            'rows[0].item' => 'skip',
            'customer' => '',           // prázdný string — zahozen
        ]);

        $this->assertTrue($outcome->ok);
        $this->assertSame(self::MESSAGE_NDX, $outcome->messageNdx);
        $this->assertSame(self::ANALYSIS_NDX, $outcome->analysisNdx);
        $this->assertNull($outcome->savedDocId);
    }

    public function testSaveUserActionsEmptyMapWritesNull(): void
    {
        // „Zrušit výběr" posledního rozhodnutí → klient pošle {} → NULL
        // ve sloupci, ne prázdný JSON objekt.
        $db = $this->db(
            $this->messageRow(),
            $this->analysisRow(['user_actions_json' => '{"supplier":"create"}']),
        );
        $this->expectUserActionsUpdate($db, ['user_actions_json' => null]);

        $outcome = $this->service($db, null)->saveUserActions(self::MESSAGE_NDX, 7, []);
        $this->assertTrue($outcome->ok);
        $this->assertSame(self::ANALYSIS_NDX, $outcome->analysisNdx);
    }

    public function testSaveUserActionsNotFound(): void
    {
        $db = $this->db(null, null);
        $this->expectUserActionsUpdate($db, null);

        $outcome = $this->service($db, null)->saveUserActions(999, 7, ['supplier' => 'create']);
        $this->assertFalse($outcome->ok);
        $this->assertSame('NOT_FOUND', $outcome->errorCode);
        $this->assertSame(404, $outcome->statusCode);
    }

    public function testSaveUserActionsRejectsArchivedOrTrashedMessage(): void
    {
        foreach ([80, 90] as $docState) {
            $db = $this->db($this->messageRow(['docState' => $docState]), $this->analysisRow());
            $this->expectUserActionsUpdate($db, null);

            $outcome = $this->service($db, null)->saveUserActions(self::MESSAGE_NDX, 7, ['supplier' => 'create']);
            $this->assertFalse($outcome->ok, "docState={$docState}");
            $this->assertSame('INVALID_STATE', $outcome->errorCode);
            $this->assertSame(409, $outcome->statusCode);
        }
    }

    public function testSaveUserActionsRejectsWithoutCompletedAnalysis(): void
    {
        // Bez úspěšné analýzy; a s analýzou, ale zprávou zpět ve frontě
        // (analysis_state=20, reanalýza běží).
        foreach ([[null, 30], [$this->analysisRow(), 20]] as [$analysis, $state]) {
            $db = $this->db($this->messageRow(['analysis_state' => $state]), $analysis);
            $this->expectUserActionsUpdate($db, null);

            $outcome = $this->service($db, null)->saveUserActions(self::MESSAGE_NDX, 7, ['supplier' => 'create']);
            $this->assertFalse($outcome->ok, "analysis_state={$state}");
            $this->assertSame('INVALID_STATE', $outcome->errorCode);
            $this->assertSame(409, $outcome->statusCode);
        }
    }

    public function testSaveUserActionsRejectsResolvedProposal(): void
    {
        foreach ([40, 50] as $resolution) {
            $db = $this->db($this->messageRow(), $this->analysisRow(['resolution' => $resolution]));
            $this->expectUserActionsUpdate($db, null);

            $outcome = $this->service($db, null)->saveUserActions(self::MESSAGE_NDX, 7, ['supplier' => 'create']);
            $this->assertFalse($outcome->ok, "resolution={$resolution}");
            $this->assertSame('INVALID_STATE', $outcome->errorCode);
            $this->assertSame(409, $outcome->statusCode);
            $this->assertSame(self::ANALYSIS_NDX, $outcome->analysisNdx);
        }
    }

    public function testSaveUserActionsWriteFailureReturns500(): void
    {
        $db = $this->db($this->messageRow(), $this->analysisRow());
        $db->method('updateWhere')->willThrowException(new \RuntimeException('DB down'));

        $outcome = $this->service($db, null)->saveUserActions(self::MESSAGE_NDX, 7, ['supplier' => 'create']);
        $this->assertFalse($outcome->ok);
        $this->assertSame('INTERNAL_ERROR', $outcome->errorCode);
        $this->assertSame(500, $outcome->statusCode);
    }

    // ── sanitize / decode helpers ────────────────────────────────────────────

    public function testSanitizeUserActionsKeepsOnlyWhitelistedStringPairs(): void
    {
        $result = MessageProposalApplier::sanitizeUserActions([
            'supplier' => 'useExisting:42',
            'customerBank' => 'create',
            'rows[0].item' => 'skip',
            'rows[3].unit' => 'useExisting:5',
            'rows[1].vatCode' => 'useExisting:2',
            'bogus' => 'create',
            'rows[abc].item' => 'skip',
            'rows[0].bogus' => 'create',
            'rows[0].item.extra' => 'x',
            123 => 'create',
            'customer' => null,
            'supplierBank' => 12345,
            'rows[2].item' => '',
        ]);

        $this->assertSame([
            'supplier' => 'useExisting:42',
            'customerBank' => 'create',
            'rows[0].item' => 'skip',
            'rows[3].unit' => 'useExisting:5',
            'rows[1].vatCode' => 'useExisting:2',
        ], $result);
    }

    public function testSanitizeIsTransparentForExpand(): void
    {
        // Sanitizace sdílí whitelist cest s expandUserActions — pro vstup bez
        // prázdných řetězců nesmí expand po sanitizaci vypadat jinak. Kdo
        // rozšíří whitelist jen na jednom místě, tento test spadne.
        $input = [
            'supplier' => 'useExisting:42',
            'bogus' => 'create',
            'rows[0].item' => 'skip',
            'rows[abc].item' => 'skip',
            'supplierBank' => 12345,
            'rows[2].vatCode' => 'useExisting:9',
            'rows[1].unit' => 'create',
        ];
        $this->assertSame(
            MessageProposalApplier::expandUserActions($input),
            MessageProposalApplier::expandUserActions(MessageProposalApplier::sanitizeUserActions($input)),
        );
    }

    public function testDecodeUserActionsToleratesNullAndGarbage(): void
    {
        $this->assertSame([], MessageProposalApplier::decodeUserActions(null));
        $this->assertSame([], MessageProposalApplier::decodeUserActions(''));
        $this->assertSame([], MessageProposalApplier::decodeUserActions('not json'));
        $this->assertSame([], MessageProposalApplier::decodeUserActions('"string"'));
        $this->assertSame([], MessageProposalApplier::decodeUserActions('[1,2]'));
        $this->assertSame(
            ['supplier' => 'useExisting:42', 'rows[0].item' => 'skip'],
            MessageProposalApplier::decodeUserActions('{"supplier":"useExisting:42","bogus":"x","rows[0].item":"skip"}'),
        );
    }

    // ── unapply() ───────────────────────────────────────────────────────────

    public function testUnapplyNotFound(): void
    {
        $db = $this->db(null, null);
        $gw = $this->createMock(TableGateway::class);
        $gw->expects($this->never())->method('loadDocument');

        $outcome = $this->unapplyService($db, $gw)->unapply(999, 7);
        $this->assertFalse($outcome->ok);
        $this->assertSame('NOT_FOUND', $outcome->errorCode);
        $this->assertSame(404, $outcome->statusCode);
    }

    public function testUnapplyRejectsNonAppliedProposal(): void
    {
        $db = $this->db($this->messageRow(), $this->analysisRow()); // resolution NULL
        $gw = $this->createMock(TableGateway::class);
        $gw->expects($this->never())->method('loadDocument');

        $outcome = $this->unapplyService($db, $gw)->unapply(self::MESSAGE_NDX, 7);
        $this->assertFalse($outcome->ok);
        $this->assertSame('INVALID_STATE', $outcome->errorCode);
        $this->assertSame(409, $outcome->statusCode);
    }

    public function testUnapplyRejectsAppliedWithoutTarget(): void
    {
        $db = $this->db(
            $this->messageRow(['target_row' => 0]),
            $this->analysisRow(['resolution' => 40]),
        );
        $gw = $this->createMock(TableGateway::class);
        $gw->expects($this->never())->method('loadDocument');

        $outcome = $this->unapplyService($db, $gw)->unapply(self::MESSAGE_NDX, 7);
        $this->assertFalse($outcome->ok);
        $this->assertSame('INVALID_STATE', $outcome->errorCode);
    }

    public function testUnapplyDocsWithoutGatewayFails(): void
    {
        $db = $this->db(
            $this->messageRow(['target_row' => 555]),
            $this->analysisRow(['resolution' => 40]),
        );

        $service = new MessageProposalApplier($db, null, null, $this->configWithTargets());
        $outcome = $service->unapply(self::MESSAGE_NDX, 7);

        $this->assertFalse($outcome->ok);
        $this->assertSame('INTERNAL_ERROR', $outcome->errorCode);
        $this->assertSame(500, $outcome->statusCode);
    }

    public function testUnapplyRejectsWhenTargetDocMissing(): void
    {
        $db = $this->db(
            $this->messageRow(['target_row' => 555]),
            $this->analysisRow(['resolution' => 40]),
        );
        $gw = $this->createMock(TableGateway::class);
        $gw->method('loadDocument')->willReturn(null);
        $gw->expects($this->never())->method('saveDocument');

        $outcome = $this->unapplyService($db, $gw)->unapply(self::MESSAGE_NDX, 7);
        $this->assertFalse($outcome->ok);
        $this->assertSame('DOC_ADVANCED', $outcome->errorCode);
        $this->assertSame(409, $outcome->statusCode);
    }

    public function testUnapplyRejectsWhenTargetDocAdvancedBeyondDraft(): void
    {
        $db = $this->db(
            $this->messageRow(['target_row' => 555]),
            $this->analysisRow(['resolution' => 40]),
        );
        $gw = $this->createMock(TableGateway::class);
        $gw->method('loadDocument')->willReturn(['id' => 555, 'docState' => 20]); // Potvrzeno
        $gw->expects($this->never())->method('saveDocument');

        $outcome = $this->unapplyService($db, $gw)->unapply(self::MESSAGE_NDX, 7);
        $this->assertFalse($outcome->ok);
        $this->assertSame('DOC_ADVANCED', $outcome->errorCode);
    }

    public function testUnapplyTrashesDraftAndReversesVerdict(): void
    {
        $db = $this->db(
            $this->messageRow(['target_row' => 555, 'docState' => 40]),
            $this->analysisRow(['resolution' => 40, 'resolved_at' => '2026-07-14 10:00:00']),
        );
        $this->withWorkingDibi($db);

        $captured = null;
        $gw = $this->createMock(TableGateway::class);
        $gw->method('loadDocument')->willReturn(['id' => 555, 'docState' => 10]);
        $gw->method('saveDocument')->willReturnCallback(function (array $doc) use (&$captured): DocumentResult {
            $captured = $doc;
            return DocumentResult::ok($doc);
        });

        $outcome = $this->unapplyService($db, $gw)->unapply(self::MESSAGE_NDX, 7);

        // 1. Doklad → Koš (soft-delete, vratné).
        $this->assertNotNull($captured);
        $this->assertSame(90, $captured['docState']);
        $this->assertSame(5, $captured['docStateMain']);

        // 2. Reverz verdiktu: resolution/resolved_* NULL, target_* NULL, zpráva 40→20.
        $this->assertTrue($outcome->ok);
        $this->assertSame(555, $outcome->savedDocId);

        [$table, $data] = $this->updates[0];
        $this->assertSame('core_mail_message_analyses', $table);
        $this->assertSame(
            ['resolution' => null, 'rejected_reason' => null, 'resolved_at' => null, 'resolved_by' => null],
            $data,
        );
        [$table, $data] = $this->updates[1];
        $this->assertSame('core_mail_incoming_messages', $table);
        $this->assertNull($data['target_table_id']);
        $this->assertNull($data['target_row']);
        [$table, $data] = $this->updates[2];
        $this->assertSame('core_mail_incoming_messages', $table);
        $this->assertSame(20, $data['docState']);
        $this->assertSame(2, $data['docStateMain']);
    }

    public function testUnapplyFailsWhenTrashSaveFails(): void
    {
        $db = $this->db(
            $this->messageRow(['target_row' => 555]),
            $this->analysisRow(['resolution' => 40]),
        );
        $gw = $this->createMock(TableGateway::class);
        $gw->method('loadDocument')->willReturn(['id' => 555, 'docState' => 10]);
        $gw->method('saveDocument')->willReturn(DocumentResult::error('save failed'));

        $outcome = $this->unapplyService($db, $gw)->unapply(self::MESSAGE_NDX, 7);
        $this->assertFalse($outcome->ok);
        $this->assertSame('INTERNAL_ERROR', $outcome->errorCode);
        $this->assertSame(500, $outcome->statusCode);
    }

    public function testUnapplyRoutesRegistryTargetToTargetApplier(): void
    {
        $db = $this->db(
            $this->messageRow(['target_row' => 555]),
            $this->analysisRow([
                'proposed_type' => 'contract',
                'resolution' => 40,
                'resolved_at' => '2026-07-14 10:00:00',
            ]),
        );

        $gw = $this->createMock(TableGateway::class);
        $gw->expects($this->never())->method('loadDocument');

        $targetApplier = $this->createMock(ProposalTargetApplier::class);
        $targetApplier->expects($this->once())->method('unapply')
            ->with(555, '2026-07-14 10:00:00')
            ->willReturn(TargetUnapplyResult::error('DOC_ADVANCED', 'Document changed since apply', 409));

        $service = new MessageProposalApplier(
            $db, null, null, $this->configWithTargets(), ['registry' => $targetApplier], $gw,
        );
        $outcome = $service->unapply(self::MESSAGE_NDX, 7);

        $this->assertFalse($outcome->ok);
        $this->assertSame('DOC_ADVANCED', $outcome->errorCode);
        $this->assertSame(409, $outcome->statusCode);
    }

    public function testUnapplyRegistryTargetHappyFinishesSharedTransition(): void
    {
        $db = $this->db(
            $this->messageRow(['target_row' => 555, 'docState' => 40]),
            $this->analysisRow([
                'proposed_type' => 'contract',
                'resolution' => 40,
                'resolved_at' => '2026-07-14 10:00:00',
            ]),
        );
        $this->withWorkingDibi($db);

        $targetApplier = $this->createMock(ProposalTargetApplier::class);
        $targetApplier->method('unapply')->willReturn(TargetUnapplyResult::ok(555));

        $service = new MessageProposalApplier(
            $db, null, null, $this->configWithTargets(), ['registry' => $targetApplier],
        );
        $outcome = $service->unapply(self::MESSAGE_NDX, 7);

        $this->assertTrue($outcome->ok);
        $this->assertSame(555, $outcome->savedDocId);
        // Sdílený reverz: analyses reset + messages target_* NULL + 40→20.
        $this->assertCount(3, $this->updates);
    }

    // ── expand / merge helpers ───────────────────────────────────────────────

    public function testExpandUserActionsTopLevelAndRows(): void
    {
        $result = MessageProposalApplier::expandUserActions([
            'supplier' => 'useExisting:42',
            'supplierBank' => 'create',
            'customer' => 'useExisting:1',
            'rows[0].item' => 'skip',
            'rows[2].item' => 'create',
        ]);

        $this->assertSame(['userAction' => 'useExisting:42'], $result['supplier']);
        $this->assertSame(['userAction' => 'create'], $result['supplierBank']);
        $this->assertSame(['userAction' => 'useExisting:1'], $result['customer']);
        $this->assertSame(['userAction' => 'skip'], $result['rows'][0]['item']);
        $this->assertSame(['userAction' => 'create'], $result['rows'][2]['item']);
        $this->assertArrayNotHasKey(1, $result['rows']);
    }

    public function testExpandUserActionsSkipsInvalidShapes(): void
    {
        $result = MessageProposalApplier::expandUserActions([
            'supplier' => 'useExisting:1',
            'bogus' => 'create',
            'rows[abc].item' => 'skip',
            'rows[0].bogus' => 'create',
            123 => 'create',
            'customer' => null,
            'supplierBank' => 12345,
        ]);

        $this->assertSame(['userAction' => 'useExisting:1'], $result['supplier']);
        $this->assertArrayNotHasKey('bogus', $result);
        $this->assertArrayNotHasKey('rows', $result);
        $this->assertArrayNotHasKey('customer', $result);
        $this->assertArrayNotHasKey('supplierBank', $result);
    }

    public function testMergeUserActionsTopLevel(): void
    {
        $result = MessageProposalApplier::mergeUserActions(
            ['supplier' => ['status' => 'canCreate', 'createPayload' => ['x' => 1]]],
            ['supplier' => ['userAction' => 'create']],
        );

        $this->assertSame('canCreate', $result['supplier']['status']);
        $this->assertSame('create', $result['supplier']['userAction']);
        $this->assertSame(['x' => 1], $result['supplier']['createPayload']);
    }

    public function testMergeUserActionsRows(): void
    {
        $result = MessageProposalApplier::mergeUserActions(
            ['rows' => [
                ['item' => ['status' => 'canCreate']],
                ['item' => ['status' => 'matched', 'matchedId' => 18]],
            ]],
            ['rows' => [
                0 => ['item' => ['userAction' => 'create']],
                1 => ['item' => ['userAction' => 'useExisting:18']],
            ]],
        );

        $this->assertSame('canCreate', $result['rows'][0]['item']['status']);
        $this->assertSame('create', $result['rows'][0]['item']['userAction']);
        $this->assertSame('matched', $result['rows'][1]['item']['status']);
        $this->assertSame('useExisting:18', $result['rows'][1]['item']['userAction']);
        $this->assertSame(18, $result['rows'][1]['item']['matchedId']);
    }

    // ── Volby DPH v mapě rozhodnutí (tasks/exchange-preview-vat-choices.md D8, D17) ──

    public function testExpandUserActionsVatHeaderPaths(): void
    {
        $expanded = MessageProposalApplier::expandUserActions([
            'vat.place'       => 'useValue:intracom',
            'vat.mode'        => 'useValue:fromTotal',
            'rows[0].vatCode' => 'useCode:cz-218',
            'vat.bogus'       => 'useValue:x',
        ]);

        $this->assertSame([
            'vat'  => [
                'place' => ['userAction' => 'useValue:intracom'],
                'mode'  => ['userAction' => 'useValue:fromTotal'],
            ],
            'rows' => [0 => ['vatCode' => ['userAction' => 'useCode:cz-218']]],
        ], $expanded);
    }

    public function testSanitizeUserActionsKeepsVatHeaderPaths(): void
    {
        $clean = MessageProposalApplier::sanitizeUserActions([
            'vat.place'  => 'useValue:domestic',
            'vat.mode'   => 'useValue:none',
            'vat.bogus'  => 'useValue:x',
            'vat'        => 'useValue:x',
            'vat.place.' => 'useValue:x',
        ]);

        $this->assertSame(['vat.place' => 'useValue:domestic', 'vat.mode' => 'useValue:none'], $clean);
    }

    public function testMergeUserActionsNestedVatKeepsEffectiveValues(): void
    {
        // `_resolve.vat` z předchozího náhledu nese value/source — merge sahá
        // jen na userAction (vzor větve rows).
        $result = MessageProposalApplier::mergeUserActions(
            ['vat' => ['place' => ['value' => 'intracom', 'source' => 'vatId']]],
            ['vat' => [
                'place' => ['userAction' => 'useValue:domestic'],
                'mode'  => ['userAction' => 'useValue:fromTotal'],
                'junk'  => 'useValue:x',
            ]],
        );

        $this->assertSame(
            ['value' => 'intracom', 'source' => 'vatId', 'userAction' => 'useValue:domestic'],
            $result['vat']['place'],
        );
        $this->assertSame(['userAction' => 'useValue:fromTotal'], $result['vat']['mode']);
        $this->assertArrayNotHasKey('junk', $result['vat']);
    }

    public function testApplyWithoutClientResolveUsesSavedDecisionsAndStaysSafe(): void
    {
        // Jednoklik z karty / MCP: klient mapu neposílá, platí rozhodnutí
        // uložená v review modalu (#76) — volba kódu DPH i dodavatele.
        $saved = ['rows[0].vatCode' => 'useCode:cz-218', 'supplier' => 'useExisting:42'];
        $db = $this->db($this->messageRow(), $this->analysisRow(['user_actions_json' => json_encode($saved)]));
        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturnCallback(function (array $passed) use (&$captured) {
            $captured = $passed;
            return ApplyResult::error('unresolved_required', 'X', [], 422);
        });

        $this->service($db, $applier)->apply(self::MESSAGE_NDX, 7, null);

        $this->assertSame('useCode:cz-218', $captured['_resolve']['rows'][0]['vatCode']['userAction']);
        $this->assertSame('useExisting:42', $captured['_resolve']['supplier']['userAction']);
        // Doplněná rozhodnutí režim nepřepínají — dodavatele s IČO dál zakládá sám.
        $this->assertSame('safe', $captured['applyOptions']['autoCreateMode']);
    }

    public function testApplyClientResolveTakesPrecedenceOverSavedDecisions(): void
    {
        $saved = ['supplier' => 'useExisting:42', 'rows[0].vatCode' => 'useCode:cz-218'];
        $db = $this->db($this->messageRow(), $this->analysisRow(['user_actions_json' => json_encode($saved)]));
        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturnCallback(function (array $passed) use (&$captured) {
            $captured = $passed;
            return ApplyResult::error('unresolved_required', 'X', [], 422);
        });

        $this->service($db, $applier)->apply(self::MESSAGE_NDX, 7, ['rows[0].item' => 'skip']);

        $this->assertSame('skip', $captured['_resolve']['rows'][0]['item']['userAction']);
        $this->assertArrayNotHasKey('supplier', $captured['_resolve'], 'uložená mapa se k explicitní nedoplňuje');
        $this->assertArrayNotHasKey('vatCode', $captured['_resolve']['rows'][0]);
        $this->assertSame('strict', $captured['applyOptions']['autoCreateMode']);
    }

    public function testApplyWithoutSavedDecisionsLeavesResolveUntouched(): void
    {
        $db = $this->db($this->messageRow(), $this->analysisRow(['user_actions_json' => null]));
        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturnCallback(function (array $passed) use (&$captured) {
            $captured = $passed;
            return ApplyResult::error('unresolved_required', 'X', [], 422);
        });

        $this->service($db, $applier)->apply(self::MESSAGE_NDX, 7, null);

        $this->assertArrayNotHasKey('_resolve', $captured);
        $this->assertSame('safe', $captured['applyOptions']['autoCreateMode']);
    }
}
