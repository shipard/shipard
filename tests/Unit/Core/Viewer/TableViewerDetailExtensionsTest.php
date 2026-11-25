<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Viewer;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleUiExtensions;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Core\Viewer\ViewerDefinition;
use Shipard\Core\Viewer\ViewerRegistry;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;
use Shipard\Tests\Unit\Core\Module\UiExtensionFixtures;

/**
 * Taby a štítky detailu z modulů (`detailExtensions`, #172 D16, D19):
 * builder je přidá za taby a štítky vieweru (taby před tab Přílohy),
 * prefixuje id tabu id modulu, hlídá kontrakt obsahu a izoluje
 * poskytovatele, který spadne nebo vrátí něco mimo kontrakt.
 */
class TableViewerDetailExtensionsTest extends TestCase
{
    use UiExtensionFixtures;

    private const RECORD = ['id' => 7, 'name' => 'Záznam', 'kind' => 'main', 'docState' => 10, 'contact_payment' => 1];

    private const FILE = ['id' => 101, 'name' => 'faktura.pdf', 'file_name' => 'f.pdf', 'file_size' => 2048, 'mime_type' => 'application/pdf', 'send_with_record' => 0];

    private const VIEWER_TAB = ['id' => 'overview', 'label' => 'Přehled', 'content' => ['type' => 'text', 'text' => 'viewer']];

    protected function setUp(): void
    {
        $this->setUpUiExtensionFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownUiExtensionFixtures();
    }

