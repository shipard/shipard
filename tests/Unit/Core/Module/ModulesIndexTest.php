<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Module;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModuleKind;
use Shipard\Core\Module\ModulesIndex;

/**
 * Generovaný rozcestník `modules/README.md` (#170 D7): obsah bloku nad
 * syntetickým stromem a shoda souboru se skutečným stromem `modules/`.
 */
class ModulesIndexTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/shpd-modules-index-' . uniqid();
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** @param array<string, mixed> $extra */
    private function makeModule(string $id, array $extra = [], bool $readme = false): void
    {
        $dir = $this->root . '/' . str_replace('.', '/', $id);
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/module.jsonc', json_encode($extra + ['id' => $id, 'name' => $id], JSON_UNESCAPED_UNICODE));
        if ($readme) {
            file_put_contents($dir . '/README.md', '# ' . $id);
        }
    }

    public function testCollectReadsCzechTextsKindAndLink(): void
    {
        $this->makeModule('install.base', ['name' => 'Base installation', 'name:cs' => 'Základní instalace', 'description:cs' => 'Základ']);
        $this->makeModule('economy.docs', ['name' => 'Documents', 'description' => 'Invoices'], readme: true);
        $this->makeModule('facility.core', ['name:cs' => 'Správa budovy', 'kind' => 'addon'], readme: true);

        $entries = ModulesIndex::collect($this->root);

        $this->assertSame(
            [
                ['id' => 'economy.docs', 'kind' => ModuleKind::Core, 'name' => 'Documents', 'description' => 'Invoices', 'link' => 'economy/docs/README.md'],
                ['id' => 'facility.core', 'kind' => ModuleKind::Addon, 'name' => 'Správa budovy', 'description' => '', 'link' => 'facility/core/README.md'],
                ['id' => 'install.base', 'kind' => ModuleKind::Install, 'name' => 'Základní instalace', 'description' => 'Základ', 'link' => 'install/base/module.jsonc'],
            ],
            $entries,
        );
    }

    public function testBlockGroupsModulesByKindAndCoreByGroup(): void
    {
        $this->makeModule('install.base', ['name:cs' => 'Základní instalace', 'description:cs' => 'Základ']);
        $this->makeModule('economy.docs', ['name:cs' => 'Doklady', 'description:cs' => 'Faktury | objednávky'], readme: true);
        $this->makeModule('economy.accounting', ['name:cs' => 'Účetnictví', 'description:cs' => "Podvojné\núčetnictví"]);
        $this->makeModule('core.system', ['name:cs' => 'Systém']);
        $this->makeModule('facility.core', ['name:cs' => 'Správa budovy', 'description:cs' => 'Nájmy', 'kind' => 'addon'], readme: true);

        $expected = <<<'MD'
            <!-- MODULY:BEGIN — generováno scripts/modules-index.php, needituj ručně -->

            ## Instalační moduly

            | Modul | Název | Popis |
            |-------|-------|-------|
            | [`install.base`](install/base/module.jsonc) | Základní instalace | Základ |

            ## Jádro

            ### `core`

            | Modul | Název | Popis |
            |-------|-------|-------|
            | [`core.system`](core/system/module.jsonc) | Systém |  |

            ### `economy`

            | Modul | Název | Popis |
            |-------|-------|-------|
            | [`economy.accounting`](economy/accounting/module.jsonc) | Účetnictví | Podvojné účetnictví |
            | [`economy.docs`](economy/docs/README.md) | Doklady | Faktury \| objednávky |

            ## Doplňky

            | Modul | Název | Popis |
            |-------|-------|-------|
            | [`facility.core`](facility/core/README.md) | Správa budovy | Nájmy |

            <!-- MODULY:END -->
            MD;

        $this->assertSame($expected, ModulesIndex::block(ModulesIndex::collect($this->root)));
    }

    public function testBlockWithoutAddonsSaysSo(): void
    {
        $this->makeModule('install.base');
        $this->makeModule('core.system');

        $block = ModulesIndex::block(ModulesIndex::collect($this->root));

        $this->assertStringContainsString("## Doplňky\n\nZatím žádný doplněk.\n\n" . ModulesIndex::END, $block);
    }

    public function testApplyReplacesOnlyTheBlockBetweenMarkers(): void
    {
        $document = "# Moduly\n\nRuční úvod.\n\n" . ModulesIndex::BEGIN . "\nstarý obsah\n" . ModulesIndex::END . "\n\nRuční patička.\n";
        $block = ModulesIndex::BEGIN . "\n\nnový obsah\n\n" . ModulesIndex::END;

        $this->assertSame(
            "# Moduly\n\nRuční úvod.\n\n" . $block . "\n\nRuční patička.\n",
            ModulesIndex::apply($document, $block),
        );
        $this->assertSame(
            ModulesIndex::apply($document, $block),
            ModulesIndex::apply(ModulesIndex::apply($document, $block), $block),
            'druhý běh nic nemění',
        );
    }

    public function testApplyWithoutMarkersThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        ModulesIndex::apply("# Moduly\n\nBez značek.\n", ModulesIndex::BEGIN . "\n" . ModulesIndex::END);
    }

    public function testRealModulesReadmeIsUpToDate(): void
    {
        $modulesRoot = dirname(__DIR__, 4) . '/modules';
        $readme = (string) file_get_contents($modulesRoot . '/README.md');

        $this->assertSame(
            $readme,
            ModulesIndex::apply($readme, ModulesIndex::block(ModulesIndex::collect($modulesRoot))),
            'modules/README.md neodpovídá module.jsonc modulů — spusť: php scripts/modules-index.php',
        );
    }
}
