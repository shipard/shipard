<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\DashboardController;
use Shipard\Api\Response;
use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Dashboard\DashboardSummaryService;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Unit testy pro DashboardController.
 *
 * Pokrývají:
 *   - buildReadySummary (čistá transformace)
 *   - dashboard() — tvar feedu, zapojení zdrojů, `sections` + strop per
 *     sekce bez karty „a další“ (#101), readySummary ze všech ready karet
 *     + `shown` (Issue #32/2, #101 D3b)
 *   - summary() — SSE události (text/done/error), degradace
 *
 * Čisté transformace collectoru (sortAndCap / countByKind /
 * stripInternalFields) žijí ve FeedCollectorTest.
 */
final class DashboardControllerTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        // Per-source izolace loguje výjimky — log do tempu, ne /opt.
        $this->logPath = sys_get_temp_dir() . '/shpd_dashctrl_' . uniqid('', true) . '.log';
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath($this->logPath);
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        @unlink($this->logPath);
    }

    /**
     * Runtime mapa tabulek pro dashboard() — hodnoty jsou minimální
     * TableDefinition, controller dělá jen isset().
     *
     * @return array<string, TableDefinition>
     */
    private function tables(string ...$names): array
    {
        $map = [];
        foreach ($names as $name) {
            $map[$name] = new TableDefinition(
                tableId: 999,
                name: $name,
                displayPattern: null,
                columnGroups: [],
                columns: [],
                indexes: [],
                childTables: [],
                docStates: null,
            );
        }
        return $map;
    }

    /** Mapa běžného DS — mail i alerty aktivní. */
    private function fullTables(): array
    {
        return $this->tables('core_mail_incoming_messages', 'core_alerts_alerts');
    }

    /** @return array<string,mixed> */
    private function card(string $kind, ?string $timestamp, string $id = 'x'): array
    {
        return ['id' => $id, 'kind' => $kind, 'timestamp' => $timestamp];
    }

    // ── buildReadySummary (čistá funkce, Issue #32/2) ────────────────────────

    /** @return array<string,mixed> */
    private function readyCard(string $id, ?float $amount, ?string $currency, ?int $confidencePct): array
    {
        $card = ['id' => $id, 'kind' => 'ready', 'timestamp' => null];
        if ($amount !== null) {
            $card['amount'] = $amount;
        }
        if ($currency !== null) {
            $card['currency'] = $currency;
        }
        if ($confidencePct !== null) {
            $card['confidencePct'] = $confidencePct;
        }
        return $card;
    }

    public function testBuildReadySummaryAggregatesPerCurrency(): void
    {
        // Karty bez category padají defenzivně do skupiny invoices.
        $ctrl = new DashboardController();
        $summary = $ctrl->buildReadySummary([
            $this->readyCard('a', 1000.50, 'CZK', 95),
            $this->readyCard('b', 2000.00, 'CZK', 91),
            $this->readyCard('c', 120.00, 'EUR', 98),
            $this->card('review', null, 'r'),   // jiné pásmo — ignoruje se
            $this->card('info', null, 'i'),
        ]);

        $this->assertNotNull($summary);
        $this->assertArrayNotHasKey('registry', $summary);
        $this->assertSame(3, $summary['invoices']['count']);
        // Per měna, nikdy napříč měnami.
        $this->assertSame(
            [['currency' => 'CZK', 'total' => 3000.50], ['currency' => 'EUR', 'total' => 120.00]],
            $summary['invoices']['amounts'],
        );
        $this->assertSame(91, $summary['invoices']['confidenceMin']);
        $this->assertSame(98, $summary['invoices']['confidenceMax']);
    }

    public function testBuildReadySummaryCountsCardsWithoutAmount(): void
    {
        // Karta bez částky (totals.totalAmount chybělo): do count ano,
        // do amounts ne. Bez měny totéž — nelze zařadit do skupiny.
        $ctrl = new DashboardController();
        $summary = $ctrl->buildReadySummary([
            $this->readyCard('a', 500.00, 'CZK', 92),
            $this->readyCard('b', null, null, 96),
            $this->readyCard('c', 300.00, null, null),
        ]);

        $this->assertNotNull($summary);
        $this->assertSame(3, $summary['invoices']['count']);
        $this->assertSame([['currency' => 'CZK', 'total' => 500.00]], $summary['invoices']['amounts']);
        $this->assertSame(92, $summary['invoices']['confidenceMin']);
        $this->assertSame(96, $summary['invoices']['confidenceMax']);
    }

    public function testBuildReadySummaryGroupsRegistrySeparately(): void
    {
        // Ready karty do Spisovny (D11) tvoří vlastní skupinu — nemíchají
        // se do počtu ani jistot faktur; částky nenesou (amounts prázdné).
        $ctrl = new DashboardController();
        $summary = $ctrl->buildReadySummary([
            $this->readyCard('a', 500.00, 'CZK', 95),
            [...$this->readyCard('r1', null, null, 90), 'category' => 'registry'],
            [...$this->readyCard('r2', null, null, 97), 'category' => 'registry'],
        ]);

        $this->assertNotNull($summary);
        $this->assertSame(1, $summary['invoices']['count']);
        $this->assertSame(95, $summary['invoices']['confidenceMin']);
        $this->assertSame(2, $summary['registry']['count']);
        $this->assertSame([], $summary['registry']['amounts']);
        $this->assertSame(90, $summary['registry']['confidenceMin']);
        $this->assertSame(97, $summary['registry']['confidenceMax']);

        // Jen registry karty → skupina invoices chybí.
        $onlyRegistry = $ctrl->buildReadySummary([
            [...$this->readyCard('r1', null, null, 90), 'category' => 'registry'],
        ]);
        $this->assertArrayNotHasKey('invoices', $onlyRegistry);
        $this->assertSame(1, $onlyRegistry['registry']['count']);
    }

    public function testBuildReadySummaryShownCountsOnlyCardsUnderCap(): void
    {
        // `count`/`amounts`/jistoty ze všech karet (allCards), `shown` jen
        // z karet po stropu (cards) — per skupina. Bez druhého argumentu
        // shown = count.
        $ctrl = new DashboardController();
        $all = [
            $this->readyCard('a', 100.00, 'CZK', 95),
            $this->readyCard('b', 200.00, 'CZK', 91),
            $this->readyCard('c', 300.00, 'CZK', 98),
            [...$this->readyCard('r1', null, null, 90), 'category' => 'registry'],
        ];
        $summary = $ctrl->buildReadySummary($all, [$all[0], $all[3]]);

        $this->assertSame(3, $summary['invoices']['count']);
        $this->assertSame(1, $summary['invoices']['shown']);
        $this->assertSame([['currency' => 'CZK', 'total' => 600.00]], $summary['invoices']['amounts']);
        $this->assertSame(91, $summary['invoices']['confidenceMin']);
        $this->assertSame(1, $summary['registry']['count']);
        $this->assertSame(1, $summary['registry']['shown']);

        $this->assertSame(3, $ctrl->buildReadySummary($all)['invoices']['shown']);
    }

    public function testBuildReadySummaryNullWithoutReadyCards(): void
    {
        $ctrl = new DashboardController();
        $this->assertNull($ctrl->buildReadySummary([]));
        $this->assertNull($ctrl->buildReadySummary([
            $this->card('urgent', null, 'u'),
            $this->card('review', null, 'v'),
            $this->card('info', null, 'i'),
        ]));
    }

    // ── dashboard() feed tvar ────────────────────────────────────────────────

    public function testDashboardEmptyFeedShape(): void
    {
        // DB mock vracející prázdné sady → žádné karty.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);

        $ctrl = new DashboardController();
        $response = $ctrl->dashboard($db, null, 'cs', null, $this->fullTables());

        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        $data = $payload['data'];

        $this->assertArrayHasKey('generatedAt', $data);
        $this->assertNull($data['summary']['aiText']);
        $this->assertSame(['urgent' => 0, 'review' => 0, 'ready' => 0], $data['summary']['counts']);
        $this->assertSame([], $data['sections']);
        $this->assertSame([], $data['cards']);
        $this->assertArrayNotHasKey('readySummary', $data);
        $this->assertArrayNotHasKey('tasks', $data);
        $this->assertArrayNotHasKey('widgets', $data);
    }

    /**
     * Řádek návrhové karty MailSuggestionsSource — zpráva s otevřeným
     * dokumentovým návrhem poslední úspěšné analýzy (message-centricky,
     * canonical na řádku analýzy).
     *
     * @return array<string,mixed>
     */
    private static function suggestionRow(int $messageNdx, int $analysisNdx, string $subject): array
    {
        return [
            'message_ndx' => $messageNdx, 'subject' => $subject, 'sender_name' => 'X',
            'received_at' => '2026-06-28 09:00:00', 'raw_source_attachment' => null,
            'analysis_ndx' => $analysisNdx, 'proposed_type' => 'invoiceReceived',
            'canonical_json' => '{}', 'analysis_json' => null,
            'confidence' => 0.95, 'profile' => null,
        ];
    }

    public function testDashboardWiresBothSourcesAndSorts(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        // Prahy pásem (AnalysisConfidenceResolver) — fetchRow → null = defaulty.
        $db->method('fetchRow')->willReturn(null);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql): array {
                // Agregátní fáze AlertsSource (GROUP BY) — jeden check pod prahem.
                if (str_contains($sql, 'core_alerts_alerts') && str_contains($sql, 'GROUP BY')) {
                    return [[
                        'check_id' => 'chk', 'cnt' => 1, 'max_severity' => 30,
                        'last_at' => '2026-06-28 08:00:00', 'first_at' => '2026-06-28 08:00:00',
                    ]];
                }
                if (str_contains($sql, 'core_alerts_alerts')) {
                    return [[
                        'id' => 7, 'check_id' => 'chk', 'title' => 'Chyba', 'message' => 'm',
                        'severity' => 30, 'actions' => null,
                        'first_seen_at' => '2026-06-28 08:00:00', 'last_seen_at' => '2026-06-28 08:00:00',
                    ]];
                }
                // `proposed_type` nese jen dotaz na návrhové karty (JOIN na
                // poslední úspěšnou analýzu); error/notInvoice/attachments ne.
                if (str_contains($sql, 'proposed_type')) {
                    return [self::suggestionRow(2, 1, 'Faktura')];
                }
                return [];
            },
        );

        $ctrl = new DashboardController();
        $data = $ctrl->dashboard($db, null, 'cs', null, $this->fullTables())->getPayload()['data'];

        $this->assertCount(2, $data['cards']);
        // Pořadí sekcí (#101 D8): Připraveno (mail) před Upozorněním (alert),
        // přestože alert je urgent — řadí sekce, ne kind.
        $this->assertSame('mail_suggestion:2', $data['cards'][0]['id']);
        $this->assertSame('ready', $data['cards'][0]['feedSection']);
        $this->assertSame('alert:7', $data['cards'][1]['id']);
        $this->assertSame('alerts', $data['cards'][1]['feedSection']);
        $this->assertSame(['urgent' => 1, 'review' => 0, 'ready' => 1], $data['summary']['counts']);
        $this->assertSame([
            ['id' => 'ready',  'total' => 1, 'shown' => 1],
            ['id' => 'alerts', 'total' => 1, 'shown' => 1],
        ], $data['sections']);
    }

    public function testDashboardCapsPerSectionWithTruthfulCounts(): void
    {
        // 35 ready návrhů → 30 karet, žádná karta „a další“ (#101 D3b);
        // sections, counts i readySummary mluví o všech 35, `shown` o 30.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql): array {
                if (!str_contains($sql, 'proposed_type')) {
                    return [];
                }
                $rows = [];
                for ($i = 1; $i <= 35; $i++) {
                    $rows[] = self::suggestionRow(100 + $i, $i, "F$i");
                }
                return $rows;
            },
        );

        $ctrl = new DashboardController();
        $data = $ctrl->dashboard($db, null, 'cs', null, $this->fullTables())->getPayload()['data'];

        $this->assertCount(30, $data['cards']);
        $this->assertNotContains('mail_more', array_column($data['cards'], 'id'));
        $this->assertSame([['id' => 'ready', 'total' => 35, 'shown' => 30]], $data['sections']);
        $this->assertSame(['urgent' => 0, 'review' => 0, 'ready' => 35], $data['summary']['counts']);
        $this->assertSame(35, $data['readySummary']['invoices']['count']);
        $this->assertSame(30, $data['readySummary']['invoices']['shown']);
    }

    public function testDashboardReadySummaryFromCanonicalAndStripsInternalFields(): void
    {
        // Dvě ready karty s částkou (CZK + EUR), jedna bez totals — do count
        // ano, do amounts ne. Interní amount/currency nesmí do payloadu.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql): array {
                if (!str_contains($sql, 'proposed_type')) {
                    return [];
                }
                $mk = static function (int $ndx, string $canonical, float $confidence): array {
                    $row = self::suggestionRow($ndx, $ndx, "F$ndx");
                    $row['canonical_json'] = $canonical;
                    $row['confidence'] = $confidence;
                    return $row;
                };
                return [
                    $mk(1, '{"totals":{"totalAmount":1000.5},"currency":"CZK"}', 0.95),
                    $mk(2, '{"totals":{"totalAmount":120},"currency":"EUR"}', 0.98),
                    $mk(3, '{}', 0.91),
                ];
            },
        );

        $ctrl = new DashboardController();
        $data = $ctrl->dashboard($db, null, 'cs', null, $this->fullTables())->getPayload()['data'];

        $invoices = $data['readySummary']['invoices'];
        $this->assertSame(3, $invoices['count']);
        $this->assertSame(3, $invoices['shown']);
        $this->assertSame(
            [['currency' => 'CZK', 'total' => 1000.50], ['currency' => 'EUR', 'total' => 120.00]],
            $invoices['amounts'],
        );
        $this->assertSame(91, $invoices['confidenceMin']);
        $this->assertSame(98, $invoices['confidenceMax']);
        foreach ($data['cards'] as $card) {
            $this->assertArrayNotHasKey('amount', $card, $card['id']);
            $this->assertArrayNotHasKey('currency', $card, $card['id']);
        }
    }

    // ── degradace dle modulů + per-source izolace + capabilities (07b) ──────

    /** DB mock z testDashboardWiresBothSourcesAndSorts — mail i alert karta. */
    private function bothSourcesDb(): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql): array {
                if (str_contains($sql, 'core_alerts_alerts') && str_contains($sql, 'GROUP BY')) {
                    return [[
                        'check_id' => 'chk', 'cnt' => 1, 'max_severity' => 30,
                        'last_at' => '2026-06-28 08:00:00', 'first_at' => '2026-06-28 08:00:00',
                    ]];
                }
                if (str_contains($sql, 'core_alerts_alerts')) {
                    return [[
                        'id' => 7, 'check_id' => 'chk', 'title' => 'Chyba', 'message' => 'm',
                        'severity' => 30, 'actions' => null,
                        'first_seen_at' => '2026-06-28 08:00:00', 'last_seen_at' => '2026-06-28 08:00:00',
                    ]];
                }
                if (str_contains($sql, 'proposed_type')) {
                    return [self::suggestionRow(2, 1, 'Faktura')];
                }
                return [];
            },
        );
        return $db;
    }

    public function testMailSourcesSkippedWithoutMailTable(): void
    {
        // DS bez core.mail (hosting DS): mail zdroje se nezaregistrují —
        // žádný dotaz na mail tabulky, response 200 jen s alert kartami.
        $ctrl = new DashboardController();
        $data = $ctrl->dashboard(
            $this->bothSourcesDb(),
            null,
            'cs',
            null,
            $this->tables('core_alerts_alerts'),
        )->getPayload()['data'];

        $this->assertSame(['alert:7'], array_column($data['cards'], 'id'));
    }

    public function testAlertsSourceSkippedWithoutAlertsTable(): void
    {
        $ctrl = new DashboardController();
        $data = $ctrl->dashboard(
            $this->bothSourcesDb(),
            null,
            'cs',
            null,
            $this->tables('core_mail_incoming_messages'),
        )->getPayload()['data'];

        $this->assertSame(['mail_suggestion:2'], array_column($data['cards'], 'id'));
    }

    public function testNoTablesYieldsEmptyFeedNotError(): void
    {
        // Prázdná mapa (degradovaný kontext) = žádné zdroje, žádný dotaz,
        // žádná chyba — DB mock bez stubů by na volání spadl.
        $ctrl = new DashboardController();
        $data = $ctrl->dashboard(
            $this->createMock(DataSourceConnection::class),
            null,
            'cs',
        )->getPayload()['data'];

        $this->assertSame([], $data['cards']);
    }

    public function testFailingSourceIsIsolated(): void
    {
        // Mail dotaz vyhodí výjimku (vzor: chybějící tabulka → Dibi
        // exception) — zaloguje se a feed pokračuje alert kartami.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql): array {
                if (str_contains($sql, 'core_alerts_alerts') && str_contains($sql, 'GROUP BY')) {
                    return [[
                        'check_id' => 'chk', 'cnt' => 1, 'max_severity' => 30,
                        'last_at' => '2026-06-28 08:00:00', 'first_at' => '2026-06-28 08:00:00',
                    ]];
                }
                if (str_contains($sql, 'core_alerts_alerts')) {
                    return [[
                        'id' => 7, 'check_id' => 'chk', 'title' => 'Chyba', 'message' => 'm',
                        'severity' => 30, 'actions' => null,
                        'first_seen_at' => '2026-06-28 08:00:00', 'last_seen_at' => '2026-06-28 08:00:00',
                    ]];
                }
                throw new \RuntimeException("Table 'x.core_mail_incoming_messages' doesn't exist");
            },
        );

        $ctrl = new DashboardController();
        $data = $ctrl->dashboard($db, null, 'cs', null, $this->fullTables())->getPayload()['data'];

        $this->assertSame(['alert:7'], array_column($data['cards'], 'id'));
        $this->assertStringContainsString('Dashboard feed source failed', (string) file_get_contents($this->logPath));
    }

    public function testCapabilitiesReflectTablesAndAuth(): void
    {
        $ctrl  = new DashboardController();
        $db    = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $db->method('fetchAll')->willReturn([]);
        $admin    = new AuthContext(isAuthenticated: true, userId: 1, isAdmin: true);
        $nonAdmin = new AuthContext(isAuthenticated: true, userId: 2, isAdmin: false);

        $caps = fn(array $tables, ?AuthContext $auth) => $ctrl
            ->dashboard($db, null, 'cs', null, $tables, $auth)
            ->getPayload()['data']['capabilities'];

        // Běžný DS s mail + chat: obě true (bez ohledu na admin flag).
        $this->assertSame(
            ['mailUpload' => true, 'chat' => true],
            $caps($this->tables('core_mail_incoming_messages', 'core_chat_conversations'), $nonAdmin),
        );
        // DS bez mail/chat modulů: obě false.
        $this->assertSame(
            ['mailUpload' => false, 'chat' => false],
            $caps($this->tables('core_alerts_alerts'), $admin),
        );
        // Aktivní chat + hosting: ne-admin false (D5), admin true.
        $hostingTables = $this->tables('core_chat_conversations', 'hosting_core_data_sources');
        $this->assertFalse($caps($hostingTables, $nonAdmin)['chat']);
        $this->assertTrue($caps($hostingTables, $admin)['chat']);
        // $auth null (degradace) = fail-closed jako ne-admin.
        $this->assertFalse($caps($hostingTables, null)['chat']);
    }

    public function testCapabilitiesOffOnReadOnlyDs(): void
    {
        // #56 D5: read-only DS — chat vypnutý, upload server odmítá 403.
        $ctrl = new DashboardController();
        $db   = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $db->method('fetchAll')->willReturn([]);
        $admin = new AuthContext(isAuthenticated: true, userId: 1, isAdmin: true);
        $tables = $this->tables('core_mail_incoming_messages', 'core_chat_conversations');

        $caps = $ctrl->dashboard($db, null, 'cs', null, $tables, $admin, null, readOnly: true)
            ->getPayload()['data']['capabilities'];
        $this->assertSame(['mailUpload' => false, 'chat' => false], $caps);
    }

    // ── sectionBadges() — badge stavů sekcí (UI shells Fáze 3) ──────────────

    public function testSectionBadgesAggregatesAlertCardsBySection(): void
    {
        // Alert s navSection z registry → review/warning v sekci accounting.
        $registry = new \Shipard\Core\Alerts\AlertCheckRegistry(
            [\Shipard\Core\Module\ModuleDefinition::fromArray([
                'id'          => 'economy.accounting',
                'name'        => 'Accounting',
                'alertChecks' => [[
                    'id'         => 'chk',
                    'name'       => 'Chyby účtování',
                    'class'      => 'X',
                    'interval'   => '1h',
                    'navSection' => 'accounting',
                ]],
            ])],
            'cs',
        );

        $ctrl = new DashboardController();
        $response = $ctrl->sectionBadges(
            $this->bothSourcesDb(),
            null,
            'cs',
            $registry,
            $this->fullTables(),
        );

        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        // Mail suggestion karta je ready — do badge nepatří; jen alert (urgent).
        $this->assertEquals(
            (object) ['accounting' => ['count' => 1, 'severity' => 'danger']],
            $payload['data']['sections'],
        );
    }

    public function testSectionBadgesEmptyFeedYieldsEmptyObject(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);

        $ctrl = new DashboardController();
        $payload = $ctrl->sectionBadges($db, null, 'cs', null, $this->fullTables())->getPayload();

        // Prázdná mapa musí být objekt (JSON `{}`), ne pole.
        $this->assertEquals(new \stdClass(), $payload['data']['sections']);
    }

    // ── dashboard(?section=) — sekční filtr karet (UI shells Fáze 5) ────────

    public function testDashboardSectionFilterReturnsOnlyMatchingCards(): void
    {
        // Alert s navSection=accounting (z registry) + mail karta (_top):
        // filtr na accounting vrátí jen alert; summary/readySummary/
        // capabilities jsou celofeedové a při filtru se vynechají.
        $registry = new \Shipard\Core\Alerts\AlertCheckRegistry(
            [\Shipard\Core\Module\ModuleDefinition::fromArray([
                'id'          => 'economy.accounting',
                'name'        => 'Accounting',
                'alertChecks' => [[
                    'id'         => 'chk',
                    'name'       => 'Chyby účtování',
                    'class'      => 'X',
                    'interval'   => '1h',
                    'navSection' => 'accounting',
                ]],
            ])],
            'cs',
        );

        $ctrl = new DashboardController();
        $data = $ctrl->dashboard(
            $this->bothSourcesDb(),
            null,
            'cs',
            $registry,
            $this->fullTables(),
            null,
            'accounting',
        )->getPayload()['data'];

        $this->assertSame(['alert:7'], array_column($data['cards'], 'id'));
        $this->assertArrayNotHasKey('summary', $data);
        $this->assertArrayNotHasKey('sections', $data);
        $this->assertArrayNotHasKey('readySummary', $data);
        $this->assertArrayNotHasKey('capabilities', $data);
    }

    public function testDashboardUnknownSectionYieldsEmptyCards(): void
    {
        // Nevalidní hodnota → prázdný seznam, ne chyba (R4).
        $ctrl = new DashboardController();
        $data = $ctrl->dashboard(
            $this->bothSourcesDb(),
            null,
            'cs',
            null,
            $this->fullTables(),
            null,
            'bogus',
        )->getPayload()['data'];

        $this->assertSame([], $data['cards']);
    }

    // ── summary() — SSE AI shrnutí (fáze 2b) ─────────────────────────────────

    private function runProducer(Response $response): string
    {
        $ref = new \ReflectionClass($response);
        $producer = $ref->getProperty('streamProducer')->getValue($response);
        $this->assertIsCallable($producer);

        ob_start();
        try {
            $producer();
        } finally {
            $out = ob_get_clean();
        }
        return (string) $out;
    }

    /** DB mock: jedna mail karta ve feedu, žádná cache (fetchRow → null). */
    private function summaryDb(): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql): array {
                if (!str_contains($sql, 'proposed_type')) {
                    return [];
                }
                return [self::suggestionRow(2, 1, 'Faktura')];
            },
        );
        return $db;
    }

    private function summaryService(DataSourceConnection $db, LlmClient $llm): DashboardSummaryService
    {
        $backends = $this->createMock(AiBackendResolver::class);
        $backends->method('defaultBackend')->willReturn([
            'provider' => 'anthropic', 'model' => 'claude-x', 'base_url' => null,
        ]);
        $backends->method('apiKey')->willReturn('sk-test');
        return new DashboardSummaryService($db, $llm, $backends);
    }

    public function testSummaryEmptyFeedEmitsDoneNull(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);

        $llm = $this->createMock(LlmClient::class);
        $llm->expects($this->never())->method('streamChat');

        $ctrl = new DashboardController();
        $out  = $this->runProducer(
            $ctrl->summary($db, $this->summaryService($db, $llm), null, 'cs'),
        );

        $this->assertStringContainsString('event: done', $out);
        $this->assertStringContainsString('"text":null', $out);
        $this->assertStringNotContainsString('event: text', $out);
        $this->assertStringNotContainsString('event: error', $out);
    }

    public function testSummaryStreamsDeltasAndDone(): void
    {
        $db  = $this->summaryDb();
        $llm = $this->createMock(LlmClient::class);
        $llm->expects($this->once())->method('streamChat')->willReturnCallback(
            static function (LlmChatParams $params, callable $onDelta): LlmChatResult {
                $onDelta('Dnes máte ');
                $onDelta('jednu fakturu.');
                return new LlmChatResult('Dnes máte jednu fakturu.', 100, 20, 'end_turn', 'claude-x');
            },
        );

        $ctrl = new DashboardController();
        $out  = $this->runProducer(
            $ctrl->summary($db, $this->summaryService($db, $llm), null, 'cs', null, $this->fullTables()),
        );

        $this->assertStringContainsString('event: text', $out);
        $this->assertStringContainsString('"delta":"Dnes máte "', $out);
        $this->assertStringContainsString('event: done', $out);
        $this->assertStringContainsString('"text":"Dnes máte jednu fakturu."', $out);
        $this->assertStringContainsString('"cached":false', $out);
    }

    public function testSummaryLlmErrorEmitsErrorEvent(): void
    {
        $db  = $this->summaryDb();
        $llm = $this->createMock(LlmClient::class);
        $llm->method('streamChat')->willThrowException(
            new LlmApiException(500, 'api_error', 'model exploded'),
        );

        $ctrl = new DashboardController();
        $out  = $this->runProducer(
            $ctrl->summary($db, $this->summaryService($db, $llm), null, 'cs', null, $this->fullTables()),
        );

        $this->assertStringContainsString('event: error', $out);
        $this->assertStringContainsString('model exploded', $out);
        $this->assertStringNotContainsString('event: done', $out);
    }
}
