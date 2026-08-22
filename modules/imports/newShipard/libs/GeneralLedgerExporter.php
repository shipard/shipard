<?php

namespace imports\newShipard\libs;

/**
 * Export agregované hlavní knihy do minimálního `ReportResult`-kompatibilního
 * JSONu pro kontrolní diff proti novému Shipardu — Fáze 15 (M3).
 *
 * Kanonický kontrakt výstupu: `shpd:docs/reports.md` §7.4. Zpracování na cíli:
 * `bin/shpd-ds report-diff <tenhle-soubor> <new.json>`, kde `new.json` vyrobí
 * `bin/shpd-ds report-run economy.accounting.generalLedger` za TOTÉŽ období.
 *
 * Starý reportovací engine se neoživuje — shoda reportů se redukuje na shodu
 * agregovaného deníku per účet a období, protože novou stranu počítá vždy
 * tentýž builder nad novým deníkem (výsledovka i rozvaha derivují z týchž
 * detail řádků).
 *
 * Výpočet doslova zrcadlí novou stranu (`GeneralLedgerBuilder`,
 * `JournalReportSupport::aggregate()`, `ReportParamValidator::resolveRange()`):
 *
 *   - `opening`  = suma měsíců roku PŘED intervalem (vč. otevíracího období,
 *                  bez uzavíracího) — počáteční stavy jsou v deníku jako každé
 *                  jiné účtování, nic se nedopočítává,
 *   - `turnover` = suma měsíců intervalu,
 *   - `closing`  = opening + turnover,
 *   - `balance`  = md − d, SYROVĚ (žádné otáčení dle povahy účtu — obě strany
 *                  mají tutéž konvenci a diff porovnává md/d/balance zvlášť),
 *   - účet s nulovým opening i turnover se neemituje.
 *
 * ## Ordinály měsíců NEJSOU kalendářní měsíce
 *
 * `--month-from/--month-to` jsou pořadí BĚŽNÉHO fiskálního měsíce v roce
 * (1-based dle `start`) — přesně jako `monthFrom`/`monthTo` na nové straně
 * (`ReportParamValidator::resolveRange()`). U kalendářního fiskálního roku to
 * vychází na kalendářní měsíc, u posunutého ne; kalendářní podoba vybraného
 * intervalu se proto vypisuje do logu.
 *
 * ## Filtr stavu dokladu (prověřeno, viz níže)
 *
 * Do nového deníku (`economy_accounting_journal`) se účtuje VÝHRADNĚ doklad ve
 * stavu 40 „V pořádku" a odchod ze 40 jeho řádky deníku maže
 * (`DocsHeadsEventHandler`). Z mapy `DocsRunner::DOC_STATE_MAP_TARGET` na 40
 * míří staré stavy **4000 (Hotovo) a 8000 (V opravě)** — ty a jen ty tedy
 * mohou mít protějšek v novém deníku. Ostatní: 1000→10 a 1200→20 (koncept /
 * potvrzeno, nezaúčtováno), 4100→30 (storno s číslem, bez deníku), 9800 se
 * neimportuje vůbec. Export proto bere `docState IN (4000, 8000)`; cokoli
 * jiného by byl garantovaný falešný rozdíl.
 *
 * Prověřeno na zrcadlených zdrojových DS (2026-08, `--acc-ring=20,40`):
 * 33271805401633 (rok 2025), 2059760940246 (2025) a 68908901448295
 * (2019/2021/2023/2024/2025/2026, ~13–30 tis. řádků deníku ročně) mají
 * v deníku **výhradně řádky dokladů ve stavu 4000** — žádné 8000, 4100 ani
 * 9800, žádný řádek bez hlavičky. Filtr tedy na těchto zdrojích nic nezahazuje;
 * 8000 je v seznamu preventivně (mapuje se na 40 stejně jako 4000).
 * Rovněž tam **neexistuje jediný řádek v okruhu 40 (Zásoby)** — otevřený bod
 * PRD je pro tyhle DS zodpovězen: default `--acc-ring=20` nic neztrácí.
 *
 * Rozhodnutí se prověřuje znovu při každém běhu: summary tiskne histogram
 * řádků deníku roku per docState dokladu i per účetní okruh (počet + sumy
 * MD/DAL, s příznakem zahrnuto/vyloučeno) — když se na jiném DS ukáže
 * netriviální objem v jiném stavu nebo okruhu, je to vidět dřív, než se
 * začne řešit diff.
 *
 * Řádky deníku BEZ dohledatelné hlavičky (`document = 0` nebo smazaný doklad)
 * se ZAHRNUJÍ a hlásí v summary — tiše zahodit část deníku je horší než
 * viditelný rozdíl v diffu.
 *
 * Read-only vůči starému DS — třída obsahuje výhradně SELECTy. Není to import
 * runner: neběží přes ImportRunner/ImportContext (ty vyžadují HTTP config,
 * LocalIdMap a HttpClient). Stačí DB + Logger, a bez configu musí projít —
 * ImportApp ho proto odbočuje ještě před `ImportConfig::load()`.
 */
