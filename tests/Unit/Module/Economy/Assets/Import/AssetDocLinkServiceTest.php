<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets\Import;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\DocumentLockReason;
use Shipard\Module\Economy\Assets\Import\AssetDocLinkService;

/** Služba nad pamětí: doklad, řádky a deník v polích, přeúčtování simulované. */
class RecordingAssetDocLinkService extends AssetDocLinkService
{
    /** @var array<int, array<string, mixed>> id → hlavička */
    public array $heads = [];
    /** @var array<int, list<array<string, mixed>>> id dokladu → řádky */
    public array $rows = [];
    /** @var array<int, array<string, array{dr: float, cr: float}>> id dokladu → obraty */
    public array $turnover = [];
    /** @var array<string, array{dr: float, cr: float}>|null obraty po přeúčtování (null = beze změny) */
    public ?array $turnoverAfter = null;
    /** @var array{state: int, messages: list<array<string, mixed>>} */
    public array $accounting = ['state' => 1, 'messages' => []];
    /** @var list<int> */
    public array $assets = [15, 16, 17];
    /** @var list<DocumentLockReason> */
    public array $locks = [];

    /** @var list<array{int, int}> [řádek, karta] */
    public array $rowUpdates = [];
    /** @var list<array{int, ?int}> */
    public array $headUpdates = [];
    /** @var list<int> */
    public array $reaccounted = [];
    /** @var list<array{int, list<string>}> */
    public array $bypassLog = [];
    /** @var list<string> */
    public array $log = [];

    public function __construct()
    {
        parent::__construct(null, null, null, null, null, null);
    }

    /** @param array<string, mixed> $o */
    public function head(int $id, int $docState = 40, ?int $asset = null, array $o = []): void
    {
        $this->heads[$id] = $o + ['id' => $id, 'docState' => $docState, 'asset' => $asset, 'doc_number' => "UD{$id}", 'accounting_date' => '2024-12-31'];
    }

    public function row(int $doc, int $id, string $account, ?int $side, float $amount, int $orderPos, ?int $asset = null): void
    {
        $this->rows[$doc][] = ['id' => $id, 'account_number' => $account, 'acc_side' => $side, 'vat_base_dom' => $amount, 'order_pos' => $orderPos, 'asset' => $asset];
    }

    protected function loadHead(int $docId): ?array
    {
        return $this->heads[$docId] ?? null;
    }

    protected function loadRows(int $docId): array
    {
        return $this->rows[$docId] ?? [];
    }

    protected function assetExists(int $assetId): bool
    {
        return in_array($assetId, $this->assets, true);
    }

    protected function updateRowAsset(int $rowId, int $assetId): void
    {
        $this->rowUpdates[] = [$rowId, $assetId];
    }

    protected function updateHeadAsset(int $docId, ?int $assetId): void
    {
        $this->headUpdates[] = [$docId, $assetId];
    }

    protected function journalTurnover(int $docId): array
    {
        if ($this->turnoverAfter !== null && in_array($docId, $this->reaccounted, true)) {
            return $this->turnoverAfter;
        }
        return $this->turnover[$docId] ?? [];
    }

    protected function reaccount(int $docId): array
    {
        $this->reaccounted[] = $docId;
        return $this->accounting;
    }

    protected function lockReasons(array $head): array
    {
        return $this->locks;
    }

    protected function logBypass(int $docId, array $reasons): void
    {
        $this->bypassLog[] = [$docId, $reasons];
    }

    protected function begin(): void
    {
        $this->log[] = 'begin';
    }

    protected function commit(): void
    {
        $this->log[] = 'commit';
    }

    protected function rollback(): void
    {
        $this->log[] = 'rollback';
    }
}

/**
 * Doplnění karty na importované doklady (docs/assets.md D80): párování
 * řádků podle účtu, částky a strany, `orderHint`, konflikt, celý doklad
 * nebo nic, přegenerování deníku s pojistkou obratů a zámky jen do logu.
 */
class AssetDocLinkServiceTest extends TestCase
{
    private RecordingAssetDocLinkService $service;

    protected function setUp(): void
    {
        $this->service = new RecordingAssetDocLinkService();
    }

    /** Účetní doklad odpisů: dvě dvojice 551 / 082 pro dvě karty. */
    private function depreciationDocument(): void
    {
        $this->service->head(4711);
        $this->service->row(4711, 801, '551022', 0, 13074.00, 1);
        $this->service->row(4711, 802, '082022', 1, 13074.00, 2);
        $this->service->row(4711, 803, '551022', 0, 2500.00, 3);
        $this->service->row(4711, 804, '082022', 1, 2500.00, 4);
        $this->service->turnover[4711] = ['551022' => ['dr' => 15574.0, 'cr' => 0.0], '082022' => ['dr' => 0.0, 'cr' => 15574.0]];
    }

