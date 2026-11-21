<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\DocRowsDocument;

/**
 * DocRowsDocument::beforeSave — automatické order_pos nového řádku
 * (MAX + 1 v rámci dokladu), explicitní pořadí a update beze změny.
 */
class DocRowsDocumentTest extends TestCase
{
    private function docWithMax(mixed $max, bool $expectQuery = true): DocRowsDocument
    {
        $db = $this->createMock(Connection::class);
        if ($expectQuery) {
            $db->expects($this->once())->method('fetchSingle')->willReturnCallback(
                function (mixed ...$args) use ($max): mixed {
                    $this->assertStringContainsString('MAX([order_pos])', $args[0]);
                    $this->assertStringContainsString('[doc_head] = %i', $args[0]);
                    $this->assertSame(7, $args[1]);
                    return $max;
                },
            );
        } else {
            $db->expects($this->never())->method('fetchSingle');
        }
        $doc = new DocRowsDocument();
        $doc->setDb($db);
        return $doc;
    }

    public function testNewRowWithoutOrderGetsMaxPlusOne(): void
    {
        $doc = $this->docWithMax(3);
        $data = ['doc_head' => 7, 'row_kind' => 1, 'description' => 'Ukázka'];
        $doc->beforeSave($data, null);
        $this->assertSame(4, $data['order_pos']);
    }

    public function testNewRowWithZeroOrderGetsMaxPlusOne(): void
    {
        $doc = $this->docWithMax('12');
        $data = ['doc_head' => 7, 'order_pos' => 0];
        $doc->beforeSave($data, null);
        $this->assertSame(13, $data['order_pos']);
    }

    public function testFirstRowOfEmptyDocumentGetsOne(): void
    {
        // MAX() nad prázdnou skupinou vrací NULL
        $doc = $this->docWithMax(null);
        $data = ['doc_head' => 7, 'order_pos' => null];
        $doc->beforeSave($data, null);
        $this->assertSame(1, $data['order_pos']);
    }

    public function testExplicitOrderIsKept(): void
    {
        $doc = $this->docWithMax(9, expectQuery: false);
        $data = ['doc_head' => 7, 'order_pos' => 2];
        $doc->beforeSave($data, null);
        $this->assertSame(2, $data['order_pos']);
    }

    public function testUpdateDoesNotTouchOrder(): void
    {
        $doc = $this->docWithMax(9, expectQuery: false);
        $data = ['id' => 55, 'doc_head' => 7, 'order_pos' => 0];
        $doc->beforeSave($data, ['id' => 55, 'doc_head' => 7, 'order_pos' => 0]);
        $this->assertSame(0, $data['order_pos']);
    }

    public function testRowWithoutHeadIsLeftAlone(): void
    {
        $doc = $this->docWithMax(9, expectQuery: false);
        $data = ['description' => 'x'];
        $doc->beforeSave($data, null);
        $this->assertArrayNotHasKey('order_pos', $data);
    }


    // ── Předmět plnění tuzemského přenesení (#89 D7) ───────────────────────