final class GeneralLedgerExporter
{
	/** `reportId` výstupu; nová strana má vlastní — diff shodu nevyžaduje (§7.4). */
	private const REPORT_ID = 'external.oldShipard.generalLedger';

	/**
	 * Staré stavy dokladu, jejichž řádky mohou mít protějšek v novém deníku
	 * (obojí → nový stav 40). Viz doc komentář třídy.
	 */
	private const JOURNAL_DOC_STATES = [4000, 8000];

	/** Smazaný záznam (e10 konvence) — účty s tímhle stavem nedávají labely. */
	private const DOC_STATE_DELETED = 9800;

	/** `fiscalmonths.fiscalType`: 0 běžné, 1 otevření, 2 uzavření. */
	private const FISCAL_TYPE_REGULAR = 0;
	private const FISCAL_TYPE_CLOSING = 2;

	/** Účetní okruh: 20 Výchozí, 40 Zásoby (nová strana zásoby zatím nevede). */
	private const DEFAULT_ACC_RING = 20;

	/** Label kontrolního součtu — shodný s novou stranou v češtině. */
	private const TOTAL_LABEL = 'Celkem';

	/** Tolerance kontrol vyrovnanosti; shodná s `ReportDiff::TOLERANCE`. */
	private const TOLERANCE = 0.005;

	/** Účty bez čísla (`accountId` prázdný) — nespárovatelné, jen se hlásí. */
	private array $unkeyed = ['count' => 0, 'md' => 0.0, 'd' => 0.0];

	public function __construct(
		private readonly \Shipard\CLI\Application $app,
		private readonly Logger $logger,
	) {}

	public function run(): bool
	{
		$year = $this->resolveFiscalYear();
		if ($year === null)
			return false;

		$months = $this->loadMonths((int) $year['ndx']);
		if ($months === [])
		{
			$this->logger->err("fiskální rok '{$year['name']}' nemá v e10doc_base_fiscalmonths žádná období.");
			return false;
		}

		$regular = array_values(array_filter(
			$months,
			static fn (array $m): bool => $m['fiscalType'] === self::FISCAL_TYPE_REGULAR,
		));
		if ($regular === [])
		{
			$this->logger->err("fiskální rok '{$year['name']}' nemá žádný běžný měsíc (fiscalType = 0).");
			return false;
		}

		$count = count($regular);
		$from  = $this->monthArg('month-from', 1, $count);
		$to    = $this->monthArg('month-to', $count, $count);
		if ($from === null || $to === null)
			return false;
		if ($from > $to)
		{
			$this->logger->err("--month-from ({$from}) je větší než --month-to ({$to}).");
			return false;
		}

		$accRings = $this->accRingsArg();
		if ($accRings === null)
			return false;

		[$beforeNdxs, $inRangeNdxs] = $this->splitMonths($months, $regular, $from, $to);

		$outPath = $this->outPath($year['name'], $from, $to);
		if (!$this->checkOutPath($outPath))
			return false;

		$this->logHeader($year, $regular, $from, $to, $accRings, $beforeNdxs, $inRangeNdxs);

		$opening  = $this->aggregate($beforeNdxs, $accRings);
		$turnover = $this->aggregate($inRangeNdxs, $accRings);

		$rows   = $this->buildRows($opening, $turnover);
		$result = $this->buildResult($year, $from, $to, $accRings, $rows);

		if (!$this->writeFile($outPath, $result))
			return false;

		$this->printDiagnostics($year, $months, $accRings);
		$this->printSummary($outPath, $rows, $year, $from, $to);
		return true;
	}

	// ── Fiskální rok a měsíce ────────────────────────────────────────────

