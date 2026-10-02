<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Prints;

/**
 * Fixture doklady pro testy tisků: faktura (plátce, dvě sazby, textový
 * řádek, odpočet zálohy, cizí měna) a zálohová faktura. Vkládají se přímo
 * ve stavu 40 se snapshoty stran z `tests/Fixtures/Prints/*.data.json`,
 * takže výstup builderu nezávisí na adresáři zdroje dat.
 *
 * Vyžaduje řady invno a invpo a registraci k DPH pro cz. Čísla dokladů
 * jsou pevná — `prepareFixtureDocuments()` uklidí zbytky po spadlém běhu.
 */
trait PrintFixtureDocuments
{
    private const INVOICE_NUMBER  = 'IT-PRINT-INV';
    private const PROFORMA_NUMBER = 'IT-PRINT-PRO';

    /** @var list<int> */
    private array $createdHeads = [];

    private int $vatRegistration = 0;

    private function prepareFixtureDocuments(): void
    {
        $registration = $this->db->fetchSingle(
            'SELECT [id] FROM [economy_codebooks_vat_registrations] WHERE [country] = %s ORDER BY [id] LIMIT 1',
            'cz',
        );
        if ($registration === null) {
            $this->markTestSkipped('DS nemá registraci k DPH pro cz.');
        }
        $this->vatRegistration = (int) $registration;

        foreach ($this->db->fetchAll(
            'SELECT [id] FROM [docs_core_heads] WHERE [doc_number] IN %in',
            [self::INVOICE_NUMBER, self::PROFORMA_NUMBER],
        ) as $leftover) {
            $this->deleteHead((int) $leftover['id']);
        }
    }

    private function deleteFixtureDocuments(): void
    {
        foreach ($this->createdHeads as $id) {
            $this->deleteHead($id);
        }
    }