    /** @return array<string, mixed> */
    private function depreciationPayload(): array
    {
        return ['docId' => 4711, 'headAsset' => null, 'rows' => [
            ['account' => '551022', 'side' => 'dr', 'amount' => 13074.00, 'asset' => 15, 'orderHint' => 1, 'sourceRef' => 'row:1'],
            ['account' => '082022', 'side' => 'cr', 'amount' => 13074.00, 'asset' => 15, 'orderHint' => 2, 'sourceRef' => 'row:2'],
            ['account' => '551022', 'side' => 'dr', 'amount' => 2500.00, 'asset' => 16, 'sourceRef' => 'row:3'],
            ['account' => '082022', 'side' => 'cr', 'amount' => 2500.00, 'asset' => 16, 'sourceRef' => 'row:4'],
        ]];
    }

    public function testLinksAccountingDocumentRowsAndReaccounts(): void
    {
        $this->depreciationDocument();

        $result = $this->service->apply($this->depreciationPayload());

        $this->assertSame('linked', $result['status']);
        $this->assertSame(4711, $result['docId']);
        $this->assertSame('unchanged', $result['head']);
        $this->assertSame(
            [[0, 'row:1', 801, 'linked'], [1, 'row:2', 802, 'linked'], [2, 'row:3', 803, 'linked'], [3, 'row:4', 804, 'linked']],
            array_map(static fn(array $r): array => [$r['index'], $r['sourceRef'], $r['rowId'], $r['status']], $result['rows']),
        );
        $this->assertSame([[801, 15], [802, 15], [803, 16], [804, 16]], $this->service->rowUpdates);
        $this->assertSame([], $this->service->headUpdates);
        $this->assertSame([4711], $this->service->reaccounted);
        $this->assertSame(['begin', 'commit'], $this->service->log);
        $this->assertSame([], $this->service->bypassLog);
    }

    public function testSameAmountRowsNeedOrderHint(): void
    {
        $this->service->head(1);
        $this->service->row(1, 11, '551022', 0, 1000.00, 1);
        $this->service->row(1, 12, '551022', 0, 1000.00, 2);
        $this->service->row(1, 13, '082022', 1, 2000.00, 3);
        $this->service->turnover[1] = [];

        // Bez nápovědy pořadí je párování nejednoznačné — doklad beze změny.
        $result = $this->service->apply(['docId' => 1, 'rows' => [
            ['account' => '551022', 'side' => 'dr', 'amount' => 1000, 'asset' => 15],
            ['account' => '551022', 'side' => 'dr', 'amount' => 1000, 'asset' => 16],
        ]]);
        $this->assertSame('ambiguous', $result['status']);
        $this->assertSame(['ambiguous', 'ambiguous'], array_column($result['rows'], 'status'));
        $this->assertSame([], $this->service->rowUpdates);
        $this->assertSame([], $this->service->log);

        // orderHint rozhodne; druhý řádek bez nápovědy už má jediného volného kandidáta.
        $result = $this->service->apply(['docId' => 1, 'rows' => [
            ['account' => '551022', 'side' => 'dr', 'amount' => 1000, 'asset' => 15, 'orderHint' => 2],
            ['account' => '551022', 'side' => 'dr', 'amount' => 1000, 'asset' => 16],
        ]]);
        $this->assertSame('linked', $result['status']);
        $this->assertSame([12, 11], array_column($result['rows'], 'rowId'));
        $this->assertSame([[12, 15], [11, 16]], $this->service->rowUpdates);
    }

    public function testInvoiceWithHeadAssetAndNoSide(): void
    {
        // Faktura: strana v payloadu chybí, karta i na hlavičce.
        $this->service->head(2);
        $this->service->row(2, 21, '042100', null, 48000.00, 1);
        $this->service->row(2, 22, '501100', null, 350.00, 2);
        $this->service->turnover[2] = ['042100' => ['dr' => 48000.0, 'cr' => 0.0], '501100' => ['dr' => 350.0, 'cr' => 0.0], '321100' => ['dr' => 0.0, 'cr' => 48350.0]];

        $result = $this->service->apply(['docId' => 2, 'headAsset' => 17, 'rows' => [
            ['account' => '042100', 'amount' => 48000, 'asset' => 17, 'sourceRef' => 'row:9'],
        ]]);

        $this->assertSame('linked', $result['status']);
        $this->assertSame('linked', $result['head']);
        $this->assertSame([[21, 17]], $this->service->rowUpdates);
        $this->assertSame([[2, 17]], $this->service->headUpdates);
        $this->assertSame([2], $this->service->reaccounted);
    }

