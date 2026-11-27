<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Api\Request;
use Shipard\Api\RequestBaseUrl;
use Shipard\Api\ResolvedDataSource;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;

/** Základní adresa zdroje dat ze schématu a hostu požadavku (#183 D2, U9). */
class RequestBaseUrlTest extends TestCase
{
	private const string DS_ID = 'abcd-efgh-ijkl-mnop';

	/** @param array<string, string> $server */
	private function request(array $server): Request
	{
		return Request::fromArray('GET', '/' . self::DS_ID . '/api/v1/_app/info', [], '', $server);
	}

	/** @return array<string, array{array<string, string>, bool, string}> */
	public static function cases(): array
	{
		$id = self::DS_ID;
		return [
			'http na IP, ID v cestě' => [
				['HTTP_HOST' => '10.0.0.7'], true, "http://10.0.0.7/{$id}",
			],
			'https na IP, ID v cestě' => [
				['HTTP_HOST' => '10.0.0.7', 'HTTPS' => 'on'], true, "https://10.0.0.7/{$id}",
			],
			'https na jméně, ID v cestě' => [
				['HTTP_HOST' => 'dev.example.com', 'HTTPS' => 'on'], true, "https://dev.example.com/{$id}",
			],
			'http na localhost, ID v cestě' => [
				['HTTP_HOST' => 'localhost'], true, "http://localhost/{$id}",
			],
			'port zůstává' => [
				['HTTP_HOST' => 'localhost:8080'], true, "http://localhost:8080/{$id}",
			],
			'https s portem' => [
				['HTTP_HOST' => 'dev.example.com:8443', 'HTTPS' => 'on'], true, "https://dev.example.com:8443/{$id}",
			],
			'X-Forwarded-Proto má přednost před HTTPS' => [
				['HTTP_HOST' => 'dev.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https'], true, "https://dev.example.com/{$id}",
			],
			'X-Forwarded-Proto http za TLS proxy' => [
				['HTTP_HOST' => 'dev.example.com', 'HTTP_X_FORWARDED_PROTO' => 'http', 'HTTPS' => 'on'], true, "http://dev.example.com/{$id}",
			],
			'HTTPS off je http' => [
				['HTTP_HOST' => '10.0.0.7', 'HTTPS' => 'off'], true, "http://10.0.0.7/{$id}",
			],
			'produkce: https bez ID' => [
				['HTTP_HOST' => 'demo.shipard.cz', 'HTTPS' => 'on'], false, 'https://demo.shipard.cz',
			],
			'bez ID se schéma nedomýšlí' => [
				['HTTP_HOST' => 'demo.shipard.cz'], false, 'http://demo.shipard.cz',
			],
			'IPv6' => [
				['HTTP_HOST' => '[2001:db8::7]', 'HTTPS' => 'on'], true, "https://[2001:db8::7]/{$id}",
			],
		];
	}

	/** @param array<string, string> $server */
	#[DataProvider('cases')]
	public function testOf(array $server, bool $pathAddressed, string $expected): void
	{
		$this->assertSame($expected, RequestBaseUrl::of($this->request($server), self::DS_ID, $pathAddressed));
	}

	public function testForReadsIdAndAddressingFromResolvedDataSource(): void
	{
		$dir = sys_get_temp_dir() . '/shpd_base_url_' . uniqid();
		mkdir($dir . '/config', 0755, true);
		file_put_contents($dir . '/config/main.json', json_encode([
			'id'            => self::DS_ID,
			'name'          => 'Test DS',
			'database_name' => 'abcd_efgh_ijkl_mnop',
			'database_user' => 'shpd_test',
			'created'       => '2026-03-16T10:00:00+01:00',
		]));

		try {
			$config = new DataSourceConfig($dir);
			$connection = (new \ReflectionClass(DataSourceConnection::class))->newInstanceWithoutConstructor();
			$request = $this->request(['HTTP_HOST' => 'dev.example.com', 'HTTPS' => 'on']);

			$this->assertSame(
				'https://dev.example.com/' . self::DS_ID,
				RequestBaseUrl::for($request, new ResolvedDataSource($config, $connection, '/api/v1/_app/info', true)),
			);
			$this->assertSame(
				'https://dev.example.com',
				RequestBaseUrl::for($request, new ResolvedDataSource($config, $connection, '/api/v1/_app/info', false)),
			);
		} finally {
			@unlink($dir . '/config/main.json');
			@rmdir($dir . '/config');
			@rmdir($dir);
		}
	}

	/**
	 * #183 D2: schéma se nikde neodvozuje z příznaku adresování — hlídá
	 * zdroje `src/`, `modules/` a `public/`.
	 */
	public function testNoSchemeDerivedFromAddressingFlag(): void
	{
		$root = dirname(__DIR__, 3);
		$pattern = '/\?\s*[\'"]https?(:\/\/)?[\'"]\s*:\s*[\'"]https?(:\/\/)?[\'"]/';
		$hits = [];
		foreach (['src', 'modules', 'public'] as $dir) {
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS),
			);
			foreach ($files as $file) {
				if ($file->getExtension() !== 'php') {
					continue;
				}
				foreach (file($file->getPathname()) ?: [] as $i => $line) {
					if (preg_match('/devMode|pathAddressed|isPathAddressed|isDevMode/', $line) && preg_match($pattern, $line)) {
						$hits[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($i + 1);
					}
				}
			}
		}
		$this->assertSame([], $hits, 'Schéma patří z Request::getScheme() přes RequestBaseUrl');
	}
}