	/**
	 * `--fiscal-year` → řádek `e10doc_base_fiscalyears`. Matchuje se PRIMÁRNĚ
	 * podle `fullName` (i ořezaného na 20 znaků — přesně to `FiscalYearsRunner`
	 * posílá do nového `economy_codebooks_fiscal_years.name`, a právě `name`
	 * bere `report-run --fiscal-year` na nové straně), teprve pak podle
	 * kalendářního roku začátku. Víc shod = tvrdá chyba, ne tichý první.
	 *
	 * @return array{ndx: int, name: string, start: ?string, end: ?string}|null
	 */
	private function resolveFiscalYear(): ?array
	{
		$arg = $this->app->arg('fiscal-year');
		$arg = is_string($arg) ? trim($arg) : '';
		if ($arg === '')
		{
			$this->logger->err('chybí povinný argument --fiscal-year=<název roku | kalendářní rok začátku>.');
			$this->logAvailableYears();
			return null;
		}

		$years = [];
		foreach ($this->db()->query(
			'SELECT [ndx], [fullName], [start], [end] FROM [e10doc_base_fiscalyears]',
			' WHERE [docState] != %i', self::DOC_STATE_DELETED,
			' ORDER BY [start]',
		)->fetchAll() as $r)
		{
			$row  = $this->toArray($r);
			$name = trim((string) ($row['fullName'] ?? ''));
			$years[] = [
				'ndx'   => (int) $row['ndx'],
				'name'  => $name,
				'start' => $this->dateToString($row['start'] ?? null),
				'end'   => $this->dateToString($row['end'] ?? null),
			];
		}

		if ($years === [])
		{
			$this->logger->err('v e10doc_base_fiscalyears není žádný fiskální rok.');
			return null;
		}

		// 1. přesná shoda názvu (i v podobě ořezané na 20 znaků jako na cíli)
		$byName = array_values(array_filter(
			$years,
			static fn (array $y): bool => $y['name'] === $arg || mb_substr($y['name'], 0, 20) === $arg,
		));
		if (count($byName) === 1)
			return $byName[0];
		if (count($byName) > 1)
		{
			$this->logger->err("--fiscal-year '{$arg}' odpovídá víc rokům — použij přesný název.");
			$this->logAvailableYears($years);
			return null;
		}

		// 2. fallback: kalendářní rok začátku
		if (preg_match('/^\d{4}$/', $arg))
		{
			$byStart = array_values(array_filter(
				$years,
				static fn (array $y): bool => $y['start'] !== null && substr($y['start'], 0, 4) === $arg,
			));
			if (count($byStart) === 1)
			{
				$this->logger->info("  --fiscal-year '{$arg}' = rok začínající {$byStart[0]['start']}"
					. " (název '{$byStart[0]['name']}').");
				return $byStart[0];
			}
			if (count($byStart) > 1)
			{
				$this->logger->err("v roce {$arg} začíná víc fiskálních roků — použij přesný název.");
				$this->logAvailableYears($years);
				return null;
			}
		}

		$this->logger->err("fiskální rok '{$arg}' neexistuje.");
		$this->logAvailableYears($years);
		return null;
	}

	/**
	 * Výpis dostupných roků do logu — bez něj uživatel neví, co má do
	 * `--fiscal-year` napsat (název se musí shodovat s novou stranou).
	 *
	 * @param array<int, array{ndx: int, name: string, start: ?string, end: ?string}>|null $years
	 */
	private function logAvailableYears(?array $years = null): void
	{
		if ($years === null)
		{
			$years = [];
			foreach ($this->db()->query(
				'SELECT [ndx], [fullName], [start], [end] FROM [e10doc_base_fiscalyears]',
				' WHERE [docState] != %i', self::DOC_STATE_DELETED,
				' ORDER BY [start]',
			)->fetchAll() as $r)
			{
				$row = $this->toArray($r);
				$years[] = [
					'ndx'   => (int) $row['ndx'],
					'name'  => trim((string) ($row['fullName'] ?? '')),
					'start' => $this->dateToString($row['start'] ?? null),
					'end'   => $this->dateToString($row['end'] ?? null),
				];
			}
		}

		if ($years === [])
			return;

		$this->logger->info('  dostupné fiskální roky:');
		foreach ($years as $y)
			$this->logger->info(sprintf("    '%s'  (%s … %s)",
				$y['name'], $y['start'] ?? '?', $y['end'] ?? '?'));
	}

	/**
	 * Měsíce roku v pořadí `start` — pořadí je nosné: ordinál běžného měsíce
	 * i hranice „před intervalem" se počítají právě z něj (jako na nové straně
	 * dle `date_begin`).
	 *
	 * @return array<int, array{ndx: int, fiscalType: int, calendarYear: int, calendarMonth: int, start: ?string}>
	 */
	private function loadMonths(int $yearNdx): array
	{
		$out = [];
		foreach ($this->db()->query(
			'SELECT [ndx], [fiscalType], [calendarYear], [calendarMonth], [start]'
			. ' FROM [e10doc_base_fiscalmonths]',
			' WHERE [fiscalYear] = %i', $yearNdx,
			' ORDER BY [start], [ndx]',
		)->fetchAll() as $r)
		{
			$row = $this->toArray($r);
			$out[] = [
				'ndx'           => (int) $row['ndx'],
				'fiscalType'    => (int) ($row['fiscalType'] ?? 0),
				'calendarYear'  => (int) ($row['calendarYear'] ?? 0),
				'calendarMonth' => (int) ($row['calendarMonth'] ?? 0),
				'start'         => $this->dateToString($row['start'] ?? null),
			];
		}
		return $out;
	}

