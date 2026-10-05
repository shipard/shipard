<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Prints;

use Shipard\Core\Config\ConfigCompiler;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Prints\PrintLanguageResolver;
use Shipard\Core\Prints\Texts\PrintTextSlot;

/**
 * Z čeho se u textu na tiscích vybírá — sloty, tisky, typy dokladů, jazyky.
 * Čte jen kompilovanou konfiguraci, proto ho sdílí formulář, Document
 * i viewer (k registru tisků se bez cest modulů nedostanou; deklarace tisků
 * mají v cfgItemu `ConfigCompiler::PRINTS_ITEM`).
 *
 * Bez konfigurace (unit testy) vrací prázdné nabídky.
 */
final class PrintTextChoices
{
    /** cfgItem s jazyky dokumentů — jen kvůli názvům jazyků; nemusí být aktivní. */
    private const LANGUAGES_CFG_ITEM = 'world.base.documentLanguages';

    public function __construct(
        private readonly ?ConfigRuntime $config,
    ) {}

    /**
     * Sloty v pořadí pro nabídku.
     *
     * @return array<string, array{name: string, description: string}>
     */
    public function slots(): array
    {
        $cfg = $this->config?->cfgItem(PrintTextSlot::CFG_ITEM);
        $cfg = is_array($cfg) ? $cfg : [];

        $slots = [];
        foreach (PrintTextSlot::cases() as $slot) {
            $item = is_array($cfg[$slot->value] ?? null) ? $cfg[$slot->value] : [];
            $slots[$slot->value] = [
                'name'        => (string) ($item['name'] ?? $slot->value),
                'description' => (string) ($item['description'] ?? ''),
                'order'       => (int) ($item['order'] ?? 1000),
            ];
        }
        uasort($slots, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(
            static fn (array $slot): array => ['name' => $slot['name'], 'description' => $slot['description']],
            $slots,
        );
    }

    public function slotName(string $slot): string
    {
        return $this->slots()[$slot]['name'] ?? $slot;
    }

    /** Zná konfigurace deklarace tisků? Bez nich nejde tisky ověřit. */
    public function knowsPrints(): bool
    {
        return is_array($this->config?->cfgItem(ConfigCompiler::PRINTS_ITEM));
    }

    /**
     * Tisky, které podporují slot (`null` = aspoň jeden slot), v pořadí
     * nabídky tisků.
     *
     * @return array<string, array{name: string, table: string, filter: array<string, list<int|string>>, textSlots: list<string>}>
     */
    public function prints(?string $slot = null): array
    {
        $cfg = $this->config?->cfgItem(ConfigCompiler::PRINTS_ITEM);
        if (!is_array($cfg)) {
            return [];
        }

        $prints = [];
        foreach ($cfg as $printId => $item) {
            if (!is_array($item)) {
                continue;
            }
            $textSlots = array_values(array_filter((array) ($item['textSlots'] ?? []), 'is_string'));
            if ($textSlots === [] || ($slot !== null && !in_array($slot, $textSlots, true))) {
                continue;
            }
            $prints[(string) $printId] = [
                'name'      => (string) ($item['name'] ?? $printId),
                'table'     => (string) ($item['table'] ?? ''),
                'filter'    => is_array($item['filter'] ?? null) ? $item['filter'] : [],
                'textSlots' => $textSlots,
                'order'     => (int) ($item['order'] ?? 1000),
            ];
        }
        uasort($prints, static fn (array $a, array $b): int => [$a['order'], $a['name']] <=> [$b['order'], $b['name']]);

        return array_map(static function (array $print): array {
            unset($print['order']);
            return $print;
        }, $prints);
    }

    /**
     * Cílení na typ dokladu a řadu pro text s danými tisky (#90 D47): jde,
     * když jsou všechny vybrané tisky nad jednou tabulkou s typem a řadou.
     * Bez vybraných tisků platí text pro všechny tisky se slotem — cílení
     * pak jde, když má typ a řadu aspoň jeden z nich (u ostatních text
     * s omezením neplatí).
     *
     * @param list<string> $printIds
     */
    public function targeting(array $printIds, ?string $slot): ?PrintTextTargeting
    {
        $available = $this->prints($slot);
        $selected  = $printIds === [] ? $available : array_intersect_key($available, array_flip($printIds));

        $targeting = null;
        foreach ($selected as $print) {
            $candidate = PrintTextTargeting::forTable($print['table']);
            if ($candidate === null) {
                if ($printIds !== []) {
                    return null;
                }
                continue;
            }
            if ($targeting !== null && $targeting->table !== $candidate->table) {
                return null;
            }
            $targeting = $candidate;
        }
        return $targeting;
    }

    /**
     * Typy dokladů, které dotčené tisky tisknou (podle `filter` deklarace;
     * tisk bez filtru = všechny typy číselníku).
     *
     * @param list<string> $printIds
     * @return array<string, string> typ → popisek
     */
    public function docTypes(PrintTextTargeting $targeting, array $printIds, ?string $slot): array
    {
        $cfg = $this->config?->cfgItem($targeting->docTypesCfgItem);
        if (!is_array($cfg)) {
            return [];
        }

        $available = $this->prints($slot);
        $selected  = $printIds === [] ? $available : array_intersect_key($available, array_flip($printIds));

        $types = [];
        foreach ($selected as $print) {
            if ($print['table'] !== $targeting->table) {
                continue;
            }
            $filter = $print['filter'][$targeting->docTypeColumn] ?? null;
            foreach (is_array($filter) ? $filter : array_keys($cfg) as $type) {
                $types[(string) $type] = true;
            }
        }

        $labels = [];
        foreach (array_keys($cfg) as $type) {
            if (isset($types[(string) $type])) {
                $labels[(string) $type] = (string) ($cfg[$type]['name'] ?? $type);
            }
        }
        return $labels;
    }

    /** @return array<string, string> jazyk tisku → název */
    public function languages(): array
    {
        $cfg = $this->config?->cfgItem(self::LANGUAGES_CFG_ITEM);
        $cfg = is_array($cfg) ? $cfg : [];

        $languages = [];
        foreach (PrintLanguageResolver::LANGUAGES as $language) {
            $languages[$language] = (string) ($cfg[$language]['name'] ?? strtoupper($language));
        }
        return $languages;
    }
}