    /**
     * @param array<string, mixed> $record
     * @param list<array<string, mixed>> $files řádky core_attachments_files
     */
    private function db(array $record = self::RECORD, array $files = []): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($record);
        $db->method('fetchAll')->willReturn($files);
        return $db;
    }

    /**
     * Viewer nad fixturovou tabulkou s jedním tabem a jedním štítkem.
     *
     * @param array<string, mixed> $record
     * @param list<array<string, mixed>> $files
     */
    private function viewer(?ModuleUiExtensions $extensions, array $record = self::RECORD, array $files = []): TableViewer
    {
        $viewer = new UiHostTestViewer($this->db($record, $files), self::UI_TABLE);
        $viewer->setConfig(ConfigRuntimeFactory::fromItems([
            'core.system.docStatesArchive' => [
                '10' => ['stateName' => 'Aktivní', 'stateStyle' => 'done', 'mainState' => 1, 'viewGroup' => 'active', 'goto' => []],
            ],
            'core.system.viewerDetailLabels' => ['tabs' => ['attachments' => ['name' => 'Přílohy']]],
        ]));
        $viewer->setLanguage('cs');
        $viewer->setTables($this->uiTables());
        if ($extensions !== null) {
            $viewer->setUiExtensions($extensions);
        }
        return $viewer;
    }

    /** @param list<array{0: string, 1?: int}> $classes krátké jméno fixturové třídy + volitelné pořadí */
    private function detailExtensions(array $classes): ModuleUiExtensions
    {
        $registrations = [];
        foreach ($classes as $entry) {
            $reg = ['table' => self::UI_TABLE, 'class' => self::UI_NAMESPACE . $entry[0]];
            if (isset($entry[1])) {
                $reg['order'] = $entry[1];
            }
            $registrations[] = $reg;
        }
        return ModuleUiExtensions::fromModules([ModuleDefinition::fromArray([
            'id' => 'test.uiExtensions',
            'name' => 'addon',
            'detailExtensions' => $registrations,
        ])]);
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<string>
     */
    private function tabIds(array $detail): array
    {
        return array_column($detail['tabs'], 'id');
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<string>
     */
    private function badgeLabels(array $detail): array
    {
        return array_column($detail['badges'], 'label');
    }

    public function testDetailWithoutExtensionsIsUnchanged(): void
    {
        $detail = $this->viewer(null)->renderDetail(7);

        $this->assertSame(['overview'], $this->tabIds($detail));
        $this->assertSame(['Aktivní', 'Viewer badge'], $this->badgeLabels($detail));

        $this->assertEquals($detail, $this->viewer(ModuleUiExtensions::none())->renderDetail(7));
    }

    public function testModuleTabsAndBadgesFollowThoseOfTheViewer(): void
    {
        $detail = $this->viewer($this->uiExtensionsOf())->renderDetail(7);

        $this->assertSame(['overview', 'test.uiExtensions.sales'], $this->tabIds($detail), 'id tabu nese prefix modulu');
        $this->assertSame(['Aktivní', 'Viewer badge', 'Fixture badge'], $this->badgeLabels($detail));
        $this->assertSame(['label' => 'Fixture badge', 'style' => 'info'], $detail['badges'][2]);

        $tab = $detail['tabs'][1];
        $this->assertSame('Fixture sales', $tab['label']);
        $this->assertSame('properties', $tab['content']['type']);
        $this->assertSame([], $this->uiExtensionFailures());
    }

    public function testProviderGetsTableLanguageAndTableDefinitionInContext(): void
    {
        $detail = $this->viewer($this->uiExtensionsOf())->renderDetail(7);

        $items = $detail['tabs'][1]['content']['groups'][0]['items'];
        $this->assertSame(['label' => 'Table', 'value' => self::UI_TABLE], $items[0]);
        $this->assertSame(['label' => 'Language', 'value' => 'cs'], $items[1]);
    }

    public function testModuleTabsComeBeforeAttachmentsTab(): void
    {
        $viewer = $this->viewer($this->uiExtensionsOf(), files: [self::FILE]);
        $viewer->setDefinition(new ViewerDefinition(
            id: 'test.uiHost.records',
            name: 'Records',
            table: self::UI_TABLE,
            class: UiHostTestViewer::class,
            moduleId: 'test.uiHost',
            detailAttachments: ViewerDefinition::ATTACHMENTS_TAB,
        ));

        $this->assertSame(
            ['overview', 'test.uiExtensions.sales', 'attachments'],
            $this->tabIds($viewer->renderDetail(7)),
        );
    }

    public function testProviderDecidesWhetherTheRecordConcernsIt(): void
    {
        $detail = $this->viewer($this->uiExtensionsOf(), ['kind' => 'skip'] + self::RECORD)->renderDetail(7);

        $this->assertSame(['overview'], $this->tabIds($detail));
        $this->assertSame(['Aktivní', 'Viewer badge'], $this->badgeLabels($detail));
        $this->assertSame([], $this->uiExtensionFailures());
    }

    public function testProvidersAreOrderedByOrder(): void
    {
        $detail = $this->viewer($this->detailExtensions([
            ['FixtureDetailExtension'],
            ['SecondDetailExtension', 100],
        ]))->renderDetail(7);

        $this->assertSame(
            ['overview', 'test.uiExtensions.second', 'test.uiExtensions.sales'],
            $this->tabIds($detail),
        );
        $this->assertSame(['Aktivní', 'Viewer badge', 'Second badge', 'Fixture badge'], $this->badgeLabels($detail));
    }

    public function testFailingProviderIsLoggedAndTheRestIsRendered(): void
    {
        $detail = $this->viewer($this->detailExtensions([
            ['ThrowingDetailExtension', 100],
            ['FixtureDetailExtension'],
        ]))->renderDetail(7);

        $this->assertSame('Záznam', $detail['title']);
        $this->assertSame(['overview', 'test.uiExtensions.sales'], $this->tabIds($detail));
        $this->assertSame(['Aktivní', 'Viewer badge', 'Fixture badge'], $this->badgeLabels($detail));

        $failures = $this->uiExtensionFailures();
        $this->assertCount(1, $failures);
        $this->assertSame('test.uiExtensions', $failures[0]['module']);
        $this->assertSame(self::UI_NAMESPACE . 'ThrowingDetailExtension', $failures[0]['class']);
        $this->assertSame(self::UI_TABLE, $failures[0]['table']);
        $this->assertSame(ModuleUiExtensions::DETAIL, $failures[0]['kind']);
        $this->assertSame('detail provider broke', $failures[0]['message']);
    }

    /** @return array<string, array{string, string}> */
    public static function contractViolations(): array
    {
        return [
            'obsah typu html' => ['HtmlDetailExtension', "uses type 'html'"],
            'typ vázaný na viewer v composite' => ['ViewerContentDetailExtension', "uses type 'attachments'"],
            'štítek bez stylu' => ['BadBadgeDetailExtension', 'badges()[0].style must be a non-empty string'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('contractViolations')]
    public function testResultOutsideTheContractSkipsTheWholeProvider(string $class, string $message): void
    {
        $detail = $this->viewer($this->detailExtensions([[$class, 100], ['FixtureDetailExtension']]))->renderDetail(7);

        // Ani platná část poskytovatele (štítek / tab) se nepoužije.
        $this->assertSame(['overview', 'test.uiExtensions.sales'], $this->tabIds($detail));
        $this->assertSame(['Aktivní', 'Viewer badge', 'Fixture badge'], $this->badgeLabels($detail));

        $failures = $this->uiExtensionFailures();
        $this->assertCount(1, $failures);
        $this->assertSame(self::UI_NAMESPACE . $class, $failures[0]['class']);
        $this->assertStringContainsString($message, $failures[0]['message']);
    }

    public function testRegistryPassesExtensionsOnlyToViewerWithTable(): void
    {
        $registry = new ViewerRegistry();
        $registry->setTables($this->uiTables());
        $registry->setUiExtensions($this->uiExtensionsOf());
        $registry->register(new ViewerDefinition('test.uiHost.records', 'Records', self::UI_TABLE, UiHostTestViewer::class, 'test.uiHost'));
        $registry->register(new ViewerDefinition('test.uiHost.config', 'Config', null, UiConfigTestViewer::class, 'test.uiHost'));

        $withTable = $registry->createViewer('test.uiHost.records', $this->db());
        $this->assertSame(['overview', 'test.uiExtensions.sales'], $this->tabIds($withTable->renderDetail(7)));

        $withoutTable = $registry->createViewer('test.uiHost.config', $this->db());
        $this->assertSame(['overview'], $this->tabIds($withoutTable->renderDetail(7)));
    }
}

/** Viewer nad fixturovou tabulkou: jeden tab a jeden štítek z háčků. */
class UiHostTestViewer extends TableViewer
{
    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        return [];
    }

    public function renderRow(array $rowData): array
    {
        return [];
    }

    protected function detailBadges(array $record): array
    {
        return [['label' => 'Viewer badge', 'style' => 'info']];
    }

    protected function detailTabs(array $record): array
    {
        return [['id' => 'overview', 'label' => 'Přehled', 'content' => ['type' => 'text', 'text' => 'viewer']]];
    }
}

/** Viewer bez tabulky: syntetický záznam z konfigurace. */
class UiConfigTestViewer extends UiHostTestViewer
{
    public function loadRecord(int $recordId): ?array
    {
        return ['id' => $recordId, 'name' => 'Config'];
    }
}