	/**
	 * Rozdělení měsíců na „před intervalem" a „v intervalu" — doslovná replika
	 * `ReportParamValidator::resolveRange()`: before = vše po `start` až po
	 * první měsíc intervalu (tedy vč. otevíracího období), s defenzivním
	 * vyloučením uzavíracího období, které se dle data řadí na konec, ale
	 * spolehnout se na to nelze. Uzavírací období tak nevstupuje NIKAM
	 * (nová strana ho intervalem nenabízí).
	 *
	 * @param array<int, array<string, mixed>> $months  všechny měsíce roku dle start
	 * @param array<int, array<string, mixed>> $regular jen běžné, dle start
	 * @return array{0: array<int, int>, 1: array<int, int>}
	 */
	private function splitMonths(array $months, array $regular, int $from, int $to): array
	{
		$firstInRangeNdx = (int) $regular[$from - 1]['ndx'];

		$before = [];
		foreach ($months as $m)
		{
			if ((int) $m['ndx'] === $firstInRangeNdx)
				break;
			if ((int) $m['fiscalType'] === self::FISCAL_TYPE_CLOSING)
				continue;
			$before[] = (int) $m['ndx'];
		}

		$inRange = [];
		for ($i = $from - 1; $i <= $to - 1; $i++)
			$inRange[] = (int) $regular[$i]['ndx'];

		return [$before, $inRange];
	}

	// ── Agregace deníku ──────────────────────────────────────────────────

	/**
	 * Sumy MD/DAL per účet za dané fiskální měsíce — jediný dotaz nad denníkem,
	 * tvarově shodný s `JournalReportSupport::aggregate()` (tam `fiscal_month
	 * IN (…) GROUP BY account_number`). Filtr stavu dokladu viz doc komentář
	 * třídy; řádky bez hlavičky (`document = 0`, smazaný doklad) projdou.
	 *
	 * @param array<int, int> $monthNdxs
	 * @param array<int, int> $accRings
	 * @return array<string, array{md: float, d: float}>
	 */
	private function aggregate(array $monthNdxs, array $accRings): array
	{
		if ($monthNdxs === [])
			return [];

		$rows = $this->db()->query(
			'SELECT j.[accountId] AS account,'
			. ' SUM(j.[moneyDr]) AS md, SUM(j.[moneyCr]) AS d'
			. ' FROM [e10doc_debs_journal] j'
			. ' LEFT JOIN [e10doc_core_heads] h ON j.[document] = h.[ndx]'
			. ' WHERE j.[fiscalMonth] IN %in', $monthNdxs,
			' AND j.[accRing] IN %in', $accRings,
			' AND (h.[ndx] IS NULL OR h.[docState] IN %in', self::JOURNAL_DOC_STATES, ')',
			' GROUP BY j.[accountId]',
		)->fetchAll();

		$out = [];
		foreach ($rows as $r)
		{
			$row     = $this->toArray($r);
			$account = trim((string) ($row['account'] ?? ''));
			$md      = (float) ($row['md'] ?? 0);
			$d       = (float) ($row['d'] ?? 0);

			// Řádek bez čísla účtu nemá čím být spárovaný (ReportDiff klíčuje
			// detaily hodnotou `account`) — do výstupu nejde, ale nesmí zmizet
			// beze stopy: sečte se a vypíše v summary.
			if ($account === '')
			{
				$this->unkeyed['count']++;
				$this->unkeyed['md'] += $md;
				$this->unkeyed['d']  += $d;
				continue;
			}

			$out[$account] = ['md' => $md, 'd' => $d];
		}
		return $out;
	}

	/**
	 * Detail řádky + kontrolní `total`. Zaokrouhlování na stejných místech
	 * jako `GeneralLedgerBuilder` (per sloupec před testem na nulu, closing
	 * z už zaokrouhlených hodnot) — jinak by se strany rozešly na haléřích.
	 *
	 * @param array<string, array{md: float, d: float}> $opening
	 * @param array<string, array{md: float, d: float}> $turnover
	 * @return array<int, array<string, mixed>>
	 */
	private function buildRows(array $opening, array $turnover): array
	{
		$accounts = array_unique(array_merge(array_keys($opening), array_keys($turnover)));
		usort($accounts, static fn ($a, $b): int => strcmp((string) $a, (string) $b));

		$names = $this->loadAccountNames();

		$rows  = [];
		$total = ['opening' => ['md' => 0.0, 'd' => 0.0], 'turnover' => ['md' => 0.0, 'd' => 0.0]];

		foreach ($accounts as $account)
		{
			$account = (string) $account;
			$o = [
				'md' => round((float) ($opening[$account]['md'] ?? 0), 2),
				'd'  => round((float) ($opening[$account]['d'] ?? 0), 2),
			];
			$t = [
				'md' => round((float) ($turnover[$account]['md'] ?? 0), 2),
				'd'  => round((float) ($turnover[$account]['d'] ?? 0), 2),
			];

			// Účet bez pohybu do knihy nepatří (shodně s novou stranou).
			if ($o['md'] === 0.0 && $o['d'] === 0.0 && $t['md'] === 0.0 && $t['d'] === 0.0)
				continue;

			$total['opening']['md']  += $o['md'];
			$total['opening']['d']   += $o['d'];
			$total['turnover']['md'] += $t['md'];
			$total['turnover']['d']  += $t['d'];

			$rows[] = [
				'kind'    => 'detail',
				'level'   => 4,
				'account' => $account,
				'label'   => $names[$account] ?? $account,
				'values'  => $this->values($o, $t),
			];
		}

		// `total` je dle §7.4 volitelný; emitujeme ho jako kontrolní součet —
		// diff ho porovná jen když ho má i druhá strana (nová strana ho tvoří
		// se stejným labelem v české lokalizaci).
		if ($rows !== [])
			$rows[] = [
				'kind'    => 'total',
				'level'   => 0,
				'account' => null,
				'label'   => self::TOTAL_LABEL,
				'values'  => $this->values(
					['md' => round($total['opening']['md'], 2),  'd' => round($total['opening']['d'], 2)],
					['md' => round($total['turnover']['md'], 2), 'd' => round($total['turnover']['d'], 2)],
				),
			];

		return $rows;
	}

