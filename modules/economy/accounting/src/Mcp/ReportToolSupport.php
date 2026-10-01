<?php
declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Mcp;

use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Api\ReportDefinitionLoader;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Reports\ReportRegistry;
use Shipard\Core\Reports\ReportRunner;

/**
 * Sdílený wiring report toolů (`report_list`, `report_run`) — registry se
 * staví lazily až při prvním volání toolu (modul scan neplatí requesty,
 * které reporty nevolají) a sdílí se mezi oběma tooly v rámci requestu.
 *
 * `McpInvocationContext` nenese `DataSourceConfig` ani jazyk — obojí se
 * injektuje při registraci v `buildMcpRegistry` (vzor `MailDraftDocumentTool`);
 * `ModulePathResolver` override je pro testy.
 */
final class ReportToolSupport
{
	private ?ReportRegistry $registry = null;

	public function __construct(
		private readonly DataSourceConfig $dsConfig,
		private readonly ?ModulePathResolver $modulePathResolver = null,
		private readonly ?string $language = null,
	) {}

	public function language(): string
	{
		return $this->language ?? $this->dsConfig->getDefaultLanguage();
	}

	public function registry(): ReportRegistry
	{
		if ($this->registry === null) {
			$resolver = $this->modulePathResolver ?? $this->buildModulePathResolver();
			$this->registry = ReportDefinitionLoader::load($this->dsConfig, $resolver, $this->language());
		}
		return $this->registry;
	}

	public function runner(McpInvocationContext $ctx): ReportRunner
	{
		return new ReportRunner(
			$this->registry(),
			$ctx->db,
			$ctx->config,
			$this->dsConfig->getId(),
			$this->language(),
			country: $this->dsConfig->getCountry(),
		);
	}

	private function buildModulePathResolver(): ModulePathResolver
	{
		$serverConfig = new ServerConfig();
		$serverConfig->load();
		// dirname(__DIR__, 4) = adresář modules/ v repu (fallback pro moduly mimo server config).
		return ModulePathResolver::fromServerConfig($serverConfig, dirname(__DIR__, 4));
	}
}
