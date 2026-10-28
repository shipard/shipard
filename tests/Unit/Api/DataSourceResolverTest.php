<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Shipard\Api\DataSourceResolver;
use Shipard\Api\Exception\UnknownDataSourceException;
use Shipard\Api\Exception\DataSourceUnavailableException;
use Shipard\Api\Exception\UnknownHostException;
use Shipard\Api\ResolvedDataSource;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Subclass that skips the real DB connection for unit tests.
 */
class TestableDataSourceResolver extends DataSourceResolver
{
	public int $connectionCalls = 0;

	protected function createConnection(DataSourceConfig $config): DataSourceConnection
	{
		$this->connectionCalls++;
		// Use reflection to bypass the real Dibi connection
		$mock = $this->createMockConnection($config);
		return $mock;
	}

	private function createMockConnection(DataSourceConfig $config): DataSourceConnection
	{
		// Build a stub without calling the constructor (which needs a real DB)
		$ref = new \ReflectionClass(DataSourceConnection::class);
		/** @var DataSourceConnection $stub */
		$stub = $ref->newInstanceWithoutConstructor();
		return $stub;
	}
}

class DataSourceResolverTest extends TestCase
{
	private string $tempDir;

	protected function setUp(): void
	{
		$this->tempDir = sys_get_temp_dir() . '/shpd_resolver_test_' . uniqid();
		mkdir($this->tempDir, 0755, true);
	}

	protected function tearDown(): void
	{
		$this->removeDir($this->tempDir);
	}

