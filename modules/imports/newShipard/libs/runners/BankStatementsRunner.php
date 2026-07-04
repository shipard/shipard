<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\AttachmentImporter;
use imports\newShipard\libs\AttachmentReader;
use imports\newShipard\libs\AttachmentUploadClient;
use imports\newShipard\libs\BaseExchangeRunner;
use imports\newShipard\libs\CrudClient;
use imports\newShipard\libs\ExchangeClient;
use imports\newShipard\libs\LocalIdMap;

/**
 * Migrace bankovních výpisů ze starého Shipardu (e10doc_core_heads docType='bank'
 * + e10doc_core_rows) do nového přes exchange formát shpd.bank.statement.v1
 * (POST /_exchange/bank/statement/apply). Závěrečná fáze modulu economy.bank
 * (Fáze 11; protistrana nov_shipard:tasks/bank-phase4.md musí být nasazena dřív).
 *
 * Vzor DocsRunner — podstatně jednodušší: bez řad, čísel, selfParty. Náš účet jde
 * jako bankAccountId (LocalIdMap z myBankAccount); idempotence přes
 * ENTITY_BANK_STATEMENT (klíč = old ndx hlavičky), transakce uvnitř deduplikuje
 * nová strana (external_id/fingerprint). Účtování i partnera dělá nová strana;
 * runner posílá jen fakta transakce (operation=null, amount znaménková).
 *
 * Rozsah (docs/bank.md §7): jen výpisy + transakce. Párování (saldo) se nemigruje —
 * transakce „hotových" výpisů se zaúčtují novým enginem na clearing (261200/261300),
 * nenulové clearing zůstatky po migraci jsou očekávané.
 */
final class BankStatementsRunner extends BaseExchangeRunner
{
	/** Cílová tabulka výpisů v novém Shipardu (economy_bank_statements) — pro PDF přílohy. */
	private const STATEMENT_TABLE_ID = 415;

	/** Starý docState výpisu Stornováno — soft-skip (viz buildCanonical). */
	private const DOC_STATE_STORNO = 4100;

	/**
	 * Starý docState výpisu → cílový stav (applyOptions.targetState, schema enum
	 * [10,40]). „Hotové" (4000/8000) se zaúčtují (40); rozpracované (1000/1200) →
	 * koncept (10). Nový enum nezná 20, proto 1200→10. Storno (4100) NENÍ v mapě —
	 * řeší se soft-skipem v buildCanonical (zrušený výpis se nemigruje; jeho
	 * transakce by jinak zaúčtovaly zrušené pohyby na clearing). Jiný neznámý stav
	 * = tvrdá chyba (fail výpisu, ne tichý default — vzor DocsRunner).
	 */
	private const DOC_STATE_MAP_TARGET = [
		1000 => 10,   // Nově rozpracováno → koncept
		1200 => 10,   // Potvrzeno         → koncept
		4000 => 40,   // Hotovo            → zaúčtovat (clearing)
		8000 => 40,   // V opravě          → zaúčtovat
	];

	/** Tvrdá chyba ve stavbě payloadu (povýšení skipped/incomplete → failed). */
	private ?string $rejectReason = null;

	/** Agregovaný souhrn uploadu PDF příloh napříč během. */
	private array $attStats = ['uploaded' => 0, 'duplicate' => 0, 'missing' => 0, 'failed' => 0];

	protected function entityType(): string   { return LocalIdMap::ENTITY_BANK_STATEMENT; }
	protected function exchangeFlow(): string { return 'bank'; }
	protected function exchangeType(): string { return 'statement'; }
	protected function savedIdKey(): string   { return 'savedStatementId'; }
	protected function entityLabel(): string  { return 'bank statement'; }

	protected function sourceQuery(): array
	{
		$q = [
			'SELECT h.* FROM [e10doc_core_heads] h'
			. ' WHERE h.[docState] != %i', 9800,
			' AND h.[docType] = %s', 'bank',
		];

		// Volitelné okno na konec období výpisu (chunkování netřeba — výpisů je málo).
		$from = $this->dateArg('from');
		$to   = $this->dateArg('to');
		if ($from !== null) { $q[] = ' AND h.[datePeriodEnd] >= %d'; $q[] = $from; }
		if ($to   !== null) { $q[] = ' AND h.[datePeriodEnd] <= %d'; $q[] = $to; }

		$q[] = ' ORDER BY h.[ndx]';
		return $q;
	}

	protected function rowDescriptor(array $oldRow): string
	{
		$no     = trim((string) ($oldRow['docOrderNumber'] ?? ''));
		$period = $this->dateToString($oldRow['datePeriodEnd'] ?? null) ?? '';
		return trim("#{$no} {$period}");
	}

	/**
	 * Obal nad base processOneRow — neznámý docState (rejectReason) povýší
	 * z incomplete na 'failed' (vzor DocsRunner), bez výjimky (import pokračuje).
	 * Storno (4100) rejectReason nenastaví → zůstává soft-skip (incomplete).
	 */
	protected function processOneRow(array $oldRow, ExchangeClient $exchange): array
	{
		$this->rejectReason = null;
		$result = parent::processOneRow($oldRow, $exchange);

		if ($this->rejectReason !== null && ($result['reason'] ?? null) === 'incomplete')
			return ['status' => 'failed', 'reason' => $this->rejectReason];

		return $result;
	}

