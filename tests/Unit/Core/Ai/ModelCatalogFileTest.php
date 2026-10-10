<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Ai\ModelSpec;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\JsoncParser;

/**
 * Konzistence skutečného katalogu `modules/core/ai/config/models.jsonc`
 * (tasks/ai-model-catalog.md krok 1) a mapy úrovní `tiers.jsonc`
 * (tasks/ai-functions.md D37): každá položka má ceny, každá úroveň mapy
 * ukazuje na aktivní model katalogu se shodnou úrovní, `replacedBy`
 * ukazuje na existující aktivní model, `thinkingModes` / `effortLevels`
 * obsahují jen hodnoty z cfgItemů `core.ai.thinkingModes` /
 * `core.ai.effortLevels` (kromě `enabled`, které naše parametry neposílají),
 * platformová ID sedí na klíč a na prefix Bedrocku.
 */
final class ModelCatalogFileTest extends TestCase
{
    private const MODULE = 'core.ai';

    /** @var array<string, array<string, array<string, mixed>>> cfgItem id → surová data z repozitáře */
    private static array $raw = [];

    /** @return array<string, array<string, mixed>> surový cfgItem modulu core.ai z repozitáře */
    private static function rawItem(string $cfgItemId): array
    {
        if (isset(self::$raw[$cfgItemId])) {
            return self::$raw[$cfgItemId];
        }
        $resolver = new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
        foreach (ModuleLoader::loadAllModules($resolver) as $module) {
            if ($module->id !== self::MODULE) {
                continue;
            }
            foreach ($module->config as $entry) {
                if (($entry['id'] ?? null) === $cfgItemId) {
                    $raw = JsoncParser::parseFile($resolver->getPath($module->id) . '/' . $entry['file']);
                    self::assertIsArray($raw);
                    self::assertNotEmpty($raw);
                    return self::$raw[$cfgItemId] = $raw;
                }
            }
        }
        self::fail('core.ai neregistruje cfgItem ' . $cfgItemId);
    }

    /** @return array<string, array<string, mixed>> surový katalog z repozitáře */
    private static function raw(): array
    {
        return self::rawItem(ModelCatalog::CFG_ITEM);
    }

    /** @return list<string> klíče cfgItemu bez `auto` */
    private static function cfgKeys(string $file): array
    {
        $raw = JsoncParser::parseFile(dirname(__DIR__, 4) . '/modules/core/ai/config/' . $file);
        return array_values(array_filter(array_map('strval', array_keys($raw)), static fn (string $k): bool => $k !== 'auto'));
    }

    private function catalog(): ModelCatalog
    {
        return ModelCatalog::fromArray(self::raw(), self::rawItem(ModelCatalog::TIERS_CFG_ITEM));
    }

    public function testCatalogBuildsAndEveryEntryHasPrices(): void
    {
        $catalog = $this->catalog();
        $this->assertFalse($catalog->isEmpty());

        foreach ($catalog->all() as $spec) {
            $this->assertTrue($spec->hasPrices(), "{$spec->id}: chybí ceny");
            $this->assertSame('anthropic', $spec->vendor, $spec->id);
            $this->assertSame('anthropic-messages', $spec->dialect, $spec->id);
        }
    }

    public function testEveryTierOfTheTierMapPointsToAnActiveCatalogModel(): void
    {
        $catalog = $this->catalog();
        $tiers = $catalog->tiers();

        // D37: všechny tři úrovně deklarované, žádná navíc.
        $this->assertSame(ModelSpec::TIERS, array_values(array_intersect(ModelSpec::TIERS, array_keys($tiers))));
        $this->assertCount(count(ModelSpec::TIERS), $tiers);

        foreach ($tiers as $tier => $entry) {
            $spec = $catalog->find($entry['model']);
            $this->assertNotNull($spec, "úroveň {$tier}: model '{$entry['model']}' není v katalogu");
            $this->assertSame($entry['model'], $spec->id, "úroveň {$tier}: model musí být klíč katalogu, ne alias");
            $this->assertSame(ModelSpec::STATUS_ACTIVE, $spec->status, "úroveň {$tier}: model '{$spec->id}' není aktivní");
            $this->assertNotNull($spec->tier, "úroveň {$tier}: model '{$spec->id}' nemá úroveň (jen ceník)");
            $this->assertNotEmpty($entry['name'], "úroveň {$tier}: chybí název");
        }

        // Přemostění (tasks/ai-functions.md): do evaluace fáze 2 všechny
        // úrovně na Sonnetu 4.6 — nasazení AI funkcí nemění chování.
        foreach (ModelSpec::TIERS as $tier) {
            $this->assertSame('claude-sonnet-4-6', $catalog->defaultModel($tier), "úroveň {$tier}");
        }
    }

