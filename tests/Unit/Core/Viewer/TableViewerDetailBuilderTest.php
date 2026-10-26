<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Viewer;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Viewer\Detail\DetailHeader;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Core\Viewer\ViewerDefinition;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Builder detailu v `TableViewer` (#136 D18–D21): výchozí hlavička,
 * štítek stavu s příznakem `detailBadge`, pořadí štítků, přílohy záznamu
 * podle `detailAttachments`, akce jen neprázdné.
 */
class TableViewerDetailBuilderTest extends TestCase
{
    private const DOC_STATES = [
        '10' => ['stateName' => 'Koncept', 'stateStyle' => 'concept', 'mainState' => 1, 'viewGroup' => 'active', 'goto' => [40]],
        '40' => ['stateName' => 'V pořádku', 'stateStyle' => 'done', 'mainState' => 3, 'viewGroup' => 'active', 'detailBadge' => false, 'goto' => []],
        '90' => ['stateName' => 'V koši', 'stateStyle' => 'trash', 'mainState' => 9, 'viewGroup' => 'trash', 'goto' => []],
    ];

    private const RECORD = ['id' => 7, 'code' => 'A1', 'name' => 'Název', 'docState' => 10];

    private const FILE = ['id' => 101, 'name' => 'faktura.pdf', 'file_name' => 'f.pdf', 'file_size' => 2048, 'mime_type' => 'application/pdf', 'send_with_record' => 0];

    /** @var list<array<int, mixed>> parametry dotazů fetchAll */
    private array $queries = [];

    /**
     * @param array<string, mixed>|null $record
     * @param list<array<string, mixed>> $files řádky core_attachments_files
     */
    private function viewer(?array $record = self::RECORD, array $files = [], bool $withConfig = true): TableViewer
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($record);
        $db->method('fetchAll')->willReturnCallback(function (string $sql, mixed ...$params) use ($files): array {
            $this->queries[] = $params;
            return $files;
        });

        $viewer = new class($db, 'test_table') extends TableViewer {
            /** @var list<array<string, mixed>> */
            public array $tabs = [];
            /** @var list<array<string, mixed>> */
            public array $actions = [];
            /** @var list<array{label: string, style: string}> */
            public array $badges = [];
            /** @var list<array<string, mixed>> */
            public array $groups = [];
            public ?DetailHeader $header = null;

            public function useDocStatesCfgItem(?string $id): void
            {
                $this->docStatesCfgItem = $id;
            }

            public function selectRows(?string $search, array $filters, int $pageNumber): array
            {
                return [];
            }

            public function renderRow(array $rowData): array
            {
                return [];
            }

            public function overviewOf(array $groups): array
            {
                return $this->propertiesTab($groups);
            }

            protected function detailHeader(array $record): DetailHeader
            {
                return $this->header ?? parent::detailHeader($record);
            }

            protected function detailBadges(array $record): array
            {
                return $this->badges;
            }

            protected function detailTabs(array $record): array
            {
                return $this->tabs;
            }

            protected function detailActions(array $record): array
            {
                return $this->actions;
            }

            protected function detailAttachmentGroups(array $record): array
            {
                return $this->groups;
            }
        };

        if ($withConfig) {
            $viewer->setConfig(ConfigRuntimeFactory::fromItems([
                'test.docStates'                => self::DOC_STATES,
                'core.system.viewerDetailLabels' => ['tabs' => [
                    'overview'    => ['name' => 'Přehled'],
                    'attachments' => ['name' => 'Přílohy'],
                ]],
            ]));
        }
        return $viewer;
    }

    private function table(?string $displayPattern = '{code} — {name}', bool $withStates = true): TableDefinition
    {
        $data = [
            'name'    => 'test_table',
            'tableId' => 999,
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                ['id' => 'code', 'name' => 'Code', 'type' => 'text'],
                ['id' => 'name', 'name' => 'Name', 'type' => 'text'],
                ['id' => 'docState', 'name' => 'State', 'type' => 'int'],
            ],
        ];
        if ($displayPattern !== null) {
            $data['displayPattern'] = $displayPattern;
        }
        if ($withStates) {
            $data['docStates'] = ['cfgItem' => 'test.docStates'];
        }
        return TableDefinition::fromArray($data);
    }

    private function definition(?array $detailHeader = null, string $attachments = 'overview', ?string $icon = 'box'): ViewerDefinition
    {
        return new ViewerDefinition(
            id: 'test.viewer',
            name: 'Test',
            table: 'test_table',
            class: null,
            moduleId: 'test',
            icon: $icon,
            detailHeader: $detailHeader,
            detailAttachments: $attachments,
        );
    }

    // ── Hlavička ─────────────────────────────────────────────────────────

    public function testDefaultHeaderComesFromDisplayPatternWithoutIcon(): void
    {
        $viewer = $this->viewer();
        $viewer->setTables(['test_table' => $this->table()]);

        $detail = $viewer->renderDetail(7);

        $this->assertSame('A1 — Název', $detail['title']);
        $this->assertNull($detail['subtitle']);
        $this->assertNull($detail['icon'], 'bez deklarace vieweru není ikona');
    }

    public function testDeclaredHeaderWinsOverDisplayPatternAndCarriesIcon(): void
    {
        $viewer = $this->viewer();
        $viewer->setTables(['test_table' => $this->table()]);
        $viewer->setDefinition($this->definition(['title' => '{name}', 'subtitle' => '{code}']));

        $detail = $viewer->renderDetail(7);

        $this->assertSame('Název', $detail['title']);
        $this->assertSame('A1', $detail['subtitle']);
        $this->assertSame('box', $detail['icon']);
    }

    public function testEmptySubtitleValueGivesNull(): void
    {
        $viewer = $this->viewer(['code' => '', 'name' => 'Název'] + self::RECORD);
        $viewer->setDefinition($this->definition(['title' => '{name}', 'subtitle' => '{code}']));

        $this->assertNull($viewer->renderDetail(7)['subtitle']);
    }

    public function testDeclaredTitleWithoutValueFallsBackToDisplayPatternThenToId(): void
    {
        $viewer = $this->viewer(['name' => ''] + self::RECORD);
        $viewer->setTables(['test_table' => $this->table()]);
        $viewer->setDefinition($this->definition(['title' => '{name}']));

        $this->assertSame('A1', $viewer->renderDetail(7)['title'], 'deklarace bez hodnoty → displayPattern');

        $viewer = $this->viewer(['name' => ''] + self::RECORD);
        $viewer->setDefinition($this->definition(['title' => '{name}']));

        $this->assertSame('#7', $viewer->renderDetail(7)['title'], 'bez definic tabulek → #id');
    }

    public function testSubtitleRepeatingTitleIsDropped(): void
    {
        $viewer = $this->viewer(['name' => ''] + self::RECORD);
        $viewer->setTables(['test_table' => $this->table(displayPattern: '{code}')]);
        $viewer->setDefinition($this->definition(['title' => '{name}', 'subtitle' => '{code}']));

        $detail = $viewer->renderDetail(7);

        $this->assertSame('A1', $detail['title']);
        $this->assertNull($detail['subtitle'], 'podtitul shodný s titulkem (token bez poznámky) se vynechá');
    }

    public function testHeaderFallsBackToIdWithoutAnyPattern(): void
    {
        $viewer = $this->viewer();
        $viewer->setTables(['test_table' => $this->table(displayPattern: null)]);

        $this->assertSame('#7', $viewer->renderDetail(7)['title']);
        $this->assertSame('#7', $this->viewer()->renderDetail(7)['title'], 'bez definic tabulek');
    }

    public function testViewerMayOverrideHeaderEntirely(): void
    {
        $viewer = $this->viewer();
        $viewer->header = new DetailHeader('Dodavatel s.r.o.', 'FP-1 · text', 'invoice-in');

        $detail = $viewer->renderDetail(7);

        $this->assertSame(['Dodavatel s.r.o.', 'FP-1 · text', 'invoice-in'], [$detail['title'], $detail['subtitle'], $detail['icon']]);
    }

    // ── Štítky ───────────────────────────────────────────────────────────

    public function testStateBadgeComesFromTableDocStates(): void
    {
        $viewer = $this->viewer();
        $viewer->setTables(['test_table' => $this->table()]);

        $this->assertSame([['label' => 'Koncept', 'style' => 'concept']], $viewer->renderDetail(7)['badges']);
    }

    public function testStateBadgeIsHiddenByDetailBadgeFlag(): void
    {
        $viewer = $this->viewer(['docState' => 40] + self::RECORD);
        $viewer->setTables(['test_table' => $this->table()]);

        $this->assertSame([], $viewer->renderDetail(7)['badges'], 'V pořádku bez štítku (D20)');
    }

    public function testStateBadgeUsesViewerCfgItemWithoutTableDefinitions(): void
    {
        $viewer = $this->viewer(['docState' => 90] + self::RECORD);
        $viewer->useDocStatesCfgItem('test.docStates');

        $this->assertSame([['label' => 'V koši', 'style' => 'trash']], $viewer->renderDetail(7)['badges']);
    }

    public function testTableWithoutStatesOrConfigHasNoStateBadge(): void
    {
        $viewer = $this->viewer();
        $viewer->setTables(['test_table' => $this->table(withStates: false)]);
        $this->assertSame([], $viewer->renderDetail(7)['badges'], 'tabulka bez stavů');

        $viewer = $this->viewer(withConfig: false);
        $viewer->setTables(['test_table' => $this->table()]);
        $this->assertSame([], $viewer->renderDetail(7)['badges'], 'bez konfigurace');
    }

    public function testStateBadgeComesFirstThenViewerBadges(): void
    {
        $viewer = $this->viewer();
        $viewer->setTables(['test_table' => $this->table()]);
        $viewer->badges = [['label' => 'Chyba účtování', 'style' => 'error']];

        $this->assertSame(
            [['label' => 'Koncept', 'style' => 'concept'], ['label' => 'Chyba účtování', 'style' => 'error']],
            $viewer->renderDetail(7)['badges'],
        );
    }

    // ── Taby a akce ──────────────────────────────────────────────────────

    public function testTabsAndActionsComeFromHooksActionsOnlyWhenPresent(): void
    {
        $viewer = $this->viewer();
        $viewer->tabs = [['id' => 'overview', 'label' => 'Přehled', 'content' => ['type' => 'properties', 'groups' => []]]];

        $detail = $viewer->renderDetail(7);
        $this->assertSame(['overview'], array_column($detail['tabs'], 'id'));
        $this->assertArrayNotHasKey('actions', $detail);

        $viewer->actions = [['id' => 'reaccount', 'label' => 'Přeúčtovat', 'kind' => 'button']];
        $this->assertSame('reaccount', $viewer->renderDetail(7)['actions'][0]['id']);
    }

    public function testPropertiesTabDropsEmptyGroups(): void
    {
        $viewer = $this->viewer();

        $tab = $viewer->overviewOf([
            ['title' => 'Identita', 'items' => [['label' => 'Kód', 'value' => 'A1']]],
            ['title' => 'Prázdná', 'items' => []],
        ]);

        $this->assertSame('overview', $tab['id']);
        $this->assertSame('Přehled', $tab['label']);
        $this->assertSame('properties', $tab['content']['type']);
        $this->assertSame(['Identita'], array_column($tab['content']['groups'], 'title'));
    }

    public function testMissingRecordGivesEmptyTabs(): void
    {
        $this->assertSame(['tabs' => []], $this->viewer(record: null)->renderDetail(7));
    }

    // ── Přílohy ──────────────────────────────────────────────────────────

    public function testOverviewPlacementPutsRecordAttachmentsIntoDetailWithoutTab(): void
    {
        $viewer = $this->viewer(files: [self::FILE]);
        $viewer->setTables(['test_table' => $this->table()]);

        $detail = $viewer->renderDetail(7);

        $this->assertSame([999, 7], $this->queries[0], 'tableId z definice tabulky + id záznamu');
        $this->assertSame([[
            'kind'        => 'record',
            'attachments' => [['id' => 101, 'name' => 'faktura.pdf', 'mime_type' => 'application/pdf', 'file_size' => 2048]],
        ]], $detail['attachments']['groups']);
        $this->assertSame([], $detail['tabs']);
    }

    public function testTabPlacementAddsAttachmentsTabAtTheEnd(): void
    {
        $viewer = $this->viewer(files: [self::FILE]);
        $viewer->setTables(['test_table' => $this->table()]);
        $viewer->setDefinition($this->definition(attachments: 'tab'));
        $viewer->tabs = [['id' => 'overview', 'label' => 'Přehled', 'content' => ['type' => 'properties', 'groups' => []]]];

        $detail = $viewer->renderDetail(7);

        $this->assertSame(['overview', 'attachments'], array_column($detail['tabs'], 'id'));
        $this->assertSame(['id' => 'attachments', 'label' => 'Přílohy', 'content' => ['type' => 'attachments']], $detail['tabs'][1]);
        $this->assertCount(1, $detail['attachments']['groups']);
    }

    public function testContentPlacementLoadsNothing(): void
    {
        $viewer = $this->viewer(files: [self::FILE]);
        $viewer->setTables(['test_table' => $this->table()]);
        $viewer->setDefinition($this->definition(attachments: 'content'));
        $viewer->groups = [['kind' => 'mail', 'attachments' => [['id' => 5]]]];

        $detail = $viewer->renderDetail(7);

        $this->assertArrayNotHasKey('attachments', $detail);
        $this->assertSame([], $this->queries, 'builder se na přílohy neptá');
    }

    public function testViewerGroupsComeBeforeRecordGroupAndEmptyOnesAreDropped(): void
    {
        $viewer = $this->viewer(files: [self::FILE]);
        $viewer->setTables(['test_table' => $this->table()]);
        $viewer->groups = [
            ['kind' => 'mail', 'message_ndx' => 11, 'attachments' => [['id' => 5, 'name' => 'scan.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1]]],
            ['kind' => 'mail', 'message_ndx' => 12, 'attachments' => []],
        ];

        $groups = $viewer->renderDetail(7)['attachments']['groups'];

        $this->assertSame(['mail', 'record'], array_column($groups, 'kind'));
        $this->assertSame(11, $groups[0]['message_ndx']);
    }

    public function testNoAttachmentsMeansNoKeyAndNoTab(): void
    {
        $viewer = $this->viewer();
        $viewer->setTables(['test_table' => $this->table()]);
        $viewer->setDefinition($this->definition(attachments: 'tab'));

        $detail = $viewer->renderDetail(7);

        $this->assertArrayNotHasKey('attachments', $detail);
        $this->assertSame([], $detail['tabs']);
    }

    public function testSendWithRecordFlagOnlyWhenSet(): void
    {
        $viewer = $this->viewer(files: [
            ['send_with_record' => 1] + self::FILE,
            ['id' => 102, 'name' => null, 'file_name' => 'b.pdf'] + self::FILE,
        ]);
        $viewer->setTables(['test_table' => $this->table()]);

        $attachments = $viewer->renderDetail(7)['attachments']['groups'][0]['attachments'];

        $this->assertTrue($attachments[0]['send_with_record']);
        $this->assertArrayNotHasKey('send_with_record', $attachments[1]);
        $this->assertSame('b.pdf', $attachments[1]['name'], 'fallback na jméno souboru');
    }

    public function testWithoutTableDefinitionsRecordAttachmentsAreNotLoaded(): void
    {
        $viewer = $this->viewer(files: [self::FILE]);

        $detail = $viewer->renderDetail(7);

        $this->assertArrayNotHasKey('attachments', $detail);
        $this->assertSame([], $this->queries);
    }

    // ── Deklarace ────────────────────────────────────────────────────────

    public function testUnknownAttachmentPlacementIsConfigurationError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Viewer 'test.viewer'");

        $this->definition(attachments: 'sidebar');
    }
}