    private function deleteHead(int $id): void
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $id)->execute();
        $dibi->delete('docs_core_rows')->where('doc_head = %i', $id)->execute();
        $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
    }

    /** @return array<string, mixed> */
    private function expected(string $name): array
    {
        $file = dirname(__DIR__, 2) . '/Fixtures/Prints/' . $name . '.data.json';
        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Čísla sjednotí na float — JSON fixture nese `1000`, builder `1000.0`;
     * porovnání pak může být striktní (null ≠ 0 ≠ false).
     */
    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::normalize(...), $value);
        }
        return is_int($value) ? (float) $value : $value;
    }

    /** @return array{0: int, 1: string} */
    private function anyUnit(): array
    {
        $unit = $this->db->fetchRow('SELECT [id], [shortcut] FROM [core_units] ORDER BY [id] LIMIT 1');
        if ($unit === null) {
            $this->markTestSkipped('DS nemá žádnou jednotku.');
        }
        return [(int) $unit['id'], (string) $unit['shortcut']];
    }

    private function seriesFor(string $docType): int
    {
        $series = $this->db->fetchSingle(
            'SELECT [id] FROM [docs_core_number_series] WHERE [doc_type] = %s AND [docState] IN (10, 40, 80) LIMIT 1',
            $docType,
        );
        if ($series === null) {
            $this->markTestSkipped("DS nemá řadu {$docType}.");
        }
        return (int) $series;
    }

    /**
     * @param array<string, mixed> $expected Fixture — snapshoty stran se berou z ní.
     * @param array<string, mixed> $head
     */
    private function insertHead(array $expected, array $head): int
    {
        $json = static fn (array $snapshot): string => (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE);

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', $head + [
            'number_series'     => $this->seriesFor((string) $head['doc_type']),
            'issue_date'        => '2026-09-30',
            'accounting_date'   => '2026-09-30',
            'due_date'          => '2026-10-14',
            'vat_registration'  => $this->vatRegistration,
            'vat_mode'          => 1,
            'payment_method'    => 1,
            'home_currency'     => 'czk',
            'supplier_snapshot' => $json($expected['supplier']),
            'customer_snapshot' => $json($expected['customer']),
            'docState'          => 40,
            'docStateMain'      => 2,
        ])->execute();
        $headId = (int) $dibi->getInsertId();
        $this->createdHeads[] = $headId;
        return $headId;
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertRows(int $headId, array $rows): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($rows as $pos => $row) {
            $dibi->insert('docs_core_rows', $row + ['doc_head' => $headId, 'order_pos' => $pos + 1])->execute();
        }
    }

    /** @param list<array<string, mixed>> $recap */
    private function insertRecap(int $headId, array $recap): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($recap as $pos => $row) {
            $dibi->insert('docs_core_vat_recap', $row + ['doc_head' => $headId, 'order_pos' => $pos])->execute();
        }
    }

    /** @param array<string, mixed> $expected */
    private function insertInvoice(array $expected, int $unitId): int
    {
        $headId = $this->insertHead($expected, [
            'doc_type'          => 'invno',
            'doc_number'        => self::INVOICE_NUMBER,
            'doc_text'          => 'Konzultace a tiskoviny za září',
            'doc_notice'        => 'Děkujeme za včasnou úhradu.',
            'notice'            => 'Interní poznámka se netiskne.',
            'vat_duzp'          => '2026-09-30',
            'period_from'       => '2026-09-01',
            'period_to'         => '2026-09-30',
            'doc_currency'      => 'eur',
            'exchange_rate'     => 24.5,
            'payment_reference' => '2026000123',
            'specific_symbol'   => '',
            'constant_symbol'   => '0308',
            'total_base'        => 590.0, 'total_vat' => 220.8, 'total_amount' => 810.8,
            'total_base_dom'    => 14455.0, 'total_vat_dom' => 5409.6, 'total_amount_dom' => 19864.6,
        ]);

        $this->insertRows($headId, [
            [
                'row_kind' => 1, 'operation' => 'sale.services', 'description' => 'Konzultace',
                'quantity' => 10, 'unit' => $unitId, 'unit_price' => 100, 'total_price' => 1000,
                'vat_code' => 'cz-120', 'vat_pct' => 21,
                'vat_base' => 1000, 'vat_amount' => 210, 'vat_total' => 1210,
            ],
            [
                'row_kind' => 1, 'operation' => 'sale.goods', 'description' => 'Tištěná příručka',
                'quantity' => 2, 'unit_price' => 50, 'discount_pct' => 10, 'total_price' => 90,
                'vat_code' => 'cz-121', 'vat_pct' => 12,
                'vat_base' => 90, 'vat_amount' => 10.8, 'vat_total' => 100.8,
            ],
            ['row_kind' => 0, 'description' => 'Děkujeme za spolupráci.'],
            [
                'row_kind' => 1, 'operation' => 'sale.advanceDeduction',
                'description' => 'Odpočet zálohy dle zálohové faktury',
                'unit_price' => 0, 'total_price' => -500, 'price_calc_mode' => 1,
                'vat_code' => 'cz-122', 'vat_pct' => 0,
                'vat_base' => -500, 'vat_amount' => 0, 'vat_total' => -500,
            ],
        ]);

        $this->insertRecap($headId, [
            ['vat_code' => 'cz-120', 'vat_pct' => 21, 'base' => 1000, 'tax' => 210, 'total' => 1210,
             'base_dom' => 24500, 'tax_dom' => 5145, 'total_dom' => 29645],
            // Druhá strana reverse charge páru se netiskne.
            ['vat_code' => 'cz-120', 'vat_pct' => 21, 'base' => 77, 'tax' => 16.17, 'total' => 93.17,
             'base_dom' => 1886.5, 'tax_dom' => 396.17, 'total_dom' => 2282.67, 'is_reverse_pair' => 1],
            ['vat_code' => 'cz-121', 'vat_pct' => 12, 'base' => 90, 'tax' => 10.8, 'total' => 100.8,
             'base_dom' => 2205, 'tax_dom' => 264.6, 'total_dom' => 2469.6],
            ['vat_code' => 'cz-122', 'vat_pct' => 0, 'base' => -500, 'tax' => 0, 'total' => -500,
             'base_dom' => -12250, 'tax_dom' => 0, 'total_dom' => -12250],
        ]);

        return $headId;
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $headOverrides
     */
    private function insertProforma(array $expected, array $headOverrides = []): int
    {
        $headId = $this->insertHead($expected, $headOverrides + [
            'doc_type'          => 'invpo',
            'doc_number'        => self::PROFORMA_NUMBER,
            'doc_text'          => 'Záloha na dodávku tiskovin',
            'doc_currency'      => 'czk',
            'exchange_rate'     => 1.0,
            'payment_reference' => '2026000045',
            'total_base'        => 10000.0, 'total_vat' => 2100.0, 'total_amount' => 12100.0,
            'total_base_dom'    => 10000.0, 'total_vat_dom' => 2100.0, 'total_amount_dom' => 12100.0,
        ]);

        $this->insertRows($headId, [[
            'row_kind' => 1, 'operation' => 'sale.services', 'description' => 'Záloha na dodávku tiskovin',
            'quantity' => 1, 'unit_price' => 10000, 'total_price' => 10000,
            'vat_code' => 'cz-120', 'vat_pct' => 21,
            'vat_base' => 10000, 'vat_amount' => 2100, 'vat_total' => 12100,
        ]]);
        $this->insertRecap($headId, [[
            'vat_code' => 'cz-120', 'vat_pct' => 21, 'base' => 10000, 'tax' => 2100, 'total' => 12100,
            'base_dom' => 10000, 'tax_dom' => 2100, 'total_dom' => 12100,
        ]]);

        return $headId;
    }
}
