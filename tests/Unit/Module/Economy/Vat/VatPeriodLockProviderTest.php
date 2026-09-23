<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\Vat\ReportPeriodLookup;
use Shipard\Module\Economy\Vat\VatOutputsMapping;
use Shipard\Module\Economy\Vat\VatPeriodLockProvider;

/** In-memory find-only lookup: [id, reg, type, begin, end]; počítá volání. */
final class LockTestPeriodLookup implements ReportPeriodLookup
{
    public int $calls = 0;

    /** @param list<array{0: int, 1: int, 2: string, 3: string, 4: string}> $instances */
    public function __construct(private readonly array $instances) {}

    public function covering(int $registrationId, string $type, string $date): ?array
    {
        $this->calls++;
        foreach ($this->instances as [$id, $reg, $t, $begin, $end]) {
            if ($reg === $registrationId && $t === $type && $begin <= $date && $end >= $date) {
                return ['id' => $id, 'date_begin' => $begin, 'date_end' => $end];
            }
        }
        return null;
    }
}

/**
 * DB seams nahrazené in-memory stavem: uložený recap per doklad, zamčené
 * instance, find-only lookup.
 */
final class TestableVatPeriodLockProvider extends VatPeriodLockProvider
{
    /** @var array<int, list<string>> id dokladu → kódy uloženého recapu */
    public array $storedRecaps = [];

    /** @var array<int, array{name: string, report_type: string}> zamčené instance */
    public array $locked = [];

    public LockTestPeriodLookup $lookup;
    public ?VatOutputsMapping $mappingValue = null;

    /** @var list<list<int>> */
    public array $lockedQueries = [];

    public function __construct(LockTestPeriodLookup $lookup)
    {
        $this->lookup = $lookup;
    }

    public function withDb(): self
    {
        $ref = new \ReflectionClass(\Dibi\Connection::class);
        $this->db = $ref->newInstanceWithoutConstructor();
        return $this;
    }

    protected function hasStoredRecap(int $headId): bool
    {
        return ($this->storedRecaps[$headId] ?? []) !== [];
    }

    protected function storedRecapCodes(int $headId): array
    {
        return array_map(static fn (string $c): array => ['vat_code' => $c], $this->storedRecaps[$headId] ?? []);
    }

    protected function lockedInstances(array $ids): array
    {
        $this->lockedQueries[] = $ids;
        return array_intersect_key($this->locked, array_flip($ids));
    }

    protected function periodLookup(): ReportPeriodLookup
    {
        return $this->lookup;
    }

    protected function mapping(): ?VatOutputsMapping
    {
        return $this->mappingValue;
    }
}

/**
 * VatPeriodLockProvider (#55 D23): zámek podle obsahu DPH a kteréhokoli
 * ukazatele, v původním i novém stavu. Instance: přiznání Q1/2026 (101,
 * zamčené) a Q2/2026 (102), KH 01/2026 (111, zamčené), 02/2026 (112),
 * 04/2026 (114). Registrace 5.
 */
final class VatPeriodLockProviderTest extends TestCase
{
    private const TABLE = 'docs_core_heads';

    private function lookup(): LockTestPeriodLookup
    {
        return new LockTestPeriodLookup([
            [101, 5, 'return', '2026-01-01', '2026-03-31'],
            [102, 5, 'return', '2026-04-01', '2026-06-30'],
            [111, 5, 'cs', '2026-01-01', '2026-01-31'],
            [112, 5, 'cs', '2026-02-01', '2026-02-28'],
            [114, 5, 'cs', '2026-04-01', '2026-04-30'],
        ]);
    }

    private function provider(): TestableVatPeriodLockProvider
    {
        $p = (new TestableVatPeriodLockProvider($this->lookup()))->withDb();
        $p->locked = [
            101 => ['name' => 'Q1/2026', 'report_type' => 'return'],
            111 => ['name' => '01/2026', 'report_type' => 'cs'],
        ];
        $p->mappingValue = new VatOutputsMapping([
            'vatOutputs' => [
                'cz-210' => ['dp3' => ['row' => 1], 'kh' => ['group' => 'A4A5'], 'sh' => null],
                'cz-410' => ['dp3' => ['row' => 20], 'kh' => null, 'sh' => ['kod' => 0]],
            ],
        ]);
        return $p;
    }

    /** Uložený doklad s DPH v Q1 (přiznání i KH leden). */
    private function storedVatDoc(array $override = []): array
    {
        return array_merge([
            'id' => 1, 'doc_number' => 'FV-1', 'docState' => 40, 'vat_registration' => 5, 'vat_mode' => 1,
            'vat_duzp' => '2026-01-15', 'vat_dppd' => null,
            'vat_period' => 101, 'cs_period' => 111, 'rs_period' => null,
        ], $override);
    }

    private function vatRow(string $code = 'cz-210'): array
    {
        return ['row_kind' => 1, 'vat_code' => $code, 'total_price' => 100];
    }