	private function removeDir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		foreach (glob($dir . '/*') as $entry) {
			is_dir($entry) ? $this->removeDir($entry) : unlink($entry);
		}
		rmdir($dir);
	}

	private function writeDomains(array $map): string
	{
		$path = $this->tempDir . '/domains.json';
		file_put_contents($path, json_encode($map));
		return $path;
	}

	private function writeDsConfig(string $dsId, array $data = []): void
	{
		$dsDir = $this->tempDir . '/data-sources/' . $dsId . '/config';
		mkdir($dsDir, 0755, true);
		$defaults = [
			'id'            => $dsId,
			'name'          => 'Test DS',
			'database_name' => str_replace('-', '_', $dsId),
			'database_user' => 'shpd_test',
			'created'       => '2026-03-16T10:00:00+01:00',
		];
		file_put_contents($dsDir . '/main.json', json_encode(array_merge($defaults, $data)));
	}

	private function makeResolver(string $domainsFile): TestableDataSourceResolver
	{
		return new TestableDataSourceResolver(
			$domainsFile,
			$this->tempDir . '/data-sources',
		);
	}

	private function makeResolverNoDomains(): TestableDataSourceResolver
	{
		return new TestableDataSourceResolver(
			$this->tempDir . '/nonexistent-domains.json',
			$this->tempDir . '/data-sources',
		);
	}

	// ── Production mode (existing tests) ────────────────────────────────────

	public function testResolveKnownHost(): void
	{
		$dsId = 'a3f2-b8c1-d4e7-f9a0';
		$domainsFile = $this->writeDomains(['demo.shipard.cz' => $dsId]);
		$this->writeDsConfig($dsId);

		$resolver = $this->makeResolver($domainsFile);
		$result = $resolver->resolve('demo.shipard.cz', '/api/v1/users');

		$this->assertInstanceOf(ResolvedDataSource::class, $result);
		$this->assertSame($dsId, $result->config->getId());
	}

	public function testResolveUnknownHostThrows(): void
	{
		$domainsFile = $this->writeDomains(['demo.shipard.cz' => 'a3f2-b8c1-d4e7-f9a0']);

		$resolver = $this->makeResolver($domainsFile);

		$this->expectException(UnknownHostException::class);
		$resolver->resolve('unknown.shipard.cz', '/api/v1/users');
	}

	public function testResolveMultipleEntries(): void
	{
		$map = [
			'firma1.shipard.cz' => 'aaaa-bbbb-cccc-dddd',
			'firma2.shipard.cz' => 'eeee-ffff-0000-1111',
			'demo.shipard.cz'   => 'a3f2-b8c1-d4e7-f9a0',
		];
		$domainsFile = $this->writeDomains($map);
		foreach ($map as $host => $dsId) {
			$this->writeDsConfig($dsId);
		}

		$resolver = $this->makeResolver($domainsFile);

		foreach ($map as $host => $dsId) {
			$result = $resolver->resolve($host, '/api/v1/users');
			$this->assertSame($dsId, $result->config->getId(), "Failed for host: {$host}");
		}
	}

	public function testMissingDomainsFileYieldsUnknownHost(): void
	{
		// Missing domains.json = no mappings yet → clean UnknownHostException
		// (404 Unknown host), not a RuntimeException (500).
		$resolver = $this->makeResolver($this->tempDir . '/nonexistent.json');

		$this->expectException(UnknownHostException::class);

		$resolver->resolve('demo.shipard.cz', '/api/v1/users');
	}

	public function testInvalidJsonInDomainsFileThrows(): void
	{
		$path = $this->tempDir . '/domains.json';
		file_put_contents($path, '{invalid json}');

		$resolver = $this->makeResolver($path);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/invalid json/i');

		$resolver->resolve('demo.shipard.cz', '/api/v1/users');
	}

	// ── Mode detection ───────────────────────────────────────────────────────

	public function testDomainHostUsesProductionMode(): void
	{
		$dsId = 'a3f2-b8c1-d4e7-f9a0';
		$domainsFile = $this->writeDomains(['demo.shipard.cz' => $dsId]);
		$this->writeDsConfig($dsId);

		$resolver = $this->makeResolver($domainsFile);
		$result = $resolver->resolve('demo.shipard.cz', '/api/v1/users');

		$this->assertFalse($result->isDevMode());
		$this->assertSame('/api/v1/users', $result->normalizedPath);
	}

	public function testIpHostUsesDevMode(): void
	{
		$dsId = 'abcd-efgh-ijkl-mnop';
		$this->writeDsConfig($dsId);

		$resolver = $this->makeResolverNoDomains();
		$result = $resolver->resolve('192.168.1.1', '/abcd-efgh-ijkl-mnop/api/v1/users');

		$this->assertTrue($result->isDevMode());
	}

	public function testLocalhostIpUsesDevMode(): void
	{
		$dsId = 'abcd-efgh-ijkl-mnop';
		$this->writeDsConfig($dsId);

		$resolver = $this->makeResolverNoDomains();
		$result = $resolver->resolve('127.0.0.1', '/abcd-efgh-ijkl-mnop/api/v1/users');

		$this->assertTrue($result->isDevMode());
	}

	// ── Dev mode ─────────────────────────────────────────────────────────────

	public function testDevModeResolvesValidDsId(): void
	{
		$dsId = 'abcd-efgh-ijkl-mnop';
		$this->writeDsConfig($dsId);

		$resolver = $this->makeResolverNoDomains();
		$result = $resolver->resolve('10.12.100.1', '/abcd-efgh-ijkl-mnop/api/v1/users');

		$this->assertSame($dsId, $result->config->getId());
		$this->assertSame('/api/v1/users', $result->normalizedPath);
	}

	public function testDevModeNormalizesPath(): void
	{
		$dsId = 'abcd-efgh-ijkl-mnop';
		$this->writeDsConfig($dsId);

		$resolver = $this->makeResolverNoDomains();
		// Query string is not part of the path; the path passed in does not include it
		$result = $resolver->resolve('10.12.100.1', '/abcd-efgh-ijkl-mnop/api/v1/users');

		$this->assertSame('/api/v1/users', $result->normalizedPath);
	}

	public function testDevModeRootPath(): void
	{
		$dsId = 'abcd-efgh-ijkl-mnop';
		$this->writeDsConfig($dsId);

		$resolver = $this->makeResolverNoDomains();
		$result = $resolver->resolve('10.12.100.1', '/abcd-efgh-ijkl-mnop/');

		$this->assertSame('/', $result->normalizedPath);
	}

	public function testDevModeInvalidDsIdFormat(): void
	{
		$resolver = $this->makeResolverNoDomains();

		$this->expectException(UnknownDataSourceException::class);
		$resolver->resolve('10.12.100.1', '/not-a-valid-id/api/v1/users');
	}

	public function testDevModeNonexistentDsId(): void
	{
		// DS config file does NOT exist on disk
		$resolver = $this->makeResolverNoDomains();

		$this->expectException(UnknownDataSourceException::class);
		$resolver->resolve('10.12.100.1', '/abcd-efgh-ijkl-mnop/api/v1/users');
	}

	public function testDevModeMissingDsId(): void
	{
		// Path starts with 'api' which does not match DS ID format
		$resolver = $this->makeResolverNoDomains();

		$this->expectException(UnknownDataSourceException::class);
		$resolver->resolve('10.12.100.1', '/api/v1/users');
	}

	public function testDevModeDoesNotRequireDomainsFile(): void
	{
		$dsId = 'abcd-efgh-ijkl-mnop';
		$this->writeDsConfig($dsId);

		// domains.json does not exist — should still succeed in dev mode
		$resolver = $this->makeResolverNoDomains();
		$result = $resolver->resolve('10.12.100.1', '/abcd-efgh-ijkl-mnop/api/v1/users');

		$this->assertInstanceOf(ResolvedDataSource::class, $result);
		$this->assertSame($dsId, $result->config->getId());
	}

	// ── Stav DS (config/state.json) ──────────────────────────────────────────

	private function writeDsState(string $dsId, array $state): void
	{
		file_put_contents(
			$this->tempDir . '/data-sources/' . $dsId . '/config/state.json',
			json_encode(array_merge(['version' => 1], $state)),
		);
	}

	public function testSuspendedDataSourceThrowsBeforeConnecting(): void
	{
		$dsId = 'a3f2-b8c1-d4e7-f9a0';
		$domainsFile = $this->writeDomains(['demo.shipard.cz' => $dsId]);
		$this->writeDsConfig($dsId);
		$this->writeDsState($dsId, ['state' => 'suspended']);

		$resolver = $this->makeResolver($domainsFile);
		try {
			$resolver->resolve('demo.shipard.cz', '/api/v1/users');
			$this->fail('expected DataSourceUnavailableException');
		} catch (DataSourceUnavailableException $e) {
			$this->assertSame($dsId, $e->dsId);
			$this->assertSame('suspended', $e->effectiveState);
			$this->assertNull($e->maintenanceReason);
		}
		$this->assertSame(0, $resolver->connectionCalls, 'DB connection must not be attempted for a closed DS');
	}

	public function testMaintenanceOverActiveThrowsWithReason(): void
	{
		$dsId = 'a3f2-b8c1-d4e7-f9a0';
		$this->writeDsConfig($dsId);
		$this->writeDsState($dsId, [
			'state' => 'active',
			'maintenance' => ['reason' => 'import', 'since' => '2026-09-01T10:00:00Z'],
		]);

		$resolver = $this->makeResolverNoDomains();
		try {
			$resolver->resolve('127.0.0.1', '/' . $dsId . '/api/v1/users');
			$this->fail('expected DataSourceUnavailableException');
		} catch (DataSourceUnavailableException $e) {
			$this->assertSame('suspended', $e->effectiveState);
			$this->assertSame('import', $e->maintenanceReason);
		}
		$this->assertSame(0, $resolver->connectionCalls);
	}

	public function testPendingDeletionThrows(): void
	{
		$dsId = 'a3f2-b8c1-d4e7-f9a0';
		$domainsFile = $this->writeDomains(['demo.shipard.cz' => $dsId]);
		$this->writeDsConfig($dsId);
		$this->writeDsState($dsId, ['state' => 'pending_deletion', 'deleteAfter' => '2026-10-01T00:00:00Z']);

		$this->expectException(DataSourceUnavailableException::class);
		$this->makeResolver($domainsFile)->resolve('demo.shipard.cz', '/_meta');
	}

	public function testReadOnlyResolvesAndCarriesState(): void
	{
		$dsId = 'a3f2-b8c1-d4e7-f9a0';
		$domainsFile = $this->writeDomains(['demo.shipard.cz' => $dsId]);
		$this->writeDsConfig($dsId);
		$this->writeDsState($dsId, ['state' => 'read_only']);

		$resolver = $this->makeResolver($domainsFile);
		$result = $resolver->resolve('demo.shipard.cz', '/api/v1/users');
		$this->assertSame($dsId, $result->config->getId());
		$this->assertSame(1, $resolver->connectionCalls);
		// Stav se předává dál — pipeline ho nečte z disku podruhé.
		$this->assertSame('read_only', $result->state->getEffectiveState());
		$this->assertTrue($result->isReadOnly());
	}

	public function testMissingStateFileResolvesAsActive(): void
	{
		$dsId = 'a3f2-b8c1-d4e7-f9a0';
		$domainsFile = $this->writeDomains(['demo.shipard.cz' => $dsId]);
		$this->writeDsConfig($dsId);

		$result = $this->makeResolver($domainsFile)->resolve('demo.shipard.cz', '/api/v1/users');
		$this->assertSame('active', $result->state->getEffectiveState());
		$this->assertFalse($result->isReadOnly());
	}

	public function testDevModeCarriesState(): void
	{
		$dsId = 'a3f2-b8c1-d4e7-f9a0';
		$this->writeDsConfig($dsId);
		$this->writeDsState($dsId, ['state' => 'read_only']);

		$result = $this->makeResolver($this->writeDomains([]))->resolve('127.0.0.1', '/' . $dsId . '/api/v1/users');
		$this->assertTrue($result->isReadOnly());
	}

	public function testCorruptedStateFileFailsClosed(): void
	{
		$dsId = 'a3f2-b8c1-d4e7-f9a0';
		$domainsFile = $this->writeDomains(['demo.shipard.cz' => $dsId]);
		$this->writeDsConfig($dsId);
		file_put_contents($this->tempDir . '/data-sources/' . $dsId . '/config/state.json', '{broken');

		$resolver = $this->makeResolver($domainsFile);
		$this->expectException(DataSourceUnavailableException::class);
		$resolver->resolve('demo.shipard.cz', '/api/v1/users');
	}

	public function testMissingStateFilePassesUnchanged(): void
	{
		$dsId = 'a3f2-b8c1-d4e7-f9a0';
		$this->writeDsConfig($dsId);

		$resolver = $this->makeResolverNoDomains();
		$result = $resolver->resolve('127.0.0.1', '/' . $dsId . '/api/v1/users');
		$this->assertSame('/api/v1/users', $result->normalizedPath);
		$this->assertSame(1, $resolver->connectionCalls);
	}
}
