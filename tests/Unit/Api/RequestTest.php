<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Request;

class RequestTest extends TestCase
{
	private function make(
		string $method = 'GET',
		string $uri = '/',
		array $queryParams = [],
		string $rawBody = '',
		array $server = [],
	): Request {
		return Request::fromArray($method, $uri, $queryParams, $rawBody, $server);
	}

	public function testGetMethodReturnsUppercase(): void
	{
		$req = $this->make(method: 'get');
		$this->assertSame('GET', $req->getMethod());
	}

	public function testGetMethodPostUppercase(): void
	{
		$req = $this->make(method: 'post');
		$this->assertSame('POST', $req->getMethod());
	}

	public function testGetPathStripsQueryString(): void
	{
		$req = $this->make(uri: '/api/v1/users?limit=10&offset=0');
		$this->assertSame('/api/v1/users', $req->getPath());
	}

	public function testGetPathWithNoQueryString(): void
	{
		$req = $this->make(uri: '/api/v1/users');
		$this->assertSame('/api/v1/users', $req->getPath());
	}

	public function testSchemeFollowsProxyHeaderTlsAndRequestScheme(): void
	{
		$this->assertSame('http', $this->make()->getScheme());
		$this->assertSame('https', $this->make(server: ['HTTPS' => 'on'])->getScheme());
		$this->assertSame('http', $this->make(server: ['HTTPS' => 'off'])->getScheme());
		$this->assertSame('https', $this->make(server: ['REQUEST_SCHEME' => 'https'])->getScheme());
		$this->assertSame('https', $this->make(server: ['HTTP_X_FORWARDED_PROTO' => 'https, http', 'HTTPS' => 'off'])->getScheme());
		$this->assertSame('http', $this->make(server: ['HTTP_X_FORWARDED_PROTO' => 'gopher'])->getScheme());
	}

	public function testOriginPrefersValidOriginHeader(): void
	{
		$this->assertSame('http://127.0.0.1', $this->make(server: ['HTTP_HOST' => '127.0.0.1'])->getOrigin());
		$this->assertSame('https://app.example:8443', $this->make(server: ['HTTP_HOST' => 'app.example:8443', 'HTTPS' => 'on'])->getOrigin());
		$this->assertSame('http://10.0.0.5', $this->make(server: ['HTTP_HOST' => 'other', 'HTTP_ORIGIN' => 'http://10.0.0.5'])->getOrigin());
		$this->assertSame('http://other', $this->make(server: ['HTTP_HOST' => 'other', 'HTTP_ORIGIN' => 'null'])->getOrigin());
		$this->assertSame('http://other', $this->make(server: ['HTTP_HOST' => 'other', 'HTTP_ORIGIN' => 'http://evil/path'])->getOrigin());
		$this->assertSame('http://', $this->make()->getOrigin());
		$this->assertTrue(Request::isOrigin('http://[::1]:8080'));
		$this->assertFalse(Request::isOrigin('ftp://x'));
		$this->assertFalse(Request::isOrigin('http://'));
		$this->assertFalse(Request::isOrigin('http://user@host'));
	}

	public function testGetQueryParams(): void
	{
		$req = $this->make(queryParams: ['limit' => '10', 'offset' => '20']);
		$this->assertSame(['limit' => '10', 'offset' => '20'], $req->getQueryParams());
	}

	public function testGetBodyValidJson(): void
	{
		$req = $this->make(rawBody: '{"login":"admin","password":"secret"}');
		$this->assertSame(['login' => 'admin', 'password' => 'secret'], $req->getBody());
	}

	public function testGetBodyInvalidJsonReturnsNull(): void
	{
		$req = $this->make(rawBody: 'not json at all');
		$this->assertNull($req->getBody());
	}

	public function testGetBodyEmptyStringReturnsNull(): void
	{
		$req = $this->make(rawBody: '');
		$this->assertNull($req->getBody());
	}

	public function testGetBodyNonArrayJsonReturnsNull(): void
	{
		$req = $this->make(rawBody: '"just a string"');
		$this->assertNull($req->getBody());
	}

	public function testGetHeaderCaseInsensitive(): void
	{
		$req = $this->make(server: ['HTTP_AUTHORIZATION' => 'Bearer token123']);
		$this->assertSame('Bearer token123', $req->getHeader('Authorization'));
		$this->assertSame('Bearer token123', $req->getHeader('authorization'));
		$this->assertSame('Bearer token123', $req->getHeader('AUTHORIZATION'));
	}

	public function testGetHeaderContentType(): void
	{
		$req = $this->make(server: ['CONTENT_TYPE' => 'application/json']);
		$this->assertSame('application/json', $req->getHeader('content-type'));
		$this->assertSame('application/json', $req->getHeader('Content-Type'));
	}

	public function testGetHeaderMissingReturnsNull(): void
	{
		$req = $this->make();
		$this->assertNull($req->getHeader('X-Nonexistent'));
	}

	public function testGetHostFromHttpHost(): void
	{
		$req = $this->make(server: ['HTTP_HOST' => 'demo.shipard.cz']);
		$this->assertSame('demo.shipard.cz', $req->getHost());
	}

	public function testGetHostFromServerName(): void
	{
		$req = $this->make(server: ['SERVER_NAME' => 'firma1.shipard.cz']);
		$this->assertSame('firma1.shipard.cz', $req->getHost());
	}

	public function testGetHostHttpHostTakesPrecedence(): void
	{
		$req = $this->make(server: ['HTTP_HOST' => 'demo.shipard.cz', 'SERVER_NAME' => 'other.cz']);
		$this->assertSame('demo.shipard.cz', $req->getHost());
	}
}
