<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Shipard\Api\SessionService;
use Shipard\Core\Database\DataSourceConnection;

class RecordingConnection extends DataSourceConnection
{
	public array $insertedRows = [];
	public array $executedQueries = [];

	public function insertRow(string $table, array $data): int
	{
		$this->insertedRows[] = ['table' => $table, 'data' => $data];
		return count($this->insertedRows);
	}

	public function execute(mixed ...$args): void
	{
		$this->executedQueries[] = $args;
	}
}

class SessionServiceTest extends TestCase
{
	private RecordingConnection $db;
	private SessionService $service;

	protected function setUp(): void
	{
		$ref = new \ReflectionClass(RecordingConnection::class);
		$this->db = $ref->newInstanceWithoutConstructor();
		$this->db->insertedRows = [];
		$this->db->executedQueries = [];
		$this->service = new SessionService();
	}

	public function testCreateSessionInsertsRowAndReturnsToken(): void
	{
		[$token, $expiresAt] = $this->service->createSession(7, $this->db, '192.168.1.10');

		$this->assertStringStartsWith('shpd_st_', $token);
		$this->assertSame(56, strlen($token));
		$this->assertNotFalse(strtotime($expiresAt));

		$this->assertCount(1, $this->db->insertedRows);
		$row = $this->db->insertedRows[0];
		$this->assertSame('core_system_sessions', $row['table']);
		$this->assertSame(7, $row['data']['user_id']);
		$this->assertSame($token, $row['data']['token']);
		$this->assertSame('192.168.1.10', $row['data']['ip_address']);
		$this->assertEqualsWithDelta(
			time() + SessionService::SESSION_TTL_SECONDS,
			strtotime($row['data']['expires']),
			5,
		);
	}

	public function testCreateSessionWithoutClientIpStoresNull(): void
	{
		$this->service->createSession(1, $this->db);

		$this->assertNull($this->db->insertedRows[0]['data']['ip_address']);
	}

	/** Proklikání datové sady dává session jen na dobu běhu (#103 F7a I7). */
	public function testCreateSessionHonoursACustomTtl(): void
	{
		[, $expiresAt] = $this->service->createSession(7, $this->db, null, 5400);

		$this->assertEqualsWithDelta(time() + 5400, strtotime($expiresAt), 5);
		$this->assertEqualsWithDelta(time() + 5400, strtotime($this->db->insertedRows[0]['data']['expires']), 5);

		[, $defaultExpiresAt] = $this->service->createSession(7, $this->db, null, null);
		$this->assertEqualsWithDelta(time() + SessionService::SESSION_TTL_SECONDS, strtotime($defaultExpiresAt), 5, 'null = výchozích 24 h');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->createSession(7, $this->db, null, 0);
	}

	public function testInvalidateSessionDeletesByToken(): void
	{
		$this->service->invalidateSession('shpd_st_sometoken', $this->db);

		$this->assertCount(1, $this->db->executedQueries);
		$this->assertStringContainsString('DELETE FROM core_system_sessions', $this->db->executedQueries[0][0]);
		$this->assertSame('shpd_st_sometoken', $this->db->executedQueries[0][1]);
	}

	public function testGenerateTokenProducesUrlSafeLowercase(): void
	{
		$token = $this->service->generateToken(48);

		$this->assertSame(48, strlen($token));
		$this->assertMatchesRegularExpression('/^[a-z0-9]+$/', $token);
		$this->assertNotSame($token, $this->service->generateToken(48));
	}
}
