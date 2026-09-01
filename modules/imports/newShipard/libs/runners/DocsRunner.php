<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\BaseExchangeRunner;
use imports\newShipard\libs\CrudClient;
use imports\newShipard\libs\ExchangeClient;
use imports\newShipard\libs\HttpException;
use imports\newShipard\libs\LocalIdMap;

/**
 * Import dokladů ze starého Shipardu (e10doc_core_heads + e10doc_core_rows)
 * do nového přes exchange formát shpd.docs.document.v1.
 *
 * Scope: faktury přijaté (invni), vydané (invno) a účetní doklady (cmnbkp).
 * Bankovní výpisy migruje samostatná fáze `bank-statements` (Fáze 11);
 * ostatní typy (pokladní, objednávky, dodací listy) jsou mimo scope —
 * viz tasks/05-docs.md.
 *
 * Klíčová zjištění (DocumentApplier):
 *   - Exchange formát je business-level — partner přes Party (business
 *     klíče), number_series / vat_registration / fiscal_* dopočítá applier.
 *   - selfParty=supplier|customer → applier volá PartyResolver::resolveSelfParty()
 *     (hledá is_own=1 firmu). Bez označené vlastní firmy resolve selže →
 *     pre-flight check v run().
 *   - autoCreateMode='safe' vytvoří partnera jen s company_id; jinak se
 *     doklad přeskočí (unresolved_required → 422). Známé omezení.
 *   - Import-mód čísla (Fáze 05b): klient pošle applyOptions.importNumber
 *     {docNumber, sequenceNumber}, applier zapíše číslo+sekvenci verbatim,
 *     přeskočí assignDocumentNumber/placeholder a synchronizuje counter přes
 *     GREATEST. Vlastní bank účet vydaných faktur jde v importOwnBankAccount.
 *     Obě směry se vkládají rovnou na cílový stav (žádný post-apply PATCH).
 */
final class DocsRunner extends BaseExchangeRunner
{
	/**
	 * Mapování starého docType → canonical docType. Faktury (invni/invno) +
	 * účetní doklady (cmnbkp). Přidání klíče sem ho automaticky zařadí do
	 * sourceQuery() i effectiveDateRange() (oboje řízené array_keys).
	 */
	private const DOC_TYPE_MAP = [
		'invni'  => 'invoiceReceived',
		'invno'  => 'invoiceIssued',
		'cmnbkp' => 'accountingDocument',
	];

	/**
	 * Směr dokladu — kdo jsme MY (selfParty). Partner je protistrana:
	 *   invni (faktura přijatá): my zákazník → selfParty=customer, partner=supplier
	 *   invno (faktura vydaná):  my dodavatel → selfParty=supplier, partner=customer
	 */
	private const SELF_PARTY_MAP = [
		'invni' => 'customer',
		'invno' => 'supplier',
	];

	/** taxCalc (hlavička) → canonical vat.mode (DocumentApplier::VAT_MODE_MAP). */
	private const VAT_MODE_MAP = [
		0 => 'none',       // nedaňový
		1 => 'fromBase',   // ze základu
		2 => 'fromTotal',  // z ceny celkem KOEF
		3 => 'fromTotal',  // z ceny celkem
	];

	/** taxType (hlavička) → canonical vat.place. */
	private const VAT_PLACE_MAP = [
		0 => 'domestic',      // tuzemsko
		1 => 'intracom',      // intrakomunitární
		2 => 'thirdCountry',  // zahraničí
	];

	/**
	 * Starý paymentMethod (e10.docs.paymentMethods, viz
	 * e10pro/install/docs-core/config/e10.docs.paymentMethods.json) → canonical
	 * payment.method. Pozor: starý 0 = "Převodním příkazem", 1 = "Hotově"
	 * (NE naopak). PayPal (11) i Platební brána (12) mapujeme na kartu — nemají
	 * bankovní účet, takže bankTransfer by byl zavádějící (a u přijatých faktur
	 * navíc applier u bankTransfer vyžaduje účet dodavatele —
	 * ReceivedInvoiceDocument). Ostatní starší kódy (Fakturou, Inkasem, Šekem, …)
	 * nemají přímý protějšek v novém formátu (cash/bankTransfer/card/
	 * cashOnDelivery/setOff) → fallback na bankTransfer. Neznámé → bankTransfer.
	 */
	private const PAYMENT_METHOD_MAP = [
		0  => 'bankTransfer',    // Převodním příkazem
		1  => 'cash',            // Hotově
		2  => 'card',            // Kartou
		3  => 'cashOnDelivery',  // Dobírka
		11 => 'card',            // PayPal (bez bankovního účtu → karta)
		12 => 'card',            // Platební brána (bez bankovního účtu → karta)
	];

	/**
	 * Staré kódy DPH, které nový Shipard nezná (vynechány při migraci
	 * vat-cz.json → vat-cz.jsonc): EUCZ000 = nedaňový řádek, EUCZ113 =
	 * artefakt zdroje. Mapujeme na null → řádek bez kódu DPH.
	 */
	private const VAT_CODE_DROP = ['EUCZ000', 'EUCZ113'];

	/**
	 * Mapování starého docState → nový cílový stav (Fáze 10). Nové stavy:
	 * 10 Koncept, 40 V pořádku, 30 Storno (NE 70 — to je „V archívu", pro
	 * doklady zrušeno; schema enum je [10, 40, 30, 80]).
	 * 8000 (V opravě) bereme jako finalizovaný doklad → 40 (zaúčtuje se).
	 * Staré 9800 (smazáno) je odfiltrováno už v sourceQuery. Neznámý stav =
	 * tvrdá chyba (fail dokladu), ne tichý default.
	 *
	 * Staré 1200 (Potvrzeno) v mapě NENÍ — nový Shipard stav Potvrzeno (20)
	 * zrušil a ostrý import běží nad zdrojem, kde už žádný takový doklad není
	 * (pre-flight, viz README). Výskyt = chyba dat → tvrdá chyba dokladu.
	 * Stav 80 (V opravě) v mapě taky není: je to výhradně parkovací strop
	 * nezaúčtovatelných cmnbkp (buildCmnbkpCanonical) a nová strana ho přes
	 * exchange přijme jen s applyOptions.importNumber.
	 */
	private const DOC_STATE_MAP_TARGET = [
		1000 => 10,   // Nově rozpracováno → Koncept (bez čísla)
		4000 => 40,   // Hotovo            → V pořádku (+ zaúčtování)
		4100 => 30,   // Stornováno        → Storno (s číslem, bez deníku)
		8000 => 40,   // V opravě          → V pořádku (finalizovat + zaúčtovat)
	];

	/**
	 * Stavy nesoucí přidělené číslo (řada + sekvence + importNumber). Mimo
	 * koncept (10), který číslo nemá. Sloučeno do predikátu místo „>= 20",
	 * ať to nedrhne o číselné uspořádání stavů (30 < 40 < 80).
	 */
	private const STATES_WITH_NUMBER = [30, 40, 80];

	/**
	 * Mapování starého řádkového pohybu (e10doc_core_rows.operation, číselné
	 * klíče e10.docs.operations) → nový string pohyb (docs.core.rowOperations).
	 * Per docType — staré klíče jsou docType-scoped. Stav 40 vyžaduje u každého
	 * item-řádku platný pohyb (DocDocument::validateRowOperations); bez něj
	 * doklad uvízne. acc.entry vyžaduje vyplněný item. Zálohové a majetkové
	 * operace tu nejsou — mají vlastní cestu s účtem (ROW_OPERATION_ACCOUNT_MAP).
	 */
	private const ROW_OPERATION_MAP = [
		'invni' => [
			1010102 => 'purchase.goods',     // Nákup zásob
			1010199 => 'purchase.goods',     // Nákup zásob bez evidence
			1099998 => 'acc.entry',          // Účetní položka
		],
		'invno' => [
			1010001 => 'sale.services',      // Prodej služeb
			1010002 => 'sale.goods',         // Prodej zásob
			1010099 => 'sale.goods',         // Prodej zásob bez evidence
			1090060 => 'sale.goods',         // Prodej majetku
			1099998 => 'acc.entry',          // Účetní položka
		],
	];

	/**
	 * Zálohové a majetkové operace → operační řádek (task 21, D3 + dodatek
	 * D8/D9). Řádek jde bez itemu (i kdyby byl vyplněný) a bez accSide —
	 * stranu určuje krok předpisu per operace. Účty:
	 *   - zálohy se NEPOSÍLAJÍ (D8/D10) — dohledá je kategorie předpisu
	 *     advances.given/received per-DS maskou (314/3149, 324/3249);
	 *     analytiky nejsou univerzální (msi 314001/314901, lefreal 314100/
	 *     314900), literály sem nepatří;
	 *   - majetek (assetAccount => true) má analytiku per řádek — dohledává
	 *     se ze starého deníku přes property (resolveAssetAccount, D9).
	 * paymentReference => true: párovací symbol zálohy ze symbol1.
	 */
	private const ROW_OPERATION_ACCOUNT_MAP = [
		'invni' => [
			1020101 => [   // Odpočet poskytnuté zálohy
				'operation' => 'purchase.advanceDeduction',
				'paymentReference' => true,
			],
			1020104 => [   // Zdanění poskytnuté zálohy
				'operation' => 'purchase.advanceVat',
				'paymentReference' => true,
			],
			1090050 => [   // Pořízení dlouhodobého majetku
				'operation' => 'purchase.asset', 'assetAccount' => true,
			],
			1090051 => [   // Technické zhodnocení majetku
				'operation' => 'purchase.asset', 'assetAccount' => true,
			],
			1090052 => [   // Nákup evidovaného majetku
				'operation' => 'purchase.asset', 'assetAccount' => true,
			],
		],
		'invno' => [
			1010101 => [   // Odpočet přijaté zálohy
				'operation' => 'sale.advanceDeduction',
				'paymentReference' => true,
			],
			1010104 => [   // Zdanění přijaté zálohy
				'operation' => 'sale.advanceVat',
				'paymentReference' => true,
			],
		],
	];

	/** Fallback pohyb pro op=0 / neznámý klíč (default řádku v novém configu). */
	private const ROW_OPERATION_DEFAULT = [
		'invni' => 'purchase.goods',
		'invno' => 'sale.services',
	];

	/**
	 * Saldokontní cmnbkp operace (e10doc_core_rows.operation), které ve starém
	 * Shipardu odvozovaly účet z kategorie (acc-default.json), ne z řádku. Na nové
	 * straně jim odpovídají operace acc.balanceReceivable / acc.balancePayable —
	 * účet dopočítá AccountingEngine z kategorie (311 / 321), strana + saldo
	 * identita (partner + symboly) jdou z řádku. Řádky tedy jdou bez účtu i položky.
	 */
	private const CMNBKP_BALANCE_OP = [
		1090001 => 'acc.balanceReceivable',  // Zápočet pohledávky (kategorie 311)
		1090002 => 'acc.balancePayable',     // Zápočet závazku    (kategorie 321)
	];

	/**
	 * Kurzové rozdíly saldokonta (D12): staré operace 1090011 (pohledávky)
	 * a 1090012 (závazky) → čtyři nové first-class operace (varianta A).
	 * Ztráta/zisk není v řádku — určuje se ze starého deníku per řádek
	 * (resolveFxDirection). saldoCat = maska saldo účtu pro vrstvu 2 lookupu.
	 * Účty dopočítá nová strana z kategorií (fx.loss 563 / fx.gain 663 +
	 * saldo 311/321); řádek jde bez účtu, s partnerem a paymentReference.
	 */
	private const CMNBKP_FX_OP = [
		1090011 => ['saldoCat' => '311', 'loss' => 'acc.fxLossReceivable', 'gain' => 'acc.fxGainReceivable'],
		1090012 => ['saldoCat' => '321', 'loss' => 'acc.fxLossPayable',    'gain' => 'acc.fxGainPayable'],
	];

	/**
	 * Majetkové cmnbkp operace (zařazení/oprávky/odpis/vyřazení) účtované ve
	 * starém Shipardu OBOUSTRANNĚ jedním řádkem (debit == credit, klíč
	 * property; acc-default: 1090070/73 MD 022 / DAL 042, 1090071 MD 08x /
	 * DAL 02x, 1090072 MD 551 / DAL 08x). Jednostranný import půlku zápisu
	 * ztrácel (nevyrovnané doklady, D15.2). Řádek se rozpadá na dva
	 * acc.record řádky s účty ze starého deníku per (document, property,
	 * částka) — vzor D9 (resolveAssetPairAccounts).
	 */
	private const CMNBKP_ASSET_PAIR_OPS = [1090070, 1090071, 1090072, 1090073];

	/**
	 * Hranice aktuálního časového úseku (chunkování). Když jsou nastaveny,
	 * sourceQuery() je preferuje před globálními --from/--to argumenty. Mezi
	 * úseky run() přepisuje a $rows uvolňuje — drží paměť na úrovni jednoho
	 * měsíce místo celého rozsahu.
	 */
	private ?string $chunkFrom = null;
	private ?string $chunkTo = null;

	/**
	 * Stav předaný z buildCanonical do processOneRow override. Tvrdá chyba ve
	 * stavbě dokladu nastaví $rejectReason a vrátí null; override ji povýší ze
	 * skipped/incomplete na 'failed' (hlasitě, ale import pokračuje). lastOld* /
	 * lastSeriesCode / lastSequence slouží souhrnné diagnostice per (docType, řada).
	 */
	private ?string $rejectReason   = null;
	private ?string $lastOldDocType = null;
	private ?string $lastSeriesCode = null;
	private ?int    $lastSequence   = null;

	/** Souhrn per "docType|docKeyId": imported / failed / maxSeq (diagnostika). */
	private array $seriesSummary = [];

