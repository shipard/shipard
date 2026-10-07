<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Assets;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\Accounting\AccountingEngine;
use Shipard\Module\Economy\Assets\Import\AssetDocLinkService;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Doplnění karty na řádek bez účtu (docs/assets.md D86) nad reálným dev
 * DS: faktura přijatá s řádkem `purchase.goods` bez účtu (účet 504 dává
 * kategorie operace až v předpisu) se zaúčtuje, `doc-links` řádek spáruje
 * podle částky, přegeneruje deník a řádek deníku 504 nese kartu, obraty
 * účtů dokladu se nezmění. Karta je smyšlená (dimenze nemá FK), ověření
 * existence karty je v testu vypnuté. Vše vytvořené se v tearDown maže.
 */
class AssetDocLinkServiceTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';
    private const ASSET_ID = 990001;

    /** @var list<int> */
    private array $createdHeads = [];

    private ?ConfigRuntime $config = null;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['economy_accounting_journal' => 'asset', 'docs_core_rows' => 'asset'] as $table => $column) {
            if (!isset($this->tables[$table]) || $this->db->fetchAll("SHOW COLUMNS FROM `{$table}` LIKE %s", $column) === []) {
                $this->markTestSkipped("Dev DS nemá {$table}.{$column} — spusťte ds-upgrade");
            }
        }
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdHeads as $id) {
            $dibi->delete('economy_accounting_journal')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_rows')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
        }
    }

    public function testRowWithoutAccountGetsTheCardIntoTheJournal(): void
    {
        $headId = $this->insertInvoice();
        $engine = new AccountingEngine($this->db->getDibiConnection(), $this->config);
        $this->assertSame(1, $engine->accountDocument($headId)['state']);
        $before = $this->turnoverOf($headId);
        $this->assertArrayHasKey('504100', $before, 'účet z kategorie purchase.goods');
        $this->assertNull($this->lineByPrefix($headId, '504')['asset']);

        $service = new class ($this->db->getDibiConnection(), $this->config) extends AssetDocLinkService {
            public function __construct(\Dibi\Connection $db, ConfigRuntime $config)
            {
                parent::__construct($db, $config, null, null, null, null);
            }

            protected function assetExists(int $assetId): bool
            {
                return true;
            }
        };

        $result = $service->apply(['docId' => $headId, 'headAsset' => null, 'rows' => [
            ['amount' => 100.0, 'asset' => self::ASSET_ID, 'sourceRef' => 'row:1'],
        ]]);

        $this->assertSame('linked', $result['status'], json_encode($result));
        $this->assertSame(['linked'], array_column($result['rows'], 'status'));
        $this->assertSame('unchanged', $result['head']);

        // Řádek dokladu nese kartu, deník ji má na řádku 504 a nikde jinde.
        $rows = $this->db->fetchAll('SELECT operation, asset FROM docs_core_rows WHERE doc_head = %i ORDER BY order_pos, id', $headId);
        $byOperation = [];
        foreach ($rows as $row) {
            $byOperation[(string) $row['operation']] = $row['asset'] !== null ? (int) $row['asset'] : null;
        }
        $this->assertSame(['purchase.goods' => self::ASSET_ID, 'purchase.services' => null], $byOperation);
        $this->assertSame(self::ASSET_ID, (int) $this->lineByPrefix($headId, '504')['asset']);
        $this->assertNull($this->lineByPrefix($headId, '518')['asset']);
        $this->assertSame($before, $this->turnoverOf($headId), 'obraty účtů dokladu se nezměnily');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Faktura přijatá ve stavu 40: zboží 100 (bez účtu → kategorie), služby 200, DPH 21 %. */
    private function insertInvoice(): int
    {
        $fy = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_years WHERE date_begin <= %s AND date_end >= %s LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        $fm = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_months WHERE date_begin <= %s AND date_end >= %s AND period_type = 1 LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        $partner = $this->db->fetchRow('SELECT id FROM base_persons_persons ORDER BY id LIMIT 1');
        $series = $this->db->fetchRow('SELECT id FROM docs_core_number_series WHERE doc_type = %s LIMIT 1', 'invni');
        if ($fy === null || $fm === null || $partner === null || $series === null) {
            $this->markTestSkipped('Dev DS nemá fiskální období, osobu nebo řadu invni pro ' . self::ACC_DATE);
        }

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', [
            'doc_type'         => 'invni',
            'number_series'    => (int) $series['id'],
            'doc_number'       => 'IT-ASSET-' . uniqid(),
            'issue_date'       => self::ACC_DATE,
            'accounting_date'  => self::ACC_DATE,
            'fiscal_year'      => (int) $fy['id'],
            'fiscal_month'     => (int) $fm['id'],
            'partner'          => (int) $partner['id'],
            'doc_currency'     => 'czk',
            'home_currency'    => 'czk',
            'exchange_rate'    => 1.0,
            'doc_text'         => 'IT test doplnění karty',
            'total_base'       => 300.0, 'total_vat' => 63.0, 'total_amount' => 363.0,
            'total_base_dom'   => 300.0, 'total_vat_dom' => 63.0, 'total_amount_dom' => 363.0,
            'docState'         => 40,
            'docStateMain'     => 2,
        ])->execute();
        $headId = (int) $dibi->getInsertId();
        $this->createdHeads[] = $headId;

        foreach ([['purchase.goods', 100.0, 1], ['purchase.services', 200.0, 2]] as [$operation, $base, $pos]) {
            $vat = round($base * 0.21, 2);
            $dibi->insert('docs_core_rows', [
                'doc_head'       => $headId,
                'row_kind'       => 1,
                'order_pos'      => $pos,
                'operation'      => $operation,
                'description'    => "Řádek {$operation}",
                'vat_code'       => 'cz-101',
                'vat_pct'        => 21.0,
                'vat_base'       => $base, 'vat_amount' => $vat, 'vat_total' => round($base + $vat, 2),
                'vat_base_dom'   => $base, 'vat_amount_dom' => $vat, 'vat_total_dom' => round($base + $vat, 2),
            ])->execute();
        }
        $dibi->insert('docs_core_vat_recap', [
            'doc_head' => $headId, 'vat_code' => 'cz-101', 'vat_pct' => 21.0,
            'base' => 300.0, 'tax' => 63.0, 'total' => 363.0, 'base_dom' => 300.0, 'tax_dom' => 63.0, 'total_dom' => 363.0,
            'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1, 'is_reverse_pair' => 0, 'order_pos' => 0,
        ])->execute();

        return $headId;
    }

    /** @return array<string, array{dr: float, cr: float}> účet → obraty MD / DAL dokladu */
    private function turnoverOf(int $headId): array
    {
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT account_number, SUM(money_dr) AS dr, SUM(money_cr) AS cr FROM economy_accounting_journal WHERE doc_head = %i GROUP BY account_number',
            $headId,
        ) as $row) {
            $out[(string) $row['account_number']] = ['dr' => round((float) $row['dr'], 2), 'cr' => round((float) $row['cr'], 2)];
        }
        ksort($out);
        return $out;
    }

    /** @return array<string, mixed> jediný řádek deníku dokladu s daným prefixem účtu */
    private function lineByPrefix(int $headId, string $prefix): array
    {
        $lines = $this->db->fetchAll(
            'SELECT * FROM economy_accounting_journal WHERE doc_head = %i AND account_number LIKE %s',
            $headId, $prefix . '%',
        );
        $this->assertCount(1, $lines, "Očekáván právě jeden řádek deníku {$prefix}*");
        $line = $lines[0];
        return is_array($line) ? $line : $line->toArray();
    }
}
