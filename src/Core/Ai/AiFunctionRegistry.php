<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

use Shipard\Core\Config\ConfigCompiler;
use Shipard\Core\Config\ConfigRuntime;

/**
 * Registr AI funkcí (tasks/ai-functions.md, #85 D36): deklarace
 * `aiFunctions` aktivních modulů složené kompilací do cfgItem
 * `core.ai.functions`. Bez compiled configu (zdroj dat před `ds-upgrade`)
 * je registr prázdný — resolver pak žádnou funkci nezná a volání selže
 * čitelně, nikdy odhadem.
 */
final class AiFunctionRegistry
{
    public const string CFG_ITEM = ConfigCompiler::AI_FUNCTIONS_ITEM;

    /** @param array<string, AiFunction> $functions id → funkce v pořadí deklarací */
    private function __construct(private readonly array $functions) {}

    /** Registr z compiled configu; `null` config nebo chybějící cfgItem = prázdný registr. */
    public static function fromConfig(?ConfigRuntime $config): self
    {
        $raw = $config?->cfgItem(self::CFG_ITEM);
        return is_array($raw) ? self::fromArray($raw) : self::empty();
    }

    /**
     * Registr ze surových dat cfgItemu (testy, provisioning).
     *
     * @param array<string, array<string, mixed>> $items id → položka
     * @throws \InvalidArgumentException při nevalidní položce
     */
    public static function fromArray(array $items): self
    {
        $functions = [];
        foreach ($items as $id => $data) {
            if (!is_array($data)) {
                throw new \InvalidArgumentException("AiFunctionRegistry: entry '{$id}' must be an object");
            }
            $functions[(string) $id] = AiFunction::fromArray((string) $id, $data);
        }
        return new self($functions);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->functions === [];
    }

    public function has(string $id): bool
    {
        return isset($this->functions[$id]);
    }

    public function get(string $id): ?AiFunction
    {
        return $this->functions[$id] ?? null;
    }

    /** @return list<AiFunction> v pořadí deklarací (moduly v pořadí rozlišení) */
    public function all(): array
    {
        return array_values($this->functions);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->functions);
    }
}
