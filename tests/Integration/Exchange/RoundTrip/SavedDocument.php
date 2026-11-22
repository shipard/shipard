<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange\RoundTrip;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Uložený doklad tak, jak leží v databázi: hlavička, řádky a rekapitulace
 * DPH se všemi sloupci (`SELECT *`). Vstup {@see SavedDocumentComparator}.
 */
final class SavedDocument
{
    /**
     * @param array<string, mixed>       $head
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $recap
     */
    public function __construct(
        public readonly array $head,
        public readonly array $rows,
        public readonly array $recap,
    ) {}

    public static function load(DataSourceConnection $db, int $headId): self
    {
        $head = $db->fetchRow('SELECT * FROM [docs_core_heads] WHERE [id] = %i', $headId);
        if ($head === null) {
            throw new \RuntimeException("Doklad #{$headId} v databázi není.");
        }
        return new self(
            $head,
            array_values($db->fetchAll(
                'SELECT * FROM [docs_core_rows] WHERE [doc_head] = %i ORDER BY [order_pos], [id]',
                $headId,
            )),
            array_values($db->fetchAll(
                'SELECT * FROM [docs_core_vat_recap] WHERE [doc_head] = %i ORDER BY [order_pos], [id]',
                $headId,
            )),
        );
    }
}