	protected function buildCanonical(array $oldRow): ?array
	{
		$oldNdx = (int) $oldRow['ndx'];

		// Náš účet: myBankAccount (ndx) → LocalIdMap. Nenalezeno → soft skip
		// (účet musí napřed naimportovat bank-accounts; AllCodebooks běží dřív).
		$myBankNdx = (int) ($oldRow['myBankAccount'] ?? 0);
		$bankAccountId = $myBankNdx > 0
			? $this->idMap()->lookup(LocalIdMap::ENTITY_BANK_ACCOUNT, $myBankNdx)
			: null;
		if ($bankAccountId === null)
		{
			$this->warn("statement {$oldNdx}: own bank account (myBankAccount={$myBankNdx}) "
				. "not in LocalIdMap — import bank-accounts first; skipping");
			return null;
		}

		$oldState = (int) ($oldRow['docState'] ?? 0);

		// Storno (4100) — výpis zrušen; jeho transakce by se neměly zaúčtovat na
		// clearing (u některých DS storno reálné řádky má). Soft-skip (warn, NE
		// fail). Pokud se ukáže, že storno výpisy jsou v daném DS reálná data,
		// lze místo skipu importovat jako koncept (targetState 10).
		if ($oldState === self::DOC_STATE_STORNO)
		{
			$this->warn("statement {$oldNdx}: docState 4100 (storno) — skipping (voided)");
			return null;
		}

		// Cílový stav z mapy — jiný neznámý stav = tvrdá chyba (→ failed).
		$targetState = self::DOC_STATE_MAP_TARGET[$oldState] ?? null;
		if ($targetState === null)
		{
			$this->rejectReason = "unknown old docState {$oldState} (not in DOC_STATE_MAP_TARGET)";
			return null;
		}

		$periodStart = $this->dateToString($oldRow['datePeriodBegin'] ?? null);
		$periodEnd   = $this->dateToString($oldRow['datePeriodEnd'] ?? null);
		if ($periodStart === null || $periodEnd === null)
		{
			$this->rejectReason = "missing statement period (datePeriodBegin/datePeriodEnd)";
			return null;
		}

		$orderNo = (int) ($oldRow['docOrderNumber'] ?? 0);

		return [
			'format'        => 'shpd.bank.statement',
			'formatVersion' => '1.0',

			'source' => ['kind' => 'import.oldShipard', 'raw' => ['oldNdx' => $oldNdx]],

			'bankAccountId' => $bankAccountId,

			'statement' => [
				'statementNumber' => $orderNo > 0 ? (string) $orderNo : null,
				'periodStart'     => $periodStart,
				'periodEnd'       => $periodEnd,
				'openingBalance'  => (float) ($oldRow['initBalance'] ?? 0),
				'closingBalance'  => (float) ($oldRow['balance'] ?? 0),
				'currency'        => $this->currencyUpper($oldRow['currency'] ?? null),
			],

			'transactions' => $this->loadTransactions($oldNdx, $periodEnd),

			'applyOptions' => [
				'targetState'          => $targetState,
				'createMissingPartner' => false,
			],
		];
	}