    // ── nedaňový typ (#79 D1) ───────────────────────────────────────────────

    /** Proforma s DPH v zamčeném Q1 a ručním ukazatelem: nové ukazatele žádné. */
    public function testNonTaxDocumentTypeHasNoNewPointers(): void
    {
        $docTypes = [
            'invno' => ['trade_dir' => 1],
            'invpo' => ['trade_dir' => 1, 'tax_document' => false],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'docs.core.docTypes' ? $docTypes : null,
        );
        $p = $this->provider();
        $p->setConfig($config);
        $data = [
            'doc_type' => 'invpo', 'docState' => 10, 'vat_registration' => 5, 'vat_mode' => 1,
            'vat_duzp' => '2026-01-15', 'vat_period' => 101, 'rows' => [$this->vatRow()],
        ];

        $this->assertSame([], $p->lockReasons(self::TABLE, $data, null));
        // Kontrola: tentýž payload jako faktura zamčený je.
        $this->assertNotEmpty($p->lockReasons(self::TABLE, ['doc_type' => 'invno'] + $data, null));
    }

    // ── původní stav ────────────────────────────────────────────────────────

    public function testStoredVatDocumentInLockedInstancesIsLocked(): void
    {
        $p = $this->provider();
        $p->storedRecaps[1] = ['cz-210'];
        $row = $this->storedVatDoc();

        $reasons = $p->lockReasons(self::TABLE, $row, $row);

        $this->assertCount(2, $reasons);
        $this->assertSame('Přiznání k DPH Q1/2026 je uzamčené', $reasons[0]->title);
        $this->assertSame('Kontrolní hlášení 01/2026 je uzamčené', $reasons[1]->title);
        $this->assertSame('vat_period', $reasons[0]->source);
        $this->assertSame(441, $reasons[0]->subjectTableId);
        $this->assertSame(101, $reasons[0]->subjectRowId);
        $this->assertSame(['type' => 'cs', 'name' => '01/2026', 'column' => 'cs_period'], $reasons[1]->params);
    }

    public function testStateTransitionOfLockedDocumentIsLocked(): void
    {
        $p = $this->provider();
        $p->storedRecaps[1] = ['cz-210'];
        $original = $this->storedVatDoc();
        // Přechod 40→80 přes saveDocument: payload = celý řádek + nový stav (bez rows).
        $data = $this->storedVatDoc(['docState' => 80]);

        $this->assertCount(2, $p->lockReasons(self::TABLE, $data, $original));
    }

    public function testRemovingVatFromLockedDocumentStaysLocked(): void
    {
        $p = $this->provider();
        $p->storedRecaps[1] = ['cz-210'];
        $original = $this->storedVatDoc();
        // Nový stav bez DPH — původní strana pořád drží zámek (obsah období by se změnil).
        $data = $this->storedVatDoc(['vat_mode' => 0, 'rows' => [['row_kind' => 1, 'vat_code' => null]]]);

        $reasons = $p->lockReasons(self::TABLE, $data, $original);

        $this->assertNotEmpty($reasons);
    }

    public function testOnlyCsPointerLockedStillLocks(): void
    {
        $p = $this->provider();
        $p->locked = [111 => ['name' => '01/2026', 'report_type' => 'cs']];   // přiznání Q1 odemčené, měsíční KH zamčené
        $p->storedRecaps[1] = ['cz-210'];
        $row = $this->storedVatDoc();

        $reasons = $p->lockReasons(self::TABLE, $row, $row);

        $this->assertCount(1, $reasons);
        $this->assertSame('Kontrolní hlášení 01/2026 je uzamčené', $reasons[0]->title);
    }

    // ── bezdaňový obsah ─────────────────────────────────────────────────────

    public function testCashTransferWithoutVatInLockedRangeIsFree(): void
    {
        $p = $this->provider();
        $row = [
            'id' => 7, 'docState' => 40, 'vat_registration' => 5, 'vat_mode' => 0,
            'vat_duzp' => '2026-01-20', 'vat_period' => null, 'cs_period' => null, 'rs_period' => null,
        ];

        $this->assertSame([], $p->lockReasons(self::TABLE, $row, $row));
        $this->assertSame(0, $p->lookup->calls);
        $this->assertSame([], $p->lockedQueries);
    }

    public function testDocumentWithoutDuzpOrRegistrationIsFree(): void
    {
        $p = $this->provider();
        $data = ['vat_registration' => 5, 'vat_mode' => 1, 'vat_duzp' => null, 'rows' => [$this->vatRow()]];
        $this->assertSame([], $p->lockReasons(self::TABLE, $data, null));

        $data = ['vat_registration' => null, 'vat_mode' => 1, 'vat_duzp' => '2026-01-15', 'rows' => [$this->vatRow()]];
        $this->assertSame([], $p->lockReasons(self::TABLE, $data, null));
    }

