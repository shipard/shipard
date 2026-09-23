<?php
declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Mcp;

use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Api\Mcp\McpTool;
use Shipard\Core\Document\DocStateConfig;

/**
 * Čtecí MCP nástroj: vyhledávání dokladů (faktury vydané/přijaté) v
 * `docs_core_heads` dle partnera, typu, účetního období, stavu a po
 * splatnosti. Období filtruje `accounting_date`. `overdue` je příznak (ne
 * samostatný nástroj) a znamená jen „po splatnosti" — stav úhrady tento
 * nástroj nevrací, takže z něj „neuhrazeno" nevyčteš.
 *
 * Součty, počty a žebříčky nad doklady patří do `documents_aggregate`.
 */
final class DocumentsSearchTool implements McpTool
{
	private const int DEFAULT_LIMIT = 20;
	private const int MAX_LIMIT = 50;

	public function isReadOnly(): bool
	{
		return true;
	}

	public function name(): string
	{
		return 'documents_search';
	}

	public function description(): string
	{
		return 'Vyhledá doklady (faktury vydané/přijaté) podle partnera, typu, '
			. 'účetního období, stavu a po splatnosti — vrací seznam konkrétních '
			. 'dokladů. `partner` je ID osoby z `persons_search`. Období filtruje '
			. 'účetní datum. `overdue=true` vrátí doklady po splatnosti (datum '
			. 'splatnosti < dnes, nestornované) — POZOR: „po splatnosti" NENÍ totéž '
			. 'co „neuhrazené"; tento nástroj stav úhrady nevrací, netvrď tedy, že '
			. 'je faktura zaplacená nebo nezaplacená. Na součty, počty a žebříčky '
			. '(„největší dodavatelé", „obrat po měsících") použij '
			. '`documents_aggregate`. Nepoužívej pro osoby '
			. '(`persons_search`/`persons_get`) ani pro došlou poštu '
			. '(`mail_list_pending`).';
	}

	public function inputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'partner'              => ['type' => 'integer', 'description' => 'ID osoby (partnera) z persons_search'],
				'doc_type'             => ['type' => 'string', 'description' => "Typ dokladu, např. 'invno' (faktura vydaná), 'invpo' (zálohová faktura vydaná) nebo 'invni' (faktura přijatá)"],
				'accounting_date_from' => ['type' => 'string', 'description' => 'Účetní datum od (YYYY-MM-DD)'],
				'accounting_date_to'   => ['type' => 'string', 'description' => 'Účetní datum do (YYYY-MM-DD)'],
				'overdue'              => ['type' => 'boolean', 'default' => false, 'description' => "Jen doklady po splatnosti (due_date < dnes, nestornované). Po splatnosti NENÍ 'neuhrazené' — stav úhrady tento nástroj nevrací."],
				'state'                => ['type' => 'string', 'enum' => ['active', 'done', 'all'], 'default' => 'active', 'description' => 'active = bez smazaných; done = jen V pořádku; all = vč. smazaných'],
				'query'                => ['type' => 'string', 'description' => 'Volný text přes doc_number / partner_doc_number'],
				'limit'                => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT],
				'offset'               => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
			],
		];
	}

	public function call(array $arguments, McpInvocationContext $ctx): array
	{
		$limit  = max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));

		// Whitelistovaný WHERE builder: každý filtr přidá fragment + parametry.
		$where  = [];
		$params = [];

		$state = (string) ($arguments['state'] ?? 'active');
		if ($state === 'done') {
			$where[] = '`h`.`docState` = 40';
		} elseif ($state !== 'all') {
			$where[] = '`h`.`docState` != 90'; // active (default)
		}

		if (isset($arguments['partner'])) {
			$where[]  = '`h`.`partner` = %i';
			$params[] = (int) $arguments['partner'];
		}
		if (!empty($arguments['doc_type'])) {
			$where[]  = '`h`.`doc_type` = %s';
			$params[] = (string) $arguments['doc_type'];
		}
		if (!empty($arguments['accounting_date_from'])) {
			$where[]  = '`h`.`accounting_date` >= %s';
			$params[] = (string) $arguments['accounting_date_from'];
		}
		if (!empty($arguments['accounting_date_to'])) {
			$where[]  = '`h`.`accounting_date` <= %s';
			$params[] = (string) $arguments['accounting_date_to'];
		}
		if (!empty($arguments['overdue'])) {
			$where[] = '`h`.`due_date` < CURDATE() AND `h`.`docState` NOT IN (30, 90)';
		}
		if (!empty($arguments['query'])) {
			$like     = '%' . trim((string) $arguments['query']) . '%';
			$where[]  = '(`h`.`doc_number` LIKE %s OR `h`.`partner_doc_number` LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$whereSql = $where === [] ? '1' : implode(' AND ', $where);

		$sql = 'SELECT `h`.`id`, `h`.`doc_type`, `h`.`doc_number`, `h`.`partner`,'
			. ' `p`.`full_name` AS `partner_name`, `h`.`accounting_date`, `h`.`due_date`,'
			. ' `h`.`total_amount`, `h`.`doc_currency`, `h`.`docState`'
			. ' FROM `docs_core_heads` `h`'
			. ' LEFT JOIN `base_persons_persons` `p` ON `p`.`id` = `h`.`partner`'
			. " WHERE {$whereSql}"
			. ' ORDER BY `h`.`accounting_date` DESC, `h`.`id` DESC'
			. ' LIMIT %i OFFSET %i';

		$rows = $ctx->db->fetchAll($sql, ...[...$params, $limit + 1, $offset]);

		$hasMore = count($rows) > $limit;
		if ($hasMore) {
			$rows = array_slice($rows, 0, $limit);
		}

		$stateCfg = DocStateConfig::fromCfgItem($ctx->config?->cfgItem('docs.core.docStates'));
		$today    = (string) $ctx->db->fetchSingle('SELECT CURDATE()');

		$overdueCount = 0;
		$items = array_map(function (array $r) use ($stateCfg, $today, &$overdueCount): array {
			$docState = (int) ($r['docState'] ?? 0);
			$dueDate  = $r['due_date'] ?: null;
			$isOverdue = $dueDate !== null && $dueDate < $today && !in_array($docState, [30, 90], true);
			if ($isOverdue) {
				$overdueCount++;
			}

			$partner = $r['partner']
				? ['id' => (int) $r['partner'], 'full_name' => (string) ($r['partner_name'] ?? '')]
				: null;
			$docNumber = (string) ($r['doc_number'] ?? '');

			return [
				'ref'             => ['type' => 'document', 'id' => (int) $r['id']],
				'full_name'       => trim($docNumber . ($partner ? ' — ' . $partner['full_name'] : '')),
				'doc_number'      => $docNumber ?: null,
				'doc_type'        => $r['doc_type'] ?: null,
				'partner'         => $partner,
				'accounting_date' => $r['accounting_date'] ?: null,
				'due_date'        => $dueDate,
				'total_amount'    => $r['total_amount'],
				'doc_currency'    => $r['doc_currency'] ?: null,
				'state_label'     => $stateCfg->getState($docState)['stateName'] ?? (string) $docState,
				'overdue'         => $isOverdue,
			];
		}, $rows);

		$shown = count($items);

		return [
			'summary' => $shown === 0
				? 'Nenalezeny žádné doklady.'
				: "{$shown} dokladů" . ($overdueCount > 0 ? ", z toho {$overdueCount} po splatnosti." : '.'),
			'items'      => $items,
			'pagination' => [
				'limit'    => $limit,
				'offset'   => $offset,
				'returned' => $shown,
				'has_more' => $hasMore,
			],
		];
	}
}
