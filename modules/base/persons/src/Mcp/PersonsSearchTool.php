<?php
declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Mcp;

use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Api\Mcp\McpTool;
use Shipard\Core\Database\SearchCondition;
use Shipard\Module\Base\Persons\PersonType;

/**
 * Čtecí MCP nástroj: hledá osoby a firmy v lokální evidenci
 * (`base_persons_persons`) dle jména, IČO, DIČ nebo kódu osoby. Rozšiřuje
 * dotazovací vzor `PersonsLookup::search()` o `tax_id`/`vat_id` a vrací
 * LLM-přívětivou obálku se stabilním `ref`.
 */
final class PersonsSearchTool implements McpTool
{
	private const int DEFAULT_LIMIT = 20;
	private const int MAX_LIMIT = 50;

	public function isReadOnly(): bool
	{
		return true;
	}

	public function name(): string
	{
		return 'persons_search';
	}

	public function description(): string
	{
		return 'Vyhledá osoby a firmy evidované v Shipardu podle jména, IČO, DIČ '
			. 'nebo kódu osoby. Použij, když uživatel odkazuje na konkrétní osobu '
			. 'nebo firmu a potřebuješ její záznam nebo `ref` pro další krok. Hledá '
			. 'v lokální evidenci osob, NE ve veřejných registrech (ARES) — pro '
			. 'lustraci nové firmy podle IČO ve veřejném rejstříku tento nástroj '
			. 'nepoužívej. Nepoužívej ani pro doklady či faktury.';
	}

	public function inputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'query'  => ['type' => 'string', 'description' => 'Volný text: jméno, IČO, DIČ nebo kód osoby. Prázdné = první stránka.'],
				'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT],
				'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
			],
			'required' => ['query'],
		];
	}

	public function call(array $arguments, McpInvocationContext $ctx): array
	{
		if (!array_key_exists('query', $arguments)) {
			throw new \InvalidArgumentException('Missing required parameter: query');
		}

		$q      = trim((string) $arguments['query']);
		$limit  = max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));

		$cols = '`id`, `full_name`, `person_type`, `company_id`, `tax_id`, `vat_id`, `email`, `person_id`';

		if ($q === '') {
			$rows = $ctx->db->fetchAll(
				"SELECT {$cols} FROM `base_persons_persons`"
				. ' WHERE `docState` IN (10, 40, 80)'
				. ' ORDER BY `full_name` ASC'
				. ' LIMIT %i OFFSET %i',
				$limit + 1, $offset,
			);
		} else {
			[$searchSql, $searchParams] = SearchCondition::anyContains(
				['`full_name`', '`company_id`', '`person_id`', '`tax_id`', '`vat_id`'],
				$q,
			);
			$rows = $ctx->db->fetchAll(
				"SELECT {$cols} FROM `base_persons_persons`"
				. ' WHERE `docState` IN (10, 40, 80)'
				. '   AND ' . $searchSql
				. ' ORDER BY `full_name` ASC'
				. ' LIMIT %i OFFSET %i',
				...[...$searchParams, $limit + 1, $offset],
			);
		}

		$hasMore = count($rows) > $limit;
		if ($hasMore) {
			$rows = array_slice($rows, 0, $limit);
		}
		$shown = count($rows);

		return [
			'summary' => $shown === 0
				? "Nenalezena žádná osoba pro \"{$q}\"."
				: "Nalezeno {$shown} osob pro \"{$q}\"" . ($hasMore ? " (zobrazeno prvních {$shown})." : "."),
			'items' => array_map(static fn(array $r) => [
				'ref'         => ['type' => 'person', 'id' => (int) $r['id']],
				'full_name'   => (string) ($r['full_name'] ?? ''),
				'person_type' => (int) ($r['person_type'] ?? 0) === PersonType::Company->value ? 'company' : 'person',
				'company_id'  => $r['company_id'] ?: null,
				'vat_id'      => $r['vat_id'] ?: null,
				'email'       => $r['email'] ?: null,
			], $rows),
			'pagination' => [
				'limit'    => $limit,
				'offset'   => $offset,
				'returned' => $shown,
				'has_more' => $hasMore,
			],
		];
	}
}
