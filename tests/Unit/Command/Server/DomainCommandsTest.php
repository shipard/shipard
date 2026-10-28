<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\DomainAddCommand;
use Shipard\Command\Server\DomainListCommand;
use Shipard\Command\Server\DomainRemoveCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class DomainCommandsTest extends TestCase
{
	private string $tempDir;
	private string $domainsFile;
	private string $dsDir;

	protected function setUp(): void
	{
		$this->tempDir     = sys_get_temp_dir() . '/shpd_domain_test_' . uniqid();
		$this->domainsFile = $this->tempDir . '/domains.json';
		$this->dsDir       = $this->tempDir . '/data-sources';

		mkdir($this->tempDir, 0755, true);
	}

	protected function tearDown(): void
	{
		$this->rmdirRecursive($this->tempDir);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function createDs(string $dsId, string $name): void
	{
		$dir = $this->dsDir . '/' . $dsId . '/config';
		mkdir($dir, 0755, true);
		file_put_contents($dir . '/main.json', json_encode([
			'id'            => $dsId,
			'name'          => $name,
			'database_name' => 'db',
			'database_user' => 'u',
			'created'       => date('c'),
		]));
	}

	private function addTester(): CommandTester
	{
		$cmd = new DomainAddCommand($this->domainsFile, $this->dsDir);
		$app = new Application();
		$app->add($cmd);
		return new CommandTester($cmd);
	}

	private function listTester(): CommandTester
	{
		$cmd = new DomainListCommand($this->domainsFile, $this->dsDir);
		$app = new Application();
		$app->add($cmd);
		return new CommandTester($cmd);
	}

	private function removeTester(): CommandTester
	{
		$cmd = new DomainRemoveCommand($this->domainsFile);
		$app = new Application();
		$app->add($cmd);
		return new CommandTester($cmd);
	}

	private function loadDomains(): array
	{
		return json_decode(file_get_contents($this->domainsFile), true);
	}

	// ── domain-add ────────────────────────────────────────────────────────────

	public function testAddRequiresHost(): void
	{
		$this->createDs('a3f2-b8c1-d4e7-f9a0', 'Test');
		$tester = $this->addTester();

		$code = $tester->execute(['--ds' => 'a3f2-b8c1-d4e7-f9a0']);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('--host is required', $tester->getDisplay());
	}

	public function testAddRequiresDs(): void
	{
		$tester = $this->addTester();

		$code = $tester->execute(['--host' => 'firma1.shipard.cz']);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('--ds is required', $tester->getDisplay());
	}

	public function testAddSucceeds(): void
	{
		$this->createDs('a3f2-b8c1-d4e7-f9a0', 'Firma 1');
		$tester = $this->addTester();

		$code = $tester->execute(['--host' => 'firma1.shipard.cz', '--ds' => 'a3f2-b8c1-d4e7-f9a0']);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('firma1.shipard.cz', $tester->getDisplay());
	}

	public function testAddWritesDomainsFile(): void
	{
		$this->createDs('a3f2-b8c1-d4e7-f9a0', 'Firma 1');
		$tester = $this->addTester();
		$tester->execute(['--host' => 'firma1.shipard.cz', '--ds' => 'a3f2-b8c1-d4e7-f9a0']);

		$map = $this->loadDomains();
		$this->assertSame('a3f2-b8c1-d4e7-f9a0', $map['firma1.shipard.cz']);
	}

	public function testAddCreatesDomainsFileIfMissing(): void
	{
		$this->createDs('a3f2-b8c1-d4e7-f9a0', 'Firma 1');
		$this->assertFileDoesNotExist($this->domainsFile);

		$tester = $this->addTester();
		$tester->execute(['--host' => 'firma1.shipard.cz', '--ds' => 'a3f2-b8c1-d4e7-f9a0']);

		$this->assertFileExists($this->domainsFile);
	}

	public function testAddMultipleHosts(): void
	{
		$this->createDs('aaaa-bbbb-cccc-dddd', 'DS A');
		$this->createDs('eeee-ffff-0000-1111', 'DS B');
		$tester = $this->addTester();

		$tester->execute(['--host' => 'a.shipard.cz', '--ds' => 'aaaa-bbbb-cccc-dddd']);
		$tester->execute(['--host' => 'b.shipard.cz', '--ds' => 'eeee-ffff-0000-1111']);

		$map = $this->loadDomains();
		$this->assertCount(2, $map);
		$this->assertSame('aaaa-bbbb-cccc-dddd', $map['a.shipard.cz']);
		$this->assertSame('eeee-ffff-0000-1111', $map['b.shipard.cz']);
	}

	public function testAddSameHostSameDsIsIdempotentNoOp(): void
	{
		// D3: agent hosting-sync smí domain-add bezpečně opakovat.
		$this->createDs('a3f2-b8c1-d4e7-f9a0', 'Firma 1');
		$tester = $this->addTester();
		$tester->execute(['--host' => 'firma1.shipard.cz', '--ds' => 'a3f2-b8c1-d4e7-f9a0']);

		$code = $tester->execute(['--host' => 'firma1.shipard.cz', '--ds' => 'a3f2-b8c1-d4e7-f9a0']);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('Already mapped', $tester->getDisplay());
		$this->assertCount(1, $this->loadDomains());
	}

	public function testAddDuplicateHostDifferentDsFails(): void
	{
		$this->createDs('a3f2-b8c1-d4e7-f9a0', 'Firma 1');
		$this->createDs('eeee-ffff-0000-1111', 'Firma 2');
		$tester = $this->addTester();
		$tester->execute(['--host' => 'firma1.shipard.cz', '--ds' => 'a3f2-b8c1-d4e7-f9a0']);

		$code = $tester->execute(['--host' => 'firma1.shipard.cz', '--ds' => 'eeee-ffff-0000-1111']);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('already mapped', $tester->getDisplay());
		$this->assertSame('a3f2-b8c1-d4e7-f9a0', $this->loadDomains()['firma1.shipard.cz']);
	}

	public function testAddNonexistentDsFails(): void
	{
		$tester = $this->addTester();

		$code = $tester->execute(['--host' => 'firma1.shipard.cz', '--ds' => 'xxxx-xxxx-xxxx-xxxx']);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('does not exist', $tester->getDisplay());
	}

	public function testAddUnwritableDomainsDirFails(): void
	{
		// Nález č. 3 z adopce: dřív tichý SUCCESS „Added" i když zápis
		// selhal a doména se nezapsala (agent z cronu do root-owned dir).
		if (posix_geteuid() === 0) {
			$this->markTestSkipped('root writes anywhere');
		}
		$this->createDs('a3f2-b8c1-d4e7-f9a0', 'Firma 1');
		chmod($this->tempDir, 0500);

		$tester = $this->addTester();
		$code = $tester->execute(['--host' => 'firma1.shipard.cz', '--ds' => 'a3f2-b8c1-d4e7-f9a0']);
		chmod($this->tempDir, 0755);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('Failed to write', $tester->getDisplay());
		$this->assertStringNotContainsString('Added', $tester->getDisplay());
		$this->assertFileDoesNotExist($this->domainsFile);
	}

	public function testRemoveUnwritableDomainsDirFails(): void
	{
		if (posix_geteuid() === 0) {
			$this->markTestSkipped('root writes anywhere');
		}
		file_put_contents($this->domainsFile, json_encode(['firma1.shipard.cz' => 'a3f2-b8c1-d4e7-f9a0']));
		chmod($this->tempDir, 0500);

		$tester = $this->removeTester();
		$code = $tester->execute(['--host' => 'firma1.shipard.cz']);
		chmod($this->tempDir, 0755);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('Failed to write', $tester->getDisplay());
		// Mapa zůstala nedotčená.
		$this->assertSame(['firma1.shipard.cz' => 'a3f2-b8c1-d4e7-f9a0'], $this->loadDomains());
	}

	// ── domain-list ───────────────────────────────────────────────────────────

	public function testListShowsNoFileMessage(): void
	{
		$tester = $this->listTester();

		$code = $tester->execute([]);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('No domains file found', $tester->getDisplay());
	}

	public function testListShowsEmptyMessage(): void
	{
		file_put_contents($this->domainsFile, '{}');
		$tester = $this->listTester();

		$code = $tester->execute([]);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('No domain mappings', $tester->getDisplay());
	}

	public function testListShowsTableWithMappings(): void
	{
		$this->createDs('a3f2-b8c1-d4e7-f9a0', 'Firma 1');
		$tester = $this->addTester();
		$tester->execute(['--host' => 'firma1.shipard.cz', '--ds' => 'a3f2-b8c1-d4e7-f9a0']);

		$tester = $this->listTester();
		$code   = $tester->execute([]);

		$this->assertSame(0, $code);
		$display = $tester->getDisplay();
		$this->assertStringContainsString('firma1.shipard.cz', $display);
		$this->assertStringContainsString('a3f2-b8c1-d4e7-f9a0', $display);
		$this->assertStringContainsString('Firma 1', $display);
	}

	public function testListShowsUnknownForMissingDs(): void
	{
		file_put_contents($this->domainsFile, json_encode(['orphan.shipard.cz' => 'dead-beef-0000-0000']));
		$tester = $this->listTester();

		$tester->execute([]);

		$this->assertStringContainsString('<unknown>', $tester->getDisplay());
	}

	// ── domain-remove ─────────────────────────────────────────────────────────

	public function testRemoveRequiresHost(): void
	{
		$tester = $this->removeTester();

		$code = $tester->execute([]);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('--host is required', $tester->getDisplay());
	}

	public function testRemoveSucceeds(): void
	{
		$this->createDs('a3f2-b8c1-d4e7-f9a0', 'Firma 1');
		$addTester = $this->addTester();
		$addTester->execute(['--host' => 'firma1.shipard.cz', '--ds' => 'a3f2-b8c1-d4e7-f9a0']);

		$tester = $this->removeTester();
		$code   = $tester->execute(['--host' => 'firma1.shipard.cz']);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('Removed', $tester->getDisplay());
		$this->assertStringContainsString('firma1.shipard.cz', $tester->getDisplay());
	}

	public function testRemoveDeletesEntryFromFile(): void
	{
		$this->createDs('a3f2-b8c1-d4e7-f9a0', 'Firma 1');
		$this->createDs('eeee-ffff-0000-1111', 'Firma 2');
		$addTester = $this->addTester();
		$addTester->execute(['--host' => 'firma1.shipard.cz', '--ds' => 'a3f2-b8c1-d4e7-f9a0']);
		$addTester->execute(['--host' => 'firma2.shipard.cz', '--ds' => 'eeee-ffff-0000-1111']);

		$removeTester = $this->removeTester();
		$removeTester->execute(['--host' => 'firma1.shipard.cz']);

		$map = $this->loadDomains();
		$this->assertArrayNotHasKey('firma1.shipard.cz', $map);
		$this->assertArrayHasKey('firma2.shipard.cz', $map);
	}

	public function testRemoveNonexistentHostFails(): void
	{
		$tester = $this->removeTester();

		$code = $tester->execute(['--host' => 'missing.shipard.cz']);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('not found', $tester->getDisplay());
	}

	public function testRemoveFromMissingFileFails(): void
	{
		$this->assertFileDoesNotExist($this->domainsFile);
		$tester = $this->removeTester();

		$code = $tester->execute(['--host' => 'firma1.shipard.cz']);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('not found', $tester->getDisplay());
	}

	// ── Cleanup ───────────────────────────────────────────────────────────────

	private function rmdirRecursive(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		foreach (scandir($dir) as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
		}
		rmdir($dir);
	}
}
