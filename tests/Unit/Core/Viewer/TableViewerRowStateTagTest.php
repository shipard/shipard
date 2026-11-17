<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Viewer;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Stav v řádku seznamu jednou sdílenou metodou (#153 D98):
 * `TableViewer::rowStateTag()` nevypisuje Koncept ani stav
 * s `detailBadge: false`, ostatní stavy vrací se třídou spanu z jediné
 * mapy; žádný viewer nemá vlastní kopii mapy.
 */
class TableViewerRowStateTagTest extends TestCase
{
    private const STATES = 'test.docStates';

    /** @param array<string, string> $classOverrides stateStyle → třída (přepis vieweru) */
    private function viewer(bool $withConfig = true, ?string $cfgItem = self::STATES, array $classOverrides = []): TableViewer
    {
        $viewer = new class($this->createMock(DataSourceConnection::class), 'test_table') extends TableViewer {
            /** @var array<string, string> */
            public array $classOverrides = [];

            public function selectRows(?string $search, array $filters, int $pageNumber): array
            {
                return [];
            }

            public function renderRow(array $rowData): array
            {
                return [];
            }

            public function useStates(?string $cfgItem): void
            {
                $this->docStatesCfgItem = $cfgItem;
            }

            public function tag(int $docState): ?array
            {
                return $this->rowStateTag($docState);
            }

            protected function rowStateClass(string $stateStyle): string
            {
                return $this->classOverrides[$stateStyle] ?? parent::rowStateClass($stateStyle);
            }
        };
        $viewer->useStates($cfgItem);
        $viewer->classOverrides = $classOverrides;

        if ($withConfig) {
            $viewer->setConfig(ConfigRuntimeFactory::fromItems([
                self::STATES => [
                    '10' => ['stateName' => 'Koncept', 'stateStyle' => 'concept'],
                    '20' => ['stateName' => 'V práci', 'stateStyle' => 'confirmed'],
                    '30' => ['stateName' => 'Storno', 'stateStyle' => 'cancelled'],
                    '40' => ['stateName' => 'V pořádku', 'stateStyle' => 'done', 'detailBadge' => false],
                    '45' => ['stateName' => 'Podáno', 'stateStyle' => 'done'],
                    '50' => ['stateName' => 'Sestaveno', 'stateStyle' => 'concept'],
                    '60' => ['stateName' => 'Zvláštní', 'stateStyle' => 'unknownStyle'],
                    '65' => ['stateName' => '', 'stateStyle' => 'done'],
                    '70' => ['stateName' => 'V archívu', 'stateStyle' => 'archive'],
                    '80' => ['stateName' => 'V opravě', 'stateStyle' => 'edit'],
                    '90' => ['stateName' => 'Smazáno', 'stateStyle' => 'trash'],
                ],
            ]));
        }
        return $viewer;
    }

    public function testConceptStyleIsNotPrinted(): void
    {
        $this->assertNull($this->viewer()->tag(10), 'Koncept nese proužek');
        $this->assertNull($this->viewer()->tag(50), 'rozhoduje stateStyle, ne hodnota 10');
    }

    public function testStateWithoutDetailBadgeIsNotPrinted(): void
    {
        $this->assertNull($this->viewer()->tag(40), 'V pořádku jako v hlavičce detailu (D20)');
    }

    public function testOtherStatesArePrintedWithSharedClass(): void
    {
        $viewer = $this->viewer();
        $this->assertSame(['text' => 'V práci', 'class' => 'primary'], $viewer->tag(20));
        $this->assertSame(['text' => 'Storno', 'class' => 'danger'], $viewer->tag(30));
        $this->assertSame(['text' => 'Podáno', 'class' => 'success'], $viewer->tag(45), 'done bez detailBadge: false se vypisuje');
        $this->assertSame(['text' => 'V archívu', 'class' => 'muted'], $viewer->tag(70));
        $this->assertSame(['text' => 'V opravě', 'class' => 'warning'], $viewer->tag(80));
        $this->assertSame(['text' => 'Smazáno', 'class' => 'muted'], $viewer->tag(90));
    }

    public function testUnknownStyleFallsBackToMuted(): void
    {
        $this->assertSame(['text' => 'Zvláštní', 'class' => 'muted'], $this->viewer()->tag(60));
    }

    public function testViewerMayOverrideClassOfOneStyle(): void
    {
        $viewer = $this->viewer(classOverrides: ['edit' => 'muted']);
        $this->assertSame(['text' => 'V opravě', 'class' => 'muted'], $viewer->tag(80));
        $this->assertSame(['text' => 'Storno', 'class' => 'danger'], $viewer->tag(30), 'ostatní styly ze sdílené mapy');
    }

    public function testNothingWithoutStatesOrName(): void
    {
        $this->assertNull($this->viewer(withConfig: false)->tag(80), 'bez konfigurace');
        $this->assertNull($this->viewer(cfgItem: null)->tag(80), 'viewer bez sady stavů');
        $this->assertNull($this->viewer()->tag(99), 'neznámý stav');
        $this->assertNull($this->viewer()->tag(65), 'stav bez názvu');
    }

    /**
     * Mapa stav → třída spanu žije jen v `TableViewer`: žádný zdroj modulu
     * nemá vlastní `STATE_SPAN_CLASS` (dřív 31 kopií) a jádro ji má jednou.
     */
    public function testNoModuleSourceHasItsOwnStateSpanClassMap(): void
    {
        $root = dirname(__DIR__, 4);
        $offenders = [];
        foreach (glob($root . '/modules/*/*/src', GLOB_ONLYDIR) ?: [] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php'
                    && str_contains((string) file_get_contents($file->getPathname()), 'STATE_SPAN_CLASS')) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($offenders);
        $this->assertSame([], $offenders, 'Stav v řádku vypisuje TableViewer::rowStateTag(), třídu dává rowStateClass().');

        $core = (string) file_get_contents($root . '/src/Core/Viewer/TableViewer.php');
        $this->assertSame(1, substr_count($core, 'const STATE_SPAN_CLASS'), 'jediná mapa v TableViewer');
    }
}
