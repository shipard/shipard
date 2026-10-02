<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/**
 * Registr deklarovaných tisků per DS — plní ho `PrintDefinitionLoader`
 * z klíče `prints` v module.jsonc. Duplicitní id napříč moduly = tvrdá
 * chyba při načtení (vzor `ReportRegistry`).
 */
final class PrintRegistry
{
    /** @var array<string, PrintDefinition> */
    private array $prints = [];

    public function add(PrintDefinition $definition): void
    {
        if (isset($this->prints[$definition->id])) {
            $existing = $this->prints[$definition->id];
            throw new \RuntimeException(
                "PrintRegistry: duplicate print id '{$definition->id}'"
                . " — registered in '{$existing->moduleId}' and '{$definition->moduleId}'",
            );
        }
        $this->prints[$definition->id] = $definition;
    }

    public function get(string $printId): ?PrintDefinition
    {
        return $this->prints[$printId] ?? null;
    }

    /** @return PrintDefinition[] */
    public function getAll(): array
    {
        return array_values($this->prints);
    }

    /**
     * Tisky dostupné pro záznam: sedí tabulka, `filter` i `docStates`.
     * Seřazené podle `order`, při shodě podle id (stabilní pořadí nabídky).
     *
     * @param array<string, mixed> $record
     * @return PrintDefinition[]
     */
    public function forRecord(string $table, array $record): array
    {
        $matching = [];
        foreach ($this->prints as $definition) {
            if ($definition->table === $table && $definition->matches($record)) {
                $matching[] = $definition;
            }
        }
        usort(
            $matching,
            static fn (PrintDefinition $a, PrintDefinition $b): int
                => [$a->order, $a->id] <=> [$b->order, $b->id],
        );
        return $matching;
    }
}
