<?php

namespace imports\newShipard\libs;

/**
 * Export agregované účetní historie přijatých faktur do souboru formátu
 * `shpd.economy.booking-history.v1` (JSONL) — Fáze 27.
 *
 * Kanonická specifikace formátu je na NOVÉ straně
 * (`shpd:docs/booking-history-format.md`); tady jen produkujeme soubor.
 * Zpracování: `shpd-ds booking-history --input=<file>` (report kvality
 * a taxonomie, seed pravidel IČO→štítek, reverzní otagování položek).
 *
 * Principy (tasks/27-booking-history-export.md):
 *   - Export = JEN FAKTA. Texty, účty, IČO, četnosti, částky, data. Žádné
 *     štítky, žádná znalost taxonomie nového Shipardu — přepočet novou verzí
 *     taxonomie nesmí vyžadovat nový export.
 *   - Degenerované texty (prázdné, shodné s názvem položky…) se NEFILTRUJÍ;
 *     jejich podíl je na nové straně metrika kvality zdroje (spec §4).
 *   - Read-only vůči starému DS — třída obsahuje výhradně SELECTy.
 *
 * Není to import runner: neběží přes ImportRunner/ImportContext, protože ty
 * vyžadují `import-newShipard.json` (HTTP config), LocalIdMap a HttpClient.
 * Export je lokální záležitost — stačí DB + Logger, a bez configu musí projít
 * (ImportApp ho proto odbočuje ještě před ImportConfig::load()).
 */
final class BookingHistoryExporter
{
	/**
	 * Typy dokladů v exportu. v1 napevno přijaté faktury; `--doc-types`
	 * (výdajové pokladní lístky a jim odpovídající filtr operací) je
	 * plánované rozšíření — proto konstanta, ne literál v dotazu.
	 */
	private const DOC_TYPES = ['invni'];

	/**
	 * Stav dokladu, který nese zaúčtovaná fakta: 4000 = „Hotovo" (v novém
	 * Shipardu „V pořádku"). ZÁMĚRNĚ jiný filtr než DocsRunner::sourceQuery(),
	 * který bere vše kromě smazaných (`docState != 9800`) — účetní historii
	 * reprezentují jen zaúčtované doklady. Koncepty (1000), potvrzené (1200),
	 * rozpracované v opravě (8000) ani storna (4100) do ní nepatří.
	 * Viz modules/e10doc/core/config/e10doc.core.heads.docStates.default.json.
	 */
	private const DOC_STATE_DONE = 4000;

	/**
	 * Řádková operace „Účetní položka" (e10.docs.operations). Ostatní operace
	 * na faktuře (zálohy, zaokrouhlení, DPH, majetek…) jsou pro účetní
	 * historii balast — nenesou vazbu text řádku ↔ účet položky.
	 * Shodná konstanta jako DocsRunner::ROW_OPERATION_MAP['invni'][1099998].
	 */
	private const ROW_OPERATION_ACC_ENTRY = 1099998;

	/** tableid pro e10_base_properties (IČO osoby) — jako v PersonsRunner. */
	private const PERSONS_TABLE_ID = 'e10.persons.persons';

	/** Interval progress výpisu v načtených řádcích. */
	private const PROGRESS_EVERY = 5000;

	/**
	 * Agregáty klíčované hashem čtveřice {companyId, account, itemCode,
	 * rowTextNorm}. Per klíč:
	 *   key       => [companyId, account, itemCode, itemName] (první výskyt)
	 *   docs      => set old ndx dokladů (distinct docCount)
	 *   rowCount  => počet řádků
	 *   amountSum => suma taxBaseHc, amountSeen => byla aspoň jedna nenull částka
	 *   firstDate / lastDate => účetní datum dokladu (YYYY-MM-DD)
	 *   texts     => originální varianta textu => četnost (nejčetnější → rowText)
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $agg = [];

	/** Lazy mapa osoba ndx → IČO (normalizované); null = ještě nenačteno. */
	private ?array $companyIdByPerson = null;

