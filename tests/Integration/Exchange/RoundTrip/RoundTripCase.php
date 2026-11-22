<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange\RoundTrip;

/**
 * Jeden případ matice round-tripu (U4, U5): doklad A sestavený z interních
 * dat a očekávání, jestli se po exportu a apply vrátí stejný.
 *
 * `holds` = doklad B se shoduje ve všech porovnávaných sloupcích.
 * `gap` = **známá mezera** s přesným popisem: buď chyba apply
 * (`['applyError' => ['code' => …, 'issues' => [kódy chyb]]]`), nebo
 * seznam rozdílů (`['diff' => ['head.sloupec' => [A, B], …]]`). Test tvrdí,
 * že rozdíl je přesně tenhle — když zmizí, řekne „přepni na `holds`“.
 * Očekávání `gap` se nastavuje podle skutečného běhu, ne odhadem.
 */
final class RoundTripCase
{
    public const HOLDS = 'holds';
    public const GAP = 'gap';

    /**
     * @param \Closure(RoundTripFixtures): array<string, mixed> $build data dokladu A pro
     *        `TableGateway::saveDocument('docs_core_heads')` bez `docState` (hlavička,
     *        `rows`, případně `vatRecap`)
     * @param int $docState cílový stav dokladu A (10 Koncept, 40 V pořádku)
     * @param array<string, mixed>|null $gap popis známé mezery; null u `holds`
     */
    private function __construct(
        public readonly string $id,
        public readonly string $description,
        public readonly \Closure $build,
        public readonly int $docState,
        public readonly string $expectation,
        public readonly ?array $gap,
    ) {}

    /**
     * @param \Closure(RoundTripFixtures): array<string, mixed> $build
     */
    public static function holds(string $id, string $description, \Closure $build, int $docState = 40): self
    {
        return new self($id, $description, $build, $docState, self::HOLDS, null);
    }

    /**
     * @param \Closure(RoundTripFixtures): array<string, mixed> $build
     * @param array<string, mixed> $gap `['applyError' => …]` nebo `['diff' => …]`
     */
    public static function gap(string $id, string $description, \Closure $build, array $gap, int $docState = 40): self
    {
        if (!isset($gap['applyError']) && !isset($gap['diff'])) {
            throw new \InvalidArgumentException("Mezera `{$id}` musí nést `applyError` nebo `diff`.");
        }
        return new self($id, $description, $build, $docState, self::GAP, $gap);
    }

    /** Tentýž doklad v Konceptu; id dostane příponu `@concept`. */
    public function asConcept(string $expectation = self::HOLDS, ?array $gap = null): self
    {
        return new self(
            $this->id . '@concept',
            $this->description . ' — Koncept',
            $this->build,
            10,
            $expectation,
            $expectation === self::GAP ? $gap : null,
        );
    }
}
