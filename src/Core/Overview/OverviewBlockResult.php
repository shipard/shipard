<?php

declare(strict_types=1);

namespace Shipard\Core\Overview;

/**
 * Výsledek jednoho bloku. `unavailable` = blok číslo vědomě neukazuje
 * (pojistka počátečních stavů D6 / D15) a říká proč zprávou; sekce bez
 * jediné položky pak dostane stav `unavailable`, sekce s položkami jiného
 * bloku zůstává `ok` a zpráva stojí vedle nich.
 */
final readonly class OverviewBlockResult
{
    public const STATE_OK          = 'ok';
    public const STATE_UNAVAILABLE = 'unavailable';

    /**
     * @param list<OverviewItem>    $items
     * @param list<OverviewMessage> $messages
     */
    public function __construct(
        public array $items = [],
        public array $messages = [],
        public string $state = self::STATE_OK,
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    public static function unavailable(OverviewMessage $message): self
    {
        return new self([], [$message], self::STATE_UNAVAILABLE);
    }
}