	/**
	 * Trojice sloupců jedné řádky; `closing` = opening + turnover, `balance`
	 * = md − d syrově (bez otáčení dle povahy účtu — obě strany stejně).
	 *
	 * @param array{md: float, d: float} $o
	 * @param array{md: float, d: float} $t
	 * @return array<string, array{md: float, d: float, balance: float}>
	 */
	private function values(array $o, array $t): array
	{
		$c = ['md' => round($o['md'] + $t['md'], 2), 'd' => round($o['d'] + $t['d'], 2)];

		return [
			'opening'  => $o + ['balance' => round($o['md'] - $o['d'], 2)],
			'turnover' => $t + ['balance' => round($t['md'] - $t['d'], 2)],
			'closing'  => $c + ['balance' => round($c['md'] - $c['d'], 2)],
		];
	}

	/**
	 * Číslo účtu → název z účtového rozvrhu (`fullName`, stejný sloupec, jaký
	 * `AccountsRunner` posílá do nového `name`). Chybějící → label = číslo.
	 * Labely diff neporovnává, jsou pro čitelnost výstupu.
	 *
	 * @return array<string, string>
	 */
	private function loadAccountNames(): array
	{
		$out = [];
		foreach ($this->db()->query(
			'SELECT [id], [fullName] FROM [e10doc_debs_accounts]',
			' WHERE [docState] != %i', self::DOC_STATE_DELETED,
		)->fetchAll() as $r)
		{
			$row    = $this->toArray($r);
			$number = trim((string) ($row['id'] ?? ''));
			$name   = trim((string) ($row['fullName'] ?? ''));
			if ($number !== '' && $name !== '')
				$out[$number] = $name;
		}
		return $out;
	}

	// ── Výstupní dokument ────────────────────────────────────────────────

	/**
	 * Minimální `ReportResult` dle `shpd:docs/reports.md` §7.4 — diff čte
	 * `columns` (průnik dle `id`), `rows` (`detail` dle `account`, `total` dle
	 * `label`) a `status`; zbytek je pro člověka a dohledatelnost.
	 *
	 * @param array{ndx: int, name: string, start: ?string, end: ?string} $year
	 * @param array<int, int> $accRings
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<string, mixed>
	 */
	private function buildResult(array $year, int $from, int $to, array $accRings, array $rows): array
	{
		return [
			'reportId'    => self::REPORT_ID,
			'params'      => [
				'fiscalYear' => $year['name'],
				'monthFrom'  => $from,
				'monthTo'    => $to,
				'accRing'    => array_values($accRings),
				'ds'         => $this->dsid(),
			],
			'generatedAt' => date('c'),
			'dataSource'  => $this->dsid(),
			'status'      => 'ok',
			'messages'    => [],
			'columns'     => [
				['id' => 'opening',  'type' => 'money', 'label' => 'Počáteční stav'],
				['id' => 'turnover', 'type' => 'money', 'label' => 'Obraty za období'],
				['id' => 'closing',  'type' => 'money', 'label' => 'Konečný zůstatek'],
			],
			'rows'        => array_values($rows),
		];
	}

