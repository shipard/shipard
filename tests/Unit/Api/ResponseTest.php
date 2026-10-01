<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Response;

class ResponseTest extends TestCase
{
	public function testSuccessPayload(): void
	{
		$resp = Response::success(['id' => 1, 'login' => 'admin']);
		$payload = $resp->getPayload();

		$this->assertTrue($payload['success']);
		$this->assertSame(['id' => 1, 'login' => 'admin'], $payload['data']);
		$this->assertArrayNotHasKey('meta', $payload);
	}

	public function testSuccessWithMeta(): void
	{
		$meta = ['total' => 42, 'limit' => 20, 'offset' => 0];
		$resp = Response::success([], 200, $meta);
		$payload = $resp->getPayload();

		$this->assertTrue($payload['success']);
		$this->assertSame($meta, $payload['meta']);
	}

	public function testSuccessWithoutMetaHasNoMetaKey(): void
	{
		$resp = Response::success(['id' => 5], 201, null);
		$this->assertArrayNotHasKey('meta', $resp->getPayload());
	}

	public function testErrorPayload(): void
	{
		$resp = Response::error('NOT_FOUND', 'Not found', 404);
		$payload = $resp->getPayload();

		$this->assertFalse($payload['success']);
		$this->assertSame('NOT_FOUND', $payload['error']['code']);
		$this->assertSame('Not found', $payload['error']['message']);
		$this->assertArrayNotHasKey('details', $payload['error']);
	}

	public function testErrorWithDetails(): void
	{
		$details = [
			['field' => 'email', 'code' => 'REQUIRED', 'message' => "Field 'email' is required"],
		];
		$resp = Response::error('VALIDATION_ERROR', 'Validation failed', 422, $details);
		$payload = $resp->getPayload();

		$this->assertFalse($payload['success']);
		$this->assertSame($details, $payload['error']['details']);
	}

	public function testErrorWithoutDetailsHasNoDetailsKey(): void
	{
		$resp = Response::error('UNAUTHORIZED', 'Unauthorized', 401);
		$this->assertArrayNotHasKey('details', $resp->getPayload()['error']);
	}

	public function testWithHeaderReturnsNewInstanceWithHeader(): void
	{
		$resp    = Response::success(['id' => 1]);
		$result  = $resp->withHeader('X-Custom', 'value');

		$this->assertSame('value', $result->getHeaders()['X-Custom']);
	}

	public function testWithHeaderDoesNotMutateOriginal(): void
	{
		$resp = Response::success(['id' => 1]);
		$resp->withHeader('X-Custom', 'value');

		$this->assertEmpty($resp->getHeaders());
	}

	public function testMultipleHeadersCanBeAdded(): void
	{
		$resp = Response::success(['id' => 1])
			->withHeader('X-Foo', 'foo')
			->withHeader('X-Bar', 'bar');

		$headers = $resp->getHeaders();
		$this->assertSame('foo', $headers['X-Foo']);
		$this->assertSame('bar', $headers['X-Bar']);
	}

	public function testGetHeadersEmptyByDefault(): void
	{
		$resp = Response::success(null);
		$this->assertSame([], $resp->getHeaders());
	}

	public function testBinaryCarriesBodyAndContentHeaders(): void
	{
		$response = Response::binary("\x00\x01abc", 'text/csv; charset=utf-8')
			->withHeader('Content-Disposition', 'attachment; filename="x.csv"');

		$this->assertSame("\x00\x01abc", $response->getPayload());
		$this->assertSame(
			[
				'Content-Type'        => 'text/csv; charset=utf-8',
				'Content-Length'      => '5',
				'Content-Disposition' => 'attachment; filename="x.csv"',
			],
			$response->getHeaders(),
		);
	}
}
