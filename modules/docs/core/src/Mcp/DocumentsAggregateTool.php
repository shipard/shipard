<?php
declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Mcp;

use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Api\Mcp\McpTool;

/**
 * Čtecí MCP nástroj: agregace dokladů z `docs_core_heads` — součty a počty
 * seskupené podle dimenze (partner / typ dokladu / fiskální měsíc / období
 * DPH). Pokrývá žebříčky („největší dodavatelé"), časové řady („obrat po
 * měsících") i součet za jednoho partnera; `documents_search` naproti tomu
 * vrací konkrétní doklady.
 *
 * Součty jdou výhradně nad sloupci v domácí měně (suffix `_dom`), aby se
 * v `SUM` nemíchaly měny.
 */
final class DocumentsAggregateTool implements McpTool
{
	private const int DEFAULT_LIMIT = 10;
	private const int MAX_LIMIT = 50;

	/** Skupina bez klíče (doklad bez partnera / bez zařazení do období). */
	private const string UNASSIGNED_LABEL = '(nezařazeno)';

	public function isReadOnly(): bool
	{
		return true;
	}

	public function name(): string
	{
		return 'documents_aggregate';
	}

	public function description(): string
	{
		return 'Sečte doklady a seskupí je podle dimenze — žebříčky (největší '
			. 'dodavatelé/odběratelé), časové řady (obrat po měsících/obdobích DPH) '
			. 'nebo součet za jednoho partnera. Použij vždy, když se uživatel ptá na '
			. 'součet, počet, žebříček („10 největších") nebo vývoj v čase; '
			. '`documents_search` použij jen pro seznam konkrétních dokladů. '
			. 'SMĚR OBCHODU: `doc_type=invni` = faktury přijaté = naši DODAVATELÉ; '
			. '`doc_type=invno` = faktury vydané = naši ODBĚRATELÉ (bez tohoto filtru '
			. 'se oba směry sečtou dohromady a výsledek nic neříká). `measure` je '
			. 'defaultně `total_base` = základ bez DPH (účetně relevantní obrat); '
			. '`total_amount` je s DPH — použij jen u dotazu na částku k úhradě nebo '
			. 'u neplátce DPH. Součty jsou vždy v domácí měně (přepočtené), takže se '
			. 'nemíchají měny. Pro obrat je vhodnější `state=done` (jen doklady ve '
			. 'stavu V pořádku); default `active` zahrnuje i koncepty. Neumí žebříčky '
			. 'nad položkami/řádky dokladů a nevrací stav úhrady (uhrazeno / '
			. 'neuhrazeno).';
	}