    /** Dokument nad hlavičkou plátce DPH se zemí cz a skutečným číselníkem. */
    private function docWithVatHead(string $duzp = '2026-05-06'): DocRowsDocument
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new \Dibi\Row([
            'vat_registration' => 1, 'vat_mode' => 1, 'vat_duzp' => $duzp, 'issue_date' => $duzp,
            'corrective' => 0, 'corrected_doc_date' => null, 'doc_type' => 'invno', 'cash_dir' => 0,
        ]));
        $db->method('fetchSingle')->willReturnCallback(
            static fn (mixed ...$args): mixed => str_contains((string) $args[0], 'vat_registrations') ? 'cz' : 1,
        );
        $doc = new DocRowsDocument();
        $doc->setDb($db);
        $doc->setConfig(\Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory::fromItems([
            'world.vat.cz' => \Shipard\Core\Utils\JsoncParser::parseFile(
                dirname(__DIR__, 5) . '/modules/world/vat/config/vat-cz.jsonc',
            ),
        ]));
        return $doc;
    }

    public function testSubjectIsKeptOnReverseChargeCodeAndClearedOnOtherCode(): void
    {
        $doc = $this->docWithVatHead();

        $kept = ['doc_head' => 7, 'order_pos' => 1, 'row_kind' => 1, 'vat_code' => 'cz-150', 'reverse_charge_code' => ' 4a '];
        $doc->beforeSave($kept, null);
        $this->assertSame('4a', $kept['reverse_charge_code']);

        $cleared = ['doc_head' => 7, 'order_pos' => 1, 'row_kind' => 1, 'vat_code' => 'cz-120', 'reverse_charge_code' => '4'];
        $doc->beforeSave($cleared, null);
        $this->assertNull($cleared['reverse_charge_code']);

        // Změna kódu bez sloupce předmětu v datech: uložený předmět nesmí zůstat.
        $partial = ['id' => 3, 'doc_head' => 7, 'vat_code' => 'cz-120'];
        $doc->beforeSave($partial, ['id' => 3]);
        $this->assertArrayHasKey('reverse_charge_code', $partial);
        $this->assertNull($partial['reverse_charge_code']);
    }

    public function testRowValidationRejectsUnknownOrNotYetValidSubjectButNotMissingOne(): void
    {
        $errors = function (DocRowsDocument $doc, array $row): array {
            $row += ['doc_head' => 7, 'row_kind' => 1];
            return array_values(array_filter(
                array_map(static fn ($e): array => $e->toArray(), $doc->validate($row)->getErrors()),
                static fn (array $e): bool => $e['column'] === 'reverse_charge_code',
            ));
        };

        $doc = $this->docWithVatHead();
        $this->assertSame([], $errors($doc, ['vat_code' => 'cz-150']), 'chybějící předmět hlídá potvrzení dokladu');
        $this->assertSame([], $errors($doc, ['vat_code' => 'cz-150', 'reverse_charge_code' => '14']));
        $this->assertSame([], $errors($doc, ['vat_code' => 'cz-120', 'reverse_charge_code' => '99']), 'jiný kód: nuluje se');

        $unknown = $errors($doc, ['vat_code' => 'cz-150', 'reverse_charge_code' => '99']);
        $this->assertCount(1, $unknown);
        $this->assertSame('invalid_value', $unknown[0]['code']);

        // 4a platí od 1. 7. 2017.
        $early = $errors($this->docWithVatHead('2017-06-30'), ['vat_code' => 'cz-150', 'reverse_charge_code' => '4a']);
        $this->assertCount(1, $early);
        $this->assertStringContainsString('2017-06-30', $early[0]['message']);
    }

    // ── Datum sazby DPH (#159 D1, D5, D6, D15, U7) ─────────────────────────

    /**
     * Dokument nad hlavičkou plátce DPH: DPPD 1. 2. 2024, zdroj data sazby
     * podle parametru.
     */
    private function docWithRateHead(int $source, ?string $headRateDate = null): DocRowsDocument
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new \Dibi\Row([
            'vat_registration' => 1, 'vat_mode' => 1, 'doc_type' => 'invni', 'cash_dir' => 0,
            'vat_duzp' => '2024-02-01', 'vat_dppd' => '2024-02-01', 'issue_date' => '2024-02-05',
            'corrective' => 0, 'corrected_doc_date' => null,
            'vat_rate_source' => $source, 'vat_rate_date' => $headRateDate,
        ]));
        $db->method('fetchSingle')->willReturnCallback(
            static fn (mixed ...$args): mixed => str_contains((string) $args[0], 'vat_registrations') ? 'cz' : 1,
        );
        $doc = new DocRowsDocument();
        $doc->setDb($db);
        $doc->setConfig(\Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory::fromItems([
            'world.vat.cz' => \Shipard\Core\Utils\JsoncParser::parseFile(
                dirname(__DIR__, 5) . '/modules/world/vat/config/vat-cz.jsonc',
            ),
        ]));
        return $doc;
    }

    public function testRowPercentIsDerivedFromCodeAndRowDate(): void
    {
        $row = ['doc_head' => 7, 'order_pos' => 1, 'row_kind' => 1, 'vat_code' => 'cz-302', 'vat_pct' => 99,
            'vat_rate_date' => '2023-11-30'];
        $this->docWithRateHead(2)->beforeSave($row, null);
        $this->assertSame(10.0, $row['vat_pct'], 'Druhá snížená k datu řádku');
        $this->assertSame('2023-11-30', $row['vat_rate_date']);

        $row = ['doc_head' => 7, 'order_pos' => 1, 'row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 99,
            'vat_rate_date' => '2012-12-31'];
        $this->docWithRateHead(0)->beforeSave($row, null);
        $this->assertSame(21.0, $row['vat_pct'], 'zdroj 0: automatika dokladu');
        $this->assertNull($row['vat_rate_date'], 'datum řádku mimo svůj režim se maže');

        $row = ['doc_head' => 7, 'order_pos' => 1, 'row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 99];
        $this->docWithRateHead(1, '2012-12-31')->beforeSave($row, null);
        $this->assertSame(20.0, $row['vat_pct'], 'zdroj 1: vlastní datum dokladu');
        $this->assertNull($row['vat_rate_date']);
    }

    public function testRowWithoutRateKeepsPercentAndPartialWriteIsUntouched(): void
    {
        $row = ['doc_head' => 7, 'order_pos' => 1, 'row_kind' => 1, 'vat_code' => 'cz-302', 'vat_pct' => 10];
        $this->docWithRateHead(0)->beforeSave($row, null);
        $this->assertSame(10, $row['vat_pct'], 'kód bez sazby k datu: procento zůstává, hlásí validace');

        $partial = ['id' => 3, 'doc_head' => 7, 'order_pos' => 4];
        $this->docWithRateHead(2)->beforeSave($partial, ['id' => 3]);
        $this->assertArrayNotHasKey('vat_pct', $partial);
        $this->assertArrayNotHasKey('vat_rate_date', $partial, 'zápis bez kódu i data se nemění');

        $text = ['doc_head' => 7, 'order_pos' => 1, 'row_kind' => 0, 'vat_code' => 'cz-110'];
        $this->docWithRateHead(0)->beforeSave($text, null);
        $this->assertArrayNotHasKey('vat_pct', $text, 'textový řádek procento nemá');
    }

    public function testRowValidationRejectsCodeWithoutRateAndWarnsOnLateDate(): void
    {
        $collect = static function (DocRowsDocument $doc, array $row): array {
            $row += ['doc_head' => 7, 'row_kind' => 1];
            $result = $doc->validate($row);
            return [
                'errors'   => array_map(static fn ($e): array => $e->toArray(), $result->getErrors()),
                'warnings' => array_map(static fn ($w): array => $w->toArray(), $result->getWarnings()),
            ];
        };
        $ofCode = static fn (array $list, string $code): array => array_values(array_filter(
            $list,
            static fn (array $f): bool => $f['code'] === $code,
        ));

        $missing = $collect($this->docWithRateHead(0), ['vat_code' => 'cz-302']);
        $errors = $ofCode($missing['errors'], 'vat_rate_missing');
        $this->assertCount(1, $errors, 'ze sub-formuláře je kód bez sazby chyba');
        $this->assertSame('vat_code', $errors[0]['column']);

        $dated = $collect($this->docWithRateHead(2), ['vat_code' => 'cz-302', 'vat_rate_date' => '2023-11-30']);
        $this->assertSame([], $ofCode($dated['errors'], 'vat_rate_missing'));

        $late = $collect($this->docWithRateHead(2), ['vat_code' => 'cz-110', 'vat_rate_date' => '2024-03-01']);
        $this->assertSame([], $ofCode($late['errors'], 'vat_rate_date_late'));
        $warnings = $ofCode($late['warnings'], 'vat_rate_date_late');
        $this->assertCount(1, $warnings);
        $this->assertSame('vat_rate_date', $warnings[0]['column']);
    }

    public function testSubjectIsCheckedAtRowDate(): void
    {
        $errors = static function (DocRowsDocument $doc, array $row): array {
            $row += ['doc_head' => 7, 'row_kind' => 1];
            return array_values(array_filter(
                array_map(static fn ($e): array => $e->toArray(), $doc->validate($row)->getErrors()),
                static fn (array $e): bool => $e['column'] === 'reverse_charge_code',
            ));
        };
        // 4a platí od 1. 7. 2017; doklad je z roku 2024.
        $row = ['vat_code' => 'cz-150', 'reverse_charge_code' => '4a'];
        $this->assertSame([], $errors($this->docWithRateHead(2), $row));
        $this->assertCount(1, $errors($this->docWithRateHead(2), $row + ['vat_rate_date' => '2017-06-30']));
        $this->assertSame(
            [],
            $errors($this->docWithRateHead(0), $row + ['vat_rate_date' => '2017-06-30']),
            'mimo zdroj 2 se datum řádku nečte',
        );
    }

    // ── Řádek bez kódu DPH = nevyplněno (#164 D2, D3, U8) ──────────────────

    /** @param array<string, mixed> $row */
    private function missingCodeWarnings(DocRowsDocument $doc, array $row): array
    {
        $row += ['doc_head' => 7, 'row_kind' => 1];
        return array_values(array_filter(
            array_map(static fn ($w): array => $w->toArray(), $doc->validate($row)->getWarnings()),
            static fn (array $w): bool => $w['code'] === 'vat_code_missing',
        ));
    }

    public function testRowWithoutVatCodeOnVatDocumentWarnsOnSubformSave(): void
    {
        $doc = $this->docWithVatHead();

        $warnings = $this->missingCodeWarnings($doc, ['vat_code' => null]);
        $this->assertCount(1, $warnings, 'uložení řádku ze sub-formuláře jen varuje');
        $this->assertSame('vat_code', $warnings[0]['column']);
        $this->assertStringContainsString('Mimo DPH', $warnings[0]['message']);
        $uncoded = ['doc_head' => 7, 'row_kind' => 1];
        $this->assertSame([], array_filter(
            array_map(static fn ($e): array => $e->toArray(), $doc->validate($uncoded)->getErrors()),
            static fn (array $e): bool => $e['code'] === 'vat_code_missing',
        ));

        $this->assertSame([], $this->missingCodeWarnings($doc, ['vat_code' => 'cz-120']));
        $this->assertSame([], $this->missingCodeWarnings($doc, ['vat_code' => 'cz-000']), '„Mimo DPH“ je vyplněný kód');
        $this->assertSame([], $this->missingCodeWarnings($doc, ['vat_code' => null, 'row_kind' => 0]), 'textový řádek');
    }

    public function testRowWithoutVatCodeIsFineOnDocumentWithoutVatAndOnContationRow(): void
    {
        $config = \Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory::fromItems([
            'world.vat.cz' => \Shipard\Core\Utils\JsoncParser::parseFile(
                dirname(__DIR__, 5) . '/modules/world/vat/config/vat-cz.jsonc',
            ),
            'docs.core.rowOperations' => ['acc.record' => ['rowSide' => 1], 'sale' => []],
        ]);
        $docFor = function (int $vatMode) use ($config): DocRowsDocument {
            $db = $this->createMock(Connection::class);
            $db->method('fetch')->willReturn(new \Dibi\Row([
                'vat_registration' => 1, 'vat_mode' => $vatMode, 'vat_duzp' => '2026-05-06',
                'issue_date' => '2026-05-06', 'corrective' => 0, 'corrected_doc_date' => null,
                'doc_type' => 'invno', 'cash_dir' => 0, 'operation' => '',
            ]));
            $db->method('fetchSingle')->willReturn('cz');
            $doc = new DocRowsDocument();
            $doc->setDb($db);
            $doc->setConfig($config);
            return $doc;
        };

        // D3: doklad Bez DPH kódy nenese.
        $this->assertSame([], $this->missingCodeWarnings($docFor(0), ['vat_code' => null, 'operation' => 'sale']));
        // Kontačnímu řádku formulář pole Kód DPH nenabízí.
        $this->assertSame([], $this->missingCodeWarnings($docFor(1), ['vat_code' => null, 'operation' => 'acc.record']));
        $this->assertCount(1, $this->missingCodeWarnings($docFor(1), ['vat_code' => null, 'operation' => 'sale']));
    }
}