	/**
	 * Zápis do `<out>.tmp` a až hotový soubor se přesune na cílovou cestu —
	 * přerušený běh nezůstane jako zdánlivě platný vstup pro `report-diff`.
	 * Pretty print: soubor je malý (stovky účtů) a body 2–3 ověření se čtou
	 * očima.
	 *
	 * @param array<string, mixed> $result
	 */
	private function writeFile(string $outPath, array $result): bool
	{
		$json = json_encode(
			$result,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			| JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION,
		);
		if ($json === false)
		{
			$this->logger->err('json_encode selhal: ' . json_last_error_msg());
			return false;
		}

		$tmpPath = $outPath . '.tmp';
		if (@file_put_contents($tmpPath, $json . "\n") === false)
		{
			$this->logger->err("nelze zapsat '{$tmpPath}' (zkontroluj práva k adresáři).");
			return false;
		}
		if (!@rename($tmpPath, $outPath))
		{
			$this->logger->err("nelze přesunout '{$tmpPath}' na '{$outPath}'.");
			@unlink($tmpPath);
			return false;
		}
		return true;
	}

	/** Cílová cesta: --output / --out, jinak `log/general-ledger-<ds>-<rok>-<od>-<do>.json`. */
	private function outPath(string $yearName, int $from, int $to): string
	{
		foreach (['output', 'out'] as $argName)
		{
			$arg = $this->app->arg($argName);
			if (is_string($arg) && trim($arg) !== '')
				return trim($arg);
		}

		return __APP_DIR__ . '/log/' . sprintf(
			'general-ledger-%s-%s-%02d-%02d.json',
			$this->slug($this->dsid()), $this->slug($yearName), $from, $to,
		);
	}

	/** Cesta musí být zapisovatelná a nesmí to být adresář; existující se přepíše. */
	private function checkOutPath(string $path): bool
	{
		if (is_dir($path))
		{
			$this->logger->err("--output '{$path}' je adresář, ne soubor.");
			return false;
		}

		$dir = dirname($path);
		if (!is_dir($dir))
		{
			$this->logger->err("adresář '{$dir}' neexistuje.");
			return false;
		}
		if (!is_writable($dir))
		{
			$this->logger->err("do adresáře '{$dir}' nelze zapisovat.");
			return false;
		}
		if (is_file($path))
			$this->logger->info("soubor '{$path}' už existuje — bude přepsán.");

		return true;
	}

	// ── Výstup do logu ───────────────────────────────────────────────────

	/**
	 * Co přesně se počítá — hlavně kalendářní podoba intervalu (ordinály
	 * běžných měsíců nejsou kalendářní měsíce, viz doc komentář třídy)
	 * a kolik období spadlo do `opening`.
	 *
	 * @param array{ndx: int, name: string, start: ?string, end: ?string} $year
	 * @param array<int, array<string, mixed>> $regular
	 * @param array<int, int> $accRings
	 * @param array<int, int> $beforeNdxs
	 * @param array<int, int> $inRangeNdxs
	 */
	private function logHeader(
		array $year, array $regular, int $from, int $to, array $accRings,
		array $beforeNdxs, array $inRangeNdxs,
	): void
	{
		$this->logger->info('Export hlavní knihy pro kontrolní diff (report-diff)…');
		$this->logger->info(sprintf("  fiskální rok: '%s' (%s … %s), běžných měsíců %d",
			$year['name'], $year['start'] ?? '?', $year['end'] ?? '?', count($regular)));
		$this->logger->info(sprintf('  interval: měsíce %d–%d = kalendářně %s … %s',
			$from, $to,
			$this->calendarLabel($regular[$from - 1]),
			$this->calendarLabel($regular[$to - 1])));
		$this->logger->info(sprintf('  opening: %d období před intervalem (vč. otevíracího, bez uzavíracího)',
			count($beforeNdxs)));
		$this->logger->info('  turnover: ' . count($inRangeNdxs) . ' období intervalu');
		$this->logger->info('  účetní okruh: ' . implode(', ', $accRings)
			. ', stavy dokladů: ' . implode('/', self::JOURNAL_DOC_STATES) . ' (+ řádky bez hlavičky)');
		$this->logger->info('  na nové straně odpovídá: bin/shpd-ds report-run economy.accounting.generalLedger'
			. " --fiscal-year='{$year['name']}' --month-from={$from} --month-to={$to}");
	}

	/** @param array<string, mixed> $month */
	private function calendarLabel(array $month): string
	{
		$y = (int) ($month['calendarYear'] ?? 0);
		$m = (int) ($month['calendarMonth'] ?? 0);
		if ($y > 0 && $m > 0)
			return sprintf('%04d-%02d', $y, $m);
		return (string) ($month['start'] ?? '?');
	}