	public function inputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dimension' => [
					'type' => 'string',
					'enum' => ['partner', 'doc_type', 'fiscal_month', 'vat_period'],
					'description' => 'Podle čeho seskupit: partner (žebříček dodavatelů/odběratelů),'
						. ' doc_type (podle typu dokladu), fiscal_month (časová řada po měsících),'
						. ' vat_period (po instancích přiznání k DPH)',
				],
				'measure' => [
					'type' => 'string',
					'enum' => ['total_base', 'total_amount', 'count'],
					'default' => 'total_base',
					'description' => 'total_base = základ bez DPH (default, obrat); total_amount = celkem s DPH; count = počet dokladů',
				],
				'order' => [
					'type' => 'string',
					'enum' => ['measure_desc', 'dimension_asc'],
					'default' => 'measure_desc',
					'description' => 'measure_desc = žebříček od největšího (default); dimension_asc = přirozené pořadí dimenze (časová řada)',
				],
				'doc_type'             => ['type' => 'string', 'description' => "Typ dokladu: 'invni' (přijaté = dodavatelé), 'invno' (vydané = odběratelé) nebo 'invpo' (zálohové vydané = odběratelé)"],
				'partner'              => ['type' => 'integer', 'description' => 'ID osoby (partnera) z persons_search — součet jen za tohoto partnera'],
				'fiscal_year'          => ['type' => 'string', 'description' => "Označení fiskálního roku, např. '2025'"],
				'accounting_date_from' => ['type' => 'string', 'description' => 'Účetní datum od (YYYY-MM-DD)'],
				'accounting_date_to'   => ['type' => 'string', 'description' => 'Účetní datum do (YYYY-MM-DD)'],
				'state'                => ['type' => 'string', 'enum' => ['active', 'done', 'all'], 'default' => 'active', 'description' => 'active = bez smazaných (vč. konceptů); done = jen V pořádku (doporučeno pro obrat); all = vč. smazaných'],
				'limit'                => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT, 'description' => 'Počet vrácených skupin (top N)'],
			],
			'required' => ['dimension'],
		];
	}

	public function call(array $arguments, McpInvocationContext $ctx): array
	{
		$dimension = trim((string) ($arguments['dimension'] ?? ''));
		if ($dimension === '') {
			throw new \InvalidArgumentException(
				'Argument `dimension` je povinný. Povolené hodnoty: partner, doc_type, fiscal_month, vat_period.',
			);
		}

		// Whitelist: dimenze / měra / řazení se převádějí přes match() na
		// hard-coded SQL fragmenty. Hodnota argumentu se do SQL nikdy nedostane
		// jako řetězec — jen skaláry přes %i / %s.
		$dimSql = match ($dimension) {
			'partner'      => '`h`.`partner`',
			'doc_type'     => '`h`.`doc_type`',
			'fiscal_month' => '`h`.`fiscal_month`',
			'vat_period'   => '`h`.`vat_period`',
			default        => throw new \InvalidArgumentException(
				"Neplatná `dimension` '{$dimension}'. Povolené hodnoty: partner, doc_type, fiscal_month, vat_period.",
			),
		};

		$measure    = (string) ($arguments['measure'] ?? 'total_base');
		$measureSql = match ($measure) {
			'total_base'   => 'SUM(`h`.`total_base_dom`)',
			'total_amount' => 'SUM(`h`.`total_amount_dom`)',
			'count'        => 'COUNT(*)',
			default        => throw new \InvalidArgumentException(
				"Neplatná `measure` '{$measure}'. Povolené hodnoty: total_base, total_amount, count.",
			),
		};
		$isCount = $measure === 'count';

		$order = (string) ($arguments['order'] ?? 'measure_desc');
		if (!in_array($order, ['measure_desc', 'dimension_asc'], true)) {
			throw new \InvalidArgumentException(
				"Neplatný `order` '{$order}'. Povolené hodnoty: measure_desc, dimension_asc.",
			);
		}

		$limit = max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT)));

		// LEFT JOIN i u codebooků: skupina s NULL klíčem se nezahazuje, aby
		// součty souhlasily s grand totalem.
		[$labelSelect, $joinSql, $dimOrderSql] = match ($dimension) {
			'partner' => [
				', MIN(`p`.`full_name`) AS `label_raw`',
				' LEFT JOIN `base_persons_persons` `p` ON `p`.`id` = `h`.`partner`',
				'`label_raw` ASC',
			],
			'doc_type' => [
				// Label je až z cfgItem docs.core.docTypes, řadíme podle kódu.
				'',
				'',
				'`dim_key` ASC',
			],
			'fiscal_month' => [
				', MIN(`fm`.`calendar_year`) AS `cal_year`, MIN(`fm`.`calendar_month`) AS `cal_month`'
					. ', MIN(`fm`.`date_begin`) AS `dim_sort`',
				' LEFT JOIN `economy_codebooks_fiscal_months` `fm` ON `fm`.`id` = `h`.`fiscal_month`',
				'`dim_sort` ASC',
			],
			'vat_period' => [
				', MIN(`vp`.`name`) AS `label_raw`, MIN(`vp`.`date_begin`) AS `dim_sort`',
				' LEFT JOIN `economy_vat_report_periods` `vp` ON `vp`.`id` = `h`.`vat_period`',
				'`dim_sort` ASC',
			],
		};

		[$whereSql, $params] = $this->buildWhere($arguments, $ctx);

		$orderSql = $order === 'measure_desc'
			? '`measure_value` DESC, `dim_key` ASC'
			: $dimOrderSql;

		// Vše mimo dimenzi je agregované (MIN / SUM / COUNT), GROUP BY má právě
		// jeden sloupec → bezpečné i pod ONLY_FULL_GROUP_BY.
		$sql = "SELECT {$dimSql} AS `dim_key`{$labelSelect}"
			. ", {$measureSql} AS `measure_value`"
			. ', COUNT(*) AS `doc_count`'
			. ', MIN(`h`.`home_currency`) AS `currency`'
			. ' FROM `docs_core_heads` `h`'
			. $joinSql
			. " WHERE {$whereSql}"
			. " GROUP BY {$dimSql}"
			. " ORDER BY {$orderSql}"
			. ' LIMIT %i';

		$rows = $ctx->db->fetchAll($sql, ...[...$params, $limit + 1]);

		$hasMore = count($rows) > $limit;
		if ($hasMore) {
			$rows = array_slice($rows, 0, $limit);
		}

		if ($rows === []) {
			return [
				'summary'    => 'Pro zadané filtry nejsou žádné doklady.',
				'items'      => [],
				'pagination' => ['limit' => $limit, 'offset' => 0, 'returned' => 0, 'has_more' => false],
			];
		}

		// Grand total: stejné WHERE, bez GROUP BY → celek přes všechny skupiny,
		// ne jen vrácených top N. COUNT(DISTINCT home_currency) je tady (nad
		// celým setem), protože per-skupina by smíchané měny neodhalil.
		$totalRow = $ctx->db->fetchRow(
			"SELECT {$measureSql} AS `total_value`, COUNT(*) AS `total_docs`"
				. ', COUNT(DISTINCT `h`.`home_currency`) AS `currency_count`'
				. ', MIN(`h`.`home_currency`) AS `currency`'
				. ' FROM `docs_core_heads` `h`'
				. " WHERE {$whereSql}",
			...$params,
		) ?? [];

		$totalValue    = (float) ($totalRow['total_value'] ?? 0);
		$totalDocs     = (int) ($totalRow['total_docs'] ?? 0);
		$currencyCount = (int) ($totalRow['currency_count'] ?? 0);
		$totalCurrency = $this->currencyCode($totalRow['currency'] ?? null);

		$items = [];
		foreach ($rows as $r) {
			$rawValue = $isCount ? $r['doc_count'] : $r['measure_value'];
			$value    = (float) ($rawValue ?? 0);

			$items[] = [
				'ref'       => $dimension === 'partner' && $r['dim_key'] !== null
					? ['type' => 'person', 'id' => (int) $r['dim_key']]
					: null,
				'full_name' => $this->groupLabel($dimension, $r, $ctx),
				'value'     => $isCount ? (int) $value : number_format($value, 2, '.', ''),
				'currency'  => $isCount ? null : $this->currencyCode($r['currency'] ?? null),
				'doc_count' => (int) ($r['doc_count'] ?? 0),
				'share_pct' => $totalValue === 0.0 ? null : round($value / $totalValue * 100, 1),
			];
		}

		return [
			'summary' => $this->buildSummary(
				$arguments, $dimension, $measure, $order, $items,
				$totalValue, $totalDocs, $totalCurrency, $currencyCount,
			),
			'items'      => $items,
			'pagination' => [
				'limit'    => $limit,
				'offset'   => 0, // top-N žebříček se nestránkuje; klíč držíme kvůli tvaru obálky
				'returned' => count($items),
				'has_more' => $hasMore,
			],
		];
	}

	/**
	 * Whitelistovaný WHERE builder — shodná semantika filtrů jako
	 * `documents_search` (plus `fiscal_year` přes codebook).
	 *
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function buildWhere(array $arguments, McpInvocationContext $ctx): array
	{
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
		if (!empty($arguments['fiscal_year'])) {
			$where[]  = '`h`.`fiscal_year` = %i';
			$params[] = $this->resolveFiscalYear((string) $arguments['fiscal_year'], $ctx);
		}

		return [$where === [] ? '1' : implode(' AND ', $where), $params];
	}

	/**
	 * `fiscal_year` je FK do codebooku, ne číslo roku → resolve podle označení.
	 * Roků je v DS jednotky, takže jeden dotaz: seznam poslouží i do hlášky,
	 * ze které se model dokáže sám zotavit (controller ji mapuje na -32602).
	 */
	private function resolveFiscalYear(string $name, McpInvocationContext $ctx): int
	{
		$wanted = trim($name);
		$years  = $ctx->db->fetchAll(
			'SELECT `id`, `name` FROM `economy_codebooks_fiscal_years`'
				. ' WHERE `docState` != 90 ORDER BY `name` DESC',
		);

		foreach ($years as $y) {
			if ((string) $y['name'] === $wanted) {
				return (int) $y['id'];
			}
		}

		$available = implode(', ', array_map(static fn (array $y): string => (string) $y['name'], $years));

		throw new \InvalidArgumentException(
			"Fiskální rok '{$wanted}' v číselníku neexistuje."
			. ($available !== ''
				? " Dostupná označení: {$available}."
				: ' Číselník fiskálních roků je prázdný.'),
		);
	}

	/** Popisek skupiny; NULL klíč se nezahazuje, jen dostane „(nezařazeno)". */
	private function groupLabel(string $dimension, array $row, McpInvocationContext $ctx): string
	{
		if ($row['dim_key'] === null) {
			return self::UNASSIGNED_LABEL;
		}

		return match ($dimension) {
			'partner'      => trim((string) ($row['label_raw'] ?? '')) ?: '#' . (string) $row['dim_key'],
			'doc_type'     => $this->docTypeLabel((string) $row['dim_key'], $ctx),
			'fiscal_month' => $row['cal_year'] !== null && $row['cal_month'] !== null
				? sprintf('%04d-%02d', (int) $row['cal_year'], (int) $row['cal_month'])
				: self::UNASSIGNED_LABEL,
			'vat_period'   => trim((string) ($row['label_raw'] ?? '')) ?: '#' . (string) $row['dim_key'],
		};
	}

	/**
	 * Compiled config je per-jazyk, takže cfgItem name už je lokalizované.
	 * Bez configu fallback na surový kód.
	 */
	private function docTypeLabel(string $code, McpInvocationContext $ctx): string
	{
		$cfg = $ctx->config?->cfgItem('docs.core.docTypes');
		if (!is_array($cfg) || !isset($cfg[$code]['name'])) {
			return $code;
		}
		return (string) $cfg[$code]['name'];
	}

	/** `home_currency` drží kódy malými (`czk`); pro výstup velkými. */
	private function currencyCode(mixed $raw): ?string
	{
		$code = strtoupper(trim((string) ($raw ?? '')));
		return $code === '' ? null : $code;
	}

	/** @param array<int, array<string, mixed>> $items */
	private function buildSummary(
		array $arguments,
		string $dimension,
		string $measure,
		string $order,
		array $items,
		float $totalValue,
		int $totalDocs,
		?string $totalCurrency,
		int $currencyCount,
	): string {
		$count    = count($items);
		$dimLabel = match ($dimension) {
			'partner'      => 'partnerů',
			'doc_type'     => 'typů dokladů',
			'fiscal_month' => 'fiskálních měsíců',
			'vat_period'   => 'přiznání k DPH',
		};
		$measureLabel = match ($measure) {
			'total_base'   => 'podle základu bez DPH',
			'total_amount' => 'podle částky s DPH',
			'count'        => 'podle počtu dokladů',
		};

		$head = $order === 'measure_desc'
			? "Top {$count} {$dimLabel} {$measureLabel}"
			: "{$count} {$dimLabel} {$measureLabel} v přirozeném pořadí";

		$filters = [];
		if (!empty($arguments['doc_type'])) {
			$filters[] = (string) $arguments['doc_type'];
		}
		if (isset($arguments['partner'])) {
			$filters[] = 'partner #' . (int) $arguments['partner'];
		}
		if (!empty($arguments['fiscal_year'])) {
			$filters[] = 'rok ' . (string) $arguments['fiscal_year'];
		}
		if (!empty($arguments['accounting_date_from'])) {
			$filters[] = 'od ' . (string) $arguments['accounting_date_from'];
		}
		if (!empty($arguments['accounting_date_to'])) {
			$filters[] = 'do ' . (string) $arguments['accounting_date_to'];
		}
		$state = (string) ($arguments['state'] ?? 'active');
		if ($state === 'done') {
			$filters[] = 'jen V pořádku';
		} elseif ($state === 'all') {
			$filters[] = 'vč. smazaných';
		}
		if ($filters !== []) {
			$head .= ' (' . implode(', ', $filters) . ')';
		}

		$total = $measure === 'count'
			? "celkem {$totalDocs} dokladů"
			: 'celkem ' . $this->formatMoney($totalValue)
				. ($totalCurrency !== null ? ' ' . $totalCurrency : '')
				. " ({$totalDocs} dokladů)";

		$summary = "{$head}: {$total}";

		if ($order === 'measure_desc' && $items !== []) {
			$top     = $items[0];
			$topVal  = $measure === 'count'
				? $top['value'] . ' dokladů'
				: $this->formatMoney((float) $top['value'])
					. ($top['currency'] !== null ? ' ' . $top['currency'] : '');
			$topPct  = $top['share_pct'] !== null
				? ', ' . number_format((float) $top['share_pct'], 1, ',', ' ') . ' %'
				: '';
			$summary .= ", největší {$top['full_name']} ({$topVal}{$topPct})";
		}

		$summary .= '.';

		if ($currencyCount > 1) {
			$summary .= " POZOR: doklady mají {$currencyCount} různé domácí měny,"
				. ' součet je proto nespolehlivý.';
		}

		return $summary;
	}

	private function formatMoney(float $amount): string
	{
		return number_format($amount, 2, ',', ' ');
	}
}
