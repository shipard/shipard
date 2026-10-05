<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Prints;

/**
 * Cílení textu na typ dokladu a číselnou řadu (#90 D47). Modul `core.prints`
 * je obecný a na dokladech nezávisí — co je u záznamu „typ dokladu“ a „řada“,
 * ví jen tato mapa tabulka tisku → sloupce. Tisk nad tabulkou, která tu
 * není, cílení nemá: text s omezením na typ nebo řadu u něj neplatí.
 *
 * Další tabulka s typem a řadou = další řádek `TABLES`, nic jiného.
 */
final class PrintTextTargeting
{
    /**
     * `docTypesCfgItem` — číselník typů (klíč = typ, `name` = popisek);
     * `seriesTable` — tabulka řad se sloupci `id`, `name` a `seriesTypeColumn`.
     */
    private const TABLES = [
        'docs_core_heads' => [
            'docTypeColumn'    => 'doc_type',
            'seriesColumn'     => 'number_series',
            'docTypesCfgItem'  => 'docs.core.docTypes',
            'seriesTable'      => 'docs_core_number_series',
            'seriesTypeColumn' => 'doc_type',
        ],
    ];

    private function __construct(
        public readonly string $table,
        public readonly string $docTypeColumn,
        public readonly string $seriesColumn,
        public readonly string $docTypesCfgItem,
        public readonly string $seriesTable,
        public readonly string $seriesTypeColumn,
    ) {}

    /** Cílení pro tisky nad tabulkou, nebo null, když tabulka typ a řadu nemá. */
    public static function forTable(string $table): ?self
    {
        $map = self::TABLES[$table] ?? null;
        if ($map === null) {
            return null;
        }
        return new self(
            $table,
            $map['docTypeColumn'],
            $map['seriesColumn'],
            $map['docTypesCfgItem'],
            $map['seriesTable'],
            $map['seriesTypeColumn'],
        );
    }
}