	public function __construct(
		private readonly \Shipard\CLI\Application $app,
		private readonly Logger $logger,
	) {}

	public function run(): bool
	{
		$from = $this->dateArg('from');
		$to   = $this->dateArg('to');

		$outPath = $this->outPath();
		if (!$this->checkOutPath($outPath))
			return false;

		$this->logger->info('Export účetní historie (booking-history.v1)…');
		$this->logger->info('  doklady: ' . implode(', ', self::DOC_TYPES)
			. ', docState = ' . self::DOC_STATE_DONE . ' (Hotovo)'
			. ($from !== null || $to !== null
				? ', období ' . ($from ?? '…') . ' … ' . ($to ?? '…')
				: ''));

		$this->aggregate($from, $to);

		$header = $this->buildHeader($from, $to, count($this->agg));
		if (!$this->writeFile($outPath, $header))
			return false;

		$this->printSummary($outPath, $header, $from, $to);
		return true;
	}

	// ── Agregace ─────────────────────────────────────────────────────────

	/**
	 * Streamovaný průchod s agregací v paměti. Počty distinct klíčů jsou řádově
	 * tisíce, ne miliony — mapa v paměti je v pohodě; v každém okamžiku se drží
	 * jen jedna dávka dokladů a jejich řádků.
	 *
	 * Keyset jde přes DOKLADY (`heads.ndx`), ne přes řádky, a řádky se dotahují
	 * druhým dotazem `r.document IN (…)`. Původní varianta (jeden JOIN + keyset
	 * `ORDER BY r.ndx LIMIT`) je past: optimizer řídí join od hlavičky
	 * a řazení řeší přes `Using temporary; Using filesort`, takže KAŽDÁ dávka
	 * přepočítá celý join znovu → kvadratická složitost (na DS s ~78 tis.
	 * doklady běh nedojel ani k první tisícovce řádků). Takto jsou oba dotazy
	 * index-friendly: hlavičky po PK, řádky přes index `s1 (document, …)`.
	 */
	private function aggregate(?string $from, ?string $to): void
	{
		$batch        = max(1, (int) ($this->app->arg('batch') ?? 500));
		$afterNdx     = 0;
		$rowsSeen     = 0;
		$docsSeen     = 0;   // doklady s aspoň jedním exportovaným řádkem
		$docsScanned  = 0;
		$nextProgress = self::PROGRESS_EVERY;

		while (true)
		{
			$heads = $this->fetchDocsBatch($from, $to, $afterNdx, $batch);
			if ($heads === [])
				break;

			$afterNdx     = (int) array_key_last($heads);
			$docsScanned += count($heads);

			$withRows = [];   // doklady této dávky, které mají exportovaný řádek
			foreach ($this->fetchRowsForDocs(array_keys($heads)) as $row)
			{
				$docNdx = (int) $row['document'];
				$head   = $heads[$docNdx];   // IN se skládá z týchž ndx → vždy existuje

				// Účetní datum a osobu (dodavatele) bere záznam z HLAVIČKY dokladu
				// (řádek má vlastní dateAccounting/person, ale ty nesou analytiku
				// řádku, ne identitu dokladu).
				$row['dateAccounting'] = $head['dateAccounting'];
				$row['person']         = $head['person'];

				$rowsSeen++;
				$withRows[$docNdx] = true;
				$this->addRow($row);
			}
			$docsSeen += count($withRows);

			unset($heads, $withRows);

			if ($rowsSeen >= $nextProgress)
			{
				$this->progress($rowsSeen, $docsSeen, count($this->agg), $docsScanned);
				$nextProgress = $rowsSeen + self::PROGRESS_EVERY;
			}
		}

		$this->logger->info(sprintf(
			'  načteno: %d řádků z %d dokladů (prohlédnuto %d) → %d agregovaných klíčů.',
			$rowsSeen, $docsSeen, $docsScanned, count($this->agg),
		));
	}

