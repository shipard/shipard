<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Viewer;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Text z katalogu popisků v `TableViewer` (#133): plochý katalog
 * (`labelText()`), sekce katalogu detailu (`detailText()`,
 * `detailSectionText()`) — náhrada parametrů a fallback na jednom místě.
 */
class TableViewerLabelTextTest extends TestCase
{
    private const FLAT = 'test.viewerLabels';
    private const DETAIL = 'test.viewerDetailLabels';

    private function viewer(bool $withConfig = true): TableViewer
    {
        $viewer = new class($this->createMock(DataSourceConnection::class), 'test_table') extends TableViewer {
            public function selectRows(?string $search, array $filters, int $pageNumber): array
            {
                return [];
            }

            public function renderRow(array $rowData): array
            {
                return [];
            }

            public function flat(string $key, string $fallback, array $params = []): string
            {
                return $this->labelText('test.viewerLabels', $key, $fallback, $params);
            }

            public function detail(string $key, string $fallback, array $params = []): string
            {
                return $this->detailText('test.viewerDetailLabels', $key, $fallback, $params);
            }

            public function section(string $section, string $key, string $fallback): string
            {
                return $this->detailSectionText('test.viewerDetailLabels', $section, $key, $fallback);
            }
        };

        if ($withConfig) {
            $viewer->setConfig(ConfigRuntimeFactory::fromItems([
                self::FLAT => [
                    'title' => ['name' => 'Karta'],
                    'count' => ['name' => '{n} z {total} karet'],
                ],
                self::DETAIL => [
                    'tabs'   => ['title' => ['name' => 'Tab']],
                    'texts'  => ['empty' => ['name' => 'Nic pro {who}.']],
                    'fields' => ['insurer' => ['name' => 'Pojišťovna']],
                ],
            ]));
        }
        return $viewer;
    }

    public function testLabelTextReadsFlatCatalog(): void
    {
        $this->assertSame('Karta', $this->viewer()->flat('title', 'Card'));
    }

    public function testLabelTextReplacesParams(): void
    {
        $this->assertSame('2 z 5 karet', $this->viewer()->flat('count', '{n} of {total} cards', ['n' => 2, 'total' => 5]));
    }

    public function testMissingKeyGivesFallbackWithParams(): void
    {
        $this->assertSame('3 rows', $this->viewer()->flat('unknown', '{n} rows', ['n' => 3]));
    }

    public function testWithoutConfigGivesFallbackWithParams(): void
    {
        $viewer = $this->viewer(withConfig: false);
        $this->assertSame('3 rows', $viewer->flat('count', '{n} rows', ['n' => 3]));
        $this->assertSame('Nothing for me.', $viewer->detail('empty', 'Nothing for {who}.', ['who' => 'me']));
        $this->assertSame('insurer', $viewer->section('fields', 'insurer', 'insurer'));
    }

    public function testDetailTextReadsTextsSectionOnly(): void
    {
        $viewer = $this->viewer();
        $this->assertSame('Nic pro tebe.', $viewer->detail('empty', 'Nothing for {who}.', ['who' => 'tebe']));
        // Klíč ze sekce `tabs` v sekci `texts` není.
        $this->assertSame('Fallback', $viewer->detail('title', 'Fallback'));
    }

    public function testDetailSectionTextReadsNamedSection(): void
    {
        $viewer = $this->viewer();
        $this->assertSame('Pojišťovna', $viewer->section('fields', 'insurer', 'insurer'));
        $this->assertSame('legacyKind', $viewer->section('fields', 'legacyKind', 'legacyKind'));
        $this->assertSame('x', $viewer->section('missing', 'insurer', 'x'));
    }

    public function testFlatCatalogIsNotReadAsSections(): void
    {
        // Plochý katalog nemá sekce — `detailText()` nad ním nic nenajde.
        $viewer = new class($this->createMock(DataSourceConnection::class), 'test_table') extends TableViewer {
            public function selectRows(?string $search, array $filters, int $pageNumber): array
            {
                return [];
            }

            public function renderRow(array $rowData): array
            {
                return [];
            }

            public function detail(string $key, string $fallback): string
            {
                return $this->detailText('test.viewerLabels', $key, $fallback);
            }
        };
        $viewer->setConfig(ConfigRuntimeFactory::fromItems([self::FLAT => ['title' => ['name' => 'Karta']]]));

        $this->assertSame('Title', $viewer->detail('title', 'Title'));
    }
}
