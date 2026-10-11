<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Mapa platforem z cfgItemu `core.ai.platforms` (`modules/core/ai/config/
 * platforms.jsonc`): jediné místo, které ho čte — resolver, dokument
 * a formulář připojení, viewery, `ai-test`. Bez compiled configu je mapa
 * prázdná: platforma připojení se nehlídá a resolver použije
 * {@see AiPlatform::fallback()}.
 */
final class AiPlatforms
{
    /** @param array<string, AiPlatform> $platforms id → platforma, seřazené podle `order` */
    private function __construct(private readonly array $platforms) {}

    public static function fromConfig(?ConfigRuntime $config): self
    {
        $raw = $config?->cfgItem(AiPlatform::CFG_ITEM);
        return is_array($raw) ? self::fromArray($raw) : self::empty();
    }

    /**
     * @param array<string, array<string, mixed>> $items id → položka
     * @throws \InvalidArgumentException při nevalidní položce
     */
    public static function fromArray(array $items): self
    {
        $platforms = [];
        foreach ($items as $id => $data) {
            if (!is_array($data)) {
                throw new \InvalidArgumentException("AiPlatforms: entry '{$id}' must be an object");
            }
            $platforms[(string) $id] = AiPlatform::fromArray((string) $id, $data);
        }
        uasort($platforms, static fn (AiPlatform $a, AiPlatform $b): int => [$a->order, $a->id] <=> [$b->order, $b->id]);
        return new self($platforms);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->platforms === [];
    }

    public function has(string $id): bool
    {
        return isset($this->platforms[$id]);
    }

    public function get(string $id): ?AiPlatform
    {
        return $this->platforms[$id] ?? null;
    }

    /**
     * Platforma podle id; bez položky (nebo bez cfgItemu) záložní
     * platforma bez adresy a kontrol.
     */
    public function getOrFallback(string $id): AiPlatform
    {
        return $this->platforms[$id] ?? AiPlatform::fallback($id);
    }

    /** Název platformy pro uživatele; bez položky klíč. */
    public function label(string $id): string
    {
        return $this->platforms[$id]->name ?? $id;
    }

    /** @return list<AiPlatform> seřazené podle `order`, pak id */
    public function all(): array
    {
        return array_values($this->platforms);
    }
}
