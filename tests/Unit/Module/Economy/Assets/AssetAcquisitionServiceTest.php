<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Assets\AssetAcquisitionService;

/**
 * Pořízení karty z dokladů (docs/assets.md D63): součet základů řádků,
 * podklad pro zařazení jen z účtů 04x a datum posledního takového dokladu.
 * Výběr řádků (jen `purchase.asset`, jen potvrzené doklady, jen karta
 * řádku) drží SQL — ověřuje se jeho tvar.
 */
class AssetAcquisitionServiceTest extends TestCase
{
    /** @param list<array<string, mixed>> $rows */
    private function service(array $rows): AssetAcquisitionService
    {
        return new class($rows) extends AssetAcquisitionService {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(private readonly array $rows)
            {
                parent::__construct(null);
            }

            protected function loadRows(int $assetId): array
            {
                return $this->rows;
            }
        };
    }

    /** @return array<string, mixed> */
    private function row(int $id, int $docId, string $date, string $account, float $base, string $text = 'Řádek'): array
    {
        return [
            'id' => $id, 'doc_head' => $docId, 'doc_number' => '226' . $docId, 'accounting_date' => $date,
            'description' => $text, 'account_number' => $account, 'vat_base_dom' => $base,
        ];
    }

    public function testSumsRowsAndSplitsAcquisitionAccounts(): void
    {
        $acquisition = $this->service([
            $this->row(1, 10, '2026-03-05', '042100', 80000.0, 'Soustruh'),
            $this->row(2, 11, '2026-04-20', '042100', 12500.5, 'Doprava a montáž'),
            // Do nákladů (drobný majetek) — do zařazení se nepočítá.
            $this->row(3, 12, '2026-05-02', '501201', 4990.0, 'Svěrák'),
        ])->acquisition(7);

        $this->assertSame(97490.5, $acquisition['total']);
        $this->assertSame(['amount' => 92500.5, 'date' => '2026-04-20'], $acquisition['activation']);
        $this->assertSame([true, true, false], array_column($acquisition['rows'], 'toActivate'));
        $this->assertSame([
            'rowId' => 1, 'docId' => 10, 'docNumber' => '22610', 'date' => '2026-03-05', 'text' => 'Soustruh',
            'accountNumber' => '042100', 'amount' => 80000.0, 'toActivate' => true,
        ], $acquisition['rows'][0]);
    }

    public function testExpenseOnlyAcquisitionOffersNothingToActivate(): void
    {
        $acquisition = $this->service([$this->row(3, 12, '2026-05-02', '501201', 4990.0)])->acquisition(7);

        $this->assertSame(4990.0, $acquisition['total']);
        $this->assertSame(['amount' => 0.0, 'date' => null], $acquisition['activation']);
    }

    public function testCardWithoutDocumentsIsEmpty(): void
    {
        $this->assertSame(
            ['rows' => [], 'total' => 0.0, 'activation' => ['amount' => 0.0, 'date' => null]],
            $this->service([])->acquisition(7),
        );
        // Bez DB (testy formulářů) služba nic nenačte.
        $this->assertSame([], (new AssetAcquisitionService(null))->acquisition(7)['rows']);
    }

    public function testDateObjectsAndMissingAccountAreTolerated(): void
    {
        $row = ['accounting_date' => new \DateTimeImmutable('2026-06-30'), 'account_number' => null]
            + $this->row(4, 13, '', '', 1000.0);

        $acquisition = $this->service([$row])->acquisition(7);

        $this->assertSame('2026-06-30', $acquisition['rows'][0]['date']);
        $this->assertFalse($acquisition['rows'][0]['toActivate']);
    }

    public function testQuerySelectsOnlyAcquisitionRowsOfConfirmedDocuments(): void
    {
        $captured = null;
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$args) use (&$captured): array {
            $captured = $args;
            return [];
        });

        (new AssetAcquisitionService($db))->acquisition(7);

        $this->assertStringContainsString('[r].[asset] = %i AND [r].[operation] = %s AND [h].[docState] = %i', $captured[0]);
        // Karta z hlavičky dokladu se u pořízení neřeší.
        $this->assertStringNotContainsString('[h].[asset]', $captured[0]);
        $this->assertSame([7, 'purchase.asset', 40], array_slice($captured, 1));
    }
}