	/**
	 * Jedna dávka hlaviček dokladů — keyset přes PK, tedy bez filesortu
	 * i u velkých DS. Vrací mapu ndx → [dateAccounting, person], seřazenou
	 * podle ndx (kurzor bere poslední klíč).
	 *
	 * @return array<int, array{dateAccounting: mixed, person: mixed}>
	 */
	private function fetchDocsBatch(?string $from, ?string $to, int $afterNdx, int $batchSize): array
	{
		$q = array_merge(
			['SELECT h.[ndx], h.[dateAccounting], h.[person] FROM [e10doc_core_heads] h WHERE 1'],
			$this->docFilter($from, $to),
			[
				' AND h.[ndx] > %i', $afterNdx,
				' ORDER BY h.[ndx] LIMIT %i', $batchSize,
			],
		);

		$out = [];
		foreach ($this->db()->query($q)->fetchAll() as $r)
		{
			$row = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;
			$out[(int) $row['ndx']] = [
				'dateAccounting' => $row['dateAccounting'] ?? null,
				'person'         => $row['person'] ?? null,
			];
		}
		return $out;
	}

	/**
	 * Účetní řádky dávky dokladů. `r.document IN (…)` sedne na index
	 * `s1 (document, text)`; položka se přijoinuje kvůli kódu, názvu a účtu.
	 *
	 * @param array<int, int> $docNdxs
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchRowsForDocs(array $docNdxs): array
	{
		if ($docNdxs === [])
			return [];

		$rows = $this->db()->query(
			'SELECT r.[document], r.[text], r.[taxBaseHc],'
			. ' i.[id] AS itemCode, i.[fullName] AS itemName,'
			. ' i.[debsAccountId] AS account'
			. ' FROM [e10doc_core_rows] r'
			. ' LEFT JOIN [e10_witems_items] i ON r.[item] = i.[ndx]'
			. ' WHERE r.[document] IN %in', $docNdxs,
			' AND r.[operation] = %i', self::ROW_OPERATION_ACC_ENTRY,
			// V e10 je nevyplněný int FK 0, ne NULL — `> 0` pokrývá obojí
			// (zadání říká `item IS NOT NULL`, samo by balast nevyfiltrovalo).
			' AND r.[item] > %i', 0,
			' ORDER BY r.[document], r.[rowOrder], r.[ndx]',
		)->fetchAll();

		$out = [];
		foreach ($rows as $r)
			$out[] = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;
		return $out;
	}

	/**
	 * Zařazení jednoho zdrojového řádku do agregátu.
	 *
	 * @param array<string, mixed> $row
	 */
	private function addRow(array $row): void
	{
		$companyId = $this->companyIdOf((int) ($row['person'] ?? 0));
		$account   = $this->stringOrNull($row['account'] ?? null);
		$itemCode  = $this->stringOrNull($row['itemCode'] ?? null);
		$itemName  = $this->stringOrNull($row['itemName'] ?? null);

		// Text bereme jak je (i prázdný / degenerovaný) — filtruje až nová
		// strana, podíl degenerací je metrika kvality zdroje (spec §4).
		$rawText  = (string) ($row['text'] ?? '');
		$textNorm = $this->normalizeText($rawText);

		$key = md5(($companyId ?? "\x01") . "\x00" . ($account ?? "\x01") . "\x00"
			. ($itemCode ?? "\x01") . "\x00" . $textNorm);

		$date  = $this->dateToString($row['dateAccounting'] ?? null);
		$docNdx = (int) ($row['document'] ?? 0);

		if (!isset($this->agg[$key]))
		{
			$this->agg[$key] = [
				'companyId'  => $companyId,
				'account'    => $account,
				'itemCode'   => $itemCode,
				'itemName'   => $itemName,
				'docs'       => [],
				'rowCount'   => 0,
				'amountSum'  => 0.0,
				'amountSeen' => false,
				'firstDate'  => null,
				'lastDate'   => null,
				'texts'      => [],
			];
		}

		$a = &$this->agg[$key];
		$a['docs'][$docNdx] = true;
		$a['rowCount']++;

		// taxBaseHc = „Základ daně [MD]" (domácí měna) — tentýž sloupec, z něhož
		// domácí částky řádků čte DocsRunner. NULL do sumy nejde; totalAmount
		// zůstane null jen když se nedá spočíst vůbec nic.
		$amount = $row['taxBaseHc'] ?? null;
		if ($amount !== null && $amount !== '')
		{
			$a['amountSum'] += (float) $amount;
			$a['amountSeen'] = true;
		}

		if ($date !== null)
		{
			// YYYY-MM-DD → lexikografické porovnání = chronologické.
			if ($a['firstDate'] === null || $date < $a['firstDate'])
				$a['firstDate'] = $date;
			if ($a['lastDate'] === null || $date > $a['lastDate'])
				$a['lastDate'] = $date;
		}

		// Nejčetnější ORIGINÁLNÍ varianta textu (spec §4) — normalizovaná
		// podoba se do souboru neposílá, slouží jen jako klíč.
		if (!isset($a['texts'][$rawText]))
			$a['texts'][$rawText] = 0;
		$a['texts'][$rawText]++;

		unset($a);
	}

