<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Server\DataSourceStateScanner;

class DataSourceStateScannerTest extends TestCase
{
	private string $root;
	private \DateTimeImmutable $now;

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/shpd_state_scan_' . uniqid();
		mkdir($this->root, 0755, true);
		$this->now = new \DateTimeImmutable('2026-09-10T12:00:00Z');
		// Fail-closed čtení loguje error — v testu bez log path (stderr se
		// nezapisuje), jen reset, aby nic neteklo do reálného logu.
		ErrorLogger::resetForTesting();
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->root));
		ErrorLogger::resetForTesting();
	}

	private function ds(string $id, ?string $stateJson = null, bool $withMain = true): void
	{
		mkdir($this->root . '/' . $id . '/config', 0755, true);
		if ($withMain) {
			file_put_contents($this->root . '/' . $id . '/config/main.json', '{}');
		}
		if ($stateJson !== null) {
			file_put_contents($this->root . '/' . $id . '/config/state.json', $stateJson);
		}
	}

	private function maintenance(string $sinceIso, string $reason = 'import'): string
	{
		return json_encode([
			'version' => 1,
			'state' => 'active',
			'maintenance' => ['reason' => $reason, 'since' => $sinceIso],
		]);
	}

	public function testMissingDirYieldsEmpty(): void
	{
		$this->assertSame([], (new DataSourceStateScanner($this->root . '/nope'))->scan($this->now));
	}

	public function testScanClassifiesEntries(): void
	{
		$this->ds('aaaa-aaaa-aaaa-aaaa');                                          // bez souboru → active
		$this->ds('bbbb-bbbb-bbbb-bbbb', $this->maintenance('2026-08-30T12:00:00Z')); // 11 dní
		$this->ds('cccc-cccc-cccc-cccc', $this->maintenance('2026-09-09T13:00:00Z')); // 0 dní
		$this->ds('dddd-dddd-dddd-dddd', '{broken');                                // fail-closed
		$this->ds('eeee-eeee-eeee-eeee', json_encode(['version' => 1, 'state' => 'read_only']));
		$this->ds('lost+found', null, withMain: false);                           // ignorováno

		$entries = (new DataSourceStateScanner($this->root))->scan($this->now);
		$byId = [];
		foreach ($entries as $e) {
			$byId[$e->dsId] = $e;
		}
		$this->assertSame(
			['aaaa-aaaa-aaaa-aaaa', 'bbbb-bbbb-bbbb-bbbb', 'cccc-cccc-cccc-cccc', 'dddd-dddd-dddd-dddd', 'eeee-eeee-eeee-eeee'],
			array_keys($byId),
		);

		$this->assertSame('active', $byId['aaaa-aaaa-aaaa-aaaa']->effectiveState());
		$this->assertNull($byId['aaaa-aaaa-aaaa-aaaa']->maintenanceDays);

		$this->assertSame('suspended', $byId['bbbb-bbbb-bbbb-bbbb']->effectiveState());
		$this->assertSame(11, $byId['bbbb-bbbb-bbbb-bbbb']->maintenanceDays);
		$this->assertTrue($byId['bbbb-bbbb-bbbb-bbbb']->isMaintenanceOverdue(7));

		$this->assertSame(0, $byId['cccc-cccc-cccc-cccc']->maintenanceDays);
		$this->assertFalse($byId['cccc-cccc-cccc-cccc']->isMaintenanceOverdue(7));

		$this->assertTrue($byId['dddd-dddd-dddd-dddd']->isCorrupted());
		$this->assertSame('suspended', $byId['dddd-dddd-dddd-dddd']->effectiveState());
		$this->assertFalse($byId['dddd-dddd-dddd-dddd']->isMaintenanceOverdue(7));

		$this->assertSame('read_only', $byId['eeee-eeee-eeee-eeee']->effectiveState());
	}

	public function testOverdueThresholdIsStrictlyGreater(): void
	{
		$this->ds('aaaa-aaaa-aaaa-aaaa', $this->maintenance('2026-09-03T12:00:00Z')); // přesně 7 dní
		$this->ds('bbbb-bbbb-bbbb-bbbb', $this->maintenance('2026-09-02T12:00:00Z')); // 8 dní

		$entries = (new DataSourceStateScanner($this->root))->scan($this->now);
		$overdue = DataSourceStateScanner::overdueMaintenance($entries);
		$this->assertSame(['bbbb-bbbb-bbbb-bbbb'], array_map(fn($e) => $e->dsId, $overdue));
	}

	public function testCountByState(): void
	{
		$this->ds('aaaa-aaaa-aaaa-aaaa');
		$this->ds('bbbb-bbbb-bbbb-bbbb', $this->maintenance('2026-09-01T00:00:00Z'));
		$this->ds('cccc-cccc-cccc-cccc', '{broken');
		$this->ds('dddd-dddd-dddd-dddd', json_encode(['version' => 1, 'state' => 'read_only']));
		$this->ds('eeee-eeee-eeee-eeee', json_encode(['version' => 1, 'state' => 'active', 'cron' => false]));

		$counts = DataSourceStateScanner::countByState((new DataSourceStateScanner($this->root))->scan($this->now));
		$this->assertSame(2, $counts['active']);
		$this->assertSame(1, $counts['read_only']);
		$this->assertSame(2, $counts['suspended']); // maintenance + corrupted
		$this->assertSame(0, $counts['pending_deletion']);
		$this->assertSame(1, $counts['maintenance']);
		$this->assertSame(1, $counts['corrupted']);
		$this->assertSame(1, $counts['cronOff']); // #115 — corrupted se nepočítá
	}

	public function testIsCronOff(): void
	{
		$this->ds('aaaa-aaaa-aaaa-aaaa');
		$this->ds('bbbb-bbbb-bbbb-bbbb', json_encode(['version' => 1, 'state' => 'read_only', 'cron' => false]));
		$this->ds('cccc-cccc-cccc-cccc', '{broken');

		$entries = (new DataSourceStateScanner($this->root))->scan($this->now);
		$byId = [];
		foreach ($entries as $e) {
			$byId[$e->dsId] = $e;
		}
		$this->assertFalse($byId['aaaa-aaaa-aaaa-aaaa']->isCronOff());
		$this->assertTrue($byId['bbbb-bbbb-bbbb-bbbb']->isCronOff());
		$this->assertSame('read_only', $byId['bbbb-bbbb-bbbb-bbbb']->effectiveState());
		$this->assertFalse($byId['cccc-cccc-cccc-cccc']->isCronOff());
	}
}