	/**
	 * Souhrn původu DIČ partnera per docType: header (dobové personVATIN)
	 * / directory (fallback z karty osoby) / none (bez DIČ) + kolik z toho jsou
	 * doklady s nepinnutým partnerem. Odhad legitimních `vatKh.missingVatId`
	 * před ostrým re-importem (Task 29).
	 */
	private array $vatIdStats = [];

	/**
	 * Viděné klíče "docType|řada|rok|seq" → ['ndx' => první old ndx, 'count' => n]
	 * pro sufix pravých duplicit (D14-B): druhý a další výskyt klíče se
	 * importuje s docNumber sufixem '-2'/'-3'… a BEZ sekvence (sequence_number
	 * NULL v unq_series_seq nekoliduje; číslo mimo formuli se nesynchronizuje
	 * do čítače). Rok = kalendářní rok dateAccounting — shodně s odvozením
	 * fiscal_year na nové straně (oba DS mají kalendářní fiskální roky).
	 * Pozor: množina žije jen per běh — korektní pro plný re-import z čisté
	 * mapy; už namapované (přeskočené) doklady klíč neregistrují.
	 */
	private array $seenSeriesKeys = [];

	protected function entityType(): string   { return LocalIdMap::ENTITY_DOC; }
	protected function exchangeFlow(): string { return 'docs'; }
	protected function exchangeType(): string { return 'document'; }
	protected function savedIdKey(): string   { return 'savedDocId'; }
	protected function entityLabel(): string  { return 'document'; }

	/**
	 * Pre-flight: doklady používají selfParty resolution, která vyžaduje
	 * označenou vlastní firmu (is_own=1) v cílovém Shipardu. Bez ní by
	 * resolveSelfParty() selhal na každém dokladu.
	 */
	public function run(): bool
	{
		if (!$this->isDryRun() && !$this->hasOwnCompany())
		{
			$this->err("No own company (is_own=1) found in target Shipard.");
			$this->err("Documents use selfParty resolution which requires a flagged own company.");
			$this->err("Set it via UI or SQL:");
			$this->err("  UPDATE base_persons_persons SET is_own = 1 WHERE company_id = '<your-ICO>';");
			return false;
		}

		$this->info("Importing documents via exchange flow...");

		[$from, $to] = $this->effectiveDateRange();
		if ($from === null || $to === null)
		{
			$this->info("No documents to import (empty date range).");
			return true;
		}

		$chunkMonths = max(1, (int) ($this->app()->arg('chunk-months') ?? 1));
		$chunks = $this->monthlyChunks($from, $to, $chunkMonths);
		$this->info("Range {$from} … {$to} in " . count($chunks) . " chunk(s) of {$chunkMonths} month(s).");

		$exchange = new ExchangeClient($this->http());
		$stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
		$limit = (int) ($this->app()->arg('limit') ?? 0);

		foreach ($chunks as [$cFrom, $cTo])
		{
			$this->chunkFrom = $cFrom;
			$this->chunkTo   = $cTo;

			// --limit vyčerpán → další chunky nezpracovávat (jako dnes). $stats
			// je kumulativní přes chunky, processAllRows limit hlídá i uvnitř.
			if ($limit > 0 && array_sum($stats) >= $limit)
				break;

			// COUNT respektuje chunkFrom/chunkTo přes sourceQuery(); dávkové
			// čtení uvnitř chunku řeší sdílená smyčka (kurzor se resetuje per chunk).
			$this->info("— chunk {$cFrom} … {$cTo}: " . $this->countSourceRows() . " docs");
			if (!$this->processAllRows($exchange, $stats, $limit))
				return false;
		}

		$this->printSeriesSummary();
		$this->printVatIdSummary();
		$this->printDone($stats);
		return true;   // chyby řádků → exit code 2 přes Logger::errorCount()
	}

	/**
	 * Efektivní rozsah účetních dat k importu. Když uživatel nezadal
	 * --from/--to, vezme se MIN/MAX(dateAccounting) napříč fakturami; zadané
	 * argumenty rozsah jen ohraničí (max(from, dataMin) … min(to, dataMax)).
	 * Vrací [null, null] když nejsou žádné doklady nebo je průnik prázdný.
	 *
	 * @return array{0: ?string, 1: ?string}
	 */
	private function effectiveDateRange(): array
	{
		$r = $this->db()->query(
			'SELECT MIN(h.[dateAccounting]) AS minD, MAX(h.[dateAccounting]) AS maxD'
			. ' FROM [e10doc_core_heads] h'
			. ' WHERE h.[docState] != %i', 9800,
			' AND h.[docType] IN %in', array_keys(self::DOC_TYPE_MAP),
		)->fetch();

		$dataMin = $this->dateToString($r['minD'] ?? null);
		$dataMax = $this->dateToString($r['maxD'] ?? null);
		if ($dataMin === null || $dataMax === null)
			return [null, null];

		$argFrom = $this->dateArg('from');
		$argTo   = $this->dateArg('to');

		$from = ($argFrom !== null && $argFrom > $dataMin) ? $argFrom : $dataMin;
		$to   = ($argTo   !== null && $argTo   < $dataMax) ? $argTo   : $dataMax;

		// Lexikografické porovnání YYYY-MM-DD = chronologické.
		if ($from > $to)
			return [null, null];

		return [$from, $to];
	}

