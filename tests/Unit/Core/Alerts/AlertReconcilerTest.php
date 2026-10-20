<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Alerts;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Core\Alerts\AlertReconciler;
use Shipard\Core\Alerts\AlertRunResult;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleDefinition;

// ── Test fixtures ────────────────────────────────────────────────────
// Statické sloty pro fixture třídy. Reconciler třídy instantiate-uje
// `new $fqcn(...)`, takže potřebujeme reálné classes (anonymní nestačí —
// class_exists by selhal).

class ReconcilerTestEmptyCheck extends AlertCheck
{
    public function run(): array
    {
        return [];
    }
}

class ReconcilerTestSingleFindingCheck extends AlertCheck
{
    public function run(): array
    {
        return [
            new AlertFinding(
                findingKey: '',
                title: 'Own Person is missing',
                message: 'Set up your own legal entity',
                severity: 'warning',
            ),
        ];
    }
}

class ReconcilerTestThrowingCheck extends AlertCheck
{
    public function run(): array
    {
        throw new \RuntimeException('DB exploded');
    }
}

/** Titulek = země ze čtvrtého parametru (#121 task B) — bez konfigurace zdroje dat „none“. */
class ReconcilerTestCountryCheck extends AlertCheck
{
    public function run(): array
    {
        return [new AlertFinding(findingKey: '', title: 'country:' . ($this->dsConfig?->getCountry() ?? 'none'))];
    }
}

// ── Tests ─────────────────────────────────────────────────────────────

class AlertReconcilerTest extends TestCase
{
    private function makeRegistry(string $class): AlertCheckRegistry
    {
        $module = ModuleDefinition::fromArray([
            'id'   => 'test.alerts',
            'name' => 'Test',
            'alertChecks' => [[
                'id'       => 'test.alerts.fixture',
                'name'     => 'Fixture',
                'class'    => $class,
                'interval' => '1h',
            ]],
        ]);
        return new AlertCheckRegistry([$module], 'en');
    }