    public function testReplacedByPointsToAnActiveModelAndRetiringEntriesHaveOne(): void
    {
        $catalog = $this->catalog();
        foreach ($catalog->all() as $spec) {
            if ($spec->replacedBy !== null) {
                $target = $catalog->find($spec->replacedBy);
                $this->assertNotNull($target, "{$spec->id}: replacedBy '{$spec->replacedBy}' není v katalogu");
                $this->assertSame($spec->replacedBy, $target->id, "{$spec->id}: replacedBy musí být klíč, ne alias");
                $this->assertSame(ModelSpec::STATUS_ACTIVE, $target->status, "{$spec->id}: náhrada '{$target->id}' není aktivní");
            }
            if ($spec->isRetiring()) {
                $this->assertNotNull($spec->replacedBy, "{$spec->id}: vyřazený model bez náhrady by se při ds-upgrade nepřepsal");
                $this->assertNotNull($spec->retiresAt, "{$spec->id}: vyřazený model bez data konce");
            } else {
                $this->assertNull($spec->replacedBy, "{$spec->id}: aktivní model nemá mít replacedBy");
            }
        }

        // Přemostění F0-D8: Sonnet 4.5 → 4.6, ne na řadu 5.
        $this->assertSame('claude-sonnet-4-6', $catalog->retiredReplacement('claude-sonnet-4-5'));
        $this->assertSame('claude-sonnet-4-6', $catalog->retiredReplacement('claude-sonnet-4-5-20250929'));
        $this->assertNull($catalog->retiredReplacement('claude-sonnet-4-6'));
    }

    public function testCapabilityValuesComeFromTheTuningCfgItems(): void
    {
        $thinking = self::cfgKeys('thinkingModes.jsonc');
        $effort = self::cfgKeys('effortLevels.jsonc');
        $this->assertContains('between_tools', $thinking);
        $this->assertContains('xhigh', $effort);

        foreach ($this->catalog()->all() as $spec) {
            foreach ($spec->thinkingModes ?? [] as $mode) {
                // `enabled` (thinking s rozpočtem) v cfgItemu není — neposíláme ho.
                if ($mode === 'enabled') {
                    continue;
                }
                $this->assertContains($mode, $thinking, "{$spec->id}: thinkingModes obsahuje '{$mode}' mimo core.ai.thinkingModes");
            }
            foreach ($spec->effortLevels ?? [] as $level) {
                $this->assertContains($level, $effort, "{$spec->id}: effortLevels obsahuje '{$level}' mimo core.ai.effortLevels");
            }
            if ($spec->thinkingOffMaxEffort !== null) {
                $this->assertContains($spec->thinkingOffMaxEffort, $effort, "{$spec->id}: thinkingOffMaxEffort mimo core.ai.effortLevels");
                $this->assertNotNull($spec->thinkingModes, "{$spec->id}: strop effortu bez známých režimů thinkingu");
                $this->assertNotEmpty(
                    array_intersect(['disabled', 'between_tools'], $spec->thinkingModes),
                    "{$spec->id}: strop effortu u modelu, který thinking vypnout neumí",
                );
            }
        }
    }

    public function testPlatformIdsMatchTheKeyAndBedrockPrefix(): void
    {
        foreach ($this->catalog()->all() as $spec) {
            $this->assertSame(
                $spec->id,
                $spec->platformIds[ModelCatalog::PLATFORM_ANTHROPIC] ?? null,
                "{$spec->id}: platformIds.anthropic musí být klíč položky",
            );
            foreach ($spec->platformIds as $platform => $platformId) {
                $this->assertContains($platform, [ModelCatalog::PLATFORM_ANTHROPIC, ModelCatalog::PLATFORM_BEDROCK], "{$spec->id}: neznámá platforma '{$platform}'");
            }
            if (isset($spec->platformIds[ModelCatalog::PLATFORM_BEDROCK])) {
                $this->assertSame('anthropic.' . $spec->id, $spec->platformIds[ModelCatalog::PLATFORM_BEDROCK], "{$spec->id}: Bedrock ID");
            }
        }
    }

    public function testOfferedModelsCarryTheDecisionFields(): void
    {
        // Modely s úrovní nabízíme — u nich musí být vyplněno, podle čeho
        // se chat (chatToolLoop) a provisioner (lifecycle) rozhodují.
        foreach ($this->catalog()->all() as $spec) {
            if ($spec->tier === null) {
                continue;
            }
            $this->assertNotNull($spec->chatToolLoop, "{$spec->id}: chatToolLoop");
            $this->assertNotNull($spec->thinkingDefault, "{$spec->id}: thinkingDefault");
            $this->assertNotNull($spec->temperature, "{$spec->id}: temperature");
            $this->assertTrue(
                $spec->isRetiring() ? $spec->retiresAt !== null : $spec->retiresNotBefore !== null,
                "{$spec->id}: chybí datum životního cyklu",
            );
            // chatToolLoop = model bez parametru nepřemýšlí (thinking bloky
            // by se musely vracet — fáze 4).
            $this->assertSame(
                $spec->thinkingDefault === ModelSpec::THINKING_DEFAULT_OFF,
                $spec->chatToolLoop,
                "{$spec->id}: chatToolLoop neodpovídá thinkingDefault",
            );
        }
    }
}
