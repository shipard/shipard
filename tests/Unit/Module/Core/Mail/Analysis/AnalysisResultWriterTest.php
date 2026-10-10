<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Analysis;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Module\Core\Exchange\Enrich\ContentTagResolver;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\Analysis\AnalysisResultException;
use Shipard\Module\Core\Mail\Analysis\AnalysisResultWriter;
use Shipard\Module\Core\Mail\Analysis\AnalysisStates;

/**
 * Zápis výsledku a selhání AI analýzy jako služba (dřív pokryté přes HTTP
 * `POST /result` / `POST /failed` v AnalysisControllerTest, protokol zrušen
 * #85 D20): validace těla kontraktu v4, INSERT běhu + uvolnění claimu +
 * stavy zprávy nad mockovaným Dibi, krok klasifikace a titulku, validace
 * canonicalu (docs i registry, enrichment). Plnou SQL cestu nad reálnou DB
 * kryje AnalysisResultWriterIntegrationTest.
 */
class AnalysisResultWriterTest extends TestCase
{
    private string $tmpDir;
    private DataSourceConfig $config;

    protected function setUp(): void
    {
        DsSecretCipher::resetCache();
        $this->tmpDir = sys_get_temp_dir() . '/shpd_result_writer_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir . '/config', 0700, true);
        file_put_contents($this->tmpDir . '/config/main.json', json_encode([
            'id' => 'test-test-test-test',
            'name' => 'Result Writer Test',
            'database_name' => 'test_db',
            'database_user' => 'test',
            'database_password' => 'pw',
            'created' => date('c'),
        ]));
        DsSecretCipher::generateKey($this->tmpDir);
        $this->config = new DataSourceConfig($this->tmpDir);
    }

