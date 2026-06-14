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
 * MVP scope: faktury přijaté (invni) a vydané (invno). Ostatní typy
 * (pokladní, bankovní, objednávky, dodací listy) jsou mimo scope —
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
	/** Mapování starého docType → canonical docType. MVP: jen faktury. */
	private const DOC_TYPE_MAP = [
		'invni' => 'invoiceReceived',
		'invno' => 'invoiceIssued',
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
	 * (NE naopak). PayPal (11) mapujeme na kartu — taky nemá bankovní účet,
	 * takže bankTransfer by byl zavádějící. Ostatní starší kódy (Fakturou,
	 * Inkasem, Šekem, …) nemají přímý protějšek v novém formátu (cash/
	 * bankTransfer/card/cashOnDelivery/setOff) → fallback na bankTransfer.
	 * Neznámé → bankTransfer.
	 */
	private const PAYMENT_METHOD_MAP = [
		0  => 'bankTransfer',    // Převodním příkazem
		1  => 'cash',            // Hotově
		2  => 'card',            // Kartou
		3  => 'cashOnDelivery',  // Dobírka
		11 => 'card',            // PayPal (bez bankovního účtu → karta)
	];

	/**
	 * Staré kódy DPH, které nový Shipard nezná (vynechány při migraci
	 * vat-cz.json → vat-cz.jsonc): EUCZ000 = nedaňový řádek, EUCZ113 =
	 * artefakt zdroje. Mapujeme na null → řádek bez kódu DPH.
	 */
	private const VAT_CODE_DROP = ['EUCZ000', 'EUCZ113'];

	/**
	 * Mapování starého docState → nový cílový stav (Fáze 10). Nové stavy:
	 * 10 Koncept, 20 Potvrzeno, 40 V pořádku, 30 Storno (NE 70 — to je
	 * „V archívu", pro doklady zrušeno; schema enum je [10,20,40,30]).
	 * 8000 (V opravě) bereme jako finalizovaný doklad → 40 (zaúčtuje se).
	 * Staré 9800 (smazáno) je odfiltrováno už v sourceQuery. Neznámý stav =
	 * tvrdá chyba (fail dokladu), ne tichý default.
	 */
	private const DOC_STATE_MAP_TARGET = [
		1000 => 10,   // Nově rozpracováno → Koncept (bez čísla)
		1200 => 20,   // Potvrzeno         → Potvrzeno
		4000 => 40,   // Hotovo            → V pořádku (+ zaúčtování)
		4100 => 30,   // Stornováno        → Storno (s číslem, bez deníku)
		8000 => 40,   // V opravě          → V pořádku (finalizovat + zaúčtovat)
	];

	/**
	 * Stavy nesoucí přidělené číslo (řada + sekvence + importNumber). Mimo
	 * koncept (10), který číslo nemá. Sloučeno do predikátu místo „>= 20",
	 * ať to nedrhne o číselné uspořádání stavů (30 < 40).
	 */
	private const STATES_WITH_NUMBER = [20, 30, 40];

	/**
	 * Mapování starého řádkového pohybu (e10doc_core_rows.operation, číselné
	 * klíče e10.docs.operations) → nový string pohyb (docs.core.rowOperations).
	 * Per docType — staré klíče jsou docType-scoped. Stav 40 vyžaduje u každého
	 * item-řádku platný pohyb (DocDocument::validateRowOperations); bez něj
	 * doklad uvízne. acc.entry vyžaduje vyplněný item (jinak fallback na default).
	 */
	private const ROW_OPERATION_MAP = [
		'invni' => [
			1010102 => 'purchase.goods',     // Nákup zásob
			1010199 => 'purchase.goods',     // Nákup zásob bez evidence
			1090050 => 'purchase.other',     // Pořízení dlouhodobého majetku
			1090051 => 'purchase.other',     // Technické zhodnocení majetku
			1090052 => 'purchase.other',     // Nákup evidovaného majetku
			1020101 => 'acc.entry',          // Odpočet poskytnuté zálohy
			1020104 => 'acc.entry',          // Zdanění poskytnuté zálohy
			1099998 => 'acc.entry',          // Účetní položka
		],
		'invno' => [
			1010001 => 'sale.services',      // Prodej služeb
			1010002 => 'sale.goods',         // Prodej zásob
			1010099 => 'sale.goods',         // Prodej zásob bez evidence
			1090060 => 'sale.goods',         // Prodej majetku
			1010101 => 'acc.entry',          // Odpočet přijaté zálohy
			1010104 => 'acc.entry',          // Zdanění přijaté zálohy
			1099998 => 'acc.entry',          // Účetní položka
		],
	];

	/** Fallback pohyb pro op=0 / neznámý klíč (default řádku v novém configu). */
	private const ROW_OPERATION_DEFAULT = [
		'invni' => 'purchase.goods',
		'invno' => 'sale.services',
	];

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
			$rows = $this->fetchSourceRows();

			if ($limit > 0)
			{
				$remaining = $limit - array_sum($stats);
				if ($remaining <= 0)
					break;
				if (count($rows) > $remaining)
					$rows = array_slice($rows, 0, $remaining);
			}

			$this->info("— chunk {$cFrom} … {$cTo}: " . count($rows) . " docs");
			if (!$this->processRows($rows, $exchange, $stats))
				return false;

			unset($rows);   // uvolnit před dalším úsekem
		}

		$this->printSeriesSummary();
		$this->printDone($stats);
		return $stats['failed'] === 0;
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

	protected function sourceQuery(): array
	{
		$docTypes = array_keys(self::DOC_TYPE_MAP);  // ['invni', 'invno']

		$q = [
			'SELECT h.* FROM [e10doc_core_heads] h'
			. ' WHERE h.[docState] != %i', 9800,     // ne smazané
			' AND h.[docType] IN %in', $docTypes,     // jen faktury (MVP)
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

		$q[] = ' ORDER BY h.[ndx]';
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

		// Partner jde do strany, kterou MY nejsme. Vlastní firma → selfParty flag.
		$supplier = $selfParty === 'supplier' ? null : $partnerParty;
		$customer = $selfParty === 'customer' ? null : $partnerParty;

		$rows = $this->loadRows($oldNdx, $oldDocType);
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
		// (chybějící docKeyId cfg) i nevyhodnotitelná sekvence = tvrdá chyba.
		$seriesCode = null;
		$sequence   = null;
		if ($hasNumber)
		{
			$seriesCode = $this->resolveNumberSeriesCode($oldRow);
			if ($seriesCode === null)
			{
				$dbCounter = (int) ($oldRow['dbCounter'] ?? 0);
				$this->rejectReason = "number series code (docKeyId) not found in cfg "
					. "e10.docs.dbCounters.{$oldDocType}.{$dbCounter}.docKeyId";
				return null;
			}
			if ($docNumber === null)
			{
				$this->rejectReason = "target state {$targetState} requires a number but docNumber is empty";
				return null;
			}
			$diag = '';
			$sequence = $this->parseSequenceNumber($oldRow, $seriesCode, $diag);
			if ($sequence === null)
			{
				$this->rejectReason = "cannot parse sequence from docNumber '{$docNumber}' ({$diag})";
				return null;
			}
		}

		$this->lastSeriesCode = $seriesCode;
		$this->lastSequence   = $sequence;

		$applyOptions = [
			'targetDocState'        => $targetState,
			'autoCreateMode'        => 'safe',
			'createMissingEntities' => true,
			'rejectOnIssues'        => ['error'],
		];

		// Řadu + číslo posíláme jen u stavů nesoucích číslo (importNumber má obě
		// pole zaručeně neprázdná — jinak doklad výše failnul). Koncept (10) jde
		// bez čísla; applier ho přidělí standardně při ručním povýšení.
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

		return [
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
	}

	/**
	 * Pořadové číslo (sequence) z původního docNumber, řízené formulí řady.
	 * Replikuje prefix/suffix část makeDocNumber: vyhodnotí tokeny formule pro
	 * tento doklad (s autoritativním %C = $seriesCode), ořízne vyhodnocený
	 * prefix zleva a suffix zprava — zbytek je počítadlo.
	 *
	 * Fáze 10: žádný fallback. Neshoda formule (chybějící counter token, zbylý
	 * nevyhodnocený token, neshoda prefixu/suffixu, nečíselné jádro) = null.
	 * Volající (buildCanonical) z null udělá tvrdou chybu dokladu. $diag nese
	 * lidsky čitelný důvod do hlášky failu.
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

		$prefix = $this->evaluateNumberTokens($m[1], $oldRow, $seriesCode);
		$suffix = $this->evaluateNumberTokens($m[3], $oldRow, $seriesCode);
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
	 * autoritativní kód řady ($seriesCode = docKeyId z dbCounter). Tokeny
	 * %B/%A/%W (cashBox/bankAccount/warehouse id) ponecháváme nevyhodnocené;
	 * jejich přítomnost shodí parser na null (neshoda formule).
	 */
	private function evaluateNumberTokens(string $pattern, array $oldRow, string $seriesCode): string
	{
		if ($pattern === '')
			return '';

		$docType = (string) ($oldRow['docType'] ?? '');
		$dateAcc = $oldRow['dateAccounting'] ?? null;
		$dt = $dateAcc instanceof \DateTimeInterface
			? $dateAcc
			: (is_string($dateAcc) && $dateAcc !== '' && !str_starts_with($dateAcc, '0000')
				? new \DateTime($dateAcc) : null);

		$docIdCode = (string) $this->app()->cfgItem('e10.docs.types.' . $docType . '.docIdCode', '');

		return strtr($pattern, [
			'%D' => $docIdCode,
			'%r' => $this->resolveFiscalYearMark($oldRow),
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

		$dateAcc = $this->dateToString($oldRow['dateAccounting'] ?? null);
		if ($dateAcc !== null)
		{
			$r = $this->db()->query(
				'SELECT [mark] FROM [e10doc_base_fiscalyears] WHERE [docState] != %i', 9800,
				' AND [start] <= %d', $dateAcc,
				' AND [end] >= %d', $dateAcc,
				' ORDER BY [start] DESC',
			)->fetch();
			if ($r !== null)
				return (string) ($r['mark'] ?? '');
		}

		return '';
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
	 * U item-řádků mapuje starý pohyb (operation) na nový string — stav 40 ho
	 * vyžaduje (DocDocument::validateRowOperations). Text-řádky pohyb nemají.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadRows(int $docNdx, string $oldDocType): array
	{
		$rows = $this->db()->query(
			'SELECT r.*, i.[id] AS item_code, i.[fullName] AS item_name'
			. ' FROM [e10doc_core_rows] r'
			. ' LEFT JOIN [e10_witems_items] i ON r.[item] = i.[ndx]'
			. ' WHERE r.[document] = %i', $docNdx,
			' ORDER BY r.[rowOrder], r.[ndx]',
		)->fetchAll();

		$out = [];
		$pos = 0;
		foreach ($rows as $r)
		{
			$row = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;
			$pos++;

			$itemCode = $this->emptyToNull($row['item_code'] ?? null);
			$itemName = $this->emptyToNull($row['item_name'] ?? null)
				?? $this->emptyToNull($row['text'] ?? null);

			$rowKind = ($itemCode !== null || (int) ($row['item'] ?? 0) > 0) ? 'item' : 'text';

			// Pohyb jen u item-řádků; text-řádek pohyb mít nesmí (applier ho odmítne).
			$operation = $rowKind === 'item'
				? $this->mapRowOperation($oldDocType, (int) ($row['operation'] ?? 0), $itemCode !== null || (int) ($row['item'] ?? 0) > 0)
				: null;

			$out[] = [
				'rowKind'   => $rowKind,
				'operation' => $operation,
				'orderPos'  => $pos,
				'item'     => $rowKind === 'item' ? [
					'ourCode'      => $itemCode,
					'supplierCode' => null,
					'sku'          => null,
					'ean'          => null,
					'name'         => $itemName,
					'description'  => $this->emptyToNull($row['text'] ?? null),
				] : null,
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
		}
		return $out;
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
	 * Starý řádkový pohyb (číselný klíč e10doc_core_rows.operation) → nový string
	 * (docs.core.rowOperations) přes ROW_OPERATION_MAP. Neznámý/0 → docType
	 * default. acc.entry bez vyplněného itemu → také default (applier acc.entry
	 * bez položky odmítne). Vrací null jen pro docType mimo mapu (nemělo by nastat).
	 */
	private function mapRowOperation(string $oldDocType, int $oldOp, bool $hasItem): ?string
	{
		$op = self::ROW_OPERATION_MAP[$oldDocType][$oldOp] ?? null;
		if ($op === null)
		{
			$op = self::ROW_OPERATION_DEFAULT[$oldDocType] ?? null;
			if ($op !== null)
				$this->debug("doc row: unmapped operation {$oldOp} for {$oldDocType}, defaulting to '{$op}'");
		}
		if ($op === 'acc.entry' && !$hasItem)
		{
			$fallback = self::ROW_OPERATION_DEFAULT[$oldDocType] ?? null;
			$this->debug("doc row: acc.entry without item, falling back to '{$fallback}'");
			$op = $fallback;
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
	 * Vlastní bankovní účet z hlavičky (myBankAccount → e10doc.base.bankaccounts)
	 * namapovaný na nový economy_codebooks_bank_accounts přes LocalIdMap (Fáze 02).
	 * Vydané faktury ho potřebují při stavu 20+ (IssuedInvoiceDocument::validate);
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