    public function testWithoutDbProviderIsSilent(): void
    {
        $p = new TestableVatPeriodLockProvider($this->lookup());
        $p->storedRecaps[1] = ['cz-210'];
        $row = $this->storedVatDoc();

        $this->assertSame([], $p->lockReasons(self::TABLE, $row, $row));
    }

    // ── nový stav ───────────────────────────────────────────────────────────

    public function testAddingVatRowToNonVatDocumentIsBlocked(): void
    {
        $p = $this->provider();
        $original = [
            'id' => 8, 'docState' => 10, 'vat_registration' => 5, 'vat_mode' => 1,
            'vat_duzp' => '2026-01-20', 'vat_period' => null, 'cs_period' => null, 'rs_period' => null,
        ];
        $data = $original + ['rows' => [$this->vatRow('cz-210')]];

        $reasons = $p->lockReasons(self::TABLE, $data, $original);

        $this->assertSame(
            ['Přiznání k DPH Q1/2026 je uzamčené', 'Kontrolní hlášení 01/2026 je uzamčené'],
            array_map(static fn ($r) => $r->title, $reasons),
        );
    }

    public function testNewDocumentWithDuzpInLockedRangeIsBlockedWithoutCreatingInstance(): void
    {
        $p = $this->provider();
        $data = [
            'vat_registration' => 5, 'vat_mode' => 1, 'vat_duzp' => '2026-02-10',
            'rows' => [$this->vatRow('cz-410')],   // jen SH kód → KH se neřeší
        ];

        $reasons = $p->lockReasons(self::TABLE, $data, null);

        $this->assertCount(1, $reasons);
        $this->assertSame(101, $reasons[0]->subjectRowId);
        // Lookup je find-only fake — SH instance neexistuje, nic se nezaložilo, doklad je přesto blokovaný přiznáním.
        $this->assertGreaterThan(0, $p->lookup->calls);
    }

    public function testNewDocumentInUnlockedRangeIsFree(): void
    {
        $p = $this->provider();
        $data = ['vat_registration' => 5, 'vat_mode' => 1, 'vat_duzp' => '2026-04-10', 'rows' => [$this->vatRow()]];

        $this->assertSame([], $p->lockReasons(self::TABLE, $data, null));
    }

    public function testManualMoveOfCsPointerIntoLockedInstanceIsBlocked(): void
    {
        $p = $this->provider();
        $p->storedRecaps[2] = ['cz-210'];
        $original = $this->storedVatDoc(['id' => 2, 'vat_duzp' => '2026-02-10', 'vat_period' => 101, 'cs_period' => 112]);
        $p->locked = [111 => ['name' => '01/2026', 'report_type' => 'cs']];
        $data = $original;
        $data['cs_period'] = 111;   // ruční přesun do zamčeného ledna

        $reasons = $p->lockReasons(self::TABLE, $data, $original);

        $this->assertCount(1, $reasons);
        $this->assertSame(111, $reasons[0]->subjectRowId);
        $this->assertSame('cs_period', $reasons[0]->params['column']);
    }

    public function testManualMoveOutOfLockedInstanceIsBlocked(): void
    {
        $p = $this->provider();
        $p->storedRecaps[1] = ['cz-210'];
        $original = $this->storedVatDoc();   // KH leden (zamčený)
        $data = $original;
        $data['cs_period'] = 112;            // pryč ze zamčeného ledna
        $p->locked = [111 => ['name' => '01/2026', 'report_type' => 'cs']];

        $reasons = $p->lockReasons(self::TABLE, $data, $original);

        $this->assertCount(1, $reasons);
        $this->assertSame(111, $reasons[0]->subjectRowId);
    }

    public function testHeaderOnlySaveUsesStoredRecapForMembership(): void
    {
        $p = $this->provider();
        $p->locked = [111 => ['name' => '01/2026', 'report_type' => 'cs']];
        $p->storedRecaps[3] = ['cz-210'];
        // Uložený doklad má KH ukazatel NULL (např. přiřazení dorovná až handler), payload bez rows.
        $original = $this->storedVatDoc(['id' => 3, 'vat_period' => 101, 'cs_period' => null]);
        $data = $original;
        $data['doc_text'] = 'edited';

        $reasons = $p->lockReasons(self::TABLE, $data, $original);

        // Nový stav spočítá KH leden z uloženého recapu → zamčeno.
        $this->assertCount(1, $reasons);
        $this->assertSame(111, $reasons[0]->subjectRowId);
    }

    public function testLockedInstancesQueriedOnceWithUnionOfPointers(): void
    {
        $p = $this->provider();
        $p->storedRecaps[1] = ['cz-210'];
        $original = $this->storedVatDoc();
        $data = $original;
        $data['cs_period'] = 112;

        $p->lockReasons(self::TABLE, $data, $original);

        $this->assertCount(1, $p->lockedQueries);
        $this->assertEqualsCanonicalizing([101, 111, 112], $p->lockedQueries[0]);
    }
}
