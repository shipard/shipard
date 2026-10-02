<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Prints;

/**
 * Fixture doklady pro testy tisků: faktura (plátce, dvě sazby, textový
 * řádek, odpočet zálohy, cizí měna), zálohová faktura a pokladní doklady
 * (příjem s prodejem, příjem úhrady faktury, výdej bez partnera) a prodejky
 * (hotově bez partnera, převodem, vratka). Vkládají se přímo
 * ve stavu 40 se snapshoty stran z `tests/Fixtures/Prints/*.json`,
 * takže výstup builderu nezávisí na adresáři zdroje dat.
 *
 * Vyžaduje řady invno a invpo a registraci k DPH pro cz. Čísla dokladů
 * jsou pevná — `prepareFixtureDocuments()` uklidí zbytky po spadlém běhu.
 */
trait PrintFixtureDocuments
{
    /** Společný prefix čísel fixture dokladů — podle něj se uklízí. */
    private const NUMBER_PREFIX   = 'IT-PRINT-';
    private const INVOICE_NUMBER  = 'IT-PRINT-INV';
    private const PROFORMA_NUMBER = 'IT-PRINT-PRO';
    private const CASH_NUMBER     = 'IT-PRINT-CASH';
    private const RECEIPT_NUMBER  = 'IT-PRINT-REC';
    private const GENERAL_NUMBER  = 'IT-PRINT-GEN';

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
            'SELECT [id] FROM [docs_core_heads] WHERE [doc_number] LIKE %s',
            self::NUMBER_PREFIX . '%',
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
        $dibi->delete('economy_accounting_journal')->where('doc_head = %i', $id)->execute();
        $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $id)->execute();
        $dibi->delete('docs_core_rows')->where('doc_head = %i', $id)->execute();
        $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
    }

    /** @return array<string, mixed> Sekce `data` fixture. */
    private function expected(string $name): array
    {
        return $this->expectedEnvelope($name)['data'];
    }

    /** @return array<string, mixed> Celá obálka `PrintData` fixture. */
    private function expectedEnvelope(string $name): array
    {
        $file = dirname(__DIR__, 2) . '/Fixtures/Prints/' . $name . '.json';
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

    private function anyPersonId(): int
    {
        $person = $this->db->fetchSingle('SELECT [id] FROM [base_persons_persons] ORDER BY [id] LIMIT 1');
        if ($person === null) {
            $this->markTestSkipped('DS nemá žádnou osobu.');
        }
        return (int) $person;
    }

    /** @return array{id: int, code: string, name: string} */
    private function anyCashDesk(): array
    {
        $desk = $this->db->fetchRow('SELECT [id], [code], [name] FROM [economy_codebooks_cash_desks] ORDER BY [id] LIMIT 1');
        if ($desk === null) {
            $this->markTestSkipped('DS nemá žádnou pokladnu.');
        }
        return ['id' => (int) $desk['id'], 'code' => (string) $desk['code'], 'name' => (string) $desk['name']];
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
        $json = static fn (?array $snapshot): ?string => $snapshot === null
            ? null
            : (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE);

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

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $headOverrides
     * @param int $extraTextRows Textové řádky navíc — doklad přes víc stran.
     */
    private function insertInvoice(array $expected, int $unitId, array $headOverrides = [], int $extraTextRows = 0): int
    {
        $headId = $this->insertHead($expected, $headOverrides + [
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
            ...array_map(
                static fn (int $n): array => ['row_kind' => 0, 'description' => "Doplňkový text {$n}"],
                $extraTextRows > 0 ? range(1, $extraTextRows) : [],
            ),
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
     * Příjmový pokladní doklad plátce s prodejem zboží — daňový doklad.
     *
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $headOverrides
     */
    private function insertCashSale(array $expected, int $cashDeskId, array $headOverrides = []): int
    {
        $headId = $this->insertHead($expected, $headOverrides + self::cashHead($cashDeskId) + [
            'cash_dir'       => 1,
            'doc_text'       => 'Prodej zboží za hotové',
            'vat_dppd'       => '2026-09-29',
            'total_base'     => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRows($headId, [[
            'row_kind' => 1, 'operation' => 'sale.goods', 'description' => 'Tonery do tiskárny',
            'quantity' => 2, 'unit_price' => 500, 'total_price' => 1000,
            'vat_code' => 'cz-120', 'vat_pct' => 21,
            'vat_base' => 1000, 'vat_amount' => 210, 'vat_total' => 1210,
        ]]);
        $this->insertRecap($headId, [[
            'vat_code' => 'cz-120', 'vat_pct' => 21, 'base' => 1000, 'tax' => 210, 'total' => 1210,
            'base_dom' => 1000, 'tax_dom' => 210, 'total_dom' => 1210,
        ]]);

        return $headId;
    }

    /**
     * Příjmový pokladní doklad plátce, kterým odběratel hradí fakturu —
     * kontační řádek bez DPH, rekapitulace prázdná.
     *
     * @param array<string, mixed> $expected
     */
    private function insertCashInvoicePayment(array $expected, int $cashDeskId): int
    {
        $headId = $this->insertHead($expected, self::cashHead($cashDeskId) + [
            'cash_dir'       => 1,
            'doc_text'       => 'Úhrada faktury v hotovosti',
            'vat_dppd'       => '2026-09-30',
            'total_base'     => 1210.0, 'total_vat' => 0.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1210.0, 'total_vat_dom' => 0.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRows($headId, [[
            'row_kind' => 1, 'operation' => 'payment.receivable', 'description' => 'Úhrada faktury 2026000123',
            'unit_price' => 0, 'total_price' => 1210, 'payment_reference' => '2026000123',
            'vat_base' => 1210, 'vat_amount' => 0, 'vat_total' => 1210,
        ]]);

        return $headId;
    }

    /**
     * Výdajový pokladní doklad neplátce bez partnera — dodavatel chybí,
     * vlastní firma je odběratel.
     *
     * @param array<string, mixed> $expected
     */
    private function insertCashDisbursement(array $expected, int $cashDeskId): int
    {
        $headId = $this->insertHead($expected, self::cashHead($cashDeskId) + [
            'cash_dir'         => 2,
            'doc_text'         => 'Nákup kancelářských potřeb',
            'vat_registration' => null,
            'vat_mode'         => 0,
            'total_base'       => 110.0, 'total_vat' => 0.0, 'total_amount' => 110.0,
            'total_base_dom'   => 110.0, 'total_vat_dom' => 0.0, 'total_amount_dom' => 110.0,
        ]);
        $this->insertRows($headId, [[
            'row_kind' => 1, 'operation' => 'purchase.goods', 'description' => 'Kancelářské potřeby',
            'quantity' => 10, 'unit_price' => 11, 'total_price' => 110,
            'vat_base' => 110, 'vat_amount' => 0, 'vat_total' => 110,
        ]]);

        return $headId;
    }

    /**
     * Prodejka plátce: jeden řádek zboží se základní sazbou. Záporné
     * `$quantity` = vratka. Bez `$headOverrides` hotově a bez partnera.
     *
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $headOverrides
     */
    private function insertReceipt(array $expected, int $cashDeskId, float $quantity = 2.0, array $headOverrides = []): int
    {
        $base = 500.0 * $quantity;
        $vat  = round($base * 0.21, 2);

        $headId = $this->insertHead($expected, $headOverrides + [
            'doc_type'         => 'cashreg',
            'doc_number'       => self::RECEIPT_NUMBER,
            'cash_desk'        => $cashDeskId,
            'payment_method'   => 0,
            'due_date'         => '2026-09-30',
            'vat_duzp'         => '2026-09-30',
            'doc_currency'     => 'czk',
            'exchange_rate'    => 1.0,
            'total_base'       => $base, 'total_vat' => $vat, 'total_amount' => $base + $vat,
            'total_base_dom'   => $base, 'total_vat_dom' => $vat, 'total_amount_dom' => $base + $vat,
        ]);
        $this->insertRows($headId, [[
            'row_kind' => 1, 'operation' => 'sale.goods', 'description' => 'Tonery do tiskárny',
            'quantity' => $quantity, 'unit_price' => 500, 'total_price' => $base,
            'vat_code' => 'cz-120', 'vat_pct' => 21,
            'vat_base' => $base, 'vat_amount' => $vat, 'vat_total' => $base + $vat,
        ]]);
        $this->insertRecap($headId, [[
            'vat_code' => 'cz-120', 'vat_pct' => 21, 'base' => $base, 'tax' => $vat, 'total' => $base + $vat,
            'base_dom' => $base, 'tax_dom' => $vat, 'total_dom' => $base + $vat,
        ]]);

        return $headId;
    }

    /**
     * Účetní doklad (`cmnbkp`) — typ bez směru obchodu a bez snapshotů stran.
     */
    private function insertGeneralDocument(): int
    {
        return $this->insertHead(['supplier' => null, 'customer' => null], [
            'doc_type'         => 'cmnbkp',
            'doc_number'       => self::GENERAL_NUMBER,
            'doc_text'         => 'Zaúčtování mezd',
            'vat_registration' => null,
            'vat_mode'         => 0,
            'doc_currency'     => 'czk',
            'exchange_rate'    => 1.0,
            'total_amount'     => 5000.0,
        ]);
    }

    /**
     * Řádky deníku fixture dokladu — vkládají se přímo, účtovací engine
     * neběží. Každý řádek nese aspoň `account`, `account_number` a částky.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function insertJournal(int $headId, array $rows): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($rows as $row) {
            $dibi->insert('economy_accounting_journal', $row + [
                'source_kind'     => 'doc',
                'doc_head'        => $headId,
                'accounting_date' => '2026-09-30',
            ])->execute();
        }
        $dibi->update('docs_core_heads', ['accounting_state' => 1])->where('id = %i', $headId)->execute();
    }

    /**
     * Účty rozvrhu DS pro řádky deníku fixture — názvy se v tisku berou
     * aktuální, test je proto čte z DS.
     *
     * @return list<array{id: int, number: string, name: string}>
     */
    private function anyAccounts(int $count): array
    {
        $accounts = $this->db->fetchAll(
            'SELECT [id], [number], [name] FROM [economy_accounting_accounts]'
            . ' WHERE [docState] IN (10, 40, 80) AND CHAR_LENGTH([number]) = 6 ORDER BY [number] LIMIT %i',
            $count,
        );
        if (count($accounts) < $count) {
            $this->markTestSkipped('DS nemá dost účtů v rozvrhu.');
        }
        return array_map(
            static fn (array $a): array => ['id' => (int) $a['id'], 'number' => (string) $a['number'], 'name' => (string) $a['name']],
            $accounts,
        );
    }

    /**
     * Společné hodnoty hlavičky pokladního dokladu: hotově, v domácí měně,
     * splatnost = vystavení.
     *
     * @return array<string, mixed>
     */
    private static function cashHead(int $cashDeskId): array
    {
        return [
            'doc_type'       => 'cash',
            'doc_number'     => self::CASH_NUMBER,
            'cash_desk'      => $cashDeskId,
            'payment_method' => 0,
            'due_date'       => '2026-09-30',
            'vat_duzp'       => '2026-09-30',
            'doc_currency'   => 'czk',
            'exchange_rate'  => 1.0,
        ];
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
