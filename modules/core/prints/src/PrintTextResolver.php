<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Prints;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\Texts\PrintTextProvider;

/**
 * Výběr textů platných pro tisk záznamu (#90 D47, D48, D52):
 *
 *  1. stav V pořádku a slot, který tisk podporuje (`textSlots` deklarace);
 *  2. `prints` prázdné, nebo obsahuje id tisku;
 *  3. `doc_types` / `number_series`: jsou-li vyplněné, musí odpovídat
 *     záznamu — a tisk musí být nad tabulkou, která typ a řadu má
 *     (`PrintTextTargeting`); u jiné tabulky text s omezením neplatí;
 *  4. `language` prázdný, nebo jazyk tisku;
 *  5. den tisku uvnitř platnosti (oba kraje včetně) — ne datum dokladu;
 *  6. pořadí `order_pos`, `id`; do slotu jdou všechny platné texty.
 *
 * Zdroj dat bez tabulky textů (před `ds-upgrade`) tiskne bez nich.
 */
final class PrintTextResolver implements PrintTextProvider
{
    /** Stav, ve kterém se text tiskne. */
    private const ACTIVE_DOC_STATE = 40;

    private ?bool $tableReady = null;

    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    public function resolve(
        PrintDefinition $definition,
        array $record,
        string $language,
        \DateTimeImmutable $today,
    ): array {
        if ($definition->textSlots === [] || !$this->tableReady()) {
            return [];
        }

        $day  = $today->format('Y-m-d');
        $rows = $this->db->fetchAll(
            'SELECT [id], [slot], [text], [prints], [doc_types], [number_series] FROM %n'
            . ' WHERE [docState] = %i AND [slot] IN %in'
            . ' AND ([language] IS NULL OR [language] = %s)'
            . ' AND ([valid_from] IS NULL OR [valid_from] <= %s)'
            . ' AND ([valid_to] IS NULL OR [valid_to] >= %s)'
            . ' ORDER BY [order_pos], [id]',
            PrintTextDocument::TABLE,
            self::ACTIVE_DOC_STATE,
            $definition->textSlots,
            $language,
            $day,
            $day,
        );

        $targeting = PrintTextTargeting::forTable($definition->table);

        $texts = [];
        foreach ($rows as $row) {
            $prints = self::listValue($row['prints'] ?? null);
            if ($prints !== [] && !in_array($definition->id, $prints, true)) {
                continue;
            }

            $docTypes = self::listValue($row['doc_types'] ?? null);
            $series   = self::listValue($row['number_series'] ?? null);
            if ($docTypes !== [] || $series !== []) {
                if ($targeting === null) {
                    continue;
                }
                if ($docTypes !== [] && !in_array(
                    (string) ($record[$targeting->docTypeColumn] ?? ''),
                    array_map('strval', $docTypes),
                    true,
                )) {
                    continue;
                }
                if ($series !== [] && !in_array(
                    (int) ($record[$targeting->seriesColumn] ?? 0),
                    array_map('intval', $series),
                    true,
                )) {
                    continue;
                }
            }

            $texts[(string) $row['slot']][] = ['id' => (int) $row['id'], 'text' => (string) $row['text']];
        }

        return $texts;
    }

    private function tableReady(): bool
    {
        return $this->tableReady ??= $this->db->getTableColumns(PrintTextDocument::TABLE) !== [];
    }

    /** @return list<mixed> */
    private static function listValue(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }
        return is_array($value) ? array_values($value) : [];
    }
}