	/**
	 * Normalizace textu pro klíčování (spec §4, obě strany počítají stejně):
	 * trim → collapse whitespace → lowercase. ŽÁDNÉ odstraňování diakritiky,
	 * interpunkce ani čísel — normalizace je záměrně lehká, aby neslévala
	 * texty, které se účtují jinak.
	 */
	private function normalizeText(string $text): string
	{
		$t = preg_replace('/\s+/u', ' ', $text);
		if ($t === null)                      // nevalidní UTF-8 → bez /u modifikátoru
			$t = preg_replace('/\s+/', ' ', $text) ?? $text;
		return mb_strtolower(trim($t), 'UTF-8');
	}

	/**
	 * Nejčetnější varianta textu; při remíze první vložená (stabilní pořadí
	 * díky iteraci v pořadí vkládání). Prázdný string se vrací jak je —
	 * degenerovaný text je dle spec legální hodnota, ne důvod k null.
	 *
	 * @param array<string, int> $variants
	 */
	private function dominantText(array $variants): ?string
	{
		$best      = null;
		$bestCount = -1;
		foreach ($variants as $text => $count)
		{
			if ($count > $bestCount)
			{
				$best      = (string) $text;
				$bestCount = $count;
			}
		}
		return $best;
	}

	// ── Filtr dokladů (sdílený všemi dotazy) ─────────────────────────────

	/**
	 * Podmínky výběru dokladů — jeden zdroj pravdy pro průchod řádků, rozsah
	 * období v hlavičce i histogram domácí měny. Kdyby si každý dotaz skládal
	 * WHERE sám, mohla by se hlavička rozejít s daty.
	 *
	 * Předpokládá, že se připojuje za `WHERE 1` (nebo jiný předchozí predikát)
	 * a hlavička dokladu má alias `h`.
	 *
	 * @return array<int, mixed> fragmenty pro Dibi
	 */
	private function docFilter(?string $from, ?string $to): array
	{
		$q = [
			' AND h.[docType] IN %in', self::DOC_TYPES,
			' AND h.[docState] = %i', self::DOC_STATE_DONE,
		];
		if ($from !== null)
		{
			$q[] = ' AND h.[dateAccounting] >= %d';
			$q[] = $from;
		}
		if ($to !== null)
		{
			$q[] = ' AND h.[dateAccounting] <= %d';
			$q[] = $to;
		}
		return $q;
	}

