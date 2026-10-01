<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Docs\AccountingDocs\AccountingDocument;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

/**
 * `DocDocument::computeAmounts()` — výpočet řádků, rekapitulace a součtů bez
 * uložení (tasks/exchange-preview-vat-recompute.md D1). Náhled návrhu ho volá
 * přímo, uložení přes `beforeSave()`; oba musí dát stejná čísla a výpočet
 * nesmí sáhnout do DB jinak než čtením registrace DPH.
 */
class DocDocumentComputeAmountsTest extends TestCase
{
    private const VAT_CZ_PATH = __DIR__ . '/../../../../../modules/world/vat/config/vat-cz.jsonc';

    private const AMOUNT_KEYS = ['total_base', 'total_vat', 'total_amount', 'total_rounding'];
    private const ROW_KEYS = ['vat_base', 'vat_amount', 'vat_total', 'vat_base_dom', 'vat_amount_dom', 'vat_total_dom'];

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_compute_amounts_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode([
                '_meta' => ['language' => 'cs'],
                'items' => ['world.vat.cz' => JsoncParser::parseFile(self::VAT_CZ_PATH)],
            ]),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }

    /**
     * Mock DB: zná jen zemi registrace DPH; všechno ostatní je prázdné.
     * Zápisy dokumentu jdou přes `executeSql()` — ty zachytává
     * `TestableDocsHeadsDocument::$executedSql` (`Connection::query` je final,
     * `expects(never())` na něm nejde).
     */
    private function db(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(
            static fn (string $sql): ?Row => str_contains($sql, 'economy_codebooks_vat_registrations')
                ? new Row(['country' => 'cz'])
                : null,
        );
        $db->method('fetchAll')->willReturn([]);
        return $db;
    }

    private function doc(): TestableDocsHeadsDocument
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->db());
        $doc->setConfig(ConfigRuntime::load($this->tmpDir, 'cs'));
        return $doc;
    }

    private function row(string $code, float $pct, float $price): array
    {
        return [
            'row_kind'        => 1,
            'quantity'        => 1,
            'unit_price'      => $price,
            'price_calc_mode' => 0,
            'vat_code'        => $code,
            'vat_pct'         => $pct,
        ];
    }

    /** Nový doklad (bez id, bez řady) s řádky v payloadu — tvar, jaký staví applier. */
    private function data(array $rows, array $extra = []): array
    {
        return array_merge([
            'doc_type'            => 'invni',
            'vat_mode'            => 1,
            'vat_recap_source'    => 0,
            'vat_registration'    => 1,
            'issue_date'          => '2026-05-06',
            'vat_duzp'            => '2026-05-06',
            'exchange_rate'       => 1.0,
            'total_rounding_mode' => 0,
            'docState'            => 10,
            'rows'                => $rows,
        ], $extra);
    }

    /** @return array<string, mixed> Jen částky — hlavička, rekapitulace, řádky. */
    private function amounts(array $data, array $rows): array
    {
        $recap = array_map(
            static fn (array $r): array => array_intersect_key(
                $r,
                array_flip(['vat_code', 'vat_pct', 'base', 'tax', 'total', 'is_reverse_pair', 'sum_base', 'sum_tax', 'sum_total']),
            ),
            $data['vatRecap'] ?? [],
        );
        return [
            'head'  => array_intersect_key($data, array_flip(self::AMOUNT_KEYS)),
            'recap' => $recap,
            'rows'  => array_map(
                static fn (array $r): array => array_intersect_key($r, array_flip(self::ROW_KEYS)),
                $rows,
            ),
        ];
    }

    /** Totéž co `beforeSave()` nad kopií dat — parita výpočtu a uložení. */
    private function assertMatchesBeforeSave(array $data): void
    {
        $computeData = $data;
        $result = $this->doc()->computeAmounts($computeData);

        $saveDoc = $this->doc();
        $saveData = $data;
        $saveDoc->beforeSavePub($saveData);

        $this->assertSame(
            $this->amounts($saveData, $saveDoc->getComputedRows()),
            $this->amounts($computeData, $result['rows']),
        );
        $this->assertSame($saveData['vatRecap'], $result['recap']);
    }

    // ── Parita s uložením ───────────────────────────────────────────────────

    public function testDomesticTwoRatesMatchBeforeSave(): void
    {
        $data = $this->data([$this->row('cz-110', 21.0, 1000.00), $this->row('cz-111', 12.0, 500.00)]);

        $result = $this->doc()->computeAmounts($data);

        $this->assertFalse($result['recapDeclared']);
        $this->assertCount(2, $result['recap']);
        $this->assertSame(1500.0, $data['total_base']);
        $this->assertSame(270.0, $data['total_vat']);
        $this->assertSame(1770.0, $data['total_amount']);
        $this->assertSame(0.0, $data['total_rounding']);
        // Řádky propagované zpět do payloadu (klíč existoval).
        $this->assertSame(210.0, $data['rows'][0]['vat_amount']);
        $this->assertSame(60.0, $data['rows'][1]['vat_amount']);

        $this->assertMatchesBeforeSave($this->data([$this->row('cz-110', 21.0, 1000.00), $this->row('cz-111', 12.0, 500.00)]));
    }

    public function testDeclaredRecapIsTakenOverAndMatchesBeforeSave(): void
    {
        // Dodavatel počítá haléřově „po svém" — jeho rekapitulace je fakt.
        $data = $this->data(
            [$this->row('cz-110', 21.0, 55.00), $this->row('cz-110', 21.0, 55.00)],
            [
                'vat_recap_source' => 1,
                'vatRecap'         => [
                    ['vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 90.90, 'tax' => 19.10, 'total' => 110.00],
                ],
            ],
        );

        $result = $this->doc()->computeAmounts($data);

        $this->assertTrue($result['recapDeclared']);
        $this->assertSame(90.90, $result['recap'][0]['base']);
        $this->assertSame(19.10, $result['recap'][0]['tax']);
        $this->assertSame(90.90, $data['total_base']);
        $this->assertSame(19.10, $data['total_vat']);
        $this->assertSame(110.00, $data['total_amount']);

        $this->assertMatchesBeforeSave($this->data(
            [$this->row('cz-110', 21.0, 55.00), $this->row('cz-110', 21.0, 55.00)],
            [
                'vat_recap_source' => 1,
                'vatRecap'         => [
                    ['vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 90.90, 'tax' => 19.10, 'total' => 110.00],
                ],
            ],
        ));
    }

    public function testReverseChargeBuildsPairAndTotalEqualsBase(): void
    {
        // Samovyměření: nárok na odpočet + oddaňovací pár, k úhradě jen základ.
        $data = $this->data([$this->row('cz-115', 21.0, 200.00)]);

        $result = $this->doc()->computeAmounts($data);

        $this->assertFalse($result['recapDeclared']);
        $this->assertCount(2, $result['recap']);
        $this->assertSame('cz-115', $result['recap'][0]['vat_code']);
        $this->assertSame(0, $result['recap'][0]['is_reverse_pair']);
        $this->assertSame(42.0, $result['recap'][0]['tax']);
        $this->assertSame('cz-203', $result['recap'][1]['vat_code']);
        $this->assertSame(1, $result['recap'][1]['is_reverse_pair']);
        $this->assertSame(200.0, $data['total_base']);
        $this->assertSame(0.0, $data['total_vat']);
        $this->assertSame(200.0, $data['total_amount']);

        $this->assertMatchesBeforeSave($this->data([$this->row('cz-115', 21.0, 200.00)]));
    }

    // ── Bez vedlejších efektů ───────────────────────────────────────────────

    public function testDoesNotWriteOrNumberOrChangeState(): void
    {
        $doc = $this->doc();
        $data = $this->data([$this->row('cz-110', 21.0, 100.00)], ['docState' => 40]);

        $doc->computeAmounts($data);

        $this->assertSame([], $doc->executedSql);
        $this->assertArrayNotHasKey('doc_number', $data);
        $this->assertArrayNotHasKey('sequence_number', $data);
        $this->assertSame(40, $data['docState']);
        // Mimo výpočet nic: datumové defaulty i měna zůstávají na beforeSave.
        $this->assertArrayNotHasKey('accounting_date', $data);
        $this->assertArrayNotHasKey('home_currency', $data);
    }

    public function testRowsKeyIsNotAddedForHeaderOnlyData(): void
    {
        $data = $this->data([]);
        unset($data['rows']);

        $result = $this->doc()->computeAmounts($data);

        $this->assertArrayNotHasKey('rows', $data);
        $this->assertSame([], $result['rows']);
        $this->assertSame(0.0, $data['total_amount']);
    }

    public function testInstanceStateIsResetBetweenRuns(): void
    {
        $doc = $this->doc();
        $declared = $this->data(
            [$this->row('cz-110', 21.0, 100.00)],
            [
                'vat_recap_source' => 1,
                'vatRecap'         => [
                    ['vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 100.00, 'tax' => 21.00, 'total' => 121.00],
                ],
            ],
        );
        $this->assertTrue($doc->computeAmounts($declared)['recapDeclared']);

        // Druhý doklad na téže instanci: přepočítaná, řádek bez kódu se musí
        // do součtů započítat — to by při „zapomenutém" recapDeclared nešlo.
        $computed = $this->data([
            $this->row('cz-110', 21.0, 100.00),
            ['row_kind' => 1, 'quantity' => 1, 'unit_price' => 50.00, 'price_calc_mode' => 0, 'vat_code' => null, 'vat_pct' => 0],
        ]);
        $result = $doc->computeAmounts($computed);

        $this->assertFalse($result['recapDeclared']);
        $this->assertSame(150.0, $computed['total_base']);
        $this->assertSame(171.0, $computed['total_amount']);
        // computedRows se přepisují, ne hromadí.
        $this->assertCount(2, $doc->getComputedRows());
        $this->assertSame($result['rows'], $doc->getComputedRows());
    }

    // ── Polymorfismus ───────────────────────────────────────────────────────

    public function testAccountingDocumentForcesVatModeZeroAndSumsDebitRows(): void
    {
        // Účetní doklad bez validate() (náhled): vat_mode z payloadu nesmí
        // rozjet výpočet DPH — override computeAmounts ho vynutí na 0.
        $doc = new AccountingDocument();
        $doc->setDb($this->db());
        $doc->setConfig(ConfigRuntime::load($this->tmpDir, 'cs'));

        $data = [
            'doc_type'  => 'cmnbkp',
            'vat_mode'  => 1,
            'docState'  => 10,
            'rows'      => [
                ['row_kind' => 1, 'price_calc_mode' => 1, 'total_price' => 1000.00, 'acc_side' => 0],
                ['row_kind' => 1, 'price_calc_mode' => 1, 'total_price' => 1000.00, 'acc_side' => 1],
            ],
        ];
        $result = $doc->computeAmounts($data);

        $this->assertSame(0, $data['vat_mode']);
        $this->assertSame([], $result['recap']);
        $this->assertSame(1000.0, $data['total_base']);
        $this->assertSame(0.0, $data['total_vat']);
        $this->assertSame(1000.0, $data['total_amount']);
    }
}
