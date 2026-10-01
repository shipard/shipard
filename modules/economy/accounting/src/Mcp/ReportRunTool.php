<?php
declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Mcp;

use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Api\Mcp\McpTool;

/**
 * Čtecí MCP nástroj: spustí report a vrátí kompletní `ReportResult` (D11).
 * Generický pro všechny deklarované reporty — parametry validuje
 * `ReportRunner` z deklarace, nevalidní vstup propadá jako
 * `InvalidArgumentException` (→ -32602).
 */
final class ReportRunTool implements McpTool
{
	public function __construct(private readonly ?ReportToolSupport $support = null) {}

	public function isReadOnly(): bool
	{
		return true;
	}

	public function name(): string
	{
		return 'report_run';
	}

	public function description(): string
	{
		return 'Spustí report (hlavní kniha, výsledovka, rozvaha, živé výstupy DPH) '
			. 'za zadané období a vrátí strukturovaný výsledek: sloupce, řádky '
			. 's hodnotami, zprávy a status. Id reportů, zdroj období '
			. '(periodSource) a dostupné fiskální roky či registrace DPH zjistíš '
			. 'nástrojem report_list; reporty s periodSource "fiscal" chtějí '
			. 'fiscalYear+monthFrom+monthTo, reporty "vatPeriod" chtějí '
			. 'period (id instance daňového tvrzení z report_list → '
			. 'vatRegistrations[].periods, typ instance musí odpovídat vatReportType '
			. 'reportu). DŮLEŽITÉ: výsledek se '
			. 'status "errors" NEJSOU spolehlivá čísla — chybu vždy nahlas '
			. 'uživateli a nepoužívej je mlčky pro výpočty; status "warnings" '
			. 'u odpovědi zmiň. Pro přehledy a srovnání používej detail '
			. '"synthetic" (menší výstup); "analytic" jen při dohledávání '
			. 'konkrétního analytického účtu.';
	}

	public function inputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'reportId'   => ['type' => 'string', 'description' => 'Id reportu z report_list (např. economy.accounting.balanceSheet)'],
				'fiscalYear' => ['type' => 'integer', 'description' => 'Fiskální rok dle názvu, např. 2026 — povinné pro reporty s periodSource "fiscal" (dostupné roky viz report_list)'],
				'monthFrom'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12, 'description' => 'První fiskální měsíc intervalu (periodSource "fiscal")'],
				'monthTo'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12, 'description' => 'Poslední fiskální měsíc intervalu (periodSource "fiscal")'],
				'period'     => ['type' => 'integer', 'description' => 'Id instance daňového tvrzení — povinné pro reporty s periodSource "vatPeriod" (instance viz report_list → vatRegistrations[].periods; typ instance = vatReportType reportu)'],
				'detail'     => [
					'type'        => 'string',
					'enum'        => ['analytic', 'synthetic'],
					'default'     => 'synthetic',
					'description' => 'Úroveň detailu; default synthetic = agregace na syntetické účty (menší výstup, pro LLM přehledy), analytic = plné analytiky.',
				],
				'params'     => [
					'type'                 => 'object',
					'additionalProperties' => ['type' => ['string', 'boolean']],
					'description'          => 'Další parametry reportu jako {id: hodnota} — id, povolené hodnoty a výchozí hodnoty viz report_list → params (např. {"groupBy": "type"}). Vynechaný parametr = výchozí hodnota.',
				],
			],
			'required' => ['reportId'],
		];
	}

	public function call(array $arguments, McpInvocationContext $ctx): array
	{
		if ($this->support === null) {
			throw new \RuntimeException('report_run: missing ReportToolSupport wiring');
		}

		if (!array_key_exists('reportId', $arguments)) {
			throw new \InvalidArgumentException('Missing required parameter: reportId');
		}

		$reportId   = (string) $arguments['reportId'];
		$definition = $this->support->registry()->get($reportId);
		if ($definition === null) {
			throw new \InvalidArgumentException(
				"Unknown report '{$reportId}' — call report_list for available reports",
			);
		}

		if ($definition->periodSource === 'vatPeriod') {
			if (!array_key_exists('period', $arguments)) {
				throw new \InvalidArgumentException('Missing required parameter: period');
			}
			$rawParams = ['period' => (string) $arguments['period']];
		} else {
			foreach (['fiscalYear', 'monthFrom', 'monthTo'] as $required) {
				if (!array_key_exists($required, $arguments)) {
					throw new \InvalidArgumentException("Missing required parameter: {$required}");
				}
			}
			$rawParams = [
				'fiscalYear' => (string) $arguments['fiscalYear'],
				'monthFrom'  => $arguments['monthFrom'],
				'monthTo'    => $arguments['monthTo'],
			];
		}
		// Default pro MCP je synthetic (menší výstup pro LLM; UI/CLI default je
		// analytic) — podsouvá se jen reportům, které parametr deklarují.
		foreach ($definition->params as $param) {
			if ($param['id'] === 'detail') {
				$rawParams['detail'] = (string) ($arguments['detail'] ?? 'synthetic');
				break;
			}
		}

		// Ostatní parametry deklarace — platnost id i hodnoty hlídá validátor.
		$extra = $arguments['params'] ?? [];
		if (!is_array($extra)) {
			throw new \InvalidArgumentException("Parameter 'params' must be an object {id: value}");
		}
		foreach ($extra as $id => $value) {
			$rawParams[(string) $id] = $value;
		}

		$result = $this->support->runner($ctx)->run($reportId, $rawParams);

		$period = $result->params['period'];
		$periodLabel = $definition->periodSource === 'vatPeriod'
			? sprintf('%s (%s–%s)', $period['name'], $period['dateFrom'], $period['dateTo'])
			: sprintf('%s/%d–%d', $period['fiscalYear'], $period['monthFrom'], $period['monthTo']);

		return [
			'summary' => sprintf(
				'%s %s, status: %s (%d messages)',
				$definition->name,
				$periodLabel,
				$result->status->value,
				count($result->messages),
			),
			'report' => $result->toArray(),
		];
	}
}