	// ── Hlavička ─────────────────────────────────────────────────────────

	/**
	 * Hlavička souboru (řádek 1) dle spec §3. `recordCount` je znám, protože
	 * agregace proběhla celá do paměti před zápisem.
	 *
	 * @return array<string, mixed>
	 */
	private function buildHeader(?string $from, ?string $to, int $recordCount): array
	{
		[$dataFrom, $dataTo] = $this->dataDateRange($from, $to);

		return [
			'format'       => 'shpd.economy.booking-history',
			'version'      => 1,
			'sourceSystem' => ['name' => 'shipard-e10', 'version' => __E10_VERSION__],
			'sourceRef'    => $this->sourceRef(),
			// e10 variantu účtové osnovy nezná (osnova je volná tabulka) →
			// nová strana použije podnikatelskou nabídku a poznamená to v reportu.
			'chartVariant' => 'unknown',
			'currency'     => $this->homeCurrency($from, $to),
			'period'       => ['from' => $from ?? $dataFrom, 'to' => $to ?? $dataTo],
			'docTypes'     => array_values(self::DOC_TYPES),
			'exportedAt'   => date('c'),
			'recordCount'  => $recordCount,
		];
	}

	/**
	 * MIN/MAX účetního data vybraných dokladů — pro `period`, když uživatel
	 * `--from`/`--to` nezadal (per pole, ne all-or-nothing).
	 *
	 * @return array{0: ?string, 1: ?string}
	 */
	private function dataDateRange(?string $from, ?string $to): array
	{
		$q = array_merge(
			[
				'SELECT MIN(h.[dateAccounting]) AS minD, MAX(h.[dateAccounting]) AS maxD'
				. ' FROM [e10doc_core_heads] h WHERE 1',
			],
			$this->docFilter($from, $to),
		);
		$r = $this->db()->query($q)->fetch();
		if (!$r)
			return [null, null];

		$row = (array) $r;
		return [$this->dateToString($row['minD'] ?? null), $this->dateToString($row['maxD'] ?? null)];
	}

	/**
	 * Měna `totalAmount` = domácí měna vybraných dokladů, odvozená z dat
	 * (`heads.homeCurrency`), ne z nastavení — částky v souboru pocházejí
	 * z těchto dokladů. Víc variant = míchané sumy → nejčetnější + warn.
	 * Prázdný výběr → fallback fiskální rok (jako SettingsRunner) → 'CZK'.
	 */
	private function homeCurrency(?string $from, ?string $to): string
	{
		$q = array_merge(
			[
				'SELECT h.[homeCurrency] AS cur, COUNT(*) AS cnt'
				. ' FROM [e10doc_core_heads] h WHERE 1',
			],
			$this->docFilter($from, $to),
			[' GROUP BY h.[homeCurrency] ORDER BY cnt DESC'],
		);

		$variants = [];
		foreach ($this->db()->query($q)->fetchAll() as $r)
		{
			$row = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;
			$cur = strtoupper(trim((string) ($row['cur'] ?? '')));
			if ($cur !== '')
				$variants[$cur] = (int) $row['cnt'];
		}

		if ($variants === [])
			return $this->fiscalYearCurrency();

		if (count($variants) > 1)
		{
			$list = [];
			foreach ($variants as $cur => $cnt)
				$list[] = "{$cur}={$cnt}";
			$this->logger->warn('doklady mají víc domácích měn (' . implode(', ', $list)
				. ') — částky v exportu jsou míchané; hlavička uvádí nejčetnější.');
		}

		return (string) array_key_first($variants);
	}

