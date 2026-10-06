<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Alerts\Feed;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Feed\FeedContext;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Module\Core\Alerts\Feed\AlertsSource;

/**
 * Unit testy pro AlertsSource.
 *
 * Pokrývají:
 *   - severity → kind mapping (error→urgent, warning→review, info→info)
 *   - actions passthrough beze změny (open_form/open_viewer)
 *   - title/subtitle/timestamp/id tvar
 *   - prázdný vstup → []
 *   - agregaci per check (práh GROUP_THRESHOLD = 3): skupinová karta
 *     nahrazuje individuální, titulek z registru s fallbackem na check_id,
 *     kind dle MAX(severity), jediná primary open_viewer akce
 */
final class AlertsSourceTest extends TestCase
{
    /**
     * Dvoufázový mock: agregátní dotaz (obsahuje GROUP BY) vrací `$groups`,
     * dotaz na individuální řádky vrací `$rows`. Fáze 2 se při prázdném
     * seznamu checků pod prahem vůbec nevolá — počet volání není konstantní,
     * proto callback dle SQL, ne willReturnOnConsecutiveCalls.
     *
     * @param list<array<string,mixed>> $groups
     * @param list<array<string,mixed>> $rows
     */
    private function context(
        array $groups,
        array $rows = [],
        string $lang = 'cs',
        ?int &$fetchCalls = null,
        ?array $setupAgg = null,
        ?string $setupTitle = null,
        ?int &$fetchRowCalls = null,
    ): FeedContext {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use ($groups, $rows, &$fetchCalls): array {
                $fetchCalls = ($fetchCalls ?? 0) + 1;
                return str_contains((string) $args[0], 'GROUP BY') ? $groups : $rows;
            },
        );
        // Fáze 0 (tagová agregace setup checků) — agregát přes fetchRow,
        // title jediné položky přes fetchSingle.
        $db->method('fetchRow')->willReturnCallback(
            static function (mixed ...$args) use ($setupAgg, &$fetchRowCalls): ?array {
                $fetchRowCalls = ($fetchRowCalls ?? 0) + 1;
                return $setupAgg;
            },
        );
        $db->method('fetchSingle')->willReturn($setupTitle);
        return new FeedContext($db, null, $lang, 30);
    }

    /** @return array<string,mixed> agregátní řádek fáze 1 */
    private function groupRow(
        string $checkId,
        int $cnt,
        int $maxSeverity,
        ?string $lastAt = '2026-06-28 12:00:00',
        ?string $firstAt = '2026-06-01 08:00:00',
    ): array {
        return [
            'check_id'     => $checkId,
            'cnt'          => $cnt,
            'max_severity' => $maxSeverity,
            'last_at'      => $lastAt,
            'first_at'     => $firstAt,
        ];
    }

    /** @return array<string,mixed> */
    private function alertRow(int $severity, int $id = 1, ?array $actions = null): array
    {
        return [
            'id'            => $id,
            'check_id'      => 'base.persons.missing_own_person',
            'title'         => 'Chybí vlastní firma',
            'message'       => 'Založ vlastní Osobu.',
            'severity'      => $severity,
            'actions'       => $actions === null ? null : json_encode($actions),
            'first_seen_at' => '2026-06-01 08:00:00',
            'last_seen_at'  => '2026-06-28 12:00:00',
        ];
    }

    /** Kontext pro jediný individuální alert (check pod prahem). */
    private function singleAlertContext(array $row): FeedContext
    {
        return $this->context(
            [$this->groupRow((string) $row['check_id'], 1, (int) $row['severity'])],
            [$row],
        );
    }

    // ── Individuální karty (checky pod prahem) ──────────────────────────────

    public function testErrorSeverityMapsToUrgent(): void
    {
        $src = new AlertsSource();
        $cards = $src->collectCards($this->singleAlertContext($this->alertRow(30)));

        $this->assertCount(1, $cards);
        $card = $cards[0];
        $this->assertSame('alert:1', $card['id']);
        $this->assertSame('alerts', $card['source']);
        $this->assertSame('urgent', $card['kind']);
        $this->assertSame('error', $card['stateStyle']);
        $this->assertSame('other', $card['category']);
        // Sekce Upozornění bez ohledu na závažnost (#101 D5).
        $this->assertSame('alerts', $card['feedSection']);
        $this->assertSame('Chybí vlastní firma', $card['title']);
        $this->assertSame('Založ vlastní Osobu.', $card['subtitle']);
        $this->assertSame('2026-06-28T12:00:00+00:00', $card['timestamp']);
        $this->assertSame(30, $card['context']['severity']);
    }

    public function testWarningSeverityMapsToReview(): void
    {
        $src = new AlertsSource();
        $card = $src->collectCards($this->singleAlertContext($this->alertRow(20)))[0];
        $this->assertSame('review', $card['kind']);
        $this->assertSame('edit', $card['stateStyle']);
    }

    public function testInfoSeverityMapsToInfo(): void
    {
        $src = new AlertsSource();
        $card = $src->collectCards($this->singleAlertContext($this->alertRow(10)))[0];
        $this->assertSame('info', $card['kind']);
        $this->assertSame('concept', $card['stateStyle']);
        // I info alert patří do Upozornění, ne do Ostatní (#101 D5).
        $this->assertSame('alerts', $card['feedSection']);
    }

    public function testActionsPassthrough(): void
    {
        $actions = [
            ['id' => 'create_own_person', 'label' => 'Přidat vlastní firmu', 'kind' => 'open_form',
             'target' => ['table' => 'base_persons_persons', 'mode' => 'create'], 'primary' => true],
        ];
        $src = new AlertsSource();
        $card = $src->collectCards($this->singleAlertContext($this->alertRow(30, 1, $actions)))[0];

        $this->assertSame($actions, $card['actions']);
    }

    public function testInvalidActionsJsonYieldsEmpty(): void
    {
        $row = $this->alertRow(30);
        $row['actions'] = 'not-json{';
        $src = new AlertsSource();
        $card = $src->collectCards($this->singleAlertContext($row))[0];
        $this->assertSame([], $card['actions']);
    }

    public function testSubtitleFallsBackToCheckIdWhenNoMessage(): void
    {
        $row = $this->alertRow(20);
        $row['message'] = '';
        $src = new AlertsSource();
        $card = $src->collectCards($this->singleAlertContext($row))[0];
        $this->assertSame('base.persons.missing_own_person', $card['subtitle']);
    }

    public function testEmptyInputReturnsNoCards(): void
    {
        $src = new AlertsSource();
        $this->assertSame([], $src->collectCards($this->context([])));
    }

    // ── Agregace per check (skupinové karty) ────────────────────────────────

    public function testFourAlertsOfOneCheckCollapseIntoSingleGroupCard(): void
    {
        $fetchCalls = 0;
        $ctx = $this->context(
            [$this->groupRow('docs.core.stale_in_repair', 4, 20)],
            [$this->alertRow(20)], // fáze 2 se nesmí spustit — řádky by vyrobily kartu navíc
            'cs',
            $fetchCalls,
        );

        $src = new AlertsSource();
        $cards = $src->collectCards($ctx);

        $this->assertCount(1, $cards);
        $card = $cards[0];
        $this->assertSame('alert-group:docs.core.stale_in_repair', $card['id']);
        $this->assertSame('alerts', $card['source']);
        $this->assertSame('other', $card['category']);
        $this->assertSame('alerts', $card['feedSection']);
        $this->assertStringContainsString('4', $card['subtitle']);
        $this->assertSame('4 upozornění', $card['subtitle']);
        $this->assertSame(4, $card['context']['count']);
        $this->assertTrue($card['context']['group']);
        $this->assertSame('2026-06-28T12:00:00+00:00', $card['timestamp']);
        $this->assertSame(1, $fetchCalls, 'fáze 2 se při prázdném seznamu checků pod prahem nevolá');
    }

    public function testThreeAlertsStayIndividual(): void
    {
        $ctx = $this->context(
            [$this->groupRow('base.persons.missing_own_person', 3, 20)],
            [$this->alertRow(20, 1), $this->alertRow(20, 2), $this->alertRow(20, 3)],
        );

        $src = new AlertsSource();
        $cards = $src->collectCards($ctx);

        $this->assertCount(3, $cards);
        $this->assertSame(['alert:1', 'alert:2', 'alert:3'], array_column($cards, 'id'));
    }

    public function testMixedChecksYieldGroupAndIndividualCards(): void
    {
        $ctx = $this->context(
            [
                $this->groupRow('docs.core.stale_in_repair', 5, 30),
                $this->groupRow('base.persons.missing_own_person', 2, 20),
            ],
            [$this->alertRow(20, 1), $this->alertRow(20, 2)],
        );

        $src = new AlertsSource();
        $cards = $src->collectCards($ctx);

        $this->assertCount(3, $cards);
        $this->assertSame(
            ['alert-group:docs.core.stale_in_repair', 'alert:1', 'alert:2'],
            array_column($cards, 'id'),
        );
    }

    public function testGroupCardKindFollowsMaxSeverity(): void
    {
        $src = new AlertsSource();

        $urgent = $src->collectCards($this->context([$this->groupRow('x.y.err', 4, 30)]))[0];
        $this->assertSame('urgent', $urgent['kind']);
        $this->assertSame('error', $urgent['stateStyle']);

        $info = $src->collectCards($this->context([$this->groupRow('x.y.info', 4, 10)]))[0];
        $this->assertSame('info', $info['kind']);
        $this->assertSame('concept', $info['stateStyle']);

        $review = $src->collectCards($this->context([$this->groupRow('x.y.warn', 4, 20)]))[0];
        $this->assertSame('review', $review['kind']);
        $this->assertSame('edit', $review['stateStyle']);
    }

    public function testGroupCardTitleComesFromRegistry(): void
    {
        $registry = new AlertCheckRegistry([ModuleDefinition::fromArray([
            'id'          => 'docs.core',
            'name'        => 'Docs Core',
            'alertChecks' => [[
                'id'       => 'docs.core.stale_in_repair',
                'name'     => 'Doklady dlouho v opravě',
                'class'    => 'X',
                'interval' => '1h',
            ]],
        ])], 'cs');

        $src = new AlertsSource($registry);
        $card = $src->collectCards($this->context([$this->groupRow('docs.core.stale_in_repair', 4, 20)]))[0];

        $this->assertSame('Doklady dlouho v opravě', $card['title']);
    }

    public function testGroupCardTitleFallsBackToCheckId(): void
    {
        // Bez registru (null).
        $src = new AlertsSource();
        $card = $src->collectCards($this->context([$this->groupRow('docs.core.stale_in_repair', 4, 20)]))[0];
        $this->assertSame('docs.core.stale_in_repair', $card['title']);

        // Registr bez daného checku (check mezitím zmizel z modulu).
        $src = new AlertsSource(new AlertCheckRegistry([], 'cs'));
        $card = $src->collectCards($this->context([$this->groupRow('docs.core.stale_in_repair', 4, 20)]))[0];
        $this->assertSame('docs.core.stale_in_repair', $card['title']);
    }

    public function testNavSectionComesFromRegistryOnIndividualAndGroupCards(): void
    {
        $registry = new AlertCheckRegistry([ModuleDefinition::fromArray([
            'id'          => 'base.persons',
            'name'        => 'Persons',
            'alertChecks' => [[
                'id'         => 'base.persons.missing_own_person',
                'name'       => 'X',
                'class'      => 'X',
                'interval'   => '1h',
                'navSection' => 'accounting',
            ]],
        ])], 'cs');
        $src = new AlertsSource($registry);

        // Individuální karta (check pod prahem).
        $card = $src->collectCards($this->singleAlertContext($this->alertRow(20)))[0];
        $this->assertSame('accounting', $card['navSection']);

        // Skupinová karta (check nad prahem).
        $group = $src->collectCards($this->context([$this->groupRow('base.persons.missing_own_person', 4, 20)]))[0];
        $this->assertSame('accounting', $group['navSection']);
    }

    public function testNavSectionNullWithoutRegistryOrField(): void
    {
        // Bez registru (null) — chování beze změny, pole je null.
        $src = new AlertsSource();
        $card = $src->collectCards($this->singleAlertContext($this->alertRow(20)))[0];
        $this->assertNull($card['navSection']);

        // Registr s checkem bez navSection.
        $registry = new AlertCheckRegistry([ModuleDefinition::fromArray([
            'id'          => 'base.persons',
            'name'        => 'Persons',
            'alertChecks' => [[
                'id' => 'base.persons.missing_own_person', 'name' => 'X', 'class' => 'X', 'interval' => '1h',
            ]],
        ])], 'cs');
        $card = (new AlertsSource($registry))->collectCards($this->singleAlertContext($this->alertRow(20)))[0];
        $this->assertNull($card['navSection']);
    }

    public function testGroupCardActionOpensAlertsViewer(): void
    {
        $src = new AlertsSource();

        $cs = $src->collectCards($this->context([$this->groupRow('x.y.z', 4, 20)], [], 'cs'))[0];
        $this->assertCount(1, $cs['actions']);
        $action = $cs['actions'][0];
        $this->assertSame('open_viewer', $action['kind']);
        $this->assertSame(['viewerId' => 'core.alerts.alerts'], $action['target']);
        $this->assertTrue($action['primary']);
        $this->assertSame('Otevřít upozornění', $action['label']);

        $en = $src->collectCards($this->context([$this->groupRow('x.y.z', 4, 20)], [], 'en'))[0];
        $this->assertSame('Open alerts', $en['actions'][0]['label']);
        $this->assertSame('4 alerts', $en['subtitle']);
    }

    // ── Agregace podle tagu setup (ds-setup D8, Task 07) ────────────────────

    /** Osm reálných setup check id z SetupChecklist::ORDER. */
    private const SETUP_IDS = [
        'base.persons.missing_own_person',
        'base.persons.missing_own_headquarters',
        'economy.codebooks.undecided_vat_agenda',
        'economy.codebooks.missing_vat_registration',
        'economy.codebooks.missing_own_bank_account',
        'economy.accounting.undecided_account_chart',
        'economy.codebooks.undecided_fiscal_year_start',
        'economy.codebooks.undecided_home_currency',
    ];

    /**
     * Registry se setup checky + volitelně netagovanými — checky mohou mít
     * i další tagy, zdroj musí filtrovat in_array, ne rovnost.
     *
     * @param list<string> $setupIds
     * @param list<string> $plainIds
     */
    private function setupRegistry(array $setupIds = self::SETUP_IDS, array $plainIds = []): AlertCheckRegistry
    {
        $entries = [];
        foreach ($setupIds as $id) {
            $entries[] = [
                'id'       => $id,
                'name'     => "Name {$id}",
                'class'    => 'X',
                'interval' => '5m',
                'tags'     => ['setup', 'onboarding'],
            ];
        }
        foreach ($plainIds as $id) {
            $entries[] = ['id' => $id, 'name' => "Name {$id}", 'class' => 'X', 'interval' => '1h'];
        }
        return new AlertCheckRegistry([ModuleDefinition::fromArray([
            'id'          => 'test.fixture',
            'name'        => 'Fixture',
            'alertChecks' => $entries,
        ])], 'cs');
    }

    /** @return array<string,mixed> agregátní řádek fáze 0 */
    private function setupAgg(
        int $cnt,
        int $maxSeverity = 20,
        ?string $lastAt = '2026-06-28 12:00:00',
        ?string $firstAt = '2026-06-01 08:00:00',
    ): array {
        return ['cnt' => $cnt, 'max_severity' => $maxSeverity, 'last_at' => $lastAt, 'first_at' => $firstAt];
    }

    public function testEightSetupAlertsCollapseIntoSingleSetupCard(): void
    {
        // Osm aktivních setup alertů (každý check jeden — pod per-check
        // prahem) → JEDNA karta, žádná individuální karta žádného z checků.
        $groups = array_map(fn(string $id): array => $this->groupRow($id, 1, 20), self::SETUP_IDS);

        $src = new AlertsSource($this->setupRegistry());
        $cards = $src->collectCards($this->context($groups, setupAgg: $this->setupAgg(8)));

        $this->assertCount(1, $cards);
        $card = $cards[0];
        $this->assertSame('alert-group:setup', $card['id']);
        $this->assertSame('Dokončit nastavení', $card['title']);
        $this->assertSame('8 položek', $card['subtitle']);
        $this->assertSame('review', $card['kind']);
        // Setup karta patří do Položek k založení, ne do Upozornění (#101 D5).
        $this->assertSame('newItems', $card['feedSection']);
        $this->assertSame(
            ['tag' => 'setup', 'count' => 8, 'severity' => 20, 'group' => true],
            $card['context'],
        );

        $this->assertCount(1, $card['actions']);
        $action = $card['actions'][0];
        $this->assertSame('open_panel', $action['kind']);
        $this->assertSame(['panelId' => 'dsSetup'], $action['target']);
        $this->assertTrue($action['primary']);
        $this->assertSame('Otevřít nastavení', $action['label']);
    }

    public function testSingleSetupAlertSubtitleShowsItsTitle(): void
    {
        // Poslední zbývající krok → karta říká konkrétně, co chybí.
        $src = new AlertsSource($this->setupRegistry());
        $cards = $src->collectCards($this->context(
            [$this->groupRow(self::SETUP_IDS[0], 1, 20)],
            setupAgg: $this->setupAgg(1),
            setupTitle: 'Chybí vlastní Osoba',
        ));

        $this->assertCount(1, $cards);
        $this->assertSame('Chybí vlastní Osoba', $cards[0]['subtitle']);
        $this->assertSame(1, $cards[0]['context']['count']);
    }

    public function testSetupSubtitlePluralization(): void
    {
        $src = new AlertsSource($this->setupRegistry());

        $two = $src->collectCards($this->context([], setupAgg: $this->setupAgg(2)))[0];
        $this->assertSame('2 položky', $two['subtitle']);

        $five = $src->collectCards($this->context([], setupAgg: $this->setupAgg(5)))[0];
        $this->assertSame('5 položek', $five['subtitle']);

        $en = $src->collectCards($this->context([], lang: 'en', setupAgg: $this->setupAgg(5)))[0];
        $this->assertSame('5 items', $en['subtitle']);
        $this->assertSame('Finish setup', $en['title']);
        $this->assertSame('Open setup', $en['actions'][0]['label']);
    }

    public function testSetupAndRegularAlertsDoNotOverlap(): void
    {
        // Setup alert + běžný check nad prahem + běžný check pod prahem →
        // tři karty, každá jednou; setup check nemá individuální kartu.
        $groups = [
            $this->groupRow(self::SETUP_IDS[0], 1, 20),
            $this->groupRow('docs.core.stale_in_repair', 4, 20),
            $this->groupRow('base.persons.missing_own_person2', 1, 30),
        ];
        $row = $this->alertRow(30);
        $row['check_id'] = 'base.persons.missing_own_person2';

        $src = new AlertsSource($this->setupRegistry(
            [self::SETUP_IDS[0]],
            ['docs.core.stale_in_repair', 'base.persons.missing_own_person2'],
        ));
        $cards = $src->collectCards($this->context(
            $groups,
            [$row],
            setupAgg: $this->setupAgg(1),
            setupTitle: 'Chybí vlastní Osoba',
        ));

        $this->assertSame(
            ['alert-group:setup', 'alert-group:docs.core.stale_in_repair', 'alert:1'],
            array_column($cards, 'id'),
        );
    }

    public function testSetupCardKindFollowsMaxSeverity(): void
    {
        // Jeden error mezi warningy → karta je urgent (agregace nesnižuje
        // viditelnost).
        $src = new AlertsSource($this->setupRegistry());
        $card = $src->collectCards($this->context([], setupAgg: $this->setupAgg(3, 30)))[0];

        $this->assertSame('urgent', $card['kind']);
        $this->assertSame('error', $card['stateStyle']);
    }

    public function testNullRegistrySkipsTagAggregation(): void
    {
        // Bez registry tagy neznáme → fail-open: žádný agregátní dotaz,
        // alerty projdou individuálně.
        $fetchRowCalls = null;
        $src = new AlertsSource();
        $cards = $src->collectCards($this->context(
            [$this->groupRow('base.persons.missing_own_person', 1, 30)],
            [$this->alertRow(30)],
            fetchRowCalls: $fetchRowCalls,
        ));

        $this->assertNull($fetchRowCalls);
        $this->assertSame(['alert:1'], array_column($cards, 'id'));
    }

    public function testNoActiveSetupAlertsNoSetupCard(): void
    {
        $src = new AlertsSource($this->setupRegistry());
        $cards = $src->collectCards($this->context([], setupAgg: $this->setupAgg(0)));

        $this->assertSame([], $cards);
    }
}
