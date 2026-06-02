<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\BaseExchangeRunner;
use imports\newShipard\libs\CrudClient;
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

	/** paymentMethod (hlavička) → canonical payment.method. Neznámé → bankTransfer. */
	private const PAYMENT_METHOD_MAP = [
		0 => 'cash',
		1 => 'bankTransfer',
		2 => 'card',
		3 => 'cashOnDelivery',
	];

	/**
	 * Staré kódy DPH, které nový Shipard nezná (vynechány při migraci
	 * vat-cz.json → vat-cz.jsonc): EUCZ000 = nedaňový řádek, EUCZ113 =
	 * artefakt zdroje. Mapujeme na null → řádek bez kódu DPH.
	 */
	private const VAT_CODE_DROP = ['EUCZ000', 'EUCZ113'];

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
		return parent::run();
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

		// Filtr období na dateAccounting (kompletní fiskální období).
		$from = $this->dateArg('from');
		$to   = $this->dateArg('to');
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

		$rows = $this->loadRows($oldNdx);
		if ($rows === [])
		{
			$this->warn("doc {$oldNdx}: no rows, skipping");
			return null;
		}

		// Import-mód čísla (Fáze 05b): pořadí parsujeme z původního docNumber,
		// applier zapíše číslo+sekvenci a synchronizuje counter (GREATEST).
		$sequence  = $this->parseSequenceNumber($oldRow);
		$docNumber = $this->emptyToNull($oldRow['docNumber'] ?? null);

		// Vydané faktury (invno) potřebují při stavu 20+ vlastní bank účet.
		// Bez dohledatelného účtu spadnou na koncept (10) + warning.
		$ownBankId   = null;
		$targetState = $this->finalDocState();   // 20 (nebo --target-state=10)
		if ($oldDocType === 'invno' && $targetState >= 20)
		{
			$ownBankId = $this->resolveOwnBankAccount($oldRow);
			if ($ownBankId === null)
			{
				$this->warn("doc {$oldNdx}: own bank account unresolved (myBankAccount); "
					. "importing issued invoice as draft (docState 10)");
				$targetState = 10;
			}
		}

		$applyOptions = [
			'targetDocState'        => $targetState,
			'autoCreateMode'        => 'safe',
			'createMissingEntities' => true,
			'rejectOnIssues'        => ['error'],
		];

		// Číslo posíláme jen když máme obojí a cílíme na potvrzený stav.
		// Koncept (10) číslo nemá — applier ho přidělí standardně při povýšení.
		if ($docNumber !== null && $sequence !== null && $targetState >= 20)
			$applyOptions['importNumber'] = [
				'docNumber'      => $docNumber,
				'sequenceNumber' => $sequence,
			];
		if ($ownBankId !== null)
			$applyOptions['importOwnBankAccount'] = $ownBankId;

		// partner_doc_number = "číslo od partnera". U vydaných faktur je docNumber
		// naše číslo (jde do doc_number přes importNumber) → top-level necháme null.
		// U přijatých faktur staré docNumber zůstává naše evidenční → partner_doc_number.
		$partnerDocNumber = $oldDocType === 'invno' ? null : $docNumber;

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
				'variableSymbol' => $this->emptyToNull($oldRow['symbol1'] ?? null),
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
	 * tento doklad, ořízne vyhodnocený prefix zleva a suffix zprava — zbytek je
	 * počítadlo. Fallback: poslední souvislá skupina číslic (s warningem).
	 * Vrací null, když nelze parsovat (s warningem).
	 */
	private function parseSequenceNumber(array $oldRow): ?int
	{
		$docNumber = $this->emptyToNull($oldRow['docNumber'] ?? null);
		if ($docNumber === null)
			return null;

		$docType = (string) ($oldRow['docType'] ?? '');
		$formula = (string) ($this->app()->cfgItem('e10.options.docNumbers.' . $docType, '') ?: '');
		if ($formula === '')
			$formula = (string) ($this->app()->cfgItem('e10.docs.types.' . $docType . '.docNumber', '') ?: '');
		if ($formula === '')
			$formula = '%D%r%C%4';

		// Counter token (%2–%6) rozdělí formuli na prefix | counter | suffix.
		// Greedy levá část vezme poslední counter token, kdyby jich bylo víc.
		if (preg_match('/^(.*)(%[2-6])(.*)$/', $formula, $m))
		{
			$prefix = $this->evaluateNumberTokens($m[1], $oldRow);
			$suffix = $this->evaluateNumberTokens($m[3], $oldRow);

			// Prefix/suffix musí být plně vyhodnocené — zbylý %token (např.
			// %A/%B/%W, které neznáme) signalizuje neshodu → fallback níže.
			if (strpos($prefix, '%') === false && strpos($suffix, '%') === false)
			{
				$core = $docNumber;
				$matched = true;
				if ($prefix !== '')
				{
					if (str_starts_with($core, $prefix))
						$core = substr($core, strlen($prefix));
					else
						$matched = false;
				}
				if ($matched && $suffix !== '')
				{
					if (str_ends_with($core, $suffix))
						$core = substr($core, 0, -strlen($suffix));
					else
						$matched = false;
				}
				// intval zahodí leading zeros i případné přetečení šířky paddingu.
				if ($matched && $core !== '' && ctype_digit($core))
					return (int) $core;
			}
		}

		// Fallback: poslední souvislá skupina číslic z docNumber.
		if (preg_match('/(\d+)(?!.*\d)/', $docNumber, $mm))
		{
			$this->warn("doc {$oldRow['ndx']}: sequence parsed via fallback "
				. "(trailing digits) from docNumber '{$docNumber}' → {$mm[1]}");
			return (int) $mm[1];
		}

		$this->warn("doc {$oldRow['ndx']}: cannot parse sequence from docNumber "
			. "'{$docNumber}', skipping number import");
		return null;
	}

	/**
	 * Vyhodnotí prefix/suffix tokeny formule (%D %r %C %Y %y %M) konkrétními
	 * hodnotami daného dokladu — replikuje relevantní část makeDocNumber.
	 * Tokeny %B/%A/%W (cashBox/bankAccount/warehouse id) ponecháváme
	 * nevyhodnocené; jejich přítomnost shodí parser na fallback (trailing digits).
	 */
	private function evaluateNumberTokens(string $pattern, array $oldRow): string
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

		$dbCounter = (int) ($oldRow['dbCounter'] ?? 0);
		$dbCntrId  = (string) $this->app()->cfgItem(
			'e10.docs.dbCounters.' . $docType . '.' . $dbCounter . '.docKeyId', '1');

		$mark = '';
		$fyNdx = (int) ($oldRow['fiscalYear'] ?? 0);
		if ($fyNdx > 0)
		{
			$r = $this->db()->query('SELECT [mark] FROM [e10doc_base_fiscalyears] WHERE [ndx] = %i', $fyNdx)->fetch();
			if ($r !== null)
				$mark = (string) ($r['mark'] ?? '');
		}

		return strtr($pattern, [
			'%D' => $docIdCode,
			'%r' => $mark,
			'%C' => $dbCntrId,
			'%Y' => $dt ? $dt->format('Y') : '',
			'%y' => $dt ? $dt->format('y') : '',
			'%M' => $dt ? $dt->format('m') : '',
		]);
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
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadRows(int $docNdx): array
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

			$out[] = [
				'rowKind'  => $rowKind,
				'orderPos' => $pos,
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
	 * Finální (cílový) docState importovaných dokladů. Schema dovoluje 10
	 * (Koncept) nebo 20 (Potvrzeno). Default 20 — reálná aktivní data.
	 * Override --target-state=10 (vše jako koncepty).
	 */
	private function finalDocState(): int
	{
		$arg = $this->app()->arg('target-state');
		if ($arg !== null && (int) $arg === 10)
			return 10;
		return 20;
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