	/**
	 * Rozseká rozsah [from, to] na úseky o délce $months. Hranice jsou
	 * zarovnané na začátky měsíců (po prvním, případně částečném úseku);
	 * první úsek začíná přesně na $from, poslední končí na $to.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function monthlyChunks(string $from, string $to, int $months): array
	{
		$chunks = [];
		$cursor = new \DateTimeImmutable($from);
		$end    = new \DateTimeImmutable($to);

		while ($cursor <= $end)
		{
			$monthStart   = $cursor->modify('first day of this month');
			$nextBoundary = $monthStart->modify("+{$months} months");
			$chunkEnd     = $nextBoundary->modify('-1 day');
			if ($chunkEnd > $end)
				$chunkEnd = $end;

			$chunks[] = [$cursor->format('Y-m-d'), $chunkEnd->format('Y-m-d')];
			$cursor = $chunkEnd->modify('+1 day');
		}

		return $chunks;
	}

	private function hasOwnCompany(): bool
	{
		$crud = new CrudClient($this->http());
		try
		{
			return $crud->findOneBy('base_persons_persons', 'is_own', 1) !== null;
		}
		catch (HttpException $e)
		{
			// Filtr na is_own nemusí být podporován přes generic CRUD. Fallback:
			// nedáme abort kvůli nejistotě — vypíšeme warning a pustíme dál
			// (applier stejně selže jasnou chybou, pokud vlastní firma chybí).
			$this->warn("Could not verify own company via CRUD (HTTP {$e->statusCode}); proceeding, applier will enforce.");
			return true;
		}
	}

	protected function sourceAlias(): string { return 'h'; }

	protected function sourceQuery(): array
	{
		$docTypes = array_keys(self::DOC_TYPE_MAP);  // ['invni', 'invno', 'cmnbkp']

		$q = [
			'SELECT h.* FROM [e10doc_core_heads] h'
			. ' WHERE h.[docState] != %i', 9800,     // ne smazané
			' AND h.[docType] IN %in', $docTypes,     // podporované typy (DOC_TYPE_MAP)
		];

		// Filtr období na dateAccounting. Při chunkování run() nastaví
		// chunkFrom/chunkTo (úsek), které mají přednost před globálními
		// --from/--to argumenty.
		$from = $this->chunkFrom ?? $this->dateArg('from');
		$to   = $this->chunkTo   ?? $this->dateArg('to');
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

	/**
	 * Parse --from / --to CLI arg jako YYYY-MM-DD. Vrátí null pokud chybí
	 * nebo je nevalidní (s warningem).
	 */
	private function dateArg(string $name): ?string
	{
		$raw = $this->app()->arg($name);
		if (!is_string($raw) || $raw === '')
			return null;
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw))
		{
			$this->warn("Invalid --{$name} date '{$raw}' (expected YYYY-MM-DD), ignoring.");
			return null;
		}
		return $raw;
	}

	/**
	 * Popisek dokladu pro logy: docType + docNumber (lepší orientace než jen
	 * ndx, hlavně u failed řádků). Např. "invno 2024/0091".
	 */
	protected function rowDescriptor(array $oldRow): string
	{
		$docType   = trim((string) ($oldRow['docType'] ?? ''));
		$docNumber = trim((string) ($oldRow['docNumber'] ?? ''));

		return trim($docType . ' ' . $docNumber);
	}

	/**
	 * Obal nad BaseExchangeRunner::processOneRow. buildCanonical pro tvrdé chyby
	 * (neznámý stav, nedohledatelná řada, neparsovatelná sekvence, placeholder
	 * u stavu s číslem) nastaví $rejectReason a vrátí null — base to namapuje na
	 * skipped/incomplete. Tady to povýšíme na 'failed' (hlasitě + počítá se),
	 * ale BEZ vyhození výjimky, takže processRows pokračuje dalšími doklady
	 * (abort je jen v catch(HttpException)). Zároveň sbíráme souhrn per řada.
	 */
	protected function processOneRow(array $oldRow, ExchangeClient $exchange): array
	{
		$this->rejectReason   = null;
		$this->lastOldDocType = null;
		$this->lastSeriesCode = null;
		$this->lastSequence   = null;

		$result = parent::processOneRow($oldRow, $exchange);

		// Tvrdá chyba ve stavbě → failed (base ji vrací jako skipped/incomplete).
		// logRow(case 'failed') vypíše reason — neduplikujeme vlastním err().
		if ($this->rejectReason !== null && ($result['reason'] ?? null) === 'incomplete')
		{
			$this->recordSeries(
				$this->lastOldDocType ?? (string) ($oldRow['docType'] ?? '?'),
				$this->lastSeriesCode, null, 'failed');
			return ['status' => 'failed', 'reason' => $this->rejectReason];
		}

		// Souhrn: počítáme doklady, které se reálně (nebo v dry-runu „by se")
		// odeslaly. already-imported / soft-skip / incomplete ignorujeme.
		if ($this->lastOldDocType !== null)
		{
			$status = $result['status'];
			$reason = $result['reason'] ?? null;
			if ($status === 'created' || $status === 'updated'
				|| ($status === 'skipped' && $reason === 'dry-run'))
				$this->recordSeries($this->lastOldDocType, $this->lastSeriesCode,
					$this->lastSequence, 'imported');
		}

		return $result;
	}

	protected function buildCanonical(array $oldRow): ?array
	{
		$oldNdx     = (int) $oldRow['ndx'];
		$oldDocType = (string) ($oldRow['docType'] ?? '');

		$docType = self::DOC_TYPE_MAP[$oldDocType] ?? null;
		if ($docType === null)
		{
			$this->warn("doc {$oldNdx}: unsupported docType '{$oldDocType}', skipping");
			return null;
		}

		// Účetní doklad (cmnbkp) je strukturálně jiný — bez obchodního směru,
		// nepovinný hlavičkový partner, kontační řádky. Vlastní cesta; faktury
		// (níže) zůstávají beze změny.
		if ($oldDocType === 'cmnbkp')
			return $this->buildCmnbkpCanonical($oldRow);

		$selfParty = self::SELF_PARTY_MAP[$oldDocType] ?? null;

		// Faktura má vždy dvě strany. Bez partnera (interní/opravné doklady)
		// je faktura podezřelá → skip s warningem.
		$partnerNdx = (int) ($oldRow['person'] ?? 0);
		if ($partnerNdx <= 0)
		{
			$this->warn("doc {$oldNdx}: no partner (person=0), skipping");
			return null;
		}

		$partnerParty = $this->loadParty($partnerNdx);
		if ($partnerParty === null)
		{
			$this->warn("doc {$oldNdx}: partner person={$partnerNdx} not found, skipping");
			return null;
		}

		// Účet protistrany na dokladu (e10doc_core_heads.bankAccount, combo nad
		// personsBA) je per-doklad a autoritativní — preferujeme ho před prvním
		// účtem z karty osoby (ten je fallback z loadParty). U přijatých faktur
		// je to účet dodavatele, který applier vyžaduje (partner_bank).
		$headerBank = $this->parseBankAccountString($oldRow['bankAccount'] ?? null);
		if ($headerBank !== null)
			$partnerParty['bankAccount'] = $headerBank;

		// Dobové DIČ z hlavičky dokladu (personVATIN) přebíjí adresářové z karty
		// osoby. Je to táž hodnota, ze které četl starý VatCSEngine (A4/B2), a na
		// historickém dokladu jediná dobová pravda — nová strana z kanonické strany
		// partnera staví snapshot, ze kterého KH čte DIČ. Prázdné personVATIN
		// (osoba tehdy DIČ neměla) ponechává fallback z loadParty().
		$headerVatId = $this->emptyToNull($oldRow['personVATIN'] ?? null);
		$vatIdSource = 'none';
		if ($headerVatId !== null)
		{
			if ($partnerParty['vatId'] !== null && $partnerParty['vatId'] !== $headerVatId)
				$this->debug("doc {$oldNdx}: header VATIN '{$headerVatId}' differs from "
					. "directory '{$partnerParty['vatId']}', using header");
			$partnerParty['vatId'] = $headerVatId;
			$vatIdSource = 'header';
		}
		elseif ($partnerParty['vatId'] !== null)
			$vatIdSource = 'directory';

		// Partner jde do strany, kterou MY nejsme. Vlastní firma → selfParty flag.
		$supplier = $selfParty === 'supplier' ? null : $partnerParty;
		$customer = $selfParty === 'customer' ? null : $partnerParty;

		// loadRows vrací null = řádek s penězi bez mapované operace; rejectReason
		// už je nastavený → doklad failne (hlasitě), ne soft-skip.
		$loaded = $this->loadRows($oldNdx, $oldDocType);
		if ($loaded === null)
			return null;
		$rows = $loaded['rows'];
		if ($rows === [])
		{
			$this->warn("doc {$oldNdx}: no rows, skipping");
			return null;
		}

		// Od tohoto bodu doklad „stavíme" — případné tvrdé chyby níže nastaví
		// $this->rejectReason a vrátí null; processOneRow je povýší na 'failed'
		// (hlasitě, ale import pokračuje). Soft-skipy výše ($rejectReason
		// nesahnou) zůstávají skipped/incomplete.
		$this->lastOldDocType = $oldDocType;

		// Cílový stav z mapy (Fáze 10). Neznámý starý stav = tvrdá chyba.
		// --target-state=10 je globální strop „vše jako koncept" (testovací).
		$oldState    = (int) ($oldRow['docState'] ?? 0);
		$targetState = $this->targetDocState($oldState);
		if ($targetState === null)
		{
			$this->rejectReason = "unknown old docState {$oldState} (not in DOC_STATE_MAP_TARGET)";
			return null;
		}

		// Placeholder „!…" = doklad bez přiděleného čísla (typicky starý koncept).
		// Nikdy nejde do importNumber ani partner_doc_number. U stavu nesoucího
		// číslo je placeholder data-integrity chyba → fail.
		$rawNumber     = $this->emptyToNull($oldRow['docNumber'] ?? null);
		$isPlaceholder = $rawNumber !== null && str_starts_with($rawNumber, '!');
		$docNumber     = $isPlaceholder ? null : $rawNumber;

		$hasNumber = $this->stateHasNumber($targetState);
		if ($isPlaceholder && $hasNumber)
		{
			$this->rejectReason = "placeholder number '{$rawNumber}' but target state "
				. "{$targetState} requires a real number";
			return null;
		}

		// Vydané faktury (invno) potřebují při stavu s číslem vlastní bank účet.
		// Bez dohledatelného účtu degradují na koncept (10) + warning — má přednost
		// před stavovou mapou (existující chování Fáze 05b).
		$ownBankId = null;
		if ($oldDocType === 'invno' && $hasNumber)
		{
			$ownBankId = $this->resolveOwnBankAccount($oldRow);
			if ($ownBankId === null)
			{
				$this->warn("doc {$oldNdx}: own bank account unresolved (myBankAccount); "
					. "importing issued invoice as draft (docState 10)");
				$targetState = 10;
				$hasNumber   = false;
			}
		}

		// Číselná řada + pořadí — jen pro stavy nesoucí číslo. Nedohledatelná řada
		// (chybějící docKeyId cfg) = tvrdá chyba; neshoda čísla s přilinkovanou
		// řadou zkouší fallback přes ostatní řady docTypu (D13). Duplicitní klíč
		// (řada, rok, sekvence) vrátí docNumber se sufixem a sekvenci null (D14-B).
		$seriesCode = null;
		$sequence   = null;
		if ($hasNumber)
		{
			$ss = $this->resolveSeriesAndSequence($oldRow, $docNumber, $targetState);
			if ($ss === null)
				return null;
			$seriesCode = $ss['code'];
			$sequence   = $ss['seq'];
			$docNumber  = $ss['docNumber'];
		}

		$this->lastSeriesCode = $seriesCode;
		$this->lastSequence   = $sequence;

		$applyOptions = [
			'targetDocState'        => $targetState,
			'autoCreateMode'        => 'safe',
			'createMissingEntities' => true,
			'rejectOnIssues'        => ['error'],
		];

		// Řadu + číslo posíláme jen u stavů nesoucích číslo (docNumber zaručeně
		// neprázdný — jinak doklad výše failnul; sequenceNumber je null jen
		// u D14-B duplicit → nová strana uloží číslo bez sekvence a nebumpne
		// čítač). Koncept (10) jde bez čísla; applier ho přidělí standardně
		// při ručním povýšení.
		if ($hasNumber)
		{
			$applyOptions['numberSeriesCode'] = $seriesCode;
			$applyOptions['importNumber'] = [
				'docNumber'      => $docNumber,
				'sequenceNumber' => $sequence,
			];
		}
		if ($ownBankId !== null)
			$applyOptions['importOwnBankAccount'] = $ownBankId;

		// partner_doc_number = "číslo od partnera". U vydaných faktur je docNumber
		// naše číslo (jde do doc_number přes importNumber) → top-level null.
		// U přijatých faktur staré docNumber zůstává naše evidenční. Placeholder
		// (docNumber===null) → null v obou směrech.
		$partnerDocNumber = ($oldDocType === 'invno' || $docNumber === null) ? null : $docNumber;

		$canonical = [
			'format'        => 'shpd.docs.document',
			'formatVersion' => '1.0',

			'source' => [
				'kind' => 'import.oldShipard',
				'raw'  => ['oldNdx' => $oldNdx],
			],

			'docType'   => $docType,
			'docNumber' => $partnerDocNumber,
			'docText'   => $this->emptyToNull($oldRow['title'] ?? null),
			'selfParty' => $selfParty,

			'supplier' => $supplier,
			'customer' => $customer,

			'dates' => [
				'issueDate'         => $this->dateToString($oldRow['dateIssue'] ?? null),
				'dueDate'           => $this->dateToString($oldRow['dateDue'] ?? null),
				'accountingDate'    => $this->dateToString($oldRow['dateAccounting'] ?? null),
				'taxPointDate'      => $this->dateToString($oldRow['dateTax'] ?? null),
				'vatObligationDate' => $this->dateToString($oldRow['dateTaxDuty'] ?? null),
				'periodFrom'        => $this->dateToString($oldRow['datePeriodBegin'] ?? null),
				'periodTo'          => $this->dateToString($oldRow['datePeriodEnd'] ?? null),
			],

			'currency'     => $this->currencyUpper($oldRow['currency'] ?? null),
			'exchangeRate' => $this->positiveOrNull($oldRow['exchangeRate'] ?? null),

			'vat' => [
				'mode'                => self::VAT_MODE_MAP[(int) ($oldRow['taxCalc'] ?? 1)] ?? 'fromBase',
				'place'               => self::VAT_PLACE_MAP[(int) ($oldRow['taxType'] ?? 0)] ?? 'domestic',
				'registrationCountry' => $this->countryCode($oldRow['taxCountry'] ?? null),
			],

			'payment' => [
				'method'         => self::PAYMENT_METHOD_MAP[(int) ($oldRow['paymentMethod'] ?? 1)] ?? 'bankTransfer',
				'paymentReference' => $this->emptyToNull($oldRow['symbol1'] ?? null),
				'specificSymbol' => $this->emptyToNull($oldRow['symbol2'] ?? null),
				'constantSymbol' => null,
			],

			'notes' => [
				'internal'   => null,
				'onDocument' => null,
			],

			'rows' => $rows,

			'totals' => [
				'totalBase'     => $this->moneyOrNull($oldRow['sumBase'] ?? null),
				'totalVat'      => $this->moneyOrNull($oldRow['sumTax'] ?? null),
				'totalAmount'   => $this->moneyOrNull($oldRow['sumTotal'] ?? null),
				'totalRounding' => $this->moneyOrNull($oldRow['rounding'] ?? null),
			],

			'applyOptions' => $applyOptions,
		];

		// Pinning přes LocalIdMap (staré ndx → nové id) → applier použije přesný
		// migrovaný záznam místo dohledávání podle business klíčů. Nezbytné, když
		// se stejnojmenné osoby/položky už neslučují (matchStrategy=identifiersOnly):
		// partner-FO i řádkové položky by jinak byly nejednoznačné.
		//   - rows: kompletní pozičně zarovnané pole (i placeholdery) — applier
		//     čte clientResolve.rows[i] podle pozice, výpadek by posunul indexy.
		//   - partner: strana, kterou MY nejsme (customer u invno, supplier u invni).
		$resolve = $loaded['resolve'] !== [] ? ['rows' => $loaded['resolve']] : [];
		$partnerNewId = $this->idMap()->lookup(LocalIdMap::ENTITY_PERSON, $partnerNdx);
		if ($partnerNewId !== null)
		{
			$partnerSide = $selfParty === 'supplier' ? 'customer' : 'supplier';
			$resolve[$partnerSide] = ['userAction' => 'useExisting:' . $partnerNewId];
		}
		if ($resolve !== [])
			$canonical['_resolve'] = $resolve;

		// Statistika původu DIČ až tady — doklad je hotový (soft-skipy i tvrdé
		// chyby výše se do souhrnu nepočítají). Nepinnutý partner se sleduje zvlášť:
		// tam vatId slouží na nové straně i jako business klíč dohledání osoby.
		$this->recordVatIdSource($oldDocType, $vatIdSource, $partnerNewId === null);

		return $canonical;
	}

	/**
	 * Účetní doklad (cmnbkp) → canonical accountingDocument. Liší se od faktur:
	 *   - žádný obchodní směr (selfParty/supplier/customer = null),
	 *   - hlavičkový partner je nepovinný (saldo identita žije per řádek) —
	 *     když je vyplněn, předá se jen jako pin přes LocalIdMap,
	 *   - řádky jsou kontace (účet + strana MD/DAL + částka + per-řádková
	 *     saldo identita), ne položky — viz loadCmnbkpRows().
	 *
	 * Číselná řada + sekvence + stav jdou stejnou cestou jako faktury
	 * (resolveNumberSeriesCode přes dbCounter, parseSequenceNumber, targetDocState,
	 * importNumber). Vlastní bank účet (invno) se cmnbkp netýká.
	 */
	private function buildCmnbkpCanonical(array $oldRow): ?array
	{
		$oldNdx = (int) $oldRow['ndx'];

		// Hlavičkový partner nepovinný. Když je (person>0), pin přes LocalIdMap;
		// bez hitu v mapě zůstává null (pin-only — applier neprovádí side-create).
		$headPartnerNdx = (int) ($oldRow['person'] ?? 0);
		$headPartnerNewId = $headPartnerNdx > 0
			? $this->idMap()->lookup(LocalIdMap::ENTITY_PERSON, $headPartnerNdx)
			: null;

		// Od tohoto bodu doklad „stavíme" — tvrdé chyby níže (včetně loadu řádků)
		// nastaví rejectReason a processOneRow je povýší na 'failed' (hlasitě,
		// import pokračuje).
		$this->lastOldDocType = 'cmnbkp';

		$loaded = $this->loadCmnbkpRows($oldNdx);
		if ($loaded === null)
			return null;   // rejectReason nastaven (D12/D3d)
		$rows = $loaded['rows'];
		$droppedTotal = (float) ($loaded['droppedTotal'] ?? 0);
		if ($rows === [])
		{
			$this->warn("doc {$oldNdx}: no accounting rows, skipping");
			return null;
		}

		// Cílový stav z mapy (sdílená s fakturami). Neznámý starý stav = tvrdá chyba.
		$oldState    = (int) ($oldRow['docState'] ?? 0);
		$targetState = $this->targetDocState($oldState);
		if ($targetState === null)
		{
			$this->rejectReason = "unknown old docState {$oldState} (not in DOC_STATE_MAP_TARGET)";
			return null;
		}

		// Neúčtovatelné operace (majetek/vadné řádky bez účtu) — doklad se
		// naimportuje kompletní (s číslem i řádky), ale nezaúčtuje: strop stavu na
		// 80 (V opravě). Žádná tvrdá chyba, žádné tiché rozbití ve stavu 40.
		// 80 ∈ STATES_WITH_NUMBER, takže číslo + řada se přidělí jako jindy —
		// a je to zároveň podmínka přijetí: nová strana povolí targetDocState 80
		// jen s applyOptions.importNumber (parkovací stav je čistě migrační).
		// Storno (30) se necapuje (D15.4): nic neúčtuje a validace účtů/vyrovnanosti
		// (AccountingDocument::validateBalance) běží až při stavu 40 — řádky bez
		// účtu projdou beze změn. Do podmínky tak vstupuje jen stav 40
		// (--target-state=10 se vyhodnotí dřív v targetDocState()).
		if (($loaded['hasNonAccountable'] ?? false) && $targetState > 30)
		{
			$this->warn("doc {$oldNdx}: contains non-accountable operation(s) (asset/other), "
				. "parking as Being edited (80), not posted");
			$targetState = 80;
		}

		// Placeholder „!…" = doklad bez přiděleného čísla. U stavu nesoucího číslo
		// je to data-integrity chyba → fail (stejně jako u faktur).
		$rawNumber     = $this->emptyToNull($oldRow['docNumber'] ?? null);
		$isPlaceholder = $rawNumber !== null && str_starts_with($rawNumber, '!');
		$docNumber     = $isPlaceholder ? null : $rawNumber;

		$hasNumber = $this->stateHasNumber($targetState);
		if ($isPlaceholder && $hasNumber)
		{
			$this->rejectReason = "placeholder number '{$rawNumber}' but target state "
				. "{$targetState} requires a real number";
			return null;
		}

		// Číselná řada + pořadí — jen pro stavy nesoucí číslo. Nedohledatelná řada
		// (chybějící docKeyId cfg) = tvrdá chyba; neshoda čísla s přilinkovanou
		// řadou zkouší fallback přes ostatní řady docTypu (D13). Duplicitní klíč
		// (řada, rok, sekvence) vrátí docNumber se sufixem a sekvenci null (D14-B).
		$seriesCode = null;
		$sequence   = null;
		if ($hasNumber)
		{
			$ss = $this->resolveSeriesAndSequence($oldRow, $docNumber, $targetState);
			if ($ss === null)
				return null;
			$seriesCode = $ss['code'];
			$sequence   = $ss['seq'];
			$docNumber  = $ss['docNumber'];
		}

		$this->lastSeriesCode = $seriesCode;
		$this->lastSequence   = $sequence;

		$applyOptions = [
			'targetDocState'        => $targetState,
			'autoCreateMode'        => 'safe',
			'createMissingEntities' => true,
			'rejectOnIssues'        => ['error'],
		];
		if ($hasNumber)
		{
			$applyOptions['numberSeriesCode'] = $seriesCode;
			$applyOptions['importNumber'] = [
				'docNumber'      => $docNumber,
				'sequenceNumber' => $sequence,
			];
		}

		// Lineage: oldNdx + staré kódy neúčtovatelných operací (majetek/kurz/vadné)
		// kvůli dohledatelnosti, proč doklad zůstal parkovaný na stavu 80. Schema
		// řádku je uzavřené, takže op kódy nesem na doc-level source.raw, ne per řádek.
		$rawSource = ['oldNdx' => $oldNdx];
		if (($loaded['nonAccountableOps'] ?? []) !== [])
			$rawSource['oldOperations'] = $loaded['nonAccountableOps'];

		$canonical = [
			'format'        => 'shpd.docs.document',
			'formatVersion' => '1.0',

			'source' => [
				'kind' => 'import.oldShipard',
				'raw'  => $rawSource,
			],

			'docType'   => 'accountingDocument',
			// Číslo cmnbkp je naše → jde do importNumber, ne do partner_doc_number.
			'docNumber' => null,
			'docText'   => $this->emptyToNull($oldRow['title'] ?? null),
			'selfParty' => null,

			'supplier' => null,
			'customer' => null,

			'dates' => [
				'issueDate'         => $this->dateToString($oldRow['dateIssue'] ?? null),
				'dueDate'           => $this->dateToString($oldRow['dateDue'] ?? null),
				'accountingDate'    => $this->dateToString($oldRow['dateAccounting'] ?? null),
				'taxPointDate'      => $this->dateToString($oldRow['dateTax'] ?? null),
				'vatObligationDate' => $this->dateToString($oldRow['dateTaxDuty'] ?? null),
				'periodFrom'        => $this->dateToString($oldRow['datePeriodBegin'] ?? null),
				'periodTo'          => $this->dateToString($oldRow['datePeriodEnd'] ?? null),
			],

			'currency'     => $this->currencyUpper($oldRow['currency'] ?? null),
			'exchangeRate' => $this->positiveOrNull($oldRow['exchangeRate'] ?? null),

			// cmnbkp je bez DPH (useTax:0); applier vat_mode stejně vynutí na 0.
			'vat' => [
				'mode'                => 'none',
				'place'               => 'domestic',
				'registrationCountry' => null,
			],

			// Hlavičkové symboly cmnbkp nemá — saldo identita je na řádcích.
			'payment' => [
				'method'           => 'bankTransfer',
				'paymentReference' => null,
				'specificSymbol'   => null,
				'constantSymbol'   => null,
			],

			'notes' => [
				'internal'   => null,
				'onDocument' => null,
			],

			'rows' => $rows,

			// AccountingDocument přepočítá součty z řádků (Σ MD); posíláme hlavičkové
			// hodnoty pro paritu/diagnostiku. Staré cmnbkp totals = Σ obou stran
			// vč. doprovodných P&L řádků — o vypuštěné řádky (D12, droppedTotal)
			// se deklarace ponižuje, aby odpovídala Σ odesílaných řádků
			// (self-balancing FX řádek se do součtu počítá jednou).
			'totals' => [
				'totalBase'     => $this->totalsLessDropped($oldRow['sumBase'] ?? null, $droppedTotal),
				'totalVat'      => $this->moneyOrNull($oldRow['sumTax'] ?? null),
				'totalAmount'   => $this->totalsLessDropped($oldRow['sumTotal'] ?? null, $droppedTotal),
				'totalRounding' => $this->moneyOrNull($oldRow['rounding'] ?? null),
			],

			'applyOptions' => $applyOptions,
		];

		// Pinning přes LocalIdMap: řádky (item + per-řádkový partner) pozičně
		// zarovnané, hlavičkový partner volitelně.
		$resolve = $loaded['resolve'] !== [] ? ['rows' => $loaded['resolve']] : [];
		if ($headPartnerNewId !== null)
			$resolve['partner'] = ['userAction' => 'useExisting:' . $headPartnerNewId];
		if ($resolve !== [])
			$canonical['_resolve'] = $resolve;

		return $canonical;
	}

	/**
	 * Kontační řádky účetního dokladu z e10doc_core_rows (+ debsAccountId z debs
	 * rozšíření, LEFT JOIN items kvůli kódu/jménu/pinu). Každý řádek:
	 *   - strana + částka: debit (MD) → accSide='debit', credit (DAL) →
	 *     accSide='credit'; právě jedna je nenulová (obě 0 → řádek přeskočit),
	 *   - operace + účet: debsAccountId (číselný) → acc.record + account; jinak
	 *     item>0 → acc.item + item fragment/pin; jinak řádek přeskočit,
	 *   - per-řádkový partner: person>0 → pin _resolve.rows[i].partner,
	 *   - identita: paymentReference=symbol1, specificSymbol=symbol2,
	 *     constantSymbol=symbol3, dueDate=dateDue; description=text.
	 *
	 * Vrací rows + pozičně zarovnaný resolve (stejně jako loadRows): přeskočené
	 * řádky vypadnou z obou polí současně, takže indexy zůstanou v zákrytu.
	 * Null = tvrdá chyba řádku (rejectReason nastaven), např. neurčitelný směr
	 * kurzového rozdílu (D12/D3d).
	 *
	 * @return array{rows: array<int, array<string, mixed>>, resolve: array<int, array<string, mixed>>}|null
	 */
	private function loadCmnbkpRows(int $docNdx): ?array
	{
		$rows = $this->db()->query(
			'SELECT r.*, i.[id] AS item_code, i.[fullName] AS item_name'
			. ' FROM [e10doc_core_rows] r'
			. ' LEFT JOIN [e10_witems_items] i ON r.[item] = i.[ndx]'
			. ' WHERE r.[document] = %i', $docNdx,
			' ORDER BY r.[rowOrder], r.[ndx]',
		)->fetchAll();

		// D12 prescan: doklad s kurzovými řádky (1090011/1090012, nenulové) nese
		// i doprovodné P&L řádky (1099998 s itemem „Kurzové ztráty/zisky", příp.
		// 1099999) — starou stranu 563/663. Nová FX operace účtuje OBĚ strany
		// sama (MD 563 / DAL 311 apod.), takže doprovodné řádky se vypouštějí.
		// Řádky s explicitním číselným debsAccountId se NEpočítají ani nevypouští
		// — jdou bucketem 1 jako úplná ruční kontace (zrcadlí pořadí bucketů níže).
		// Pojistka proti tichému výpadku cizích řádků: součty doprovodných řádků
		// musí zrcadlit součty FX řádků per strana (loss FX = credit ↔ P&L debit,
		// gain FX = debit ↔ P&L credit); nesoulad = tvrdá chyba dokladu (D3d).
		$fxDebit = 0.0;
		$fxCredit = 0.0;
		$compDebit = 0.0;
		$compCredit = 0.0;
		$hasFxRows = false;
		foreach ($rows as $r)
		{
			$row    = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;
			$op     = (int) ($row['operation'] ?? 0);
			$debit  = (float) ($row['debit'] ?? 0);
			$credit = (float) ($row['credit'] ?? 0);
			if ($debit == 0.0 && $credit == 0.0)
				continue;
			$acc = $this->emptyToNull($row['debsAccountId'] ?? null);
			if ($acc !== null && ctype_digit($acc))
				continue;
			if (isset(self::CMNBKP_FX_OP[$op]) && (int) ($row['item'] ?? 0) <= 0)
			{
				$hasFxRows = true;
				$fxDebit  += $debit;
				$fxCredit += $credit;
			}
			elseif ($op === 1099998 || $op === 1099999)
			{
				$compDebit  += $debit;
				$compCredit += $credit;
			}
		}
		$dropFxCompanions = false;
		if ($hasFxRows)
		{
			if (abs($compDebit - $fxCredit) > 0.005 || abs($compCredit - $fxDebit) > 0.005)
			{
				$this->rejectReason = 'FX companion P&L rows do not mirror FX rows'
					. " (fx dr={$fxDebit}/cr={$fxCredit}, companions dr={$compDebit}/cr={$compCredit})";
				return null;
			}
			$dropFxCompanions = true;
		}

		$out = [];
		$resolve = [];
		$pos = 0;
		$hasNonAccountable = false;
		$nonAccountableOps = [];
		$droppedTotal = 0.0;
		foreach ($rows as $r)
		{
			$row    = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;
			$rowNdx = (int) ($row['ndx'] ?? 0);

			// Majetková operace účtovaná oboustranně jedním řádkem (viz
			// CMNBKP_ASSET_PAIR_OPS) → split na dva acc.record řádky s účty ze
			// starého deníku. Musí předběhnout derivaci strany (obě jsou nenulové)
			// i bucket 1 (deník je autorita nad případným debsAccountId, D9).
			if (in_array((int) ($row['operation'] ?? 0), self::CMNBKP_ASSET_PAIR_OPS, true))
			{
				$pairDebit  = (float) ($row['debit'] ?? 0);
				$pairCredit = (float) ($row['credit'] ?? 0);
				if ($pairDebit == 0.0 && $pairCredit == 0.0)
				{
					$this->debug("doc {$docNdx} row {$rowNdx}: zero debit & credit, skipping (no side)");
					continue;
				}
				$pair = $this->resolveAssetPairAccounts($docNdx, $row);
				if ($pair === null)
					return null;   // rejectReason nastaven (D3d)

				foreach ([['debit', $pair['dr'], $pairDebit], ['credit', $pair['cr'], $pairCredit]] as [$pairSide, $pairAccount, $pairAmount])
				{
					$pos++;
					$out[] = [
						'rowKind'        => 'item',
						'operation'      => 'acc.record',
						'orderPos'       => $pos,
						'item'           => null,
						'unit'           => null,
						'quantity'       => null,
						'unitPrice'      => null,
						'totalPrice'     => $pairAmount,
						'priceCalcMode'  => 'fromTotal',
						'discountPct'    => null,
						'discountAmount' => null,
						'vat'            => ['code' => null, 'pct' => null],
						'description'    => $this->emptyToNull($row['text'] ?? null),
						'account'        => $pairAccount,
						'accSide'        => $pairSide,
						'paymentReference' => $this->emptyToNull($row['symbol1'] ?? null),
						'specificSymbol'   => $this->emptyToNull($row['symbol2'] ?? null),
						'constantSymbol'   => $this->emptyToNull($row['symbol3'] ?? null),
						'dueDate'          => $this->dateToString($row['dateDue'] ?? null),
					];
					$pairResolve = ['index' => count($out) - 1];
					$pairPersonNdx = (int) ($row['person'] ?? 0);
					if ($pairPersonNdx > 0)
					{
						$pairPartnerNewId = $this->idMap()->lookup(LocalIdMap::ENTITY_PERSON, $pairPersonNdx);
						if ($pairPartnerNewId !== null)
							$pairResolve['partner'] = ['userAction' => 'useExisting:' . $pairPartnerNewId];
					}
					$resolve[] = $pairResolve;
				}
				continue;
			}

			// Strana + částka. debit = Má dáti (Vyplaceno), credit = Dal (Přijato).
			// Právě jedna je nenulová; obě nula → textový/oddělovací řádek bez strany.
			$debit  = (float) ($row['debit'] ?? 0);
			$credit = (float) ($row['credit'] ?? 0);
			if ($debit != 0.0)
			{
				$accSide    = 'debit';
				$totalPrice = $debit;
			}
			elseif ($credit != 0.0)
			{
				$accSide    = 'credit';
				$totalPrice = $credit;
			}
			else
			{
				$this->debug("doc {$docNdx} row {$rowNdx}: zero debit & credit, skipping (no side)");
				continue;
			}

			// Operace + zdroj účtu. Pořadí výběru:
			//   1. debsAccountId (číselný) → acc.record + účet z řádku,
			//   2. item > 0 → acc.item + položka (pin),
			//   3. saldokontní operace → acc.balanceReceivable/Payable (účet
			//      dopočítá nová strana z kategorie 311/321, řádek bez účtu/položky),
			//   4. kurzový rozdíl (D12) → acc.fx* dle ztráta/zisk ze starého
			//      deníku (resolveFxDirection); nejednoznačnost = tvrdá chyba,
			//   5. jinak neúčtovatelné (majetek/vadný řádek) → acc.record bez
			//      účtu: řádek se nezahodí (nese stranu/částku/partnera/symboly/text),
			//      ale doklad se zastropuje na stav 80 (buildCmnbkpCanonical).
			$accountRaw = $this->emptyToNull($row['debsAccountId'] ?? null);
			$oldItemNdx = (int) ($row['item'] ?? 0);
			$oldOp      = (int) ($row['operation'] ?? 0);

			// D12: doprovodný P&L řádek kurzového dokladu — vypustit (musí předběhnout
			// bucket 2, protože 1099998 nese item; řádky s explicitním účtem zůstávají
			// bucketu 1). Součtová pojistka viz prescan. Částka se akumuluje do
			// droppedTotal — buildCmnbkpCanonical o ni poníží deklarované totals.
			if ($dropFxCompanions && ($oldOp === 1099998 || $oldOp === 1099999)
				&& !($accountRaw !== null && ctype_digit($accountRaw)))
			{
				$this->debug("doc {$docNdx} row {$rowNdx}: FX companion P&L row (op {$oldOp}), "
					. 'dropping — fx operation posts both sides itself');
				$droppedTotal += $totalPrice;
				continue;
			}

			$operation    = null;
			$account      = null;
			$itemFragment = null;
			$itemPin      = null;

			if ($accountRaw !== null && ctype_digit($accountRaw))
			{
				$operation = 'acc.record';
				$account   = $accountRaw;
			}
			elseif ($oldItemNdx > 0)
			{
				$operation = 'acc.item';
				$itemFragment = [
					'ourCode'      => $this->emptyToNull($row['item_code'] ?? null),
					'supplierCode' => null,
					'sku'          => null,
					'ean'          => null,
					'name'         => $this->emptyToNull($row['item_name'] ?? null)
						?? $this->emptyToNull($row['text'] ?? null),
					'description'  => $this->emptyToNull($row['text'] ?? null),
				];
				$newItemId = $this->idMap()->lookup(LocalIdMap::ENTITY_ITEM, $oldItemNdx);
				if ($newItemId !== null)
					$itemPin = ['userAction' => 'useExisting:' . $newItemId];
			}
			elseif (isset(self::CMNBKP_BALANCE_OP[$oldOp]))
			{
				$operation = self::CMNBKP_BALANCE_OP[$oldOp];
			}
			elseif (isset(self::CMNBKP_FX_OP[$oldOp]))
			{
				$dir = $this->resolveFxDirection($docNdx, $row, $totalPrice);
				if ($dir === null)
					return null;   // rejectReason nastaven → doklad failne (D3d)
				$operation = self::CMNBKP_FX_OP[$oldOp][$dir];
			}
			else
			{
				$operation = 'acc.record';
				$hasNonAccountable = true;
				if (!in_array($oldOp, $nonAccountableOps, true))
					$nonAccountableOps[] = $oldOp;
				$this->debug("doc {$docNdx} row {$rowNdx}: non-accountable operation {$oldOp} "
					. "(no account/item), importing row without account");
			}

			$pos++;
			$out[] = [
				'rowKind'        => 'item',
				'operation'      => $operation,
				'orderPos'       => $pos,
				'item'           => $itemFragment,
				'unit'           => null,
				'quantity'       => null,
				'unitPrice'      => null,
				'totalPrice'     => $totalPrice,
				// Kontace účtuje částku přímo; accSide stejně vynutí fromTotal v applieru.
				'priceCalcMode'  => 'fromTotal',
				'discountPct'    => null,
				'discountAmount' => null,
				'vat'            => ['code' => null, 'pct' => null],
				'description'    => $this->emptyToNull($row['text'] ?? null),
				'account'        => $account,
				'accSide'        => $accSide,
				'paymentReference' => $this->emptyToNull($row['symbol1'] ?? null),
				'specificSymbol'   => $this->emptyToNull($row['symbol2'] ?? null),
				'constantSymbol'   => $this->emptyToNull($row['symbol3'] ?? null),
				'dueDate'          => $this->dateToString($row['dateDue'] ?? null),
			];

			// Resolve hint zarovnaný s pozicí v $out: item pin (acc.item) +
			// per-řádkový partner pin.
			$resolveRow = ['index' => count($out) - 1];
			if ($itemPin !== null)
				$resolveRow['item'] = $itemPin;
			$personNdx = (int) ($row['person'] ?? 0);
			if ($personNdx > 0)
			{
				$partnerNewId = $this->idMap()->lookup(LocalIdMap::ENTITY_PERSON, $personNdx);
				if ($partnerNewId !== null)
					$resolveRow['partner'] = ['userAction' => 'useExisting:' . $partnerNewId];
			}
			$resolve[] = $resolveRow;
		}
		return [
			'rows'              => $out,
			'resolve'           => $resolve,
			'hasNonAccountable' => $hasNonAccountable,
			'nonAccountableOps' => $nonAccountableOps,
			'droppedTotal'      => $droppedTotal,
		];
	}

	/**
	 * Pořadové číslo (sequence) z původního docNumber, řízené formulí řady.
	 * Replikuje prefix/suffix část makeDocNumber: vyhodnotí tokeny formule pro
	 * tento doklad (s autoritativním %C = $seriesCode), ořízne vyhodnocený
	 * prefix zleva a suffix zprava — zbytek je počítadlo.
	 *
	 * Neshoda formule (chybějící counter token, zbylý nevyhodnocený token,
	 * neshoda prefixu/suffixu, nečíselné jádro) = null. Volající (buildCanonical)
	 * z null udělá tvrdou chybu dokladu. $diag nese lidsky čitelný důvod do
	 * hlášky failu.
	 *
	 * Fallbacky pro přelomové doklady (číslo se otisklo v jiném období, než
	 * kam padlo zaúčtování):
	 *   - fiskální značka (%r): primárně z hlavičkového fiscalYear, při
	 *     neshodě značka fiskálního roku obsahujícího dateIssue,
	 *   - datum pro %Y/%y/%M: primárně dateAccounting, při neshodě dateIssue
	 *     (číslo raženo při vystavení) a začátek fiskálního roku hlavičky
	 *     (13. období — zaúčtováno v lednu, číslo nese rok fiskálního roku).
	 * Zkouší se kombinace značka × datum; první shoda vyhrává (debug log,
	 * když nevyhrála primární kombinace).
	 */
	private function parseSequenceNumber(array $oldRow, string $seriesCode, string &$diag): ?int
	{
		$docNumber = $this->emptyToNull($oldRow['docNumber'] ?? null);
		if ($docNumber === null)
		{
			$diag = 'empty docNumber';
			return null;
		}

		$docType = (string) ($oldRow['docType'] ?? '');
		$formula = (string) ($this->app()->cfgItem('e10.options.docNumbers.' . $docType, '') ?: '');
		if ($formula === '')
			$formula = (string) ($this->app()->cfgItem('e10.docs.types.' . $docType . '.docNumber', '') ?: '');
		if ($formula === '')
			$formula = '%D%r%C%4';

		// Counter token (%2–%6) rozdělí formuli na prefix | counter | suffix.
		// Greedy levá část vezme poslední counter token, kdyby jich bylo víc.
		if (!preg_match('/^(.*)(%[2-6])(.*)$/', $formula, $m))
		{
			$diag = "formula '{$formula}' has no counter token";
			return null;
		}

		// Kandidátní fiskální značky (%r): primární (FK / dateAccounting) a při
		// neshodě fallback na fiskální rok obsahující dateIssue (číslo se otisklo
		// v čase vystavení). Vrátíme první, která sedí na docNumber.
		$primaryMark = $this->resolveFiscalYearMark($oldRow);
		$marks = [$primaryMark];
		$issueMark = $this->markByDate($this->dateToString($oldRow['dateIssue'] ?? null));
		if ($issueMark !== null && $issueMark !== $primaryMark)
			$marks[] = $issueMark;

		// Kandidátní data pro %Y/%y/%M (jen když formule datové tokeny má):
		// primární dateAccounting (null = default), pak dateIssue a začátek
		// fiskálního roku hlavičky — když nesou jiný rok.
		$dates = [null];
		if (preg_match('/%[YyM]/', $formula))
		{
			$accYear   = substr((string) $this->dateToString($oldRow['dateAccounting'] ?? null), 0, 4);
			$issueDate = $this->dateToString($oldRow['dateIssue'] ?? null);
			$years     = [$accYear];
			if ($issueDate !== null && !in_array(substr($issueDate, 0, 4), $years, true))
			{
				$dates[] = $issueDate;
				$years[] = substr($issueDate, 0, 4);
			}
			$fyStart = $this->fiscalYearStart((int) ($oldRow['fiscalYear'] ?? 0));
			if ($fyStart !== null && !in_array(substr($fyStart, 0, 4), $years, true))
				$dates[] = $fyStart;
		}

		foreach ($marks as $i => $mark)
		{
			foreach ($dates as $j => $date)
			{
				$seq = $this->tryParseSequence($docNumber, $m[1], $m[3], $oldRow, $seriesCode, $mark, $formula, $diag, $date);
				if ($seq !== null)
				{
					if ($i > 0 || $j > 0)
						$this->debug("doc {$oldRow['ndx']}: sequence parsed with fallback fiscal mark "
							. "'{$mark}' / date '" . ($date ?? 'dateAccounting') . "'"
							. " (primary mark '{$primaryMark}' + dateAccounting didn't match docNumber)");
					return $seq;
				}
			}
		}
		return null;
	}

	/** Začátek fiskálního roku (YYYY-MM-DD) podle FK hlavičky, nebo null. */
	private function fiscalYearStart(int $fyNdx): ?string
	{
		if ($fyNdx <= 0)
			return null;
		$r = $this->db()->query(
			'SELECT [start] FROM [e10doc_base_fiscalyears] WHERE [ndx] = %i', $fyNdx,
		)->fetch();
		return $r !== null ? $this->dateToString($r['start'] ?? null) : null;
	}

	/**
	 * Jeden pokus o rozparsování sekvence s konkrétní fiskální značkou
	 * ($fiscalMark → token %r). Replikuje prefix/suffix část makeDocNumber:
	 * ořízne vyhodnocený prefix zleva a suffix zprava, zbytek je počítadlo.
	 * Vrací sekvenci, nebo null + důvod do $diag (nevyhodnocený token, neshoda
	 * prefixu/suffixu, nečíselné jádro).
	 */
	private function tryParseSequence(
		string $docNumber, string $prefixPat, string $suffixPat,
		array $oldRow, string $seriesCode, string $fiscalMark, string $formula, string &$diag,
		?string $dateOverride = null
	): ?int
	{
		$prefix = $this->evaluateNumberTokens($prefixPat, $oldRow, $seriesCode, $fiscalMark, $dateOverride);
		$suffix = $this->evaluateNumberTokens($suffixPat, $oldRow, $seriesCode, $fiscalMark, $dateOverride);
		$diag = "formula='{$formula}', prefix='{$prefix}', suffix='{$suffix}'";

		// Prefix/suffix musí být plně vyhodnocené — zbylý %token (např. %A/%B/%W,
		// které neumíme) signalizuje, že formule na tento doklad nesedí.
		if (strpos($prefix, '%') !== false || strpos($suffix, '%') !== false)
		{
			$diag .= ' (unresolved token)';
			return null;
		}

		$core = $docNumber;
		if ($prefix !== '')
		{
			if (!str_starts_with($core, $prefix))
			{
				$diag .= ' (prefix mismatch)';
				return null;
			}
			$core = substr($core, strlen($prefix));
		}
		if ($suffix !== '')
		{
			if (!str_ends_with($core, $suffix))
			{
				$diag .= ' (suffix mismatch)';
				return null;
			}
			$core = substr($core, 0, -strlen($suffix));
		}

		// intval zahodí leading zeros i případné přetečení šířky paddingu.
		if ($core === '' || !ctype_digit($core))
		{
			$diag .= " (core '{$core}' not numeric)";
			return null;
		}
		return (int) $core;
	}

	/**
	 * Vyhodnotí prefix/suffix tokeny formule (%D %r %C %Y %y %M) konkrétními
	 * hodnotami daného dokladu — replikuje relevantní část makeDocNumber. %C bere
	 * autoritativní kód řady ($seriesCode = docKeyId z dbCounter). %r bere
	 * předanou fiskální značku ($fiscalMark); když není (null), dohledá se přes
	 * resolveFiscalYearMark. %Y/%y/%M bere $dateOverride (fallback přelomových
	 * dokladů), jinak dateAccounting. Tokeny %B/%A/%W (cashBox/bankAccount/
	 * warehouse id) ponecháváme nevyhodnocené; jejich přítomnost shodí parser
	 * na null.
	 */
	private function evaluateNumberTokens(string $pattern, array $oldRow, string $seriesCode,
		?string $fiscalMark = null, ?string $dateOverride = null): string
	{
		if ($pattern === '')
			return '';

		$docType = (string) ($oldRow['docType'] ?? '');
		$dateAcc = $dateOverride ?? ($oldRow['dateAccounting'] ?? null);
		$dt = $dateAcc instanceof \DateTimeInterface
			? $dateAcc
			: (is_string($dateAcc) && $dateAcc !== '' && !str_starts_with($dateAcc, '0000')
				? new \DateTime($dateAcc) : null);

		$docIdCode = (string) $this->app()->cfgItem('e10.docs.types.' . $docType . '.docIdCode', '');

		return strtr($pattern, [
			'%D' => $docIdCode,
			'%r' => $fiscalMark ?? $this->resolveFiscalYearMark($oldRow),
			'%C' => $seriesCode,
			'%Y' => $dt ? $dt->format('Y') : '',
			'%y' => $dt ? $dt->format('y') : '',
			'%M' => $dt ? $dt->format('m') : '',
		]);
	}

	/**
	 * Mark fiskálního roku (%r token). Primárně z e10doc_base_fiscalyears podle
	 * hlavičkového fiscalYear; když chybí/0 (reálně se stává — doklad ndx=1
	 * '22511204'), dohledá fiskální rok podle dateAccounting v rozsahu
	 * start..end. Bez dohledání → prázdný string (parser pak typicky failne na
	 * neshodu prefixu, což je správně — radši hlasitě než tiše špatně).
	 */
	private function resolveFiscalYearMark(array $oldRow): string
	{
		$fyNdx = (int) ($oldRow['fiscalYear'] ?? 0);
		if ($fyNdx > 0)
		{
			$r = $this->db()->query('SELECT [mark] FROM [e10doc_base_fiscalyears] WHERE [ndx] = %i', $fyNdx)->fetch();
			if ($r !== null)
				return (string) ($r['mark'] ?? '');
		}

		$markByAcc = $this->markByDate($this->dateToString($oldRow['dateAccounting'] ?? null));
		return $markByAcc ?? '';
	}

	/**
	 * Mark fiskálního roku, jehož rozsah start..end obsahuje dané datum (YYYY-MM-DD),
	 * nebo null když datum chybí / žádný rok nesedí. Sdílí resolveFiscalYearMark
	 * (fallback hlavičky) i parseSequenceNumber (fallback %r přes dateIssue).
	 */
	private function markByDate(?string $date): ?string
	{
		if ($date === null)
			return null;

		$r = $this->db()->query(
			'SELECT [mark] FROM [e10doc_base_fiscalyears] WHERE [docState] != %i', 9800,
			' AND [start] <= %d', $date,
			' AND [end] >= %d', $date,
			' ORDER BY [start] DESC',
		)->fetch();

		return $r !== null ? (string) ($r['mark'] ?? '') : null;
	}

	/**
	 * Partner Party fragment z persons + properties + hlavní adresa + bankovní
	 * účet. Vrátí null pokud osoba neexistuje.
	 */
	private function loadParty(int $personNdx): ?array
	{
		$personRow = $this->db()->query(
			'SELECT * FROM [e10_persons_persons] WHERE [ndx] = %i', $personNdx,
		)->fetch();
		if ($personRow === null)
			return null;
		$person = is_object($personRow) && method_exists($personRow, 'toArray')
			? $personRow->toArray() : (array) $personRow;

		$properties = $this->loadPersonProperties($personNdx);
		$address    = $this->loadMainAddress($personNdx);
		$bank       = $this->loadFirstBankAccount($personNdx);

		return [
			'name'              => $this->emptyToNull($person['fullName'] ?? null),
			'country'           => $address['country'] ?? null,
			'companyId'         => $properties['oid']   ?? null,
			'taxId'             => null,
			'vatId'             => $properties['taxid'] ?? null,
			'courtRegistration' => null,
			'address'           => $address['canonical'] ?? null,
			'contact'           => null,
			'bankAccount'       => $bank,
			'paymentTermDays'   => null,
		];
	}

	/**
	 * IČO/DIČ z e10_base_properties (sub-set PersonsRunner::loadProperties).
	 *
	 * @return array<string, string>
	 */
	private function loadPersonProperties(int $personNdx): array
	{
		$rows = $this->db()->query(
			'SELECT [property], [valueString], [ndx]'
			. ' FROM [e10_base_properties]'
			. ' WHERE [tableid] = %s', 'e10.persons.persons',
			' AND [recid] = %i', $personNdx,
			' AND [property] IN %in', ['oid', 'taxid'],
			' ORDER BY [ndx] ASC',
		)->fetchAll();

		$result = [];
		foreach ($rows as $r)
		{
			$row = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;
			$prop = (string) ($row['property'] ?? '');
			if ($prop === '' || isset($result[$prop]))
				continue;
			$val = trim((string) ($row['valueString'] ?? ''));
			if ($val !== '')
				$result[$prop] = $val;
		}
		return $result;
	}

	/**
	 * Hlavní adresa (flagMainAddress=1) jako docs $defs/Address fragment.
	 * Address má jiná pole než persons addresses — jen street/houseNumber/
	 * city/cityPart/zip/country/registryCode/displayLine/displayBlock.
	 *
	 * @return array{country: ?string, canonical: ?array<string, mixed>}
	 */
	private function loadMainAddress(int $personNdx): array
	{
		$r = $this->db()->query(
			'SELECT pc.*, c.[cca2] AS country_iso'
			. ' FROM [e10_persons_personsContacts] pc'
			. ' LEFT JOIN [e10_world_countries] c ON pc.[adrCountry] = c.[ndx]'
			. ' WHERE pc.[person] = %i', $personNdx,
			' AND pc.[docState] != %i', 9800,
			' AND pc.[flagMainAddress] = %i', 1,
			' ORDER BY pc.[systemOrder], pc.[ndx]',
		)->fetch();

		if ($r === null)
			return ['country' => null, 'canonical' => null];

		$row = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;

		$country = null;
		$rawCountry = strtolower(trim((string) ($row['country_iso'] ?? '')));
		if (strlen($rawCountry) === 2 && ctype_alpha($rawCountry))
			$country = $rawCountry;

		$canonical = [
			'street'       => $this->emptyToNull($row['adrStreet'] ?? null),
			'houseNumber'  => $this->emptyToNull($row['saHouseNr'] ?? null),
			'city'         => $this->emptyToNull($row['adrCity'] ?? null),
			'cityPart'     => $this->emptyToNull($row['saCityPartName'] ?? null),
			'zip'          => $this->emptyToNull($row['adrZipCode'] ?? null),
			'country'      => $country,
			'registryCode' => null,
			'displayLine'  => null,
			'displayBlock' => null,
		];

		return ['country' => $country, 'canonical' => $canonical];
	}

	/**
	 * První bankovní účet jako docs $defs/BankAccount fragment, nebo null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadFirstBankAccount(int $personNdx): ?array
	{
		$r = $this->db()->query(
			'SELECT * FROM [e10_persons_personsBA]'
			. ' WHERE [person] = %i', $personNdx,
			' AND [docState] != %i', 9800,
			' ORDER BY [ndx]',
		)->fetch();

		if ($r === null)
			return null;
		$row = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;

		return $this->parseBankAccountString($row['bankAccount'] ?? null);
	}

	/**
	 * Starý účet je jeden volný řetězec (max 40 znaků) — buď IBAN
	 * (`CZ6508000000192000145399`), nebo tuzemský `[předčíslí-]číslo/kód`
	 * (`19-2000145399/0800`). Převede na docs $defs/BankAccount fragment se
	 * správně vyplněným polem (`iban` vs `accountNumber`), nebo null pokud prázdný.
	 *
	 * IBAN detekce: 2 písmena + 2 číslice na začátku (ISO 13616), po odstranění
	 * mezer. Vše ostatní bereme jako tuzemské číslo účtu.
	 *
	 * @return array<string, mixed>|null
	 */
	private function parseBankAccountString(mixed $raw): ?array
	{
		$value = $this->emptyToNull($raw);
		if ($value === null)
			return null;

		$compact = str_replace(' ', '', $value);
		$isIban = (bool) preg_match('/^[A-Za-z]{2}[0-9]{2}[0-9A-Za-z]{1,30}$/', $compact);

		return [
			'accountNumber' => $isIban ? null : $value,
			'iban'          => $isIban ? strtoupper($compact) : null,
			'bic'           => null,
			'currency'      => 'CZK',
		];
	}

	/**
	 * Řádky dokladu z e10doc_core_rows + LEFT JOIN items pro ourCode/name.
	 * Klasifikace per řádek, pořadí vyhodnocení (task 21, D3 + dodatek D8/D9):
	 *   1. operační řádek — stará op v ROW_OPERATION_ACCOUNT_MAP: operation,
	 *      znaménkové částky, DPH, paymentReference ze symbol1; zálohy bez
	 *      účtu (dohledá kategorie předpisu, D8/D10), majetek s účtem ze
	 *      starého deníku (resolveAssetAccount, D9 — neúspěch = chyba
	 *      dokladu); item se neposílá, i kdyby byl vyplněný,
	 *   2. item řádek — itemCode/item > 0: payload beze změny (mapRowOperation),
	 *   3. operační řádek bez účtu — řádek s penězi, bez itemu, kategorie op
	 *      (purchase.* / sale.*): operation + peníze + DPH; účet dodá kategorie
	 *      předpisu (504/518/548/6xx),
	 *   4. textový řádek — jen řádek bez peněz (priceAll i credit/debit nula),
	 *   5. chyba — řádek s penězi a nemapovanou operací (vč. acc.entry bez
	 *      itemu): rejectReason + null → doklad failne. Žádný tichý text.
	 *
	 * Vrací zároveň `resolve` — pole zarovnané s `rows` (po pozicích), kde
	 * item-řádky s LocalIdMap hitem nesou `item.userAction = useExisting:<newId>`.
	 * Applier díky tomu použije přesnou migrovanou položku místo dohledávání
	 * podle kódu/jména (po zrušení slučování stejnojmenných položek jinak
	 * nejednoznačné). Pozice musí sedět s `rows` — proto i řádky bez mapování
	 * (operační, text-řádky, nenamapované položky) dostanou placeholder
	 * `['index' => i]`.
	 *
	 * @return ?array{rows: array<int, array<string, mixed>>, resolve: array<int, array<string, mixed>>}
	 */
	private function loadRows(int $docNdx, string $oldDocType): ?array
	{
		$rows = $this->db()->query(
			'SELECT r.*, i.[id] AS item_code, i.[fullName] AS item_name'
			. ' FROM [e10doc_core_rows] r'
			. ' LEFT JOIN [e10_witems_items] i ON r.[item] = i.[ndx]'
			. ' WHERE r.[document] = %i', $docNdx,
			' ORDER BY r.[rowOrder], r.[ndx]',
		)->fetchAll();

		$out = [];
		$resolve = [];
		$pos = 0;
		foreach ($rows as $r)
		{
			$row = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;
			$pos++;

			$oldOp      = (int) ($row['operation'] ?? 0);
			$oldItemNdx = (int) ($row['item'] ?? 0);
			$itemCode   = $this->emptyToNull($row['item_code'] ?? null);
			$hasItem    = $itemCode !== null || $oldItemNdx > 0;
			$hasMoney   = (float) ($row['priceAll'] ?? 0) != 0.0
				|| (float) ($row['credit'] ?? 0) != 0.0
				|| (float) ($row['debit'] ?? 0) != 0.0;

			$opAccount  = self::ROW_OPERATION_ACCOUNT_MAP[$oldDocType][$oldOp] ?? null;
			$categoryOp = self::ROW_OPERATION_MAP[$oldDocType][$oldOp] ?? null;

			// 1 + 3: operační řádek — shape dle cmnbkp kontační cesty
			// (loadCmnbkpRows), ale bez accSide (stranu určuje krok předpisu
			// per operace) a s DPH poli. Item se neposílá. Účet nese jen
			// majetek (z deníku, D9); zálohy jdou bez účtu (kategorie, D8/D10).
			if ($opAccount !== null
				|| ($hasMoney && !$hasItem && $categoryOp !== null && $categoryOp !== 'acc.entry'))
			{
				if ($opAccount !== null)
				{
					$operation = $opAccount['operation'];
					$account   = null;
					if ($opAccount['assetAccount'] ?? false)
					{
						$account = $this->resolveAssetAccount($docNdx, $row);
						if ($account === null)
							return null;   // rejectReason nastaven → doklad failne
					}
					$paymentReference = ($opAccount['paymentReference'] ?? false)
						? $this->emptyToNull($row['symbol1'] ?? null) : null;
				}
				else
				{
					$operation        = $categoryOp;
					$account          = null;
					$paymentReference = null;
				}

				$out[] = [
					'rowKind'   => 'item',
					'operation' => $operation,
					'orderPos'  => $pos,
					'item'      => null,
					'unit'      => null,
					'quantity'  => null,
					'unitPrice' => null,
					// Znaménkový passthrough — odpočty záloh jsou ve zdroji záporné.
					'totalPrice'     => $this->moneyOrNull($row['priceAll'] ?? null),
					'priceCalcMode'  => 'fromTotal',
					'discountPct'    => null,
					'discountAmount' => null,
					'vat' => [
						'code' => $this->mapVatCode($row['taxCode'] ?? null),
						'pct'  => $this->numberOrNull($row['taxPercents'] ?? null),
					],
					'description'      => $this->emptyToNull($row['text'] ?? null),
					'account'          => $account,
					'paymentReference' => $paymentReference,
				];
				$resolve[] = ['index' => count($out) - 1];
				continue;
			}

			// 2: item řádek — beze změny proti dosavadní cestě.
			if ($hasItem)
			{
				$itemName = $this->emptyToNull($row['item_name'] ?? null)
					?? $this->emptyToNull($row['text'] ?? null);

				$out[] = [
					'rowKind'   => 'item',
					'operation' => $this->mapRowOperation($oldDocType, $oldOp),
					'orderPos'  => $pos,
					'item'     => [
						'ourCode'      => $itemCode,
						'supplierCode' => null,
						'sku'          => null,
						'ean'          => null,
						'name'         => $itemName,
						'description'  => $this->emptyToNull($row['text'] ?? null),
					],
					'unit'           => $this->unitOrNull($row['unit'] ?? null),
					'quantity'       => $this->numberOrNull($row['quantity'] ?? null),
					'unitPrice'      => $this->numberOrNull($row['priceItem'] ?? null),
					'totalPrice'     => $this->moneyOrNull($row['priceAll'] ?? null),
					'priceCalcMode'  => ((int) ($row['priceSource'] ?? 0) === 1) ? 'fromTotal' : 'fromUnitPrice',
					'discountPct'    => null,
					'discountAmount' => null,
					'vat' => [
						'code' => $this->mapVatCode($row['taxCode'] ?? null),
						'pct'  => $this->numberOrNull($row['taxPercents'] ?? null),
					],
				];

				// Resolve hint zarovnaný s pozicí v $out. Item-řádek s LocalIdMap
				// hitem → useExisting na přesnou novou položku.
				$resolveRow = ['index' => count($out) - 1];
				if ($oldItemNdx > 0)
				{
					$newItemId = $this->idMap()->lookup(LocalIdMap::ENTITY_ITEM, $oldItemNdx);
					if ($newItemId !== null)
						$resolveRow['item'] = ['userAction' => 'useExisting:' . $newItemId];
				}
				$resolve[] = $resolveRow;
				continue;
			}

			// 4: textový řádek — jen bez peněz (pohyb mít nesmí, applier ho odmítne).
			if (!$hasMoney)
			{
				$out[] = [
					'rowKind'   => 'text',
					'operation' => null,
					'orderPos'  => $pos,
					'item'      => null,
					'unit'           => $this->unitOrNull($row['unit'] ?? null),
					'quantity'       => $this->numberOrNull($row['quantity'] ?? null),
					'unitPrice'      => $this->numberOrNull($row['priceItem'] ?? null),
					'totalPrice'     => $this->moneyOrNull($row['priceAll'] ?? null),
					'priceCalcMode'  => ((int) ($row['priceSource'] ?? 0) === 1) ? 'fromTotal' : 'fromUnitPrice',
					'discountPct'    => null,
					'discountAmount' => null,
					'vat' => [
						'code' => $this->mapVatCode($row['taxCode'] ?? null),
						'pct'  => $this->numberOrNull($row['taxPercents'] ?? null),
					],
				];
				$resolve[] = ['index' => count($out) - 1];
				continue;
			}

			// 5: peníze bez mapované operace → tvrdá chyba dokladu (hlasitá,
			// --continue-on-error pokračuje dalším dokladem). Žádný tichý text.
			$rowNdx = (int) ($row['ndx'] ?? 0);
			$this->rejectReason = "row ndx={$rowNdx} (pos {$pos}): operation {$oldOp} "
				. 'with money (priceAll=' . (float) ($row['priceAll'] ?? 0)
				. ') has no mapping — refusing silent text row';
			return null;
		}
		return ['rows' => $out, 'resolve' => $resolve];
	}

	/**
	 * Cílový docState z mapy DOC_STATE_MAP_TARGET. Neznámý starý stav → null
	 * (volající z toho udělá tvrdou chybu). --target-state=10 je globální strop
	 * „vše jako koncept" pro testovací běhy: přebíjí mapu i fail neznámého stavu.
	 */
	private function targetDocState(int $oldState): ?int
	{
		$arg = $this->app()->arg('target-state');
		if ($arg !== null && (int) $arg === 10)
			return 10;
		return self::DOC_STATE_MAP_TARGET[$oldState] ?? null;
	}

	/** Stav nese přidělené číslo (řada + sekvence + importNumber)? */
	private function stateHasNumber(int $targetState): bool
	{
		return in_array($targetState, self::STATES_WITH_NUMBER, true);
	}

	/**
	 * Kód číselné řady (%C ↔ docs_core_number_series.doc_number_code) z dbCounter
	 * hlavičky: cfg e10.docs.dbCounters.{docType}.{dbCounter}.docKeyId. BEZ
	 * defaultu '1' — chybějící cfg vrací null (volající z toho udělá fail).
	 */
	private function resolveNumberSeriesCode(array $oldRow): ?string
	{
		$docType   = (string) ($oldRow['docType'] ?? '');
		$dbCounter = (int) ($oldRow['dbCounter'] ?? 0);
		$code = $this->app()->cfgItem(
			'e10.docs.dbCounters.' . $docType . '.' . $dbCounter . '.docKeyId', '');
		$code = is_string($code) ? trim($code) : (is_int($code) ? (string) $code : '');
		return $code === '' ? null : $code;
	}

	/**
	 * Řada + sekvence pro stav nesoucí číslo. Primárně řada přilinkovaná přes
	 * dbCounter; když číslo její formulí neprojde, fallback (D13) zkusí formule
	 * všech ostatních řad téhož docTypu — číslo je ground truth, část dokladů
	 * má ve zdroji přilinkovanou jinou řadu, než ze které číslo pochází (např.
	 * saldokonto pod řadou „Otevření"). Kandidáti se čtou přímo ze zdrojové
	 * tabulky e10doc_base_docnumbers (jako NumberSeriesRunner) — config cache
	 * _e10doc.docNumbers.json může být po INSERTu řady do zdroje neaktuální.
	 * Deduplikace podle kódu (víc řad může sdílet docKeyId; formule závisí jen
	 * na docType+kódu). Právě jedna shoda → použije se s warnem; 0 nebo ≥2
	 * shod → tvrdá chyba jako dřív.
	 *
	 * Výsledek prochází dedupem pravých duplicit (D14-B, applySeriesDedup) —
	 * docNumber se může vrátit se sufixem a seq jako null. Vrací
	 * ['code' => %C, 'seq' => ?int, 'docNumber' => string], nebo null
	 * + rejectReason.
	 */
	private function resolveSeriesAndSequence(array $oldRow, ?string $docNumber, int $targetState): ?array
	{
		$docType = (string) ($oldRow['docType'] ?? '');
		$linked  = $this->resolveNumberSeriesCode($oldRow);
		if ($linked === null)
		{
			$dbCounter = (int) ($oldRow['dbCounter'] ?? 0);
			$this->rejectReason = "number series code (docKeyId) not found in cfg "
				. "e10.docs.dbCounters.{$docType}.{$dbCounter}.docKeyId";
			return null;
		}
		if ($docNumber === null)
		{
			$this->rejectReason = "target state {$targetState} requires a number but docNumber is empty";
			return null;
		}

		$diag = '';
		$seq = $this->parseSequenceNumber($oldRow, $linked, $diag);
		if ($seq !== null)
			return $this->applySeriesDedup($oldRow, $docType, $linked, $seq, $docNumber);
		$linkedDiag = $diag;

		$candidates = [];
		$series = $this->db()->query(
			'SELECT [docKeyId] FROM [e10doc_base_docnumbers]'
			. ' WHERE [docType] = %s', $docType,
			' AND [docState] != %i', 9800,
		)->fetchAll();
		foreach ($series as $entry)
		{
			$code = $entry['docKeyId'] ?? '';
			$code = is_string($code) ? trim($code) : (is_int($code) ? (string) $code : '');
			if ($code !== '' && $code !== $linked)
				$candidates[$code] = true;
		}

		$matches = [];
		foreach (array_keys($candidates) as $code)
		{
			$d = '';
			$s = $this->parseSequenceNumber($oldRow, (string) $code, $d);
			if ($s !== null)
				$matches[(string) $code] = $s;
		}

		if (count($matches) === 1)
		{
			$code = (string) array_key_first($matches);
			$oldNdx = (int) ($oldRow['ndx'] ?? 0);
			$this->warn("doc {$oldNdx}: number {$docNumber} matches series {$code}, "
				. "linked to {$linked} — using {$code}");
			return $this->applySeriesDedup($oldRow, $docType, $code, $matches[$code], $docNumber);
		}

		$extra = $matches === []
			? 'no other series of this docType matched'
			: 'ambiguous fallback, matches series: ' . implode(', ', array_keys($matches));
		$this->rejectReason = "cannot parse sequence from docNumber '{$docNumber}' ({$linkedDiag}; {$extra})";
		return null;
	}

	/**
	 * Sufix pravých duplicit (D14-B): n-tý výskyt klíče (docType, řada, rok,
	 * sekvence) v běhu dostane docNumber sufix '-n' a sekvenci null — na nové
	 * straně se uloží sequence_number NULL (unq_series_seq s NULL nekoliduje)
	 * a čítač řady se nebumpne. Vyžaduje shpd podporu explicitního null
	 * v applyOptions.importNumber.sequenceNumber (task
	 * docs-import-number-null-sequence). Kaveat per-run množiny viz
	 * $seenSeriesKeys.
	 *
	 * @return array{code: string, seq: ?int, docNumber: string}
	 */
	private function applySeriesDedup(array $oldRow, string $docType, string $code, int $seq, string $docNumber): array
	{
		$year = substr((string) $this->dateToString($oldRow['dateAccounting'] ?? null), 0, 4);
		$key  = "{$docType}|{$code}|{$year}|{$seq}";

		if (!isset($this->seenSeriesKeys[$key]))
		{
			$this->seenSeriesKeys[$key] = ['ndx' => (int) ($oldRow['ndx'] ?? 0), 'count' => 1];
			return ['code' => $code, 'seq' => $seq, 'docNumber' => $docNumber];
		}

		$firstNdx = $this->seenSeriesKeys[$key]['ndx'];
		$n = ++$this->seenSeriesKeys[$key]['count'];
		$oldNdx = (int) ($oldRow['ndx'] ?? 0);
		$suffixed = $docNumber . '-' . $n;
		$this->warn("doc {$oldNdx}: duplicate series key ({$key}) — first doc {$firstNdx}, "
			. "importing as '{$suffixed}' without sequence");
		return ['code' => $code, 'seq' => null, 'docNumber' => $suffixed];
	}

	/**
	 * Analytika majetkového řádku ze starého deníku (D9). Nová strana má pro
	 * purchase.asset accountSrc=row — účet z řádku je povinný; analytiky jsou
	 * per-DS i per-řádek (msi 042002/042001/501101, lefreal 042100/042500),
	 * takže jediný spolehlivý zdroj je deník. Silný klíč je property; částka
	 * v domácí měně (taxBaseHc ↔ moneyDr). Vrstvený lookup:
	 *   1. přesná shoda (document, property, moneyDr = taxBaseHc),
	 *   2. součet skupiny — starý deník agreguje řádky téhož (účet, property)
	 *      do jedné položky, proto SUM(taxBaseHc) přes řádky dokladu se
	 *      stejnou (property, operation) proti moneyDr,
	 *   3. jediný distinct MD účet na (document, property) mimo DPH (343*)
	 *      a zaokrouhlení (548*) — kryje deníkové korekce částek.
	 * Každý krok bere jen jednoznačný výsledek (právě 1 kandidát); jinak
	 * rejectReason + null → doklad failne (D3d — žádná tichá volba účtu).
	 */
	private function resolveAssetAccount(int $docNdx, array $row): ?string
	{
		$rowNdx   = (int) ($row['ndx'] ?? 0);
		$property = (int) ($row['property'] ?? 0);
		if ($property <= 0)
		{
			$this->rejectReason = "row ndx={$rowNdx}: asset operation without property"
				. ' — cannot resolve account from journal';
			return null;
		}

		// 1. přesná shoda řádek ↔ deníková položka
		$exact = $this->db()->query(
			'SELECT [accountDrId] FROM [e10doc_debs_journal]'
			. ' WHERE [document] = %i', $docNdx,
			' AND [property] = %i', $property,
			' AND [moneyDr] = %f', (float) ($row['taxBaseHc'] ?? 0),
		)->fetchAll();
		if (count($exact) === 1)
			return (string) $exact[0]['accountDrId'];

		// 2. deníková agregace: součet skupiny (property, stará operace)
		$groupSum = $this->db()->query(
			'SELECT SUM([taxBaseHc]) FROM [e10doc_core_rows]'
			. ' WHERE [document] = %i', $docNdx,
			' AND [property] = %i', $property,
			' AND [operation] = %i', (int) ($row['operation'] ?? 0),
		)->fetchSingle();
		$bySum = $this->db()->query(
			'SELECT [accountDrId] FROM [e10doc_debs_journal]'
			. ' WHERE [document] = %i', $docNdx,
			' AND [property] = %i', $property,
			' AND [moneyDr] = %f', (float) $groupSum,
		)->fetchAll();
		if (count($bySum) === 1)
			return (string) $bySum[0]['accountDrId'];

		// 3. korigované částky: jediný MD účet na property mimo DPH/zaokrouhlení
		$distinct = $this->db()->query(
			'SELECT DISTINCT [accountDrId] FROM [e10doc_debs_journal]'
			. ' WHERE [document] = %i', $docNdx,
			' AND [property] = %i', $property,
			' AND [moneyDr] > 0',
			" AND [accountDrId] <> ''",
			' AND [accountDrId] NOT LIKE %s', '343%',
			' AND [accountDrId] NOT LIKE %s', '548%',
		)->fetchAll();
		if (count($distinct) === 1)
			return (string) $distinct[0]['accountDrId'];

		$this->rejectReason = "row ndx={$rowNdx}: asset account not resolvable from journal"
			. " (property={$property}, taxBaseHc=" . (float) ($row['taxBaseHc'] ?? 0)
			. ', exact=' . count($exact) . ', bySum=' . count($bySum)
			. ', distinctDr=' . count($distinct) . ')';
		return null;
	}

	/**
	 * Pár účtů MD × DAL oboustranného majetkového řádku (CMNBKP_ASSET_PAIR_OPS)
	 * ze starého deníku: per (document, property, částka) má deník právě jednu
	 * MD a jednu DAL položku (debit == credit == částka řádku). Jiný počet
	 * kandidátů na kterékoli straně = rejectReason + null (D3d — žádná tichá
	 * volba účtu). Vrací ['dr' => MD účet, 'cr' => DAL účet].
	 */
	private function resolveAssetPairAccounts(int $docNdx, array $row): ?array
	{
		$rowNdx   = (int) ($row['ndx'] ?? 0);
		$property = (int) ($row['property'] ?? 0);
		$amount   = (float) ($row['debit'] ?? 0);
		if ($property <= 0)
		{
			$this->rejectReason = "row ndx={$rowNdx}: asset pair operation without property"
				. ' — cannot resolve accounts from journal';
			return null;
		}

		// ABS(...) < 0.005 místo přesné rovnosti: %f (double) vs DECIMAL sloupec
		// se u částek mimo přesnou binární reprezentaci (např. 578428.93) mine.
		$dr = $this->db()->query(
			'SELECT DISTINCT [accountDrId] FROM [e10doc_debs_journal]'
			. ' WHERE [document] = %i', $docNdx,
			' AND [property] = %i', $property,
			' AND ABS([moneyDr] - %f) < 0.005', $amount,
			" AND [accountDrId] <> ''",
		)->fetchAll();
		$cr = $this->db()->query(
			'SELECT DISTINCT [accountCrId] FROM [e10doc_debs_journal]'
			. ' WHERE [document] = %i', $docNdx,
			' AND [property] = %i', $property,
			' AND ABS([moneyCr] - %f) < 0.005', $amount,
			" AND [accountCrId] <> ''",
		)->fetchAll();
		if (count($dr) === 1 && count($cr) === 1)
			return ['dr' => (string) $dr[0]['accountDrId'], 'cr' => (string) $cr[0]['accountCrId']];

		$this->rejectReason = "row ndx={$rowNdx}: asset pair accounts not resolvable from journal"
			. " (property={$property}, amount={$amount}, distinctDr=" . count($dr)
			. ', distinctCr=' . count($cr) . ')';
		return null;
	}

	/**
	 * Směr kurzového rozdílu (loss/gain) ze starého deníku (D12). Starý řádek
	 * 1090011/1090012 směr nenese — P&L stranu (563/663) účtoval samostatný
	 * řádek 1099998 a v deníku je agregovaná do jedné položky per směr.
	 * Vrstvený lookup:
	 *   1. P&L položka: moneyDr = částka + accountDrId 563* → loss; moneyCr
	 *      = částka + accountCrId 663* → gain. Shoda částky vyjde jen
	 *      u dokladu s jediným řádkem daného směru (kvůli agregaci).
	 *   2. saldo položka (per řádek, groupBy off, nese symbol1/person):
	 *      účet dle saldoCat (311* / 321*) + částka, zpřesněná symbol1
	 *      a person z řádku; MD saldo = gain (MD 311/321 / DAL 663),
	 *      DAL saldo = loss (MD 563 / DAL 311/321).
	 *   3. doklad bez deníku (storno 4100 → 30, nezaúčtováno): směr ze strany
	 *      řádku dle acc-default (docDir 1/MD = gain, docDir 2/DAL = loss) —
	 *      deterministické, deník tu z principu neexistuje.
	 * Vrstvy 1–2 berou jen jednoznačný výsledek (právě jedna strana má
	 * kandidáty); jinak rejectReason + null → doklad failne (D3d — žádná
	 * tichá volba směru).
	 */
	private function resolveFxDirection(int $docNdx, array $row, float $amount): ?string
	{
		$rowNdx = (int) ($row['ndx'] ?? 0);
		$oldOp  = (int) ($row['operation'] ?? 0);

		// 1. P&L položka deníku (agregát per směr). ABS(...) < 0.005 místo přesné
		// rovnosti — %f (double) vs DECIMAL se u některých částek mine.
		$plLoss = (int) $this->db()->query(
			'SELECT COUNT(*) FROM [e10doc_debs_journal]'
			. ' WHERE [document] = %i', $docNdx,
			' AND ABS([moneyDr] - %f) < 0.005', $amount,
			' AND [accountDrId] LIKE %s', '563%',
		)->fetchSingle();
		$plGain = (int) $this->db()->query(
			'SELECT COUNT(*) FROM [e10doc_debs_journal]'
			. ' WHERE [document] = %i', $docNdx,
			' AND ABS([moneyCr] - %f) < 0.005', $amount,
			' AND [accountCrId] LIKE %s', '663%',
		)->fetchSingle();
		if (($plLoss > 0) xor ($plGain > 0))
			return $plLoss > 0 ? 'loss' : 'gain';

		// 2. saldo položka deníku (per řádek, se saldo identitou)
		$catMask  = self::CMNBKP_FX_OP[$oldOp]['saldoCat'] . '%';
		$symbol1  = $this->emptyToNull($row['symbol1'] ?? null);
		$person   = (int) ($row['person'] ?? 0);
		$saldoDr = $this->countFxSaldoRows($docNdx, 'Dr', $catMask, $amount, $symbol1, $person);
		$saldoCr = $this->countFxSaldoRows($docNdx, 'Cr', $catMask, $amount, $symbol1, $person);
		if (($saldoDr > 0) xor ($saldoCr > 0))
			return $saldoDr > 0 ? 'gain' : 'loss';

		// 3. nezaúčtovaný doklad (storno) nemá deník — směr ze strany řádku
		$journalRows = (int) $this->db()->query(
			'SELECT COUNT(*) FROM [e10doc_debs_journal]'
			. ' WHERE [document] = %i', $docNdx,
		)->fetchSingle();
		if ($journalRows === 0)
		{
			$dir = (float) ($row['debit'] ?? 0) != 0.0 ? 'gain' : 'loss';
			$this->debug("doc {$docNdx} row {$rowNdx}: no journal (unposted/storno), "
				. "FX direction '{$dir}' from row side");
			return $dir;
		}

		$this->rejectReason = "row ndx={$rowNdx}: FX direction not resolvable from journal"
			. " (op={$oldOp}, amount={$amount}, pl loss={$plLoss}/gain={$plGain},"
			. " saldo dr={$saldoDr}/cr={$saldoCr})";
		return null;
	}

	/** Počet saldo položek deníku dané strany pro resolveFxDirection vrstvu 2. */
	private function countFxSaldoRows(int $docNdx, string $side, string $catMask,
		float $amount, ?string $symbol1, int $person): int
	{
		$q = [
			'SELECT COUNT(*) FROM [e10doc_debs_journal]'
			. ' WHERE [document] = %i', $docNdx,
			' AND ABS([money' . $side . '] - %f) < 0.005', $amount,
			' AND [account' . $side . 'Id] LIKE %s', $catMask,
		];
		if ($symbol1 !== null)
		{
			$q[] = ' AND [symbol1] = %s';
			$q[] = $symbol1;
		}
		if ($person > 0)
		{
			$q[] = ' AND [person] = %i';
			$q[] = $person;
		}
		return (int) $this->db()->query(...$q)->fetchSingle();
	}

	/**
	 * Starý řádkový pohyb (číselný klíč e10doc_core_rows.operation) → nový string
	 * (docs.core.rowOperations) přes ROW_OPERATION_MAP. Jen pro item řádky —
	 * zálohy/majetek jdou přes ROW_OPERATION_ACCOUNT_MAP (loadRows bod 1) a
	 * kategorie ops bez itemu přes bod 3. Neznámý/0 → docType default.
	 * Vrací null jen pro docType mimo mapu (nemělo by nastat).
	 */
	private function mapRowOperation(string $oldDocType, int $oldOp): ?string
	{
		$op = self::ROW_OPERATION_MAP[$oldDocType][$oldOp] ?? null;
		if ($op === null)
		{
			$op = self::ROW_OPERATION_DEFAULT[$oldDocType] ?? null;
			if ($op !== null)
				$this->debug("doc row: unmapped operation {$oldOp} for {$oldDocType}, defaulting to '{$op}'");
		}
		return $op;
	}

	/**
	 * Akumuluje souhrn per (docType, řada) pro závěrečnou diagnostiku.
	 */
	private function recordSeries(string $oldDocType, ?string $seriesCode, ?int $sequence, string $kind): void
	{
		$key = $oldDocType . '|' . ($seriesCode ?? '?');
		if (!isset($this->seriesSummary[$key]))
			$this->seriesSummary[$key] = ['imported' => 0, 'failed' => 0, 'maxSeq' => 0];

		if ($kind === 'imported')
		{
			$this->seriesSummary[$key]['imported']++;
			if ($sequence !== null && $sequence > $this->seriesSummary[$key]['maxSeq'])
				$this->seriesSummary[$key]['maxSeq'] = $sequence;
		}
		elseif ($kind === 'failed')
		{
			$this->seriesSummary[$key]['failed']++;
		}
	}

	/**
	 * Souhrn per (docType | docKeyId): imported / failed / max sequence —
	 * usnadní kontrolu počítadel řad po importu (Fáze 10, bod 5).
	 */
	private function printSeriesSummary(): void
	{
		if ($this->seriesSummary === [])
			return;

		ksort($this->seriesSummary);
		$this->info("");
		$this->info("Series summary (docType | docKeyId): imported / failed / maxSeq");
		foreach ($this->seriesSummary as $key => $s)
			$this->info(sprintf("  %-24s  imported=%d failed=%d maxSeq=%d",
				$key, $s['imported'], $s['failed'], $s['maxSeq']));
	}

	/**
	 * Akumuluje původ DIČ partnera pro souhrn běhu (Task 29).
	 */
	private function recordVatIdSource(string $oldDocType, string $source, bool $partnerUnpinned): void
	{
		if (!isset($this->vatIdStats[$oldDocType]))
			$this->vatIdStats[$oldDocType] = ['header' => 0, 'directory' => 0, 'none' => 0, 'unpinned' => 0];

		$this->vatIdStats[$oldDocType][$source]++;
		if ($partnerUnpinned && $source !== 'none')
			$this->vatIdStats[$oldDocType]['unpinned']++;
	}

	/**
	 * Souhrn původu DIČ partnera per docType. `none` = doklady, u kterých bude na
	 * nové straně legitimně chybět DIČ ve snapshotu (KH `vatKh.missingVatId`).
	 * `unpinned` = partner není v LocalIdMap, takže vatId slouží na nové straně
	 * i jako business klíč dohledání osoby — hodnota z hlavičky pak může trefit
	 * jinou osobu než adresářová.
	 *
	 * Pozor: počítají se jen doklady, které tento běh skutečně stavěl. Doklady už
	 * zapsané v LocalIdMap se přeskakují před buildCanonical(), takže dry-run nad
	 * naimportovaným DS vypíše prázdno — počty přes celý zdroj dá pre-flight SQL
	 * v README (sekce Doklady).
	 */
	private function printVatIdSummary(): void
	{
		if ($this->vatIdStats === [])
			return;

		ksort($this->vatIdStats);
		$this->info("");
		$this->info("Partner VAT id source (docType): header / directory / none");
		foreach ($this->vatIdStats as $docType => $s)
			$this->info(sprintf("  %-8s  header=%d directory=%d none=%d (unpinned partner=%d)",
				$docType, $s['header'], $s['directory'], $s['none'], $s['unpinned']));
	}

	/**
	 * Vlastní bankovní účet z hlavičky (myBankAccount → e10doc.base.bankaccounts)
	 * namapovaný na nový economy_codebooks_bank_accounts přes LocalIdMap (Fáze 02).
	 * Vydané faktury ho potřebují při stavech 40/80 (IssuedInvoiceDocument::validate);
	 * importér ho předá v applyOptions.importOwnBankAccount.
	 */
	private function resolveOwnBankAccount(array $oldRow): ?int
	{
		$myBankNdx = (int) ($oldRow['myBankAccount'] ?? 0);
		if ($myBankNdx <= 0)
			return null;
		return $this->idMap()->lookup(LocalIdMap::ENTITY_BANK_ACCOUNT, $myBankNdx);
	}

	// ── Helpers (utility) ──────────────────────────────────────────────────

	private function emptyToNull(mixed $value): ?string
	{
		if ($value === null)
			return null;
		$trimmed = trim((string) $value);
		return $trimmed === '' ? null : $trimmed;
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

	private function numberOrNull(mixed $value): ?float
	{
		if ($value === null || $value === '')
			return null;
		return (float) $value;
	}

	private function moneyOrNull(mixed $value): ?float
	{
		if ($value === null || $value === '')
			return null;
		return (float) $value;
	}

	/**
	 * Deklarovaný součet ponížený o vypuštěné doprovodné P&L řádky (D12).
	 * Null zůstává null (sémantika moneyOrNull); výsledek zaokrouhlen na
	 * haléře, aby float aritmetika nezanesla artefakty do payloadu.
	 */
	private function totalsLessDropped(mixed $value, float $dropped): ?float
	{
		$money = $this->moneyOrNull($value);
		if ($money === null || $dropped == 0.0)
			return $money;
		return round($money - $dropped, 2);
	}

	private function currencyUpper(mixed $val): ?string
	{
		$s = strtoupper(trim((string) ($val ?? '')));
		return preg_match('/^[A-Z]{3}$/', $s) ? $s : null;
	}

	private function positiveOrNull(mixed $val): ?float
	{
		if ($val === null || $val === '')
			return null;
		$f = (float) $val;
		return $f > 0 ? $f : null;
	}

	/**
	 * Země plnění — schema vat.registrationCountry vyžaduje 2 písmena.
	 * Starý taxCountry je enumString len 2 (např. "CZ"); cokoli jiného → null.
	 */
	private function countryCode(mixed $val): ?string
	{
		$s = trim((string) ($val ?? ''));
		return preg_match('/^[A-Za-z]{2}$/', $s) ? $s : null;
	}

	/**
	 * Jednotka řádku. Starý Shipard má systémovou jednotku "none" pro řádky
	 * bez jednotky — nový to vyjadřuje jako null (prázdný sloupec unit).
	 */
	private function unitOrNull(mixed $val): ?string
	{
		$s = trim((string) ($val ?? ''));
		if ($s === '' || strcasecmp($s, 'none') === 0)
			return null;
		return $s;
	}

	/**
	 * Konverze kódu DPH ze starého formátu (EUCZ{NNN}) na nový (cz-{NNN}).
	 * Při migraci v novém Shipardu byly všechny kódy přejmenovány EUCZ→cz-,
	 * EUCZ000/EUCZ113 vynechány (viz nov_shipard:modules/world/vat/config/vat-cz.jsonc).
	 * Neznámý formát se pošle beze změny — applier případně warne a spadne
	 * zpět na vat.pct.
	 */
	private function mapVatCode(mixed $val): ?string
	{
		$code = strtoupper(trim((string) ($val ?? '')));
		if ($code === '' || in_array($code, self::VAT_CODE_DROP, true))
			return null;
		if (preg_match('/^EUCZ(\d+)$/', $code, $m))
			return 'cz-' . $m[1];
		$this->debug("doc row: unmapped vat code '{$code}', passing through");
		return $code;
	}
}
