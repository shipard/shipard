<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\AnalysisController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Enrich\ContentTagResolver;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Unit testy AnalysisController::previewMessage — read-only náhled
 * dokumentového návrhu poslední úspěšné analýzy zprávy (GET). Vrací
 * obohacený canonical s `_resolve` pro split-view modal. Větve: otevřený /
 * vyřešený návrh, ai_failed wrapper, corrupted, 404 (message / NO_ANALYSIS /
 * NO_PROPOSAL), registry target, fallback bez applieru, přílohy zprávy.
 */
class AnalysisControllerPreviewMessageTest extends TestCase
{
    private string $tmpDir;
    private DataSourceConfig $config;

    protected function setUp(): void
    {
        DsSecretCipher::resetCache();
        $this->tmpDir = sys_get_temp_dir() . '/shpd_aprev_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir . '/config', 0700, true);
        file_put_contents($this->tmpDir . '/config/main.json', json_encode([
            'id' => 'test-test-test-test',
            'name' => 'Test',
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
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
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

    private function controller(
        DataSourceConnection $db,
        ?DocumentApplier $applier = null,
        ?RowEnrichmentPipeline $enricher = null,
        ?ConfigRuntime $configRuntime = null,
    ): AnalysisController {
        return new AnalysisController(
            $db, $this->config, $this->tmpDir, [],
            new DocumentRegistry(),
            new SchemaValidator(SchemaLoader::default()),
            $applier,
            $configRuntime, null,
            $enricher,
        );
    }

    private function authed(int $userId = 7): AuthContext
    {
        return new AuthContext(true, $userId, 'tester');
    }

    private function request(): Request
    {
        // Preview je read-only GET (routing v resolveMailMessagesRoute).
        return Request::fromArray('GET', '/x', [], '', ['HTTP_HOST' => 'test']);
    }

    private function statusOf(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return (int) $ref->getProperty('status')->getValue($response);
    }

    private function happyCanonical(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
    }

    /**
     * DB mock: fetchRow routuje podle názvu tabulky (první %n argument) na
     * řádek zprávy / poslední úspěšné analýzy; fetchAll vrací přílohy.
     *
     * @param list<array<string, mixed>> $attachments
     */
    private function db(?array $message, ?array $analysis, array $attachments = []): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static fn(string $sql, ...$args) => match ($args[0] ?? null) {
                'core_mail_incoming_messages' => $message,
                'core_mail_message_analyses'  => $analysis,
                default                       => null,
            },
        );
        $db->method('fetchAll')->willReturn($attachments);
        return $db;
    }

    /**
     * `$extra` přidává sloupce zprávy, které výchozí řádek nemá (kód,
     * odesílatel, datum přijetí) — default bez nich simuluje starší řádek,
     * blok `message` je musí číst přes `?? null`.
     *
     * @return array<string, mixed>
     */
    private function message(int $ndx = 100, ?int $rawSource = null, array $extra = []): array
    {
        return array_merge([
            'id' => $ndx, 'docState' => 20, 'analysis_state' => 30,
            'target_row' => null, 'raw_source_attachment' => $rawSource,
        ], $extra);
    }

    /** @return array<string, mixed> */
    private function sourceMessageColumns(array $overrides = []): array
    {
        return array_merge([
            'message_id'   => 'MSG-20260905-0012',
            'sender_name'  => 'Dodavatel s.r.o.',
            'sender_email' => 'fakturace@example.test',
            'received_at'  => '2026-09-05 14:32:10',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function analysis(
        ?string $canonicalJson,
        string $proposedType = 'invoiceReceived',
        ?int $resolution = null,
        ?float $confidence = 0.9,
        array $extra = [],
    ): array {
        // `$extra` přidává sloupce, které výchozí řádek nemá (např.
        // `user_actions_json` — default bez klíče simuluje DS před ds-upgrade).
        return array_merge([
            'id' => 11, 'canonical_json' => $canonicalJson, 'proposed_type' => $proposedType,
            'resolution' => $resolution, 'confidence' => $confidence,
        ], $extra);
    }

    public function testPreviewMessageReturns401WhenUnauthenticated(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $resp = $ctrl->previewMessage(
            new AuthContext(false, null, null),
            $this->request(),
            1,
        );
        $this->assertSame(401, $this->statusOf($resp));
    }

    public function testPreviewMessageReturns404WhenMessageMissing(): void
    {
        $db = $this->db(null, null);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('preview');

        $resp = $this->controller($db, $applier)->previewMessage($this->authed(), $this->request(), 999);
        $this->assertSame(404, $this->statusOf($resp));
        $this->assertSame('NOT_FOUND', $resp->getPayload()['error']['code']);
    }

    public function testPreviewMessageReturns404WhenNoSuccessfulAnalysis(): void
    {
        $db = $this->db($this->message(), null);

        $resp = $this->controller($db)->previewMessage($this->authed(), $this->request(), 100);
        $this->assertSame(404, $this->statusOf($resp));
        $this->assertSame('NO_ANALYSIS', $resp->getPayload()['error']['code']);
    }

    public function testPreviewMessageReturns404WhenNoProposal(): void
    {
        // Poslední úspěšná analýza bez dokumentového návrhu (canonical NULL).
        $db = $this->db($this->message(), $this->analysis(null));

        $resp = $this->controller($db)->previewMessage($this->authed(), $this->request(), 100);
        $this->assertSame(404, $this->statusOf($resp));
        $this->assertSame('NO_PROPOSAL', $resp->getPayload()['error']['code']);
    }

    public function testPreviewMessageReturns500OnCorruptedJson(): void
    {
        $db = $this->db($this->message(), $this->analysis('not-valid-json'));

        $resp = $this->controller($db)->previewMessage($this->authed(), $this->request(), 100);
        $this->assertSame(500, $this->statusOf($resp));
        $this->assertSame('CORRUPTED_DATA', $resp->getPayload()['error']['code']);
    }

    public function testPreviewMessageReturnsWrapperForAiFailed(): void
    {
        $wrapper = [
            '_validationError' => 'Canonical schema validation failed',
            '_validationIssues' => [
                ['severity' => 'error', 'path' => 'format', 'code' => 'required', 'message' => 'Missing'],
            ],
            '_rawOutput' => ['garbage' => true],
        ];
        $db = $this->db($this->message(200), $this->analysis((string) json_encode($wrapper)));

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('preview');

        $resp = $this->controller($db, $applier)->previewMessage($this->authed(), $this->request(), 200);
        $this->assertSame(200, $this->statusOf($resp));
        $data = $resp->getPayload()['data'];
        $this->assertTrue($data['aiFailed']);
        $this->assertSame($wrapper, $data['wrapper']);
        $this->assertSame(200, $data['messageNdx']);
        $this->assertSame(11, $data['analysisNdx']);
    }

    public function testPreviewMessageDelegatesToApplierForOpenProposal(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->db($this->message(100), $this->analysis((string) json_encode($canonical)));

        $enriched = $canonical;
        $enriched['_resolve'] = ['summary' => ['status' => 'ok'], 'issues' => []];

        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())
            ->method('preview')
            ->willReturnCallback(function (array $c) use (&$captured, $enriched) {
                $captured = $c;
                return ApplyResult::ok($enriched);
            });

        $resp = $this->controller($db, $applier)->previewMessage($this->authed(), $this->request(), 100);
        $this->assertSame(200, $this->statusOf($resp));

        // Server-controlled injection happened (source.message, ne extractedDoc)
        $this->assertSame(100, $captured['source']['message']);
        $this->assertSame('aiExtraction', $captured['source']['kind']);
        $this->assertSame('safe', $captured['applyOptions']['autoCreateMode']);
        $this->assertSame(10, $captured['applyOptions']['targetDocState']);

        $data = $resp->getPayload()['data'];
        $this->assertFalse($data['aiFailed']);
        $this->assertSame('ok', $data['canonical']['_resolve']['summary']['status']);
        $this->assertSame('invoiceReceived', $data['proposedType']);
        $this->assertSame(0.9, $data['confidence']);
        $this->assertNull($data['resolution']);
    }

    public function testPreviewMessageRunsFreshEnrichmentBeforePreview(): void
    {
        // Fresh enrichment běží po injection source a před preview — applier
        // už dostane canonical s doplněným řádkem z historie.
        $canonical = $this->happyCanonical();
        $db = $this->db($this->message(100), $this->analysis((string) json_encode($canonical)));

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
        $applier->expects($this->once())
            ->method('preview')
            ->willReturnCallback(function (array $c) use (&$captured) {
                $captured = $c;
                return ApplyResult::ok($c);
            });

        $resp = $this->controller($db, $applier, $enricher)
            ->previewMessage($this->authed(), $this->request(), 100);
        $this->assertSame(200, $this->statusOf($resp));

        $this->assertSame('KONZ01', $captured['rows'][0]['item']['ourCode']);
        $this->assertSame(
            'historyExactRaw',
            $captured['_resolve']['rows'][0]['enrichment']['matchedBy'],
        );
    }

    public function testPreviewMessageAlsoWorksForResolvedProposal(): void
    {
        // I zamítnutý návrh (resolution=50) má preview — čistě informativní;
        // verdikt jde klientovi v `resolution`.
        $canonical = $this->happyCanonical();
        $db = $this->db(
            $this->message(100),
            $this->analysis((string) json_encode($canonical), resolution: 50),
        );

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())->method('preview')->willReturn(ApplyResult::ok($canonical));

        $resp = $this->controller($db, $applier)->previewMessage($this->authed(), $this->request(), 100);
        $this->assertSame(200, $this->statusOf($resp));
        $this->assertSame(50, $resp->getPayload()['data']['resolution']);
    }

    public function testPreviewMessageFallsBackWithoutApplier(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->db($this->message(100), $this->analysis((string) json_encode($canonical)));

        // applier param omitted → null
        $ctrl = $this->controller($db, null);
        $resp = $ctrl->previewMessage($this->authed(), $this->request(), 100);

        $this->assertSame(200, $this->statusOf($resp));
        $data = $resp->getPayload()['data'];
        $this->assertFalse($data['aiFailed']);
        // Raw canonical without _resolve when applier is missing
        $this->assertSame($canonical['docType'], $data['canonical']['docType']);
        $this->assertArrayNotHasKey('_resolve', $data['canonical']);
    }

    public function testPreviewMessageReturnsStoredUserActions(): void
    {
        // Rozhodnutí z review (#76) — dekódovaná z `user_actions_json` po
        // sanitizaci (neznámá cesta z ručně poškozeného sloupce zmizí).
        $canonical = $this->happyCanonical();
        $db = $this->db($this->message(100), $this->analysis(
            (string) json_encode($canonical),
            extra: ['user_actions_json' => '{"supplier":"useExisting:42","rows[0].item":"skip","bogus":"x"}'],
        ));

        $resp = $this->controller($db, null)->previewMessage($this->authed(), $this->request(), 100);
        $this->assertSame(200, $this->statusOf($resp));
        $this->assertSame(
            ['supplier' => 'useExisting:42', 'rows[0].item' => 'skip'],
            $resp->getPayload()['data']['userActions'],
        );
    }

    public function testPreviewMessageReturnsEmptyUserActionsObjectWithoutColumn(): void
    {
        // Sloupec chybí (DS před ds-upgrade) nebo je NULL → `{}` (objekt, ne
        // `[]`) — frontend dělá `?? {}` + Object.keys. Klíč je v `$base`,
        // tedy i v ai_failed větvi.
        $canonical = $this->happyCanonical();
        $db = $this->db($this->message(100), $this->analysis((string) json_encode($canonical)));
        $data = $this->controller($db, null)->previewMessage($this->authed(), $this->request(), 100)
            ->getPayload()['data'];
        $this->assertInstanceOf(\stdClass::class, $data['userActions']);
        $this->assertSame('{}', json_encode($data['userActions']));

        $wrapper = ['_validationError' => 'schema', '_validationIssues' => [], '_rawOutput' => 'x'];
        $db = $this->db($this->message(100), $this->analysis(
            (string) json_encode($wrapper),
            extra: ['user_actions_json' => null],
        ));
        $data = $this->controller($db, null)->previewMessage($this->authed(), $this->request(), 100)
            ->getPayload()['data'];
        $this->assertTrue($data['aiFailed']);
        $this->assertSame('{}', json_encode($data['userActions']));
    }

    public function testPreviewMessageReturnsRegistryCanonicalWithTarget(): void
    {
        // Registry target: canonical passthrough + klíč target pro FE branch;
        // applier->preview se nevolá (docs-specifikum).
        $configRuntime = $this->createMock(ConfigRuntime::class);
        $configRuntime->method('cfgItem')->willReturnMap([
            ['core.mail.primaryTypes', [
                'insurance' => ['target' => 'registry', 'docKind' => 'insurance'],
            ]],
        ]);

        $canonical = ['schema' => 'shpd.registry.document.v1', 'docType' => 'insurance', 'title' => 'Pojistka'];
        $db = $this->db(
            $this->message(100),
            $this->analysis((string) json_encode($canonical), proposedType: 'insurance'),
        );

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('preview');

        $resp = $this->controller($db, $applier, configRuntime: $configRuntime)
            ->previewMessage($this->authed(), $this->request(), 100);
        $this->assertSame(200, $this->statusOf($resp));
        $data = $resp->getPayload()['data'];
        $this->assertFalse($data['aiFailed']);
        $this->assertSame('registry', $data['target']);
        $this->assertSame($canonical, $data['canonical']);
    }

    public function testPreviewMessageReturnsAllContentAttachmentsOfMessage(): void
    {
        // Přílohy = všechny obsahové přílohy zprávy (D10) — dotaz na
        // core_attachments_files per zpráva s vyloučením raw .eml.
        $canonical = $this->happyCanonical();
        $message = $this->message(100, rawSource: 5);
        $analysis = $this->analysis((string) json_encode($canonical));

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static fn(string $sql, ...$args) => match ($args[0] ?? null) {
                'core_mail_incoming_messages' => $message,
                'core_mail_message_analyses'  => $analysis,
                default                       => null,
            },
        );
        $db->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->stringContains('ORDER BY att_order'),
                $this->equalTo('core_attachments_files'),
                $this->equalTo(303),  // MAIL_TABLE_ID
                $this->equalTo(100),  // record_id = zpráva
                $this->equalTo(5),    // raw .eml se vylučuje
                $this->equalTo(0),    // is_deleted
            )
            ->willReturn([
                ['id' => 42, 'name' => 'invoice.pdf', 'mime_type' => 'application/pdf', 'file_size' => 12345],
                ['id' => 43, 'name' => 'scan.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 67890],
            ]);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('preview')->willReturn(ApplyResult::ok($canonical));

        $resp = $this->controller($db, $applier)->previewMessage($this->authed(), $this->request(), 100);
        $atts = $resp->getPayload()['data']['attachments'];
        $this->assertCount(2, $atts);
        $this->assertSame(42, $atts[0]['ndx']);
        $this->assertSame('invoice.pdf', $atts[0]['filename']);
        $this->assertSame('application/pdf', $atts[0]['mime_type']);
        $this->assertSame(67890, $atts[1]['size_bytes']);
    }

    // ── Blok `message` — zdrojová zpráva pro hlavičku review modalu
    //    (tasks/mail-source-message-link.md D1, D3, D8) ──

    public function testPreviewMessageCarriesSourceMessageBlockInDocsBranch(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->db(
            $this->message(100, extra: $this->sourceMessageColumns()),
            $this->analysis((string) json_encode($canonical)),
        );
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('preview')->willReturn(ApplyResult::ok($canonical));

        $resp = $this->controller($db, $applier)->previewMessage($this->authed(), $this->request(), 100);
        $this->assertSame([
            'ndx'        => 100,
            'code'       => 'MSG-20260905-0012',
            'codeShort'  => '260905-0012',
            'receivedAt' => '5. 9. 2026 14:32',
            'sender'     => 'Dodavatel s.r.o.',
        ], $resp->getPayload()['data']['message']);
    }

    public function testPreviewMessageCarriesSourceMessageBlockForAiFailed(): void
    {
        $wrapper = ['_validationError' => 'x', '_validationIssues' => [], '_rawOutput' => []];
        $db = $this->db(
            $this->message(200, extra: $this->sourceMessageColumns()),
            $this->analysis((string) json_encode($wrapper)),
        );

        $data = $this->controller($db)->previewMessage($this->authed(), $this->request(), 200)->getPayload()['data'];
        $this->assertTrue($data['aiFailed']);
        $this->assertSame(200, $data['message']['ndx']);
        $this->assertSame('260905-0012', $data['message']['codeShort']);
        $this->assertSame('Dodavatel s.r.o.', $data['message']['sender']);
    }

    public function testPreviewMessageSourceMessageBlockIsPresentWithoutApplier(): void
    {
        $db = $this->db(
            $this->message(100, extra: $this->sourceMessageColumns()),
            $this->analysis((string) json_encode($this->happyCanonical())),
        );

        $data = $this->controller($db)->previewMessage($this->authed(), $this->request(), 100)->getPayload()['data'];
        $this->assertArrayHasKey('canonical', $data);
        $this->assertSame('MSG-20260905-0012', $data['message']['code']);
    }

    public function testPreviewMessageSenderFallsBackToEmailThenNull(): void
    {
        $canonical = $this->happyCanonical();
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('preview')->willReturn(ApplyResult::ok($canonical));
        $analysis = $this->analysis((string) json_encode($canonical));

        $byEmail = $this->db($this->message(100, extra: $this->sourceMessageColumns(['sender_name' => '  '])), $analysis);
        $data = $this->controller($byEmail, $applier)->previewMessage($this->authed(), $this->request(), 100)->getPayload()['data'];
        $this->assertSame('fakturace@example.test', $data['message']['sender']);

        $nobody = $this->db(
            $this->message(100, extra: $this->sourceMessageColumns(['sender_name' => null, 'sender_email' => ''])),
            $analysis,
        );
        $data = $this->controller($nobody, $applier)->previewMessage($this->authed(), $this->request(), 100)->getPayload()['data'];
        $this->assertNull($data['message']['sender']);
    }

    public function testPreviewMessageEmptyCodeAndMissingColumnsGiveEmptyBlock(): void
    {
        // Starší řádek bez sloupců (výchozí fixture) — blok existuje,
        // kód je prázdný řetězec, datum a odesílatel null.
        $canonical = $this->happyCanonical();
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('preview')->willReturn(ApplyResult::ok($canonical));
        $db = $this->db($this->message(100), $this->analysis((string) json_encode($canonical)));

        $data = $this->controller($db, $applier)->previewMessage($this->authed(), $this->request(), 100)->getPayload()['data'];
        $this->assertSame(
            ['ndx' => 100, 'code' => '', 'codeShort' => '', 'receivedAt' => null, 'sender' => null],
            $data['message'],
        );
    }

    public function testPreviewMessageFormatsReceivedAtFromDateTimeObject(): void
    {
        // Dibi může vracet DATETIME jako objekt — formát je stejný.
        $canonical = $this->happyCanonical();
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('preview')->willReturn(ApplyResult::ok($canonical));
        $db = $this->db(
            $this->message(100, extra: $this->sourceMessageColumns([
                'received_at' => new \DateTimeImmutable('2026-01-02 08:05:00'),
            ])),
            $this->analysis((string) json_encode($canonical)),
        );

        $data = $this->controller($db, $applier)->previewMessage($this->authed(), $this->request(), 100)->getPayload()['data'];
        $this->assertSame('2. 1. 2026 08:05', $data['message']['receivedAt']);
    }

    /**
     * Uložená rozhodnutí (#76) jdou do `_resolve` canonicalu před preview
     * (tasks/exchange-preview-vat-choices.md D15) — applier tak počítá
     * s volbou kódu DPH, místa i režimu. Chování applieru nad pinem kryje
     * DocumentApplierVatPinsTest; tady jen přenos. `userActions` v odpovědi
     * zůstává plochá mapa.
     */
    public function testPreviewMessageMergesSavedDecisionsIntoResolveBeforePreview(): void
    {
        $canonical = $this->happyCanonical();
        $saved = ['rows[0].vatCode' => 'useCode:cz-218', 'vat.place' => 'useValue:domestic', 'supplier' => 'useExisting:42'];
        $db = $this->db(
            $this->message(100),
            $this->analysis((string) json_encode($canonical), extra: ['user_actions_json' => json_encode($saved)]),
        );

        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())
            ->method('preview')
            ->willReturnCallback(function (array $c) use (&$captured) {
                $captured = $c;
                return ApplyResult::ok($c);
            });

        $resp = $this->controller($db, $applier)->previewMessage($this->authed(), $this->request(), 100);
        $this->assertSame(200, $this->statusOf($resp));

        $this->assertSame('useCode:cz-218', $captured['_resolve']['rows'][0]['vatCode']['userAction']);
        $this->assertSame('useValue:domestic', $captured['_resolve']['vat']['place']['userAction']);
        $this->assertSame('useExisting:42', $captured['_resolve']['supplier']['userAction']);
        $this->assertSame($saved, $resp->getPayload()['data']['userActions']);
    }

    public function testPreviewMessageWithoutSavedDecisionsSendsNoResolve(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->db($this->message(100), $this->analysis((string) json_encode($canonical)));

        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('preview')->willReturnCallback(function (array $c) use (&$captured) {
            $captured = $c;
            return ApplyResult::ok($c);
        });

        $this->controller($db, $applier)->previewMessage($this->authed(), $this->request(), 100);

        $this->assertArrayNotHasKey('_resolve', $captured);
    }
}