	/**
	 * Fallback domácí měny z fiskálního roku (rok pokrývající dnešek, jinak
	 * poslední) — replika SettingsRunner::deriveFromFiscalYear(), jen velkými.
	 */
	private function fiscalYearCurrency(): string
	{
		$today = date('Y-m-d');
		$row = $this->db()->query(
			'SELECT [currency] FROM [e10doc_base_fiscalyears]',
			' WHERE [docState] != %i', 9800,
			' AND [start] <= %d', $today, ' AND [end] >= %d', $today,
			' ORDER BY [start] DESC',
		)->fetch();

		if (!$row)
			$row = $this->db()->query(
				'SELECT [currency] FROM [e10doc_base_fiscalyears]',
				' WHERE [docState] != %i', 9800,
				' ORDER BY [start] DESC',
			)->fetch();

		if (!$row)
			return 'CZK';

		$cur = strtoupper(trim((string) (((array) $row)['currency'] ?? '')));
		return $cur !== '' ? $cur : 'CZK';
	}

	/** Identifikace zdrojového DS pro report na nové straně: „<dsid> — <firma>". */
	private function sourceRef(): string
	{
		$dsid = trim((string) $this->app->cfgItem('dsid', ''));
		$name = $this->ownerCompanyName();

		if ($dsid !== '' && $name !== null)
			return $dsid . ' — ' . $name;
		if ($name !== null)
			return $name;
		if ($dsid !== '')
			return $dsid;
		return 'shipard-e10';
	}

	/**
	 * Název vlastní firmy — osoba z `options.core.ownerPerson` (stejný zdroj,
	 * jaký pro is_own používá PersonsRunner). Není-li nastavená, sourceRef
	 * zůstane jen na dsid.
	 */
	private function ownerCompanyName(): ?string
	{
		$ndx = (int) $this->app->cfgItem('options.core.ownerPerson', 0);
		if ($ndx <= 0)
			return null;

		$name = $this->db()->query(
			'SELECT [fullName] FROM [e10_persons_persons] WHERE [ndx] = %i', $ndx,
		)->fetchSingle();

		return $this->stringOrNull($name);
	}

	// ── IČO dodavatele ───────────────────────────────────────────────────

	/**
	 * IČO osoby (normalizované na číslice), nebo null. Načítá se jedním bulk
	 * dotazem pro všechny osoby — per-osoba dotaz by u desítek tisíc řádků
	 * znamenal desítky tisíc round tripů.
	 */
	private function companyIdOf(int $personNdx): ?string
	{
		if ($this->companyIdByPerson === null)
			$this->companyIdByPerson = $this->loadCompanyIds();

		return $personNdx > 0 ? ($this->companyIdByPerson[$personNdx] ?? null) : null;
	}

	/**
	 * Mapa osoba ndx → IČO. Zdroj i logika jako v PersonsRunner::loadProperties()
	 * (`e10_base_properties`, property 'oid', valueString, první po ndx).
	 * Normalizace: jen číslice — prázdné/nečíselné → záznam v mapě není
	 * (výsledek `companyId: null`, což je dle spec legální).
	 *
	 * @return array<int, string>
	 */
	private function loadCompanyIds(): array
	{
		$out = [];
		$rows = $this->db()->query(
			'SELECT [recid], [valueString] FROM [e10_base_properties]'
			. ' WHERE [tableid] = %s', self::PERSONS_TABLE_ID,
			' AND [property] = %s', 'oid',
			' ORDER BY [ndx] ASC',
		)->fetchAll();

		foreach ($rows as $r)
		{
			$row   = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;
			$recid = (int) ($row['recid'] ?? 0);
			if ($recid <= 0 || isset($out[$recid]))
				continue;   // first-wins po ndx (multi-value property)

			$oid = preg_replace('/\D+/', '', (string) ($row['valueString'] ?? ''));
			if ($oid !== null && $oid !== '')
				$out[$recid] = $oid;
		}

		$this->logger->debug('booking-history: načteno ' . count($out) . ' IČO z e10_base_properties.');
		return $out;
	}

	// ── Zápis souboru ────────────────────────────────────────────────────

