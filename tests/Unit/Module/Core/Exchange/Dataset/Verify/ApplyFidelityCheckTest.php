<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Verify;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Compare\CanonicalComparator;
use Shipard\Module\Core\Exchange\Compare\Projection;
use Shipard\Module\Core\Exchange\Dataset\Verify\ApplyFidelityCheck;
use Shipard\Module\Core\Exchange\Dataset\Verify\SavedDocumentProjection;
use Shipard\Module\World\Vat\VatRateResolver;
use Shipard\Tests\Unit\Module\Core\Exchange\Compare\ProjectionTest;

/**
 * Výjimky věrnosti podle dokladu a zdroje dat (schválené 2026-10-14)
 * a pravidla adaptéru export → projekce: text řádku, jednotka přes resolver
 * i mimo číselník, papírová podoba samovyměření, `vat.reverseCharge`.
 */
class ApplyFidelityCheckTest extends TestCase
{
    public function testExceptionsDependOnDocumentAndDataSource(): void
    {
        $always = array_keys(ApplyFidelityCheck::EXCEPTIONS);
        $this->assertSame($always, array_keys(ApplyFidelityCheck::exceptionsFor(['vat' => ['mode' => 'fromBase']], true)));
        $this->assertSame(
            [...$always, 'dates.taxPointDate'],
            array_keys(ApplyFidelityCheck::exceptionsFor(['vat' => ['mode' => 'none']], true)),
        );
        $receipt = ApplyFidelityCheck::exceptionsFor(
            ['vat' => ['mode' => 'fromTotal'], 'dates' => ['dueDate' => null], 'payment' => ['paymentReference' => null]],
            true,
        );
        $this->assertSame([...$always, 'dates.dueDate', 'payment.paymentReference'], array_keys($receipt), 'účtenka bez splatnosti a VS');
        $invoice = ApplyFidelityCheck::exceptionsFor(['dates' => ['dueDate' => '2026-09-08'], 'payment' => ['paymentReference' => '20261180']], true);
        $this->assertSame($always, array_keys($invoice), 'faktura se splatností a VS: splatnost i VS se porovnávají');

        $nonPayer = ApplyFidelityCheck::exceptionsFor(['vat' => ['mode' => 'fromBase']], false);
        $this->assertContains('rows[].unitPrice', array_keys($nonPayer));
        $this->assertContains('totals.totalVat', array_keys($nonPayer));
        $this->assertNotContains('dates.taxPointDate', array_keys($nonPayer));
        $this->assertNotContains('totals.totalAmount', array_keys($nonPayer), 'celková částka se u neplátce porovnává');
        foreach (ApplyFidelityCheck::EXCEPTIONS + ApplyFidelityCheck::EXCEPTIONS_NO_VAT + ApplyFidelityCheck::EXCEPTIONS_NON_PAYER
            + ApplyFidelityCheck::EXCEPTIONS_NO_DUE_DATE + ApplyFidelityCheck::EXCEPTIONS_NO_PAYMENT_REFERENCE as $path => $reason) {
            $this->assertNotSame('', trim($reason), "{$path}: výjimka bez důvodu");
        }
    }

