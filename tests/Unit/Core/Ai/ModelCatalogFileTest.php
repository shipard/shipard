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
 * (tasks/ai-model-catalog.md krok 1): každá položka má ceny, `tierDefault`
 * nejvýš jednou na úroveň a `balanced` = Sonnet 4.6 (D34), `replacedBy`
 * ukazuje na existující aktivní model, `thinkingModes` / `effortLevels`
 * obsahují jen hodnoty z cfgItemů `core.ai.thinkingModes` /
 * `core.ai.effortLevels` (kromě `enabled`, které naše parametry neposílají),
 * platformová ID sedí na klíč a na prefix Bedrocku.
 */
final class ModelCatalogFileTest extends TestCase
{
    private const MODULE = 'core.ai';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $raw = null;

    /** @return array<string, array<string, mixed>> surový katalog z repozitáře */
    private static function raw(): array
    {
        if (self::$raw !== null) {
            return self::$raw;
        }
        $resolver = new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
        foreach (ModuleLoader::loadAllModules($resolver) as $module) {
            if ($module->id !== self::MODULE) {
                continue;
            }
            foreach ($module->config as $entry) {
                if (($entry['id'] ?? null) === ModelCatalog::CFG_ITEM) {
                    $raw = JsoncParser::parseFile($resolver->getPath($module->id) . '/' . $entry['file']);
                    self::assertIsArray($raw);
                    self::assertNotEmpty($raw);
                    return self::$raw = $raw;
                }
            }
        }
        self::fail('core.ai neregistruje cfgItem ' . ModelCatalog::CFG_ITEM);
    }

    /** @return list<string> klíče cfgItemu bez `auto` */
    private static function cfgKeys(string $file): array
    {
        $raw = JsoncParser::parseFile(dirname(__DIR__, 4) . '/modules/core/ai/config/' . $file);
        return array_values(array_filter(array_map('strval', array_keys($raw)), static fn (string $k): bool => $k !== 'auto'));
    }

    private function catalog(): ModelCatalog
    {
        return ModelCatalog::fromArray(self::raw());
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

    public function testTierDefaultAtMostOncePerTierAndBalancedIsSonnet46(): void
    {
        $defaults = [];
        foreach ($this->catalog()->all() as $spec) {
            if ($spec->tierDefault) {
                $this->assertArrayNotHasKey((string) $spec->tier, $defaults, "úroveň {$spec->tier} má dva výchozí modely");
                $defaults[(string) $spec->tier] = $spec->id;
            }
        }

        // D34: zatím jen balanced; fast a strong až po evaluaci (fáze 2).
        $this->assertSame([ModelSpec::TIER_BALANCED => 'claude-sonnet-4-6'], $defaults);
        $this->assertSame('claude-sonnet-4-6', $this->catalog()->defaultModel(ModelSpec::TIER_BALANCED));
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