	/**
	 * Zápis JSONL: hlavička + záznamy, UTF-8 bez BOM, LF. Píše se do `<out>.tmp`
	 * a až hotový soubor se přesune na cílovou cestu — přerušený běh tak
	 * nezůstane jako zdánlivě platný vstup pro `shpd-ds booking-history`.
	 *
	 * @param array<string, mixed> $header
	 */
	private function writeFile(string $outPath, array $header): bool
	{
		$tmpPath = $outPath . '.tmp';
		$fh = @fopen($tmpPath, 'wb');
		if ($fh === false)
		{
			$this->logger->err("nelze zapsat '{$tmpPath}' (zkontroluj práva k adresáři).");
			return false;
		}

		$ok = $this->writeLine($fh, $header, $tmpPath);

		foreach ($this->agg as $a)
		{
			if (!$ok)
				break;
			$ok = $this->writeLine($fh, [
				'companyId'   => $a['companyId'],
				'account'     => $a['account'],
				'itemCode'    => $a['itemCode'],
				'itemName'    => $a['itemName'],
				'rowText'     => $this->dominantText($a['texts']),
				'docCount'    => count($a['docs']),
				'rowCount'    => $a['rowCount'],
				'totalAmount' => $a['amountSeen'] ? round($a['amountSum'], 2) : null,
				'firstDate'   => $a['firstDate'],
				'lastDate'    => $a['lastDate'],
			], $tmpPath);
		}

		fclose($fh);

		if (!$ok)
		{
			@unlink($tmpPath);
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

	/**
	 * Jeden JSON objekt na řádek. JSON_UNESCAPED_UNICODE kvůli čitelnosti
	 * českých textů, JSON_INVALID_UTF8_SUBSTITUTE aby jediný poškozený text
	 * ze starých dat neshodil celý export (json_encode by vrátil false).
	 *
	 * @param resource            $fh
	 * @param array<string, mixed> $data
	 */
	private function writeLine($fh, array $data, string $path): bool
	{
		$json = json_encode(
			$data,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
		);
		if ($json === false)
		{
			$this->logger->err('json_encode selhal: ' . json_last_error_msg());
			return false;
		}
		if (@fwrite($fh, $json . "\n") === false)
		{
			$this->logger->err("zápis do '{$path}' selhal (místo na disku?).");
			return false;
		}
		return true;
	}

	/** Cílová cesta: --out, jinak `booking-history-<dsid>.jsonl` v cwd. */
	private function outPath(): string
	{
		$arg = $this->app->arg('out');
		if (is_string($arg) && trim($arg) !== '')
			return trim($arg);

		$dsid = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $this->app->cfgItem('dsid', ''));
		if ($dsid === null || $dsid === '' || $dsid === '-')
			$dsid = basename(__APP_DIR__);

		return getcwd() . '/booking-history-' . $dsid . '.jsonl';
	}

	/**
	 * Cesta musí být zapisovatelná a nesmí to být adresář; existující soubor
	 * se přepíše (export je opakovatelný a jeho vstup se nemění).
	 */
	private function checkOutPath(string $path): bool
	{
		if (is_dir($path))
		{
			$this->logger->err("--out '{$path}' je adresář, ne soubor.");
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
		// Opakovaný export je normální workflow (nová verze taxonomie na cíli
		// starý soubor nepotřebuje) → info, ne warn: přepsání není nic, co by
		// mělo končit v `.err` a v recapu.
		if (is_file($path))
			$this->logger->info("soubor '{$path}' už existuje — bude přepsán.");

		return true;
	}

	// ── Výstup ───────────────────────────────────────────────────────────

	private function progress(int $rows, int $docs, int $keys, int $docsScanned): void
	{
		$msg = sprintf('  … %d řádků, %d dokladů (z %d prohlédnutých), %d klíčů',
			$rows, $docs, $docsScanned, $keys);
		$this->logger->info($msg);
		$this->logger->progress($msg);
	}

	/**
	 * Souhrn: velikost souboru, metriky kvality (kolik záznamů je bez účtu /
	 * bez IČO — na nové straně to jsou právě ty, které nedají reverzní štítek
	 * ani seed pravidla) a kolik dokladů výběrem NEPROŠLO kvůli stavu. To
	 * poslední chytá „ve zdroji je 300 faktur ve stavu V opravě" ještě před
	 * tím, než někdo pustí seed pravidel naostro.
	 *
	 * @param array<string, mixed> $header
	 */
	private function printSummary(string $outPath, array $header, ?string $from, ?string $to): void
	{
		$noAccount = 0;
		$noCompany = 0;
		$rows      = 0;
		$docs      = 0;
		foreach ($this->agg as $a)
		{
			if ($a['account'] === null)   $noAccount++;
			if ($a['companyId'] === null) $noCompany++;
			$rows += $a['rowCount'];
			$docs += count($a['docs']);
		}

		$period = $header['period'];
		$this->logger->summary('');
		$this->logger->summary(sprintf(
			'✓ booking-history: %d záznamů (%d řádků, %d doklad-výskytů), období %s … %s, měna %s.',
			$header['recordCount'], $rows, $docs,
			$period['from'] ?? '?', $period['to'] ?? '?', $header['currency'],
		));
		$this->logger->summary(sprintf(
			'  kvalita zdroje: %d záznamů bez účtu, %d bez IČO.',
			$noAccount, $noCompany,
		));

		$skipped = $this->countDocsOutOfState($from, $to);
		if ($skipped > 0)
			$this->logger->summary(sprintf(
				'  mimo výběr: %d dokladů %s v období není ve stavu %d (Hotovo) — do historie nejdou.',
				$skipped, implode('/', self::DOC_TYPES), self::DOC_STATE_DONE,
			));

		$size = @filesize($outPath);
		$this->logger->summary('  soubor: ' . $outPath
			. ($size !== false ? ' (' . $this->formatSize((int) $size) . ')' : ''));
		$this->logger->summary('  zpracování na cíli: shpd-ds booking-history --input=' . $outPath);
	}

	/**
	 * Doklady zvolených typů v období, které NEJSOU ve stavu 4000 (a nejsou
	 * smazané) — informativní metrika, ne chyba.
	 */
	private function countDocsOutOfState(?string $from, ?string $to): int
	{
		$q = [
			'SELECT COUNT(*) FROM [e10doc_core_heads] h'
			. ' WHERE h.[docType] IN %in', self::DOC_TYPES,
			' AND h.[docState] NOT IN %in', [self::DOC_STATE_DONE, 9800],
		];
		if ($from !== null)
		{
			$q[] = ' AND h.[dateAccounting] >= %d';
			$q[] = $from;
		}
		if ($to !== null)
		{
			$q[] = ' AND h.[dateAccounting] <= %d';
			$q[] = $to;
		}

		return (int) $this->db()->query($q)->fetchSingle();
	}

	private function formatSize(int $bytes): string
	{
		if ($bytes < 1024)
			return $bytes . ' B';
		if ($bytes < 1024 * 1024)
			return round($bytes / 1024, 1) . ' kB';
		return round($bytes / (1024 * 1024), 1) . ' MB';
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	private function db(): \Dibi\Connection { return $this->app->db(); }

	/** Parse --from / --to jako YYYY-MM-DD; nevalidní → null s warningem. */
	private function dateArg(string $name): ?string
	{
		$raw = $this->app->arg($name);
		if (!is_string($raw) || $raw === '')
			return null;
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw))
		{
			$this->logger->warn("Invalid --{$name} date '{$raw}' (expected YYYY-MM-DD), ignoring.");
			return null;
		}
		return $raw;
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

	private function stringOrNull(mixed $value): ?string
	{
		if ($value === null)
			return null;
		$trimmed = trim((string) $value);
		return $trimmed === '' ? null : $trimmed;
	}
}