    protected function tearDown(): void
    {
        DsSecretCipher::resetCache();
        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @chmod($path, 0600);
                @unlink($path);
            }
        }
        @chmod($dir, 0700);
        @rmdir($dir);
    }

    /** Writer bez SchemaValidatoru — canonical projde beze změn (passthrough). */
    private function writer(DataSourceConnection $db): AnalysisResultWriter
    {
        return new AnalysisResultWriter($db, $this->config);
    }

    /** Writer s reálným SchemaValidatorem (validace canonicalu). */
    private function validatingWriter(
        DataSourceConnection $db,
        ?RowEnrichmentPipeline $enricher = null,
        ?ConfigRuntime $configRuntime = null,
    ): AnalysisResultWriter {
        return new AnalysisResultWriter(
            $db,
            $this->config,
            new SchemaValidator(SchemaLoader::default()),
            $enricher,
            $configRuntime,
        );
    }

    /** ConfigRuntime s primaryTypes: insurance jako registry target. */
    private function configRuntimeWithRegistryTargets(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['core.mail.primaryTypes', [
                'invoiceReceived' => ['target' => 'docs'],
                'insurance'       => ['target' => 'registry', 'docKind' => 'insurance'],
            ]],
        ]);
        return $config;
    }

    /**
     * Real enricher nad mockovanou DB (RowHistoryEnricher je final — nelze
     * mockovat) — partner vždy matched, historie dle parametru.
     *
     * @param list<array<string, mixed>> $history
     */
    private function enricher(array $history): RowEnrichmentPipeline
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetchAll')->willReturn(array_map(
            static fn(array $row) => new \Dibi\Row($row),
            $history,
        ));
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));
        return new RowEnrichmentPipeline(new RowHistoryEnricher($dibi, $party), new ContentTagResolver($dibi));
    }

    /** @return array<string, mixed> */
    private function happyCanonical(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 6) . '/tests/Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
    }

    /**
     * DB + Dibi mock pro storeResult()/storeFailure(): INSERTy a UPDATEy se
     * zachytávají per tabulka, getInsertId vrací dané id.
     *
     * @param list<array{0: string, 1: array<string, mixed>}> $inserts
     * @param list<array{0: string, 1: array<string, mixed>}> $updates
     */
    private function dbCapturing(array &$inserts, array &$updates, int $insertId = 123): DataSourceConnection
    {
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->method('execute');

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('insert')->willReturnCallback(
            function (string $table, array $values) use (&$inserts, $fluent) {
                $inserts[] = [$table, $values];
                return $fluent;
            },
        );
        $dibi->method('update')->willReturnCallback(
            function (string $table, array $values) use (&$updates, $fluent) {
                $updates[] = [$table, $values];
                return $fluent;
            },
        );
        $dibi->method('getInsertId')->willReturn($insertId);

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getDibiConnection')->willReturn($dibi);
        return $db;
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $rows
     * @return list<array<string, mixed>>
     */
    private static function forTable(array $rows, string $table): array
    {
        return array_values(array_map(
            static fn(array $row): array => $row[1],
            array_filter($rows, static fn(array $row): bool => $row[0] === $table),
        ));
    }

    /** @return array<string, mixed> */
    private function baseBody(): array
    {
        return [
            'model_name' => 'claude',
            'prompt_version' => 'v4',
            'message_classification' => ['primary_type' => 'invoiceReceived', 'confidence' => 0.97],
        ];
    }

    private function expectResultError(string $code, int $status, ?string $field, callable $call): void
    {
        try {
            $call();
        } catch (AnalysisResultException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->httpStatus);
            if ($field !== null) {
                $this->assertSame($field, $e->details[0]['field'] ?? null);
            }
            return;
        }
        $this->fail('AnalysisResultException expected');
    }

    // -------------------------------------------------------------------
    // storeResult() — validace těla (kontrakt v4)
    // -------------------------------------------------------------------

    public function testStoreResultRequiresModelAndPromptVersion(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('getDibiConnection');

        $this->expectResultError('VALIDATION_ERROR', 422, null, fn() => $this->writer($db)->storeResult(42, 1, [], 7));
    }

    public function testStoreResultRejectsExtractedDocumentsField(): void
    {
        // Kontrakt v4: pole extracted_documents se nepřijímá (D11 big-bang).
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('getDibiConnection');
        $body = $this->baseBody() + ['extracted_documents' => []];

        $this->expectResultError('VALIDATION_ERROR', 422, 'extracted_documents', fn() => $this->writer($db)->storeResult(42, 1, $body, 7));
    }

    public function testStoreResultRequiresMessageClassification(): void
    {
        // Kontrakt v4: message_classification s neprázdným primary_type je povinná.
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('getDibiConnection');
        $body = ['model_name' => 'claude', 'prompt_version' => 'v4'];

        $this->expectResultError('VALIDATION_ERROR', 422, 'message_classification', fn() => $this->writer($db)->storeResult(42, 1, $body, 7));
    }

    public function testStoreResultRequiresNonEmptyPrimaryType(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $body = ['model_name' => 'claude', 'prompt_version' => 'v4', 'message_classification' => ['primary_type' => '  ']];

        $this->expectResultError('VALIDATION_ERROR', 422, 'message_classification', fn() => $this->writer($db)->storeResult(42, 1, $body, 7));
    }

    // -------------------------------------------------------------------
    // storeResult() — zápis (passthrough bez SchemaValidatoru)
    // -------------------------------------------------------------------

    public function testStoreResultStoresCanonicalAndProposedType(): void
    {
        // Happy path bez SchemaValidatoru (passthrough): document.extracted_json
        // se uloží do canonical_json, doc_type do proposed_type, confidence
        // návrhu do confidence; INSERT nemá extracted_document_count.
        $inserts = [];
        $updates = [];
        $db = $this->dbCapturing($inserts, $updates, 123);

        $body = $this->baseBody() + [
            'overall_confidence' => 0.5,
            'document' => [
                'doc_type' => 'invoiceReceived',
                'confidence' => 0.83,
                'extracted_json' => ['docNumber' => 'FV-1'],
            ],
        ];
        $analysisNdx = $this->writer($db)->storeResult(42, 1, $body, 7);

        $this->assertSame(123, $analysisNdx);
        $analyses = self::forTable($inserts, 'core_mail_message_analyses');
        $this->assertCount(1, $analyses);
        $insertValues = $analyses[0];
        $this->assertSame(42, $insertValues['message']);
        $this->assertSame(2, $insertValues['status']);
        $this->assertSame('invoiceReceived', $insertValues['proposed_type']);
        $this->assertSame(0.83, $insertValues['confidence']); // document.confidence, ne overall
        $this->assertSame(['docNumber' => 'FV-1'], json_decode((string) $insertValues['canonical_json'], true));
        $this->assertSame(7, $insertValues['created_by']);
        $this->assertArrayNotHasKey('extracted_document_count', $insertValues);
    }

    public function testStoreResultReleasesClaimAndAdvancesMessageStates(): void
    {
        // Validní dokument: claim released=1/result, analysis_state → 30
        // + needs_reanalysis 0, workflow Nová → K řešení (podmíněný UPDATE).
        $inserts = [];
        $updates = [];
        $db = $this->dbCapturing($inserts, $updates);

        $body = $this->baseBody() + [
            'document' => ['doc_type' => 'invoiceReceived', 'confidence' => 0.9, 'extracted_json' => ['docNumber' => 'FV-1']],
        ];
        $this->writer($db)->storeResult(42, 17, $body, null);

        $claims = self::forTable($updates, 'core_mail_analysis_claims');
        $this->assertCount(1, $claims);
        $this->assertSame(1, $claims[0]['released']);
        $this->assertSame('result', $claims[0]['release_reason']);

        $messages = self::forTable($updates, 'core_mail_incoming_messages');
        $this->assertSame(AnalysisStates::ANALYZED, $messages[0]['analysis_state']);
        $this->assertSame(0, $messages[0]['needs_reanalysis']);
        $this->assertSame(20, $messages[1]['docState'], 'validní dokument posouvá Novou na K řešení');
        $this->assertSame('invoiceReceived', $messages[2]['primary_type']);
        $this->assertSame('ai', $messages[2]['primary_type_source']);
    }

    public function testStoreResultWithoutDocumentLeavesDocStateAlone(): void
    {
        $inserts = [];
        $updates = [];
        $db = $this->dbCapturing($inserts, $updates);

        $body = $this->baseBody();
        $body['message_classification'] = ['primary_type' => 'other', 'confidence' => 0.9];
        $this->writer($db)->storeResult(42, 17, $body, null);

        foreach (self::forTable($updates, 'core_mail_incoming_messages') as $values) {
            $this->assertArrayNotHasKey('docState', $values, 'běh bez dokumentu workflow nemění');
        }
        $this->assertSame(AnalysisStates::ANALYZED, self::forTable($updates, 'core_mail_incoming_messages')[0]['analysis_state']);
    }

    public function testStoreResultPersistsContentTagColumn(): void
    {
        // Denormalizace `_resolve.contentTag.tag` z obohaceného canonicalu
        // do sloupce content_tag (tasks/content-tag-enrichment.md, D20).
        // Passthrough bez SchemaValidatoru — blok nese extracted_json.
        $inserts = [];
        $updates = [];
        $db = $this->dbCapturing($inserts, $updates, 125);

        $body = $this->baseBody() + [
            'document' => [
                'doc_type' => 'invoiceReceived',
                'confidence' => 0.8,
                'extracted_json' => [
                    'docNumber' => 'FV-2',
                    '_resolve' => ['contentTag' => ['tag' => 'vehicle.fuel', 'tagSource' => 'rule']],
                ],
            ],
        ];
        $this->assertSame(125, $this->writer($db)->storeResult(42, 1, $body, 7));

        $insertValues = self::forTable($inserts, 'core_mail_message_analyses')[0];
        $this->assertSame('vehicle.fuel', $insertValues['content_tag']);
    }

    public function testStoreResultWithoutDocumentStoresOverallConfidence(): void
    {
        $inserts = [];
        $updates = [];
        $db = $this->dbCapturing($inserts, $updates, 124);

        $body = [
            'model_name' => 'claude',
            'prompt_version' => 'v4',
            'overall_confidence' => 0.42,
            'message_classification' => ['primary_type' => 'other', 'confidence' => 0.9],
        ];
        $this->assertSame(124, $this->writer($db)->storeResult(42, 1, $body, null));

        $insertValues = self::forTable($inserts, 'core_mail_message_analyses')[0];
        $this->assertNull($insertValues['canonical_json']);
        $this->assertNull($insertValues['proposed_type']);
        $this->assertNull($insertValues['content_tag']);
        $this->assertSame(0.42, $insertValues['confidence']);
        $this->assertNull($insertValues['created_by'], 'strojový kontext runneru');
    }

    // -------------------------------------------------------------------
    // storeFailure()
    // -------------------------------------------------------------------

    public function testStoreFailureRetryableReturnsMessageToQueue(): void
    {
        $inserts = [];
        $updates = [];
        $db = $this->dbCapturing($inserts, $updates);

        $state = $this->writer($db)->storeFailure(42, 17, 'ai_error', ' boom ', true, 321, 'claude', 'v4', null);

        $this->assertSame(AnalysisStates::QUEUED, $state);
        $run = self::forTable($inserts, 'core_mail_message_analyses')[0];
        $this->assertSame(3, $run['status']);
        $this->assertSame('[ai_error] boom', $run['error_message']);
        $this->assertSame(321, $run['tokens_input']);
        $this->assertSame('claude', $run['model_name']);
        $this->assertNull($run['created_by']);

        $claims = self::forTable($updates, 'core_mail_analysis_claims');
        $this->assertSame('failed', $claims[0]['release_reason']);
        $this->assertSame(1, $claims[0]['released']);
        $messages = self::forTable($updates, 'core_mail_incoming_messages');
        $this->assertSame(AnalysisStates::QUEUED, $messages[0]['analysis_state']);
        $this->assertArrayNotHasKey('docState', $messages[0]);
    }

    public function testStoreFailurePermanentEndsInFailedState(): void
    {
        $inserts = [];
        $updates = [];
        $db = $this->dbCapturing($inserts, $updates);

        $state = $this->writer($db)->storeFailure(42, 17, 'schema_error', '', false, null, null, null, 9);

        $this->assertSame(AnalysisStates::FAILED, $state);
        $run = self::forTable($inserts, 'core_mail_message_analyses')[0];
        $this->assertSame('[schema_error]', $run['error_message']);
        $this->assertSame('unknown', $run['model_name']);
        $this->assertSame('unknown', $run['prompt_version']);
        $this->assertSame(9, $run['created_by']);
        $messages = self::forTable($updates, 'core_mail_incoming_messages');
        $this->assertSame(AnalysisStates::FAILED, $messages[0]['analysis_state']);
    }

    // -------------------------------------------------------------------
    // message_classification (spec mail-states-and-classification §B1)
    //
    // Plný storeResult() flow potřebuje reálnou DB (insert do 3 tabulek,
    // AnalysisResultWriterIntegrationTest) — tady samostatný krok
    // applyMessageClassification() nad mockovaným Dibi.
    // -------------------------------------------------------------------

    /** Sloupce pozornosti, které UPDATE klasifikace nese vždy (tasks/mail-other-attention.md D3). */
    private const NO_ATTENTION = ['attention' => null, 'action_note' => null, 'action_due' => null];

    private function callApplyClassification(\Dibi\Connection $dibi, array $body): void
    {
        $this->writer($this->createMock(DataSourceConnection::class))
            ->applyMessageClassification($dibi, 42, $body);
    }

    public function testClassificationUpdatesPrimaryTypeWithAiSource(): void
    {
        $whereCalls = [];
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnCallback(
            function (string $name, array $args) use (&$whereCalls, $fluent) {
                if ($name === 'where') {
                    $whereCalls[] = $args;
                }
                return $fluent;
            },
        );
        $fluent->expects($this->once())->method('execute');

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->once())
            ->method('update')
            ->with(
                'core_mail_incoming_messages',
                ['primary_type' => 'other', 'primary_type_source' => 'ai', ...self::NO_ATTENTION],
            )
            ->willReturn($fluent);

        $this->callApplyClassification($dibi, [
            'message_classification' => ['primary_type' => 'other', 'confidence' => 0.97],
        ]);

        // WHERE musí obsahovat guard proti přepsání uživatelské volby
        $flat = array_map(static fn(array $args): string => implode('|', array_map('strval', $args)), $whereCalls);
        $this->assertContains('primary_type_source != %s|user', $flat);
    }

    public function testClassificationFallsBackToAnalysisJson(): void
    {
        // Starší tělo bez top-level pole — klasifikace dorazí jen uvnitř
        // analysis_json (celý výstup modelu).
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->expects($this->once())->method('execute');

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->once())
            ->method('update')
            ->with(
                'core_mail_incoming_messages',
                ['primary_type' => 'invoiceReceived', 'primary_type_source' => 'ai', ...self::NO_ATTENTION],
            )
            ->willReturn($fluent);

        $this->callApplyClassification($dibi, [
            'analysis_json' => [
                'overall_confidence' => 0.95,
                'message_classification' => ['primary_type' => 'invoiceReceived', 'confidence' => 0.98],
                'documents' => [],
            ],
        ]);
    }

    public function testClassificationPrefersTopLevelFieldOverAnalysisJson(): void
    {
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->method('execute');

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->once())
            ->method('update')
            ->with(
                'core_mail_incoming_messages',
                ['primary_type' => 'other', 'primary_type_source' => 'ai', ...self::NO_ATTENTION],
            )
            ->willReturn($fluent);

        $this->callApplyClassification($dibi, [
            'message_classification' => ['primary_type' => 'other', 'confidence' => 0.9],
            'analysis_json' => [
                'message_classification' => ['primary_type' => 'invoiceReceived', 'confidence' => 0.98],
            ],
        ]);
    }

    public function testClassificationIgnoresUnknownPrimaryType(): void
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->never())->method('update');

        $this->callApplyClassification($dibi, [
            'message_classification' => ['primary_type' => 'spam', 'confidence' => 0.9],
        ]);
    }

    public function testClassificationSkipsWhenFieldMissing(): void
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->never())->method('update');

        $this->callApplyClassification($dibi, ['model_name' => 'claude']);
    }

    // -------------------------------------------------------------------
    // Pozornost u zprávy bez dokladu (tasks/mail-other-attention.md D3)
    // -------------------------------------------------------------------

    /**
     * Dibi mock zachycující data jediného UPDATE klasifikace.
     *
     * @param array<string, mixed>|null $captured
     */
    private function capturingDibi(?array &$captured): \Dibi\Connection
    {
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->expects($this->once())->method('execute');

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->once())->method('update')->willReturnCallback(
            function (string $table, array $data) use (&$captured, $fluent): \Dibi\Fluent {
                $this->assertSame('core_mail_incoming_messages', $table);
                $captured = $data;
                return $fluent;
            },
        );
        return $dibi;
    }

    public function testClassificationOtherActionWritesAttentionNoteAndDue(): void
    {
        $captured = null;
        $this->callApplyClassification($this->capturingDibi($captured), [
            'message_classification' => [
                'primary_type' => 'other',
                'confidence' => 0.9,
                'attention' => 'action',
                'action_note' => "  Prodloužit 3 domény,\n jinak 15. 10. expirují.  ",
                'due_date' => '2026-10-15',
            ],
        ]);

        $this->assertSame([
            'primary_type' => 'other',
            'primary_type_source' => 'ai',
            'attention' => 'action',
            'action_note' => 'Prodloužit 3 domény, jinak 15. 10. expirují.',
            'action_due' => '2026-10-15',
        ], $captured);
    }

    public function testClassificationActionNoteIsTruncatedToColumnLength(): void
    {
        $captured = null;
        $this->callApplyClassification($this->capturingDibi($captured), [
            'message_classification' => [
                'primary_type' => 'other',
                'attention' => 'action',
                'action_note' => str_repeat('ž', 250),
            ],
        ]);

        $this->assertSame(200, mb_strlen((string) $captured['action_note']));
        $this->assertNull($captured['action_due']);
    }

    public function testClassificationInfoDropsNoteAndDueEvenWhenSent(): void
    {
        $captured = null;
        $this->callApplyClassification($this->capturingDibi($captured), [
            'message_classification' => [
                'primary_type' => 'other',
                'attention' => 'info',
                'action_note' => 'Nic nedělat',
                'due_date' => '2026-10-15',
            ],
        ]);

        $this->assertSame('info', $captured['attention']);
        $this->assertNull($captured['action_note']);
        $this->assertNull($captured['action_due']);
    }

    public function testClassificationUnknownAttentionLeavesFieldsNullButStoresType(): void
    {
        // Neznámá hodnota = warning + ignore, uložení resultu se nerozbije
        // (stejně jako neznámý primary_type) — typ se zapíše, pozornost NULL.
        $captured = null;
        $this->callApplyClassification($this->capturingDibi($captured), [
            'message_classification' => [
                'primary_type' => 'other',
                'attention' => 'urgent',
                'action_note' => 'Zaplatit',
                'due_date' => '2026-10-15',
            ],
        ]);

        $this->assertSame(['primary_type' => 'other', 'primary_type_source' => 'ai', ...self::NO_ATTENTION], $captured);
    }

    public function testClassificationDocumentTypeClearsAttentionFields(): void
    {
        // Zpráva mohla být dřív `other` s poznámkou „Zaplatit“ — reanalýzou
        // se stala fakturou, tři pole musí být NULL i když je model poslal.
        $captured = null;
        $this->callApplyClassification($this->capturingDibi($captured), [
            'message_classification' => [
                'primary_type' => 'invoiceReceived',
                'attention' => 'action',
                'action_note' => 'Zaplatit',
                'due_date' => '2026-10-15',
            ],
        ]);

        $this->assertSame(['primary_type' => 'invoiceReceived', 'primary_type_source' => 'ai', ...self::NO_ATTENTION], $captured);
    }

    public function testClassificationActionWithNullNoteDueAndPartyStoresNull(): void
    {
        // Oprava v4.7.1: model absenci vyjadřuje nullem (schéma ho od v4.7.1
        // připouští) — server uloží NULL bez warningu a typ i pozornost zapíše.
        $captured = null;
        $this->callApplyClassification($this->capturingDibi($captured), [
            'message_classification' => [
                'primary_type' => 'other',
                'confidence' => 0.9,
                'attention' => 'action',
                'action_note' => null,
                'due_date' => null,
                'party' => null,
            ],
        ]);

        $this->assertSame([
            'primary_type' => 'other',
            'primary_type_source' => 'ai',
            'attention' => 'action',
            'action_note' => null,
            'action_due' => null,
        ], $captured);
    }

    public function testClassificationInvalidDueDateYieldsNull(): void
    {
        foreach (['15. 10. 2026', '2026-02-30', '2026-10-15T00:00:00', '', 20261015] as $invalid) {
            $captured = null;
            $this->callApplyClassification($this->capturingDibi($captured), [
                'message_classification' => [
                    'primary_type' => 'other',
                    'attention' => 'action',
                    'action_note' => 'Zaplatit',
                    'due_date' => $invalid,
                ],
            ]);
            $this->assertNull($captured['action_due'], 'due_date ' . json_encode($invalid));
            $this->assertSame('Zaplatit', $captured['action_note']);
        }
    }

    // -------------------------------------------------------------------
    // ai_title (tasks/mail-message-title-partner.md D1/D2) — stejný vzor
    // jako klasifikace.
    // -------------------------------------------------------------------

    /**
     * @param array<string, mixed>      $body
     * @param array<string, mixed>|null $canonical
     */
    private function callApplyTitle(\Dibi\Connection $dibi, array $body, ?array $canonical, ?string $proposedType): void
    {
        // configRuntime = null → composer bez labelu typu
        $this->writer($this->createMock(DataSourceConnection::class))
            ->applyMessageTitle($dibi, 42, $body, $canonical, $proposedType);
    }

    /** Dibi mock očekávající právě jeden UPDATE ai_title s danou hodnotou. */
    private function dibiExpectingTitle(?string $expected): \Dibi\Connection
    {
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->expects($this->once())->method('execute');

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->once())
            ->method('update')
            ->with('core_mail_incoming_messages', ['ai_title' => $expected])
            ->willReturn($fluent);
        return $dibi;
    }

    /** @return array<string, mixed> */
    private function titleCanonical(): array
    {
        return [
            'docType' => 'invoiceReceived',
            'docNumber' => 'FV-2026-0042',
            'selfParty' => 'customer',
            'supplier' => ['name' => 'Dodavatel s.r.o.', 'companyId' => '12345678'],
            'currency' => 'CZK',
            'totals' => ['totalAmount' => 13105.0],
        ];
    }

    public function testTitleFromClassificationWins(): void
    {
        $this->callApplyTitle(
            $this->dibiExpectingTitle('Faktura 2026-0042 — Dodavatel s.r.o., 13 105 Kč'),
            ['message_classification' => [
                'primary_type' => 'invoiceReceived',
                'confidence' => 0.97,
                'title' => "  Faktura 2026-0042 — Dodavatel s.r.o.,\n 13 105 Kč ",
            ]],
            $this->titleCanonical(),
            'invoiceReceived',
        );
    }

    public function testTitleFallsBackToComposerWhenMissing(): void
    {
        // Starší prompt (v4.2.0) bez title → deterministický fallback
        // z canonicalu (P8), žádná 422.
        $this->callApplyTitle(
            $this->dibiExpectingTitle('FV-2026-0042 — Dodavatel s.r.o., 13 105 CZK'),
            ['message_classification' => ['primary_type' => 'invoiceReceived', 'confidence' => 0.97]],
            $this->titleCanonical(),
            'invoiceReceived',
        );
    }

    public function testTitleReadsAnalysisJsonFallback(): void
    {
        $this->callApplyTitle(
            $this->dibiExpectingTitle('Newsletter — obchodní sdělení'),
            ['analysis_json' => ['message_classification' => ['primary_type' => 'other', 'title' => 'Newsletter — obchodní sdělení']]],
            null,
            null,
        );
    }

    public function testTitleIsNulledWhenNothingAvailable(): void
    {
        // Re-analýza bez dokumentu a bez title → ai_title = NULL (AI-vlastněný sloupec).
        $this->callApplyTitle(
            $this->dibiExpectingTitle(null),
            ['message_classification' => ['primary_type' => 'other', 'confidence' => 0.9, 'title' => '   ']],
            null,
            null,
        );
    }

    public function testTitleIsTruncatedToColumnLength(): void
    {
        $long = str_repeat('x', 250);
        $this->callApplyTitle(
            $this->dibiExpectingTitle(str_repeat('x', 200)),
            ['message_classification' => ['primary_type' => 'other', 'title' => $long]],
            null,
            null,
        );
    }

    public function testKnownPrimaryTypesFallbackWithoutConfig(): void
    {
        // configRuntime = null → fallback seznam z PrimaryTypes
        $types = $this->writer($this->createMock(DataSourceConnection::class))->knownPrimaryTypes();

        $this->assertContains('invoiceReceived', $types);
        $this->assertContains('other', $types);
        $this->assertContains('creditNote', $types); // enabled:false typy se tolerují
    }


    // ── validateCanonical() ─────────────────────────────────────────────────
    //
    // storeResult() potřebuje stav tabulek message_analyses + claims + …;
    // plnou pipeline kryje AnalysisResultWriterIntegrationTest. Tady samotná
    // validace canonicalu — (?array $extractedJson, string $docType):
    // array{0: ?string, 1: bool}. Writer tu má reálný SchemaValidator.

    /**
     * @return array{0: ?string, 1: bool}
     */
    private function callValidate(
        AnalysisResultWriter $writer,
        ?array $canonical,
        string $docType = 'invoiceReceived',
    ): array {
        return $writer->validateCanonical($canonical, $docType);
    }

    public function testValidateCanonicalKeepsValidPayload(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $writer = $this->validatingWriter($db);
        $canonical = $this->happyCanonical();

        [$json, $valid] = $this->callValidate($writer, $canonical);

        $this->assertTrue($valid);
        $decoded = json_decode((string) $json, true);
        $this->assertSame('shpd.docs.document', $decoded['format']);
        $this->assertArrayNotHasKey('_validationError', $decoded);
    }

    public function testValidateCanonicalWrapsInvalidPayload(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $writer = $this->validatingWriter($db);

        // Missing required `format` field — schema rejects.
        $broken = [
            'formatVersion' => '1.0',
            'docType' => 'invoiceReceived',
        ];

        [$json, $valid] = $this->callValidate($writer, $broken);

        $this->assertFalse($valid);
        $decoded = json_decode((string) $json, true);
        $this->assertSame('Canonical schema validation failed', $decoded['_validationError']);
        $this->assertIsArray($decoded['_validationIssues']);
        $this->assertNotEmpty($decoded['_validationIssues']);
        $this->assertSame($broken, $decoded['_rawOutput']);
    }

    public function testValidateCanonicalNullPayload(): void
    {
        // Běh bez dokumentu → nic se neukládá, dokument nevalidní.
        $db = $this->createMock(DataSourceConnection::class);
        $writer = $this->validatingWriter($db);

        [$json, $valid] = $this->callValidate($writer, null);

        $this->assertNull($json);
        $this->assertFalse($valid);
    }

    public function testValidateCanonicalWithoutSchemaValidatorPassesThrough(): void
    {
        // No SchemaValidator wired (unit testy) — payload projde beze změn
        // a považuje se za validní.
        $db = $this->createMock(DataSourceConnection::class);
        $writer = new AnalysisResultWriter($db, $this->config);

        [$json, $valid] = $this->callValidate($writer, ['anything' => 'goes']);

        $this->assertTrue($valid);
        $decoded = json_decode((string) $json, true);
        $this->assertSame(['anything' => 'goes'], $decoded);
    }

    public function testValidateCanonicalStampsServerExtractedAt(): void
    {
        // D12: source.extractedAt od modelu je nedůvěryhodný (typicky opsaný
        // z ukázky v promptu) — server ho nepodmíněně přepíše vlastním časem.
        $db = $this->createMock(DataSourceConnection::class);
        $writer = $this->validatingWriter($db);
        $canonical = $this->happyCanonical();
        $canonical['source']['extractedAt'] = '2025-01-09T10:30:00Z';

        [$json, $valid] = $this->callValidate($writer, $canonical);

        $this->assertTrue($valid);
        $decoded = json_decode((string) $json, true);
        $stamped = $decoded['source']['extractedAt'];
        $this->assertNotSame('2025-01-09T10:30:00Z', $stamped);
        $parsed = \DateTimeImmutable::createFromFormat(DATE_ATOM, $stamped);
        $this->assertInstanceOf(\DateTimeImmutable::class, $parsed);
        $this->assertEqualsWithDelta(time(), $parsed->getTimestamp(), 120);
    }

    // ── validateCanonical + row history enrichment ─────────────────

    public function testValidateCanonicalPersistsEnrichedCanonical(): void
    {
        // Historie pokryje jediný řádek fixtury (description fallback
        // item.description) → do canonical_json se uloží obohacený canonical.
        $db = $this->createMock(DataSourceConnection::class);
        $writer = $this->validatingWriter($db, enricher: $this->enricher([[
            'description'    => 'Hodinová sazba senior konzultanta',
            'vat_code'       => 'cz-110',
            'item_code'      => 'KONZ01',
            'account_number' => '518100',
            'doc_head'       => 777,
        ]]));

        [$json, $valid] = $this->callValidate($writer, $this->happyCanonical());

        $this->assertTrue($valid);
        $decoded = json_decode((string) $json, true);
        $this->assertSame('KONZ01', $decoded['rows'][0]['item']['ourCode']);
        $this->assertSame('518100', $decoded['rows'][0]['account']);
        $enrichment = $decoded['_resolve']['rows'][0]['enrichment'];
        $this->assertSame('historyExactRaw', $enrichment['matchedBy']);
        $this->assertSame(777, $enrichment['sourceDocId']);
    }

    public function testValidateCanonicalSurvivesEnricherFailure(): void
    {
        // Enricher spadne na DB → zápis výsledku nesmí selhat; canonical se uloží
        // neobohacený a zůstává validní (pásma řeší runtime resolver).
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetchAll')->willThrowException(new \RuntimeException('db down'));
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));

        $db = $this->createMock(DataSourceConnection::class);
        $writer = $this->validatingWriter($db, enricher: new RowEnrichmentPipeline(
            new RowHistoryEnricher($dibi, $party),
            new ContentTagResolver($dibi),
        ));

        [$json, $valid] = $this->callValidate($writer, $this->happyCanonical());

        $this->assertTrue($valid);
        $decoded = json_decode((string) $json, true);
        $this->assertArrayNotHasKey('_resolve', $decoded);
        $this->assertNull($decoded['rows'][0]['item']['ourCode']);
    }

    // ── validateCanonical — registry target ─────────────────────────
    //
    // Registry canonical se validuje proti shpd.registry.document.v1
    // (schéma base.registry, target dle PrimaryTypes) a přeskakuje enrichment.

    private function registryCanonical(): array
    {
        return [
            'schema'  => 'shpd.registry.document.v1',
            'docType' => 'insurance',
            'title'   => 'Pojistná smlouva — flotila',
            'summary' => 'Pojištění vozového parku.',
            'party'   => ['name' => 'Pojišťovna ABC', 'companyId' => '12345678', 'email' => 'info@abc.cz'],
            'kindFields' => [
                'insurer'      => 'Pojišťovna ABC',
                'policyNumber' => 'POJ-1',
                'validTo'      => '2026-12-31',
            ],
            'binderSuggestion' => 'Pojištění',
        ];
    }

    public function testValidateRegistryCanonicalKeepsValidPayload(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $writer = $this->validatingWriter($db, configRuntime: $this->configRuntimeWithRegistryTargets());
        $canonical = $this->registryCanonical();

        [$json, $valid] = $this->callValidate($writer, $canonical, docType: 'insurance');

        $this->assertTrue($valid);
        $this->assertSame($canonical, json_decode((string) $json, true)); // beze změn — žádný enrichment
    }

    public function testValidateRegistryCanonicalForeignKindFieldWrapsAiFailed(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $writer = $this->validatingWriter($db, configRuntime: $this->configRuntimeWithRegistryTargets());
        $canonical = $this->registryCanonical();
        // additionalProperties: false — přejmenované pole nesmí tiše projít
        $canonical['kindFields']['policy_number'] = 'POJ-1';

        [$json, $valid] = $this->callValidate($writer, $canonical, docType: 'insurance');

        $this->assertFalse($valid);
        $decoded = json_decode((string) $json, true);
        $this->assertArrayHasKey('_validationError', $decoded);
        $this->assertSame($canonical, $decoded['_rawOutput']);
    }

    public function testValidateRegistryCanonicalUnknownDocTypeWrapsAiFailed(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $writer = $this->validatingWriter($db, configRuntime: $this->configRuntimeWithRegistryTargets());
        $canonical = $this->registryCanonical();
        $canonical['docType'] = 'somethingElse';

        [, $valid] = $this->callValidate($writer, $canonical, docType: 'insurance');

        $this->assertFalse($valid);
    }

    public function testValidateRegistryCanonicalDoesNotStampExtractedAt(): void
    {
        // Registry schéma pole source nezná (additionalProperties: false) —
        // razítko D12 se v registry větvi nepropisuje ani do forenzního
        // _rawOutput.
        $db = $this->createMock(DataSourceConnection::class);
        $writer = $this->validatingWriter($db, configRuntime: $this->configRuntimeWithRegistryTargets());
        $canonical = $this->registryCanonical();
        $canonical['source'] = ['extractedAt' => '2025-01-09T10:30:00Z'];

        [$json, $valid] = $this->callValidate($writer, $canonical, docType: 'insurance');

        $this->assertFalse($valid);
        $decoded = json_decode((string) $json, true);
        $this->assertSame($canonical, $decoded['_rawOutput']);
    }

    public function testValidateDocsCanonicalUnaffectedByRegistryConfig(): void
    {
        // docs typ jde dál docs větví i s configem, který registry typy zná
        $db = $this->createMock(DataSourceConnection::class);
        $writer = $this->validatingWriter($db, configRuntime: $this->configRuntimeWithRegistryTargets());
        $canonical = $this->happyCanonical();

        [, $valid] = $this->callValidate($writer, $canonical, docType: 'invoiceReceived');

        $this->assertTrue($valid);
    }
}