	/**
	 * Řádky výpisu → transakce. amount znaménková (credit + / debit −); applier
	 * z toho odvodí směr + kladnou částku (ověřeno: žádný řádek nemá credit i debit
	 * zároveň). externalId = stabilní old:{rowNdx} (idempotence i kdyby se výpis
	 * později naimportoval souborem; nová strana dedupne přes external_id/
	 * fingerprint). Řádky bez pohybu peněz (credit==0 && debit==0, např. info)
	 * vynechá. dateTransaction = dateDue řádku (v datech vždy vyplněné), fallback
	 * datum hlavičky. Cizí měna: exchangeRate (za jednotku, z old řádku) se posílá;
	 * nová strana z něj počítá amount_dom (amount × rate). Domácí řádek → null →
	 * rate 1 (FX rozdíly jsou mimo scope migrace).
	 *
	 * partnerId: starý řádek nese přímý odkaz na osobu (e10doc_core_rows.person);
	 * předáváme nové id přes LocalIdMap. Spolehlivější (vyplněno u ~99,9 % řádků)
	 * než párování přes číslo protiúčtu, které nová strana dělá jako fallback a
	 * u zahraničních / neregistrovaných účtů selhává. Vyžaduje nasazenou podporu
	 * partnerId na nové straně (jinak schema_invalid).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadTransactions(int $docNdx, string $headerDateFallback): array
	{
		$rows = $this->db()->query(
			'SELECT r.* FROM [e10doc_core_rows] r'
			. ' WHERE r.[document] = %i', $docNdx,
			' ORDER BY r.[rowOrder], r.[ndx]',
		)->fetchAll();

		$out = [];
		foreach ($rows as $r)
		{
			$row = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;

			$credit = (float) ($row['credit'] ?? 0);
			$debit  = (float) ($row['debit'] ?? 0);
			if ($credit == 0.0 && $debit == 0.0)
				continue;   // řádek bez pohybu peněz (např. informativní)

			$out[] = [
				'externalId'          => 'old:' . (int) $row['ndx'],
				'amount'              => $credit > 0 ? $credit : -$debit,
				'dateTransaction'     => $this->dateToString($row['dateDue'] ?? null) ?? $headerDateFallback,
				'dateValue'           => null,
				'partnerId'           => $this->resolvePartnerId((int) ($row['person'] ?? 0), (int) $row['ndx']),
				'counterpartyAccount' => $this->emptyToNull($row['bankAccount'] ?? null),
				'counterpartyName'    => null,
				// Staré symbol1/2/3 (VS/SS/KS) → nové názvy kanonického schématu
				// (variabilní → paymentReference, viz Fáze 09 rename u dokladů).
				'paymentReference'    => $this->emptyToNull($row['symbol1'] ?? null),
				'specificSymbol'      => $this->emptyToNull($row['symbol2'] ?? null),
				'constantSymbol'      => $this->emptyToNull($row['symbol3'] ?? null),
				'message'             => $this->emptyToNull($row['text'] ?? null),
				'operation'           => null,   // nová strana → default payment.in/out dle směru
				'exchangeRate'        => $this->positiveOrNull($row['exchangeRate'] ?? null),
			];
		}
		return $out;
	}

	/**
	 * Nové id partnera (base_persons_persons) ze starého e10doc_core_rows.person
	 * přes LocalIdMap ENTITY_PERSON. Prázdná osoba (0) → null. Vyplněná, ale
	 * nenamapovaná osoba → debug + null (nová strana spadne zpět na párování
	 * přes číslo protiúčtu). Persons se importují dřív (AllRunner: persons →
	 * items → docs → bank statements), takže miss je u běhu `all` anomálie.
	 */
	private function resolvePartnerId(int $oldPersonNdx, int $rowNdx): ?int
	{
		if ($oldPersonNdx <= 0)
			return null;

		$newId = $this->idMap()->lookup(LocalIdMap::ENTITY_PERSON, $oldPersonNdx);
		if ($newId === null)
		{
			$this->debug("bank row {$rowNdx}: person {$oldPersonNdx} not in LocalIdMap (persons imported?), partnerId=null");
			return null;
		}
		return $newId;
	}

	/**
	 * Po apply: PDF přílohy starého bankovního dokladu → nový výpis (table_id 415).
	 * Sekundární — výpis bez PDF je validní; v některých DS má PDF skoro každý
	 * výpis, jinde žádný. --no-attachments vypne. V dry-runu se nevolá (base skip
	 * větev je před apply checkem).
	 */
	protected function afterApplied(array $oldRow, int $newId, CrudClient $crud): void
	{
		if ((bool) $this->app()->arg('no-attachments'))
			return;

		$importer = new AttachmentImporter(
			new AttachmentReader($this->db(), __APP_DIR__),
			new AttachmentUploadClient($this->http()),
			$this->logger(),
		);
		$r = $importer->importFor('e10doc.core.heads', (int) $oldRow['ndx'], self::STATEMENT_TABLE_ID, $newId);
		foreach (['uploaded', 'duplicate', 'missing', 'failed'] as $k)
			$this->attStats[$k] += $r[$k];

		if (array_sum($r) > 0)
			$this->debug("statement " . (int) $oldRow['ndx'] . ": attachments "
				. "uploaded={$r['uploaded']} dup={$r['duplicate']} missing={$r['missing']} failed={$r['failed']}");
	}

	/**
	 * @param array{created:int,updated:int,skipped:int,failed:int} $stats
	 */
	protected function printDone(array $stats): void
	{
		parent::printDone($stats);
		$a = $this->attStats;
		if (array_sum($a) > 0)
			$this->summary(sprintf("  attachments: uploaded=%d, duplicate=%d, missing=%d, failed=%d",
				$a['uploaded'], $a['duplicate'], $a['missing'], $a['failed']));
	}

	// ── Helpers (kopie z DocsRunner — privátní, nedědí se) ──────────────────

	/** Parse --from/--to jako YYYY-MM-DD; null pokud chybí/nevalidní (s warningem). */
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

	private function emptyToNull(mixed $value): ?string
	{
		if ($value === null)
			return null;
		$trimmed = trim((string) $value);
		return $trimmed === '' ? null : $trimmed;
	}

	private function currencyUpper(mixed $val): ?string
	{
		$s = strtoupper(trim((string) ($val ?? '')));
		return preg_match('/^[A-Z]{3}$/', $s) ? $s : null;
	}

	/** Kladný kurz za jednotku, nebo null (domácí / nevyplněno → nová strana použije 1). */
	private function positiveOrNull(mixed $val): ?float
	{
		if ($val === null || $val === '')
			return null;
		$f = (float) $val;
		return $f > 0 ? $f : null;
	}
}