	/**
	 * Prověření předpokladů exportu na živých datech — tiskne se při každém
	 * běhu, protože právě tohle jsou nejpravděpodobnější zdroje falešných
	 * rozdílů v diffu:
	 *   1. histogram řádků deníku roku per docState dokladu (co filtr pustil
	 *      a co ne, včetně částek) — doklad rozhodnutí z doc komentáře,
	 *   2. rozpad podle účetního okruhu (otevřený bod: má ring 40 na tomhle
	 *      DS vůbec řádky?),
	 *   3. řádky roku mimo jeho fiskální měsíce (fiscalMonth = 0 / cizí) —
	 *      ty se do exportu nedostanou ŽÁDNÝM intervalem.
	 *
	 * @param array{ndx: int, name: string, start: ?string, end: ?string} $year
	 * @param array<int, array<string, mixed>> $months
	 * @param array<int, int> $accRings
	 */
	private function printDiagnostics(array $year, array $months, array $accRings): void
	{
		$monthNdxs = array_map(static fn (array $m): int => (int) $m['ndx'], $months);

		$this->logger->summary('');
		$this->logger->summary('  prověření zdroje (celý rok, vybraný okruh):');

		$rows = $this->db()->query(
			'SELECT COALESCE(h.[docState], -1) AS docState, COUNT(*) AS cnt,'
			. ' SUM(j.[moneyDr]) AS md, SUM(j.[moneyCr]) AS d'
			. ' FROM [e10doc_debs_journal] j'
			. ' LEFT JOIN [e10doc_core_heads] h ON j.[document] = h.[ndx]'
			. ' WHERE j.[fiscalMonth] IN %in', $monthNdxs,
			' AND j.[accRing] IN %in', $accRings,
			' GROUP BY COALESCE(h.[docState], -1) ORDER BY 1',
		)->fetchAll();

		foreach ($rows as $r)
		{
			$row   = $this->toArray($r);
			$state = (int) $row['docState'];
			$in    = $state === -1 || in_array($state, self::JOURNAL_DOC_STATES, true);
			$this->logger->summary(sprintf(
				'    docState %-5s %s  %6d řádků, MD %14s, DAL %14s',
				$state === -1 ? '(bez hlavičky)' : (string) $state,
				$in ? '✓ v exportu ' : '✗ vyloučeno ',
				(int) $row['cnt'], $this->money($row['md']), $this->money($row['d']),
			));
		}

		$ringRows = $this->db()->query(
			'SELECT j.[accRing] AS accRing, COUNT(*) AS cnt,'
			. ' SUM(j.[moneyDr]) AS md, SUM(j.[moneyCr]) AS d'
			. ' FROM [e10doc_debs_journal] j'
			. ' WHERE j.[fiscalMonth] IN %in', $monthNdxs,
			' GROUP BY j.[accRing] ORDER BY 1',
		)->fetchAll();

		foreach ($ringRows as $r)
		{
			$row  = $this->toArray($r);
			$ring = (int) $row['accRing'];
			$this->logger->summary(sprintf(
				'    accRing  %-5d %s  %6d řádků, MD %14s, DAL %14s',
				$ring, in_array($ring, $accRings, true) ? '✓ v exportu ' : '✗ vyloučeno ',
				(int) $row['cnt'], $this->money($row['md']), $this->money($row['d']),
			));
		}

		$orphan = $this->toArray($this->db()->query(
			'SELECT COUNT(*) AS cnt, SUM([moneyDr]) AS md, SUM([moneyCr]) AS d'
			. ' FROM [e10doc_debs_journal]',
			' WHERE [fiscalYear] = %i', $year['ndx'],
			' AND [fiscalMonth] NOT IN %in', $monthNdxs,
		)->fetch() ?: []);

		if ((int) ($orphan['cnt'] ?? 0) > 0)
			$this->logger->warn(sprintf(
				'%d řádků deníku roku má fiscalMonth mimo jeho období (MD %s, DAL %s)'
				. ' — do exportu se nedostanou žádným intervalem.',
				(int) $orphan['cnt'], $this->money($orphan['md']), $this->money($orphan['d']),
			));
	}

