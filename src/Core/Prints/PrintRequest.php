<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Obálka pro `PrintBuilder::build()`. Staví ji výhradně `PrintRunner` —
 * záznam už je načtený a ověřený proti deklaraci (tabulka, filtr, stav).
 *
 * `config` je konfigurace **v jazyce tisku**, ne v jazyce requestu: popisky
 * číselníků řeší builder v jazyce tisku (#90 D13). `translator` nese
 * sloučený katalog tisku ve stejném jazyce.
 */
final class PrintRequest
{
    /** @param array<string, mixed> $record Řádek tabulky deklarace (`SELECT *`). */
    public function __construct(
        public readonly PrintDefinition $definition,
        public readonly int $recordId,
        public readonly array $record,
        public readonly string $language,
        public readonly DataSourceConnection $db,
        public readonly ?ConfigRuntime $config,
        public readonly PrintTranslator $translator,
    ) {}
}