    /** A reconciler-ready mock DB that tracks insert/update/execute calls. */
    private function mockDb(array $fetchRowReturns, array $fetchAllReturns): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);

        // fetchRow called twice per run (before+after INSERT IGNORE when lazy
        // insert), or once when state row exists. Configure via consecutive.
        $rowSeq = $fetchRowReturns;
        $db->method('fetchRow')->willReturnCallback(function () use (&$rowSeq) {
            if ($rowSeq === []) {
                return null;
            }
            return array_shift($rowSeq);
        });

        $allSeq = $fetchAllReturns;
        $db->method('fetchAll')->willReturnCallback(function () use (&$allSeq) {
            if ($allSeq === []) {
                return [];
            }
            return array_shift($allSeq);
        });

        return $db;
    }

    public function testNewFindingInserts(): void
    {
        $registry = $this->makeRegistry(ReconcilerTestSingleFindingCheck::class);
        $config   = $this->createMock(ConfigRuntime::class);

        $existingStateRow = [
            'check_id'      => 'test.alerts.fixture',
            'enabled'       => 1,
            'is_running'    => 0,
            'running_since' => null,
            'next_run_at'   => null,
        ];
        $db = $this->mockDb([$existingStateRow], [/* alerts */ []]);

        // Očekáváme: 1) lock update, 2) insert alert, 3) finish update.
        $db->expects($this->exactly(2))->method('updateWhere');
        $db->expects($this->once())->method('insertRow')->with(
            AlertReconciler::ALERTS_TABLE,
            $this->callback(function (array $data): bool {
                return $data['check_id'] === 'test.alerts.fixture'
                    && $data['finding_key'] === ''
                    && $data['title'] === 'Own Person is missing'
                    && $data['severity'] === 20         // warning → 20
                    && $data['alert_state'] === AlertReconciler::STATE_ACTIVE
                    && $data['seen_count'] === 1;
            }),
        );
        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');

        $reconciler = new AlertReconciler($db, $registry, $config, 'en');
        $result = $reconciler->runCheck('test.alerts.fixture');

        $this->assertSame(AlertRunResult::STATUS_FOUND, $result->status);
        $this->assertSame(1, $result->findingsCount);
        $this->assertSame(1, $result->newCount);
        $this->assertSame(0, $result->updatedCount);
        $this->assertSame(0, $result->resolvedCount);
    }

    public function testCheckReceivesDataSourceConfig(): void
    {
        $registry = $this->makeRegistry(ReconcilerTestCountryCheck::class);
        $config   = $this->createMock(ConfigRuntime::class);
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $dsConfig->method('getCountry')->willReturn('sk');
        $stateRow = ['check_id' => 'test.alerts.fixture', 'enabled' => 1, 'is_running' => 0, 'running_since' => null, 'next_run_at' => null];

        $db = $this->mockDb([$stateRow], [[]]);
        $db->expects($this->once())->method('insertRow')->with(
            AlertReconciler::ALERTS_TABLE,
            $this->callback(static fn(array $data): bool => $data['title'] === 'country:sk'),
        );
        (new AlertReconciler($db, $registry, $config, 'en', $dsConfig))->runCheck('test.alerts.fixture');

        $db = $this->mockDb([$stateRow], [[]]);
        $db->expects($this->once())->method('insertRow')->with(
            AlertReconciler::ALERTS_TABLE,
            $this->callback(static fn(array $data): bool => $data['title'] === 'country:none'),
        );
        (new AlertReconciler($db, $registry, $config, 'en'))->runCheck('test.alerts.fixture');
    }

    public function testEmptyFindingsResolvesExisting(): void
    {
        $registry = $this->makeRegistry(ReconcilerTestEmptyCheck::class);
        $config   = $this->createMock(ConfigRuntime::class);

        $existingStateRow = [
            'check_id' => 'test.alerts.fixture', 'enabled' => 1,
            'is_running' => 0, 'running_since' => null, 'next_run_at' => null,
        ];
        $existingAlert = [
            'id' => 555, 'finding_key' => '',
            'alert_state' => AlertReconciler::STATE_ACTIVE,
            'snoozed_until' => null, 'seen_count' => 3,
        ];

        $db = $this->mockDb([$existingStateRow], [[$existingAlert]]);

        // Capture updateWhere calls to verify resolve happened.
        $updates = [];
        $db->method('updateWhere')->willReturnCallback(
            function (string $table, array $data, string $where, ...$params) use (&$updates) {
                $updates[] = compact('table', 'data', 'where', 'params');
            },
        );

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('insertRow');

        $reconciler = new AlertReconciler($db, $registry, $config, 'en');
        $result = $reconciler->runCheck('test.alerts.fixture');

        $this->assertSame(AlertRunResult::STATUS_OK, $result->status);
        $this->assertSame(0, $result->findingsCount);
        $this->assertSame(1, $result->resolvedCount);

        // Ověř, že nějaký updateWhere resolvoval alert id=555 na state=70.
        $resolveUpdate = null;
        foreach ($updates as $u) {
            if ($u['table'] === AlertReconciler::ALERTS_TABLE
                && ($u['data']['alert_state'] ?? null) === AlertReconciler::STATE_RESOLVED) {
                $resolveUpdate = $u;
                break;
            }
        }
        $this->assertNotNull($resolveUpdate, 'expected an updateWhere resolving alert id=555');
        $this->assertSame(555, $resolveUpdate['params'][1]);
        $this->assertNotNull($resolveUpdate['data']['resolved_at']);
    }

    public function testSnoozedExpiredReactivates(): void
    {
        $registry = $this->makeRegistry(ReconcilerTestSingleFindingCheck::class);
        $config   = $this->createMock(ConfigRuntime::class);

        $existingStateRow = [
            'check_id' => 'test.alerts.fixture', 'enabled' => 1,
            'is_running' => 0, 'running_since' => null, 'next_run_at' => null,
        ];
        $snoozedAlert = [
            'id' => 777, 'finding_key' => '',
            'alert_state' => AlertReconciler::STATE_SNOOZED,
            'snoozed_until' => '2026-05-18 09:00:00',   // v minulosti
            'seen_count' => 5,
        ];

        $db = $this->mockDb([$existingStateRow], [[$snoozedAlert]]);

        $updates = [];
        $db->method('updateWhere')->willReturnCallback(
            function (string $table, array $data, string $where, ...$params) use (&$updates) {
                $updates[] = compact('table', 'data', 'where', 'params');
            },
        );

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('insertRow');

        $reconciler = new AlertReconciler($db, $registry, $config, 'en');
        $now = new \DateTimeImmutable('2026-05-18 10:00:00');
        $result = $reconciler->runCheck('test.alerts.fixture', $now);

        $this->assertSame(AlertRunResult::STATUS_FOUND, $result->status);
        $this->assertSame(0, $result->newCount);
        $this->assertSame(1, $result->updatedCount);
        $this->assertSame(0, $result->resolvedCount);

        // Ověř, že update na alert 777 přepnul state na Active a vynuloval snoozed_until.
        $reactivate = null;
        foreach ($updates as $u) {
            if ($u['table'] === AlertReconciler::ALERTS_TABLE
                && ($u['params'][1] ?? null) === 777) {
                $reactivate = $u;
                break;
            }
        }
        $this->assertNotNull($reactivate, 'expected updateWhere on alert id=777');
        $this->assertSame(AlertReconciler::STATE_ACTIVE, $reactivate['data']['alert_state']);
        $this->assertNull($reactivate['data']['snoozed_until']);
        $this->assertSame(6, $reactivate['data']['seen_count']);
    }

    public function testCheckThrowsDoesNotResolveAlerts(): void
    {
        $registry = $this->makeRegistry(ReconcilerTestThrowingCheck::class);
        $config   = $this->createMock(ConfigRuntime::class);

        $existingStateRow = [
            'check_id' => 'test.alerts.fixture', 'enabled' => 1,
            'is_running' => 0, 'running_since' => null, 'next_run_at' => null,
        ];

        $db = $this->mockDb([$existingStateRow], []);

        // Kritické: žádné begin/commit/rollback (chyba nastane PŘED tím, než se
        // fetchAll(alerts) zavolá — uvnitř instantiateCheck.run()).
        $db->expects($this->never())->method('begin');
        $db->expects($this->never())->method('commit');
        $db->expects($this->never())->method('rollback');
        $db->expects($this->never())->method('insertRow');

        $updates = [];
        $db->method('updateWhere')->willReturnCallback(
            function (string $table, array $data, string $where, ...$params) use (&$updates) {
                $updates[] = compact('table', 'data', 'where', 'params');
            },
        );

        $reconciler = new AlertReconciler($db, $registry, $config, 'en');
        $result = $reconciler->runCheck('test.alerts.fixture');

        $this->assertSame(AlertRunResult::STATUS_ERROR, $result->status);
        $this->assertSame('DB exploded', $result->errorMessage);

        // Update calls: lock + finishRun (s status=error). Žádné alert updates.
        foreach ($updates as $u) {
            $this->assertSame(
                AlertReconciler::CHECK_STATES_TABLE,
                $u['table'],
                'Reconciler must not touch alerts table when check throws',
            );
        }

        // Last update musel mít status=error + error message.
        $last = end($updates);
        $this->assertSame('error', $last['data']['last_run_status']);
        $this->assertSame('DB exploded', $last['data']['last_run_error']);
        $this->assertSame(0, $last['data']['is_running']);
    }

    public function testUnknownCheckIdReturnsError(): void
    {
        $registry = new AlertCheckRegistry([], 'en');
        $config   = $this->createMock(ConfigRuntime::class);
        $db       = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('insertRow');
        $db->expects($this->never())->method('updateWhere');

        $reconciler = new AlertReconciler($db, $registry, $config, 'en');
        $result = $reconciler->runCheck('does.not.exist');

        $this->assertSame(AlertRunResult::STATUS_ERROR, $result->status);
        $this->assertStringContainsString('not registered', $result->errorMessage ?? '');
    }

    public function testGetDueCheckIdsHandlesIsoDatetimeFromDb(): void
    {
        // DataSourceConnection normalizuje datetime sloupce na ISO 8601 s 'T'
        // separátorem. Reconciler musí srovnávat přes timestamp, ne stringově —
        // jinak by ' ' (NOW string) vs 'T' (DB hodnota) selhalo na ASCII pořadí.
        $registry = $this->makeRegistry(ReconcilerTestSingleFindingCheck::class);
        $config   = $this->createMock(ConfigRuntime::class);

        $now      = new \DateTimeImmutable('2026-05-18 10:48:33');
        $pastIso  = '2026-05-18T10:00:00';     // v minulosti, jak by to vrátila DB
        $futureIso = '2026-05-18T11:30:00';    // v budoucnu

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([
            [
                'check_id'    => 'test.alerts.fixture',
                'enabled'     => 1,
                'next_run_at' => $pastIso,
            ],
        ]);

        $reconciler = new AlertReconciler($db, $registry, $config, 'en');
        $due = $reconciler->getDueCheckIds($now);

        $this->assertSame(['test.alerts.fixture'], $due, 'Past ISO datetime must register as due');

        // Sanity: budoucí čas neregistrovat jako due
        $db2 = $this->createMock(DataSourceConnection::class);
        $db2->method('fetchAll')->willReturn([
            [
                'check_id'    => 'test.alerts.fixture',
                'enabled'     => 1,
                'next_run_at' => $futureIso,
            ],
        ]);
        $reconciler2 = new AlertReconciler($db2, $registry, $config, 'en');
        $this->assertSame([], $reconciler2->getDueCheckIds($now), 'Future ISO datetime must NOT register as due');
    }

    public function testFreshLockSkipsRun(): void
    {
        $registry = $this->makeRegistry(ReconcilerTestSingleFindingCheck::class);
        $config   = $this->createMock(ConfigRuntime::class);

        $now = new \DateTimeImmutable('2026-05-18 10:00:00');
        $existingStateRow = [
            'check_id' => 'test.alerts.fixture', 'enabled' => 1,
            'is_running' => 1,
            'running_since' => $now->modify('-30 seconds')->format('Y-m-d H:i:s'),
            'next_run_at' => null,
        ];

        $db = $this->mockDb([$existingStateRow], []);
        $db->expects($this->never())->method('updateWhere');
        $db->expects($this->never())->method('insertRow');

        $reconciler = new AlertReconciler($db, $registry, $config, 'en');
        $result = $reconciler->runCheck('test.alerts.fixture', $now);

        $this->assertSame(AlertRunResult::STATUS_SKIPPED, $result->status);
        $this->assertStringContainsString('lock', $result->skippedReason ?? '');
    }
}
