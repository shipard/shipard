<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange\RoundTrip;

use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\TableDefinition;

/**
 * Sloupce, které matice round-tripu dokladu porovnává (#166 etapa 1,
 * tasks/doc-saving-safety-net.md U3): **všechny** sloupce tří tabulek dokladu
 * podle definice tabulek včetně rozšíření — nový sloupec se do porovnání
 * dostane sám. Vyloučené jsou jen identita, audit, původ a to, co plyne
 * z jediné změny exportu (jiné číslo dokladu B).
 *
 * **Další výjimku nepřidávej bez schválení**: sloupec, který se po
 * round-tripu liší a není identita, je nález — zapisuje se jako známá mezera
 * případu ({@see RoundTripCase::gap()}), ne sem.
 */
final class RoundTripColumns
{
    public const HEADS = 'docs_core_heads';
    public const ROWS = 'docs_core_rows';
    public const RECAP = 'docs_core_vat_recap';

    /** Tabulky dokladu v pořadí hlavička → řádky → rekapitulace. */
    public const TABLES = [self::HEADS, self::ROWS, self::RECAP];

    /** @var array<string, array<string, string>> tabulka → sloupec → důvod */
    public const EXCLUDED = [
        self::HEADS => [
            'id'                   => 'identita záznamu',
            'doc_number'           => 'identita dokladu — doklad B dostává v exportu jiné číslo (U2 krok 4)',
            'sequence_number'      => 'identita v řadě — doklad B jde se sequenceNumber null, aby se čítač řady nepohnul (U2 krok 4)',
            'doc_state_changed_at' => 'audit — čas změny stavu',
            'source_kind'          => 'původ dokladu',
            'source_message'       => 'původ dokladu',
            'source_extracted_at'  => 'původ dokladu',
        ],
        self::ROWS => [
            'id'       => 'identita záznamu',
            'doc_head' => 'cizí klíč na hlavičku',
        ],
        self::RECAP => [
            'id'       => 'identita záznamu',
            'doc_head' => 'cizí klíč na hlavičku',
        ],
    ];

    /**
     * Porovnávané sloupce tabulky — definice bez vyloučených.
     *
     * @return list<ColumnDefinition>
     */
    public static function compared(string $table, TableDefinition $definition): array
    {
        $excluded = self::EXCLUDED[$table] ?? [];
        return array_values(array_filter(
            $definition->columns,
            static fn (ColumnDefinition $column): bool => !isset($excluded[$column->id]),
        ));
    }
}