    public function testLockedMonthIsBypassedAndLogged(): void
    {
        $this->depreciationDocument();
        $this->service->locks = [new DocumentLockReason('fiscal_month', 'Fiskální měsíc 2024/12 je uzamčený', subjectTableId: 314, subjectRowId: 312)];

        $result = $this->service->apply($this->depreciationPayload());

        $this->assertSame('linked', $result['status']);
        $this->assertSame([[4711, ['fiscal_month:312']]], $this->service->bypassLog);
        $this->assertSame([4711], $this->service->reaccounted);
    }

    public function testRowNotFoundLeavesDocumentUntouched(): void
    {
        $this->depreciationDocument();
        $payload = $this->depreciationPayload();
        $payload['rows'][2]['amount'] = 2500.01;

        $result = $this->service->apply($payload);

        $this->assertSame('notFound', $result['status']);
        $this->assertSame(['linked', 'linked', 'notFound', 'linked'], array_column($result['rows'], 'status'));
        $this->assertNull($result['rows'][2]['rowId']);
        $this->assertSame([], $this->service->rowUpdates);
        $this->assertSame([], $this->service->reaccounted);
        $this->assertSame([], $this->service->log);
    }

    public function testConflictingCardLeavesDocumentUntouched(): void
    {
        $this->depreciationDocument();
        $this->service->rows[4711][0]['asset'] = 16;

        $result = $this->service->apply($this->depreciationPayload());

        $this->assertSame('conflict', $result['status']);
        $this->assertSame('conflict', $result['rows'][0]['status']);
        $this->assertSame([], $this->service->rowUpdates);
        $this->assertSame([], $this->service->log);
    }

    public function testRepeatedApplyIsUnchangedWithoutReaccount(): void
    {
        $this->depreciationDocument();
        foreach ([0 => 15, 1 => 15, 2 => 16, 3 => 16] as $i => $asset) {
            $this->service->rows[4711][$i]['asset'] = $asset;
        }

        $result = $this->service->apply($this->depreciationPayload());

        $this->assertSame('unchanged', $result['status']);
        $this->assertSame(['unchanged', 'unchanged', 'unchanged', 'unchanged'], array_column($result['rows'], 'status'));
        $this->assertSame([], $this->service->reaccounted);
        $this->assertSame([], $this->service->log);
    }

    public function testChangedTurnoverRollsBack(): void
    {
        $this->depreciationDocument();
        $this->service->turnoverAfter = ['551022' => ['dr' => 15574.0, 'cr' => 0.0], '082022' => ['dr' => 0.0, 'cr' => 15000.0]];

        $result = $this->service->apply($this->depreciationPayload());

        $this->assertSame('turnover_changed', $result['status']);
        $this->assertSame(15000.0, $result['turnover']['after']['082022']['cr']);
        $this->assertSame(['begin', 'rollback'], $this->service->log);
    }

    public function testFailedAccountingRollsBack(): void
    {
        $this->depreciationDocument();
        $this->service->accounting = ['state' => 2, 'messages' => [['code' => 'account_not_found', 'message' => 'Účet 082022 nenalezen']]];

        $result = $this->service->apply($this->depreciationPayload());

        $this->assertSame('accounting_failed', $result['status']);
        $this->assertSame('account_not_found', $result['messages'][0]['code']);
        $this->assertSame(['begin', 'rollback'], $this->service->log);
    }

    public function testDocumentOutsideStateOkGetsColumnsOnly(): void
    {
        $this->depreciationDocument();
        $this->service->heads[4711]['docState'] = 10;

        $result = $this->service->apply($this->depreciationPayload());

        $this->assertSame('linked', $result['status']);
        $this->assertCount(4, $this->service->rowUpdates);
        $this->assertSame([], $this->service->reaccounted);
        $this->assertSame(['begin', 'commit'], $this->service->log);
    }

    public function testUnknownDocumentAndInvalidShape(): void
    {
        $this->assertSame('notFound', $this->service->apply(['docId' => 99, 'rows' => []])['status']);

        $result = $this->service->apply(['docId' => 0, 'headAsset' => 99, 'rows' => [
            ['account' => '', 'side' => 'md', 'asset' => 99],
        ]]);
        $this->assertSame('invalid', $result['status']);
        $this->assertSame(
            ['docId', 'headAsset', 'rows.0.account', 'rows.0.side', 'rows.0.amount', 'rows.0.asset'],
            array_column($result['issues'], 'path'),
        );
        $this->assertSame('asset_not_found', $result['issues'][1]['code']);
        $this->assertSame([], $this->service->log);
    }
}