    private function adapter(): SavedDocumentProjection
    {
        $db = $this->createMock(Connection::class);
        // Jednotky uložených řádků: ks (id 7), bal (mimo číselník → null), ks.
        $db->method('fetchAll')->willReturn([new Row(['unit' => 7]), new Row(['unit' => null]), new Row(['unit' => 7])]);
        // UnitResolver: `ks` je alias `pcs` → id 7; `bal` číselník nezná; vlastní firma se nehledá.
        $db->method('fetch')->willReturnCallback(static function (mixed ...$args): ?Row {
            $sql = (string) ($args[0] ?? '');
            if (!str_contains($sql, 'core_units')) {
                return null;
            }
            return ($args[1] ?? null) === 'pcs' ? new Row(['id' => 7]) : null;
        });
        // Skutečný číselník vat-cz.jsonc: cz-115 má reverseVatCode (cz-203), cz-110 ne.
        $vatCz = JsoncParser::parseFile(dirname(__DIR__, 7) . '/modules/world/vat/config/vat-cz.jsonc');
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $id === 'world.vat.cz' ? $vatCz : null);
        return new SavedDocumentProjection($db, new VatRateResolver($config), 'cz');
    }

    public function testAdapterGivesPaperFormOfSavedDocument(): void
    {
        $export = [
            'docNumber' => '2026-0147',
            'vat' => ['mode' => 'fromBase', 'place' => 'domestic'],
            'rows' => [
                ['item' => ['ourCode' => '511201', 'name' => 'Opravy a údržba'], 'description' => 'Oprava střechy — práce', 'unit' => 'pcs', 'quantity' => 1, 'vat' => ['code' => 'cz-115', 'pct' => 21]],
                ['item' => ['ourCode' => '501100', 'name' => 'Spotřeba materiálu'], 'description' => 'Papír A4', 'quantity' => 5, 'vat' => ['code' => 'cz-110', 'pct' => 21]],
                ['item' => ['ourCode' => '501100', 'name' => 'Spotřeba materiálu'], 'description' => 'Lepidlo', 'unit' => 'pcs', 'quantity' => 2, 'vat' => ['code' => 'cz-110', 'pct' => 21]],
            ],
            'vatRecap' => [
                ['vatCode' => 'cz-115', 'vatPct' => 21, 'base' => 69840, 'tax' => 14666.4, 'total' => 84506.4],
                ['vatCode' => 'cz-203', 'vatPct' => 21, 'base' => 69840, 'tax' => 14666.4, 'total' => 84506.4, 'isReversePair' => true],
                ['vatCode' => 'cz-110', 'vatPct' => 21, 'base' => 100, 'tax' => 21, 'total' => 121],
            ],
        ];
        $proposal = [
            'customer' => null,
            'rows' => [['unit' => 'ks'], ['unit' => 'bal'], ['unit' => 'm2']],
        ];
        $out = $this->adapter()->build($export, $proposal, 42);

        $this->assertSame('Oprava střechy — práce', $out['rows'][0]['item']['name'], 'text řádku místo názvu položky');
        $this->assertSame('ks', $out['rows'][0]['unit'], 'jednotka návrhu vede na uloženou jednotku');
        $this->assertSame('bal', $out['rows'][1]['unit'], 'jednotka mimo číselník: řádek bez jednotky ≡ text návrhu');
        $this->assertSame('pcs', $out['rows'][2]['unit'], 'jiná jednotka než v návrhu zůstává rozdíl');
        $this->assertSame(0, $out['rows'][0]['vat']['pct'], 'samovyměření: na papíře 0 %');
        $this->assertSame(21, $out['rows'][1]['vat']['pct']);
        $this->assertTrue($out['vat']['reverseCharge']);
        $this->assertCount(2, $out['vatRecap'], 'oddaňovací pár vynechán');
        $this->assertSame(['vatCode' => 'cz-115', 'vatPct' => 0, 'base' => 69840, 'tax' => 0, 'total' => 69840], $out['vatRecap'][0]);
        $this->assertSame(121, $out['vatRecap'][1]['total']);
        $this->assertNull($out['customer'] ?? null, 'bez odběratele v návrhu se vlastní firma nedoplňuje');
    }

    public function testAdapterStripsZeroVatRepresentationOfDocumentWithoutVat(): void
    {
        // Faktura od neplátce u plátce: Shipard nese kód bez daně (cz-112) a nulovou
        // rekapitulaci, papír DPH vůbec nemá — rovnocenné, jen když je daň nulová.
        $export = [
            'rows' => [['item' => ['ourCode' => '518100', 'name' => 'Služby'], 'description' => 'Kurz', 'unit' => 'pcs', 'vat' => ['code' => 'cz-112', 'pct' => 0]]],
            'vatRecap' => [['vatCode' => 'cz-112', 'vatPct' => 0, 'base' => 5700, 'tax' => 0, 'total' => 5700]],
            'totals' => ['totalBase' => 5700, 'totalVat' => 0, 'totalAmount' => 5700, 'totalRounding' => 0],
        ];
        $proposal = ['vat' => ['mode' => 'none'], 'rows' => [['unit' => 'ks']]];
        $out = $this->adapter()->build($export, $proposal, 42);
        $this->assertSame(['code' => 'cz-112', 'pct' => null], $out['rows'][0]['vat']);
        $this->assertSame([], $out['vatRecap']);
        $this->assertSame(['totalBase' => null, 'totalVat' => null, 'totalAmount' => 5700, 'totalRounding' => 0], $out['totals']);

        $export['vatRecap'][0]['tax'] = 1197;
        $kept = $this->adapter()->build($export, $proposal, 42);
        $this->assertSame(0, $kept['rows'][0]['vat']['pct'], 'nenulová daň u dokladu bez DPH zůstává rozdíl');
        $this->assertCount(1, $kept['vatRecap']);
        $this->assertSame(5700, $kept['totals']['totalBase']);
    }

    public function testAdapterOutputComparesCleanAgainstPaperProposal(): void
    {
        $proposal = ProjectionTest::corpusDoc();
        $proposal['customer'] = null;
        $export = [
            'format' => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType' => 'invoiceReceived',
            'docNumber' => $proposal['docNumber'],
            'selfParty' => 'customer',
            'supplier' => $proposal['supplier'],
            'dates' => $proposal['dates'],
            'currency' => 'CZK',
            'vat' => ['mode' => 'fromBase', 'place' => 'domestic'],
            'payment' => $proposal['payment'],
            'notes' => ['onDocument' => $proposal['notes']['onDocument']],
            'rows' => array_map(static fn (array $r): array => [
                'rowKind' => 'item',
                'item' => ['ourCode' => '501100', 'name' => 'Spotřeba materiálu'],
                'description' => $r['item']['name'],
                'unit' => $r['unit'] === 'ks' ? 'pcs' : $r['unit'],
                'quantity' => $r['quantity'], 'unitPrice' => $r['unitPrice'], 'totalPrice' => $r['totalPrice'],
                'priceCalcMode' => $r['priceCalcMode'], 'discountPct' => $r['discountPct'],
                'vat' => ['code' => 'cz-110', 'pct' => 21],
            ], $proposal['rows']),
            'vatRecap' => [['vatCode' => 'cz-110', 'vatPct' => 21, 'base' => 16530.45, 'tax' => 3471.39, 'total' => 20001.84]],
            'totals' => $proposal['totals'],
        ];
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturn([new Row(['unit' => 1]), new Row(['unit' => 7]), new Row(['unit' => null]), new Row(['unit' => 7])]);
        $db->method('fetch')->willReturnCallback(static function (mixed ...$args): ?Row {
            if (!str_contains((string) $args[0], 'core_units')) {
                return null;
            }
            return match ($args[1] ?? null) { 'pcs' => new Row(['id' => 7]), 'm2' => new Row(['id' => 1]), default => null };
        });
        $adapter = new SavedDocumentProjection($db, null, 'cz');
        $actual = $adapter->build($export, $proposal, 1);

        $comparator = new CanonicalComparator(Projection::load(ProjectionTest::projectionPath()));
        $ignore = array_keys(ApplyFidelityCheck::exceptionsFor($proposal, true) + ApplyFidelityCheck::STRUCTURAL);
        $differences = array_map('strval', $comparator->compare($proposal, $actual, $ignore));
        $this->assertSame([], $differences, implode("\n", $differences));
    }
}
