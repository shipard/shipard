<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accbal;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Accounting\OpenItem;
use Shipard\Core\Accounting\OpenItemLookup;
use Shipard\Module\Economy\Accbal\ClearingRouter;
use Shipard\Module\Economy\Accbal\RouteResult;

/**
 * ClearingRouter nad mockem Dibi — výběr kandidátů (SQL + filtry / klíč),
 * důvody přeskočení, dry-run plán bez enginu, deduplikace přes klíče.
 * Reálné přeúčtování (engine + re-derivace) kryje BankPaymentRoutingTest.
 */
class ClearingRouterTest extends TestCase
{
    /** @var list<array{sql: string, params: list<mixed>}> */
    private array $queries = [];

    /** @var list<array<string, mixed>> */
    private array $candidates = [];

    private bool $clearingExists = true;

    private function router(OpenItemLookup $lookup): ClearingRouter
    {
        $this->queries = [];
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturnCallback(
            fn(...$args) => $this->clearingExists ? new \Dibi\Row(['id' => 50]) : null,
        );
        $db->method('fetchAll')->willReturnCallback(function (...$args): array {
            $this->queries[] = ['sql' => (string) $args[0], 'params' => array_slice($args, 1)];
            return $this->candidates;
        });
        return new ClearingRouter($db, null, null, $lookup);
    }

    private static function candidate(int $txId, array $over = []): array
    {
        return array_merge([
            'id'                => 1000 + $txId,
            'bank_transaction'  => $txId,
            'partner'           => 42,
            'payment_reference' => '20260001',
            'specific_symbol'   => null,
            'currency'          => 'czk',
            'amount'            => 100.00,
            'amount_hc'         => 100.00,
            'direction'         => 1,
        ], $over);
    }

    public function testNoPartnerIsSkipped(): void
    {
        $this->candidates = [self::candidate(7, ['partner' => null])];
        $lookup = new RecordingLookup(null);

        $summary = $this->router($lookup)->rerouteAll(['partner' => 1], true);

        $this->assertSame(['no_partner' => 1], $summary->skipped);
        $this->assertSame([], $lookup->calls, 'bez partnera se lookup nevolá');
    }

    public function testNoOpenItemIsSkipped(): void
    {
        $this->candidates = [self::candidate(7)];
        $lookup = new RecordingLookup(null);

        $summary = $this->router($lookup)->rerouteAll([], true);

        $this->assertSame(['no_open_item' => 1], $summary->skipped);
        $this->assertSame(0, $summary->planned);
        $this->assertSame(1, $summary->candidates());
    }

    public function testDryRunPlansWithoutAccounting(): void
    {
        $this->candidates = [self::candidate(7, ['specific_symbol' => ' 77 ', 'currency' => 'CZK', 'amount_hc' => 123.45])];
        $lookup = new RecordingLookup(new OpenItem(1, '311100', 500.0));

        $summary = $this->router($lookup)->rerouteAll([], true);

        $this->assertSame(1, $summary->planned);
        $this->assertSame(0, $summary->routed);
        $this->assertEqualsWithDelta(123.45, $summary->routedAmount, 0.001);
        $r = $summary->results[0];
        $this->assertSame(RouteResult::STATUS_PLANNED, $r->status);
        $this->assertSame('311100', $r->targetAccount);
        $this->assertSame(7, $r->txId);
        // Lookup dostane normalizovaný klíč, směr a vyloučení vlastní transakce.
        $this->assertSame([[42, '20260001', '77', 'czk', 1, 'bankTransaction', 7]], $lookup->calls);
    }

    public function testRerouteAllAppliesFilters(): void
    {
        $this->candidates = [];

        $this->router(new RecordingLookup(null))->rerouteAll(['partner' => 42, 'fiscalYear' => 7]);

        $q = $this->queries[0];
        $this->assertStringContainsString('l.[partner] = %i', $q['sql']);
        $this->assertStringContainsString('l.[fiscal_year] = %i', $q['sql']);
        $this->assertStringContainsString('t.[docState] = %i', $q['sql']);
        $this->assertStringContainsString('ORDER BY t.[date_transaction] ASC, t.[id] ASC', $q['sql']);
        $this->assertSame([50, 'bankTransaction', 40, 42, 7], $q['params']);
    }

    public function testRerouteForKeysFiltersByKeyAndDedupes(): void
    {
        $this->candidates = [self::candidate(7)];
        $lookup = new RecordingLookup(new OpenItem(1, '311100', 500.0));

        $summary = $this->router($lookup)->rerouteForKeys([
            ['partner' => 42, 'payment_reference' => ' 20260001 ', 'specific_symbol' => '', 'currency' => 'CZK'],
            ['partner' => 42, 'payment_reference' => '20260002', 'specific_symbol' => '', 'currency' => 'czk'],
        ], true);

        $this->assertCount(2, $this->queries, 'dotaz per klíč');
        $q = $this->queries[0];
        $this->assertStringContainsString('TRIM(l.[payment_reference]) = %s', $q['sql']);
        $this->assertStringContainsString("TRIM(COALESCE(l.[specific_symbol], '')) = %s", $q['sql']);
        $this->assertStringContainsString('LOWER(l.[currency]) = %s', $q['sql']);
        $this->assertSame([50, 'bankTransaction', 40, 42, '20260001', '', 'czk'], $q['params']);
        $this->assertSame(1, $summary->candidates(), 'táž transakce přes dva klíče jen jednou');
        $this->assertSame(1, $summary->planned);
    }

    public function testMissingClearingGroupYieldsEmptySummary(): void
    {
        $this->clearingExists = false;
        $this->candidates = [self::candidate(7)];

        $summary = $this->router(new RecordingLookup(null))->rerouteAll([], true);

        $this->assertSame(0, $summary->candidates());
        $this->assertSame([], $this->queries, 'bez skupiny unmatched_payments se kandidáti nehledají');
    }
}

class RecordingLookup implements OpenItemLookup
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    public function __construct(private readonly ?OpenItem $item)
    {
    }

    public function findOpenRequest(
        int $partner,
        string $paymentReference,
        string $specificSymbol,
        string $currency,
        int $direction,
        ?string $excludeSourceKind = null,
        ?int $excludeSourceId = null,
    ): ?OpenItem {
        $this->calls[] = [$partner, $paymentReference, $specificSymbol, $currency, $direction, $excludeSourceKind, $excludeSourceId];
        return $this->item;
    }
}