	/**
	 * Souhrn + vnitřní kontroly, které si jinak musí uživatel dopočítat
	 * skriptem (bod 2 ověření v PRD): vyrovnanost obratů i konečných stavů.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param array{ndx: int, name: string, start: ?string, end: ?string} $year
	 */
	private function printSummary(string $outPath, array $rows, array $year, int $from, int $to): void
	{
		$details = array_values(array_filter($rows, static fn (array $r): bool => $r['kind'] === 'detail'));
		$total   = null;
		foreach ($rows as $r)
			if ($r['kind'] === 'total')
				$total = $r['values'];

		$this->logger->summary('');
		$this->logger->summary(sprintf(
			"✓ hlavní kniha: %d účtů, rok '%s', měsíce %d–%d.",
			count($details), $year['name'], $from, $to,
		));

		if ($total !== null)
		{
			foreach (['opening' => 'počáteční stavy', 'turnover' => 'obraty', 'closing' => 'konečné stavy'] as $col => $label)
			{
				$diff = (float) $total[$col]['balance'];
				$this->logger->summary(sprintf('  %s MD %14s, DAL %14s%s',
					$this->pad($label . ':', 16), $this->money($total[$col]['md']), $this->money($total[$col]['d']),
					abs($diff) > self::TOLERANCE ? '  ← NEVYROVNÁNO, rozdíl ' . $this->money($diff) : '  (vyrovnáno)'));
				if (abs($diff) > self::TOLERANCE)
					$this->logger->warn("deník není vyrovnaný ve sloupci '{$col}': MD − DAL = "
						. $this->money($diff) . ' — rozdíly v diffu můžou být chyba zdroje, ne importu.');
			}
		}

		if ($this->unkeyed['count'] > 0)
			$this->logger->warn(sprintf(
				'%d agregátů deníku nemá číslo účtu (MD %s, DAL %s) — nejsou ve výstupu'
				. ' (diff řádky bez `account` nespáruje).',
				$this->unkeyed['count'], $this->money($this->unkeyed['md']), $this->money($this->unkeyed['d']),
			));

		$size = @filesize($outPath);
		$this->logger->summary('  soubor: ' . $outPath
			. ($size !== false ? ' (' . $this->formatSize((int) $size) . ')' : ''));
		$this->logger->summary('  porovnání: bin/shpd-ds report-diff ' . $outPath . ' new.json');
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	private function db(): \Dibi\Connection { return $this->app->db(); }

	/**
	 * `--month-from` / `--month-to` jako ordinál běžného měsíce (1..$count).
	 * Chybějící = default (celý rok), nevalidní = tvrdá chyba: tiché sklouznutí
	 * na jiné období by v diffu vypadalo jako chyba importu.
	 */
	private function monthArg(string $name, int $default, int $count): ?int
	{
		$raw = $this->app->arg($name);
		if (!is_string($raw) || trim($raw) === '')
			return $default;

		$raw = trim($raw);
		if (!preg_match('/^\d+$/', $raw))
		{
			$this->logger->err("--{$name} '{$raw}' není číslo.");
			return null;
		}

		$value = (int) $raw;
		if ($value < 1 || $value > $count)
		{
			$this->logger->err("--{$name} = {$value} je mimo rozsah běžných měsíců roku (1–{$count}).");
			return null;
		}
		return $value;
	}

	/**
	 * `--acc-ring=20` / `--acc-ring=20,40`. Default jen 20 (Výchozí): nová
	 * strana skladové účtování zatím nevede, okruh 40 (Zásoby) by generoval
	 * falešné rozdíly na účtech 1xx/5xx. Kolik řádků v okruhu 40 na tomhle DS
	 * je, ukáže prověření v summary.
	 *
	 * @return array<int, int>|null
	 */
	private function accRingsArg(): ?array
	{
		$raw = $this->app->arg('acc-ring');
		if (!is_string($raw) || trim($raw) === '')
			return [self::DEFAULT_ACC_RING];

		$out = [];
		foreach (explode(',', $raw) as $part)
		{
			$part = trim($part);
			if ($part === '')
				continue;
			if (!preg_match('/^\d+$/', $part))
			{
				$this->logger->err("--acc-ring '{$raw}' — očekávám čísla oddělená čárkou (např. 20 nebo 20,40).");
				return null;
			}
			$out[] = (int) $part;
		}

		if ($out === [])
		{
			$this->logger->err('--acc-ring je prázdný.');
			return null;
		}
		return array_values(array_unique($out));
	}

	private function dsid(): string
	{
		$dsid = trim((string) $this->app->cfgItem('dsid', ''));
		return $dsid !== '' ? $dsid : basename(__APP_DIR__);
	}

	private function slug(string $value): string
	{
		$slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value);
		$slug = trim((string) $slug, '-');
		return $slug === '' ? 'x' : $slug;
	}

	/** Doplnění mezerami na šířku ve ZNACÍCH — sprintf %-Ns počítá bajty a české labely rozhodí. */
	private function pad(string $text, int $width): string
	{
		$len = mb_strlen($text, 'UTF-8');
		return $len >= $width ? $text : $text . str_repeat(' ', $width - $len);
	}

	private function money(mixed $value): string
	{
		return number_format((float) $value, 2, '.', ' ');
	}

	private function formatSize(int $bytes): string
	{
		if ($bytes < 1024)
			return $bytes . ' B';
		if ($bytes < 1024 * 1024)
			return round($bytes / 1024, 1) . ' kB';
		return round($bytes / (1024 * 1024), 1) . ' MB';
	}

	/** @return array<string, mixed> */
	private function toArray(mixed $row): array
	{
		if (is_object($row) && method_exists($row, 'toArray'))
			return $row->toArray();
		return (array) $row;
	}

	private function dateToString(mixed $date): ?string
	{
		if ($date === null)
			return null;
		if ($date instanceof \DateTimeInterface)
			return $date->format('Y-m-d');
		$s = (string) $date;
		if ($s === '' || str_starts_with($s, '0000-00-00'))
			return null;
		return substr($s, 0, 10);
	}
}
