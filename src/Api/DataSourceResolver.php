<?php
declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Api\Exception\DataSourceUnavailableException;
use Shipard\Api\Exception\UnknownDataSourceException;
use Shipard\Api\Exception\UnknownHostException;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\DataSourceState;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\IdGenerator;

/**
 * Zdroj dat požadavku (#183 D1). **Adresování cestou řídí režim serveru,
 * ne host:** v režimu `development` se zdroj dat vezme z prvního segmentu
 * cesty, má-li tvar ID ({@see IdGenerator::ID_PATTERN}) — pro jakýkoli host
 * (IP, `localhost`, jméno). Jinak, a v režimu `production` vždy, rozhoduje
 * host přes `domains.json`; host s IP adresou tak v produkci končí
 * `UnknownHostException`. Neznámý režim se chová jako `production`.
 */
class DataSourceResolver
{
	public const string MODE_DEVELOPMENT = 'development';

	public function __construct(
		private string $domainsFile = '/etc/shipard/domains.json',
		private string $dataSourcesDir = '/opt/shipard/data-sources',
		private string $mode = 'production',
	) {}

	public function resolve(string $host, string $path): ResolvedDataSource
	{
		if ($this->mode === self::MODE_DEVELOPMENT) {
			$dsId = self::pathDataSourceId($path);
			if ($dsId !== null) {
				return $this->resolveByPath($dsId, $path);
			}
		}
		return $this->resolveByHost($host, $path);
	}

	/** První segment cesty, má-li tvar ID zdroje dat; jinak null. */
	public static function pathDataSourceId(string $path): ?string
	{
		$trimmed = ltrim($path, '/');
		$slashPos = strpos($trimmed, '/');
		$segment = $slashPos !== false ? substr($trimmed, 0, $slashPos) : $trimmed;

		return preg_match(IdGenerator::ID_PATTERN, $segment) === 1 ? $segment : null;
	}

	/**
	 * Host pro `domains.json`: malými písmeny a bez portu (`dev.example:8443`
	 * → `dev.example`, `[::1]:8080` → `[::1]`). Prohlížeč port posílá
	 * a vývojové šablony nginx ho PHP předávají (`$http_host`).
	 */
	public static function hostWithoutPort(string $host): string
	{
		$host = strtolower(trim($host));
		if (preg_match('/^(\[[^\]]*\]|[^:]*):\d+$/', $host, $m) === 1) {
			return $m[1];
		}
		return $host;
	}

	private function resolveByHost(string $host, string $path): ResolvedDataSource
	{
		$map = $this->loadDomainsFile();
		$host = self::hostWithoutPort($host);

		if (!isset($map[$host])) {
			throw new UnknownHostException($host);
		}

		$dsId = $map[$host];
		$state = $this->assertDataSourceAvailable($dsId);
		$config = $this->createDataSourceConfig($dsId);
		$connection = $this->createConnection($config);

		return new ResolvedDataSource($config, $connection, $path, false, $state);
	}

	private function resolveByPath(string $dsId, string $path): ResolvedDataSource
	{
		$configFile = $this->dataSourcesDir . '/' . $dsId . '/config/main.json';
		if (!file_exists($configFile)) {
			throw new UnknownDataSourceException($dsId);
		}

		$state = $this->assertDataSourceAvailable($dsId);
		$config = $this->createDataSourceConfig($dsId);
		$connection = $this->createConnection($config);

		// normalizedPath = vše za /{dsId}, výchozí /
		// (/abcd-efgh-ijkl-mnop/api/v1/users → /api/v1/users)
		$rest = substr(ltrim($path, '/'), strlen($dsId));
		$normalizedPath = $rest !== '' ? $rest : '/';

		return new ResolvedDataSource($config, $connection, $normalizedPath, true, $state);
	}

	/**
	 * Kontrola config/state.json před připojením k DB — v maintenance může
	 * být databáze rozbitá nebo neexistovat (restore). Stav si nese výjimka,
	 * index.php ho vrací v error details. Propuštěný stav se vrací, aby ho
	 * ResolvedDataSource nesl dál (read-only vynucení, /_app/info) a pipeline
	 * nečetla soubor podruhé.
	 */
	private function assertDataSourceAvailable(string $dsId): DataSourceState
	{
		$state = DataSourceState::load($this->dataSourcesDir . '/' . $dsId);
		if ($state->blocksHttp()) {
			throw new DataSourceUnavailableException(
				$dsId,
				$state->getEffectiveState(),
				$state->getMaintenanceReason(),
			);
		}
		return $state;
	}

	protected function loadDomainsFile(): array
	{
		// Chybějící domains.json = zatím žádná mapování → prázdná mapa, aby
		// nenamapovaný host skončil čistým UnknownHostException (404 Unknown host),
		// ne generickým 500.
		if (!file_exists($this->domainsFile)) {
			return [];
		}

		$content = file_get_contents($this->domainsFile);
		$data = json_decode($content, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new \RuntimeException("Invalid JSON in domains file: " . json_last_error_msg());
		}

		if (!is_array($data)) {
			throw new \RuntimeException("Domains file must contain a JSON object");
		}

		return $data;
	}

	protected function createDataSourceConfig(string $dsId): DataSourceConfig
	{
		$dsDir = $this->dataSourcesDir . '/' . $dsId;
		return new DataSourceConfig($dsDir);
	}

	protected function createConnection(DataSourceConfig $config): DataSourceConnection
	{
		return new DataSourceConnection($config);
	}
}
