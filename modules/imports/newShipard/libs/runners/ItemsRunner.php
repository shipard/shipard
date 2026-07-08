<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\BaseExchangeRunner;
use imports\newShipard\libs\CrudClient;
use imports\newShipard\libs\LocalIdMap;
use imports\newShipard\libs\ResolvesAccountingAccount;

/**
 * Import položek ze starého Shipardu (e10_witems_items + itemtypes +
 * itemSuppliers) do nového přes exchange formát shpd.items.item.v1.
 *
 * Vzor: PersonsRunner. Mimo scope (viz tasks/04-items.md): sady (isSet),
 * EAN/SKU z itemCodes, manufacturerId, successorItem.
 *
 * Účet položky (starý `debsAccountId`, extension z e10doc/core) se
 * importuje post-apply PATCHem na `economy_items.accounting_account`
 * (extension z economy.accounting v novém Shipardu) — exchange formát
 * items toto pole nemá. Resolve: číslo účtu → starý ndx
 * (e10doc_debs_accounts) → LocalIdMap ENTITY_ACCOUNT → nový ndx.
 */
final class ItemsRunner extends BaseExchangeRunner
{
	use ResolvesAccountingAccount;

	/**
	 * Mapování e10.base.defaultDocStatesArchive → core.system.docStatesArchive.
	 * 9800 (Smazáno) je filtrováno ze source query.
	 */
	private const DOC_STATE_MAP = [
		1000 => 10,  // Rozpracováno → Koncept
		4000 => 40,  // Potvrzeno    → V pořádku
		8000 => 80,  // V opravě     → V opravě
		9000 => 70,  // V archívu    → V archívu
	];

	/** Tabulka v novém Shipardu pro post-apply PATCH na docState. */
	private const NEW_ITEMS_TABLE = 'economy_items';

	/**
	 * Mapování starého e10_witems_items.itemKind (enumInt) na canonical
	 * kind.itemType. Oba enumy mají identický 0/1/2/3 = Služba/Zásoba/
	 * Účetní/Ostatní, mapping 1:1.
	 */
	private const ITEM_KIND_MAP = [
		0 => 0,  // Služba
		1 => 1,  // Zásoba
		2 => 2,  // Účetní položka
		3 => 3,  // Ostatní
	];

	/**
	 * Per-run memo: nový id položky → accounting_account přiřazený v tomto
	 * běhu. Víc starých položek sloučených do jedné nové může mít rozdílné
	 * účty — bez memo by se přepisovaly tam a zpět při každém běhu
	 * (ping-pong). First-wins podle pořadí ndx + warn o konfliktu.
	 *
	 * @var array<int, int>
	 */
	private array $accountSeenByNewId = [];

	protected function entityType(): string   { return LocalIdMap::ENTITY_ITEM; }
	protected function exchangeFlow(): string { return 'items'; }
	protected function exchangeType(): string { return 'item'; }
	protected function savedIdKey(): string   { return 'savedItemId'; }
	protected function entityLabel(): string  { return 'item'; }

	protected function sourceAlias(): string { return 'i'; }

	protected function sourceQuery(): array
	{
		return [
			'SELECT i.*, t.[fullName] AS kind_name, t.[id] AS kind_code'
			. ' FROM [e10_witems_items] i'
			. ' LEFT JOIN [e10_witems_itemtypes] t ON i.[itemType] = t.[ndx]'
			. ' WHERE i.[docState] != %i', 9800,
		];
	}

	protected function buildCanonical(array $oldRow): ?array
	{
		$oldNdx = (int) $oldRow['ndx'];

		// Validační gate: name je required v schema (minLength: 1).
		$fullName = trim((string) ($oldRow['fullName'] ?? ''));
		if ($fullName === '')
		{
			$this->warn("item {$oldNdx}: missing fullName, skipping");
			return null;
		}

		// Unit je required. Pokud starý nemá defaultUnit, fallback "pcs".
		$unit = trim((string) ($oldRow['defaultUnit'] ?? ''));
		if ($unit === '')
		{
			$unit = 'pcs';
			$this->debug("item {$oldNdx}: empty defaultUnit, falling back to 'pcs'");
		}

		$supplierCodes = $this->loadSupplierCodes($oldNdx);

		return [
			'format'        => 'shpd.items.item',
			'formatVersion' => '1.0',

			'source' => [
				'kind'        => 'import.oldShipard',
				'fetchedAt'   => date('c'),
				'registryRef' => (string) $oldNdx,
			],

			'code'        => $this->emptyToNull($oldRow['id'] ?? null),
			'name'        => $fullName,
			'description' => $this->emptyToNull($oldRow['description'] ?? null),
			'sku'         => null,
			'ean'         => null,

			'kind' => $this->buildKindObject($oldRow),

			'validFrom' => $this->dateToString($oldRow['validFrom'] ?? null),
			'validTo'   => $this->dateToString($oldRow['validTo'] ?? null),

			'salesPriceNoVat' => $this->moneyOrNull($oldRow['priceSellBase'] ?? null),
			'unit'            => $unit,

			'supplierCodes' => $supplierCodes,

			'status' => [
				'isClosed' => null,   // items nemají closed flag ve starém Shipardu
			],

			'applyOptions' => [
				'mergeStrategy'  => 'fullSync',
				// Migrace má autoritativní starý kód (id) — párovat jen přes
				// identifikátory, NE podle jména. Dvě různé položky stejného
				// jména (např. "Parkovné" jako služba vs. účetní položka) musí
				// zůstat oddělené; idempotenci mezi běhy drží LocalIdMap.
				'matchStrategy'  => 'identifiersOnly',
				'targetDocState' => $this->insertDocState($oldRow),
				'rejectOnIssues' => ['error'],
			],
		];
	}

	/**
	 * Tři hinty pro KindResolver. Applier vybere první match v pořadí
	 * code (system_code) → name → itemType (seedovaný systémový druh).
	 * Spoléhá na to, že Fáze 02 importovala druhy se stejným `name`.
	 */
	private function buildKindObject(array $oldRow): array
	{
		$oldItemKind = $oldRow['itemKind'] ?? null;
		$itemType = null;
		if ($oldItemKind !== null && isset(self::ITEM_KIND_MAP[(int) $oldItemKind]))
			$itemType = self::ITEM_KIND_MAP[(int) $oldItemKind];

		return [
			'code'     => $this->emptyToNull($oldRow['kind_code'] ?? null),
			'name'     => $this->emptyToNull($oldRow['kind_name'] ?? null),
			'itemType' => $itemType,
		];
	}

	/**
	 * Per-partner dodavatelské kódy z e10_witems_itemSuppliers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadSupplierCodes(int $itemNdx): array
	{
		$rows = $this->db()->query(
			'SELECT s.*, p.[fullName] AS supplier_name, c.[cca2] AS country_iso'
			. ' FROM [e10_witems_itemSuppliers] s'
			. ' JOIN [e10_persons_persons] p ON s.[supplier] = p.[ndx]'
			. ' LEFT JOIN [e10_persons_personsContacts] pc'
			. '   ON pc.[person] = p.[ndx] AND pc.[flagMainAddress] = 1 AND pc.[docState] != 9800'
			. ' LEFT JOIN [e10_world_countries] c ON pc.[adrCountry] = c.[ndx]'
			. ' WHERE s.[item] = %i', $itemNdx,
			' AND p.[docState] != %i', 9800,
			' ORDER BY s.[rowOrder], s.[ndx]',
		)->fetchAll();

		$codes = [];
		$seen = [];

		foreach ($rows as $r)
		{
			$row = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;

			// Schema vyžaduje supplierCode minLength: 1. Skip prázdné.
			$supplierCode = trim((string) ($row['itemId'] ?? ''));
			if ($supplierCode === '')
				continue;

			$supplierNdx = (int) ($row['supplier'] ?? 0);

			// De-duplication: LEFT JOIN s personsContacts může vynásobit řádky
			// (víc adres na osobu). Bereme jen první (supplier × supplierCode).
			$key = $supplierNdx . ':' . $supplierCode;
			if (isset($seen[$key]))
				continue;
			$seen[$key] = true;

			$supplier = $this->loadSupplierParty($supplierNdx, $row);
			if ($supplier === null)
				continue;

			$codes[] = [
				'supplier'     => $supplier,
				'supplierCode' => $supplierCode,
				'supplierName' => null,
			];
		}

		return $codes;
	}

	/**
	 * Party fragment pro supplier z hlavičky persons + properties. Vrátí null
	 * pokud supplier nemá nic identifikovatelného (žádné jméno, IČO ani DIČ).
	 *
	 * @param array<string, mixed> $joinRow Result row z LEFT JOIN s country_iso.
	 */
	private function loadSupplierParty(int $supplierNdx, array $joinRow): ?array
	{
		if ($supplierNdx <= 0)
			return null;

		$properties = $this->loadPersonProperties($supplierNdx);

		$name      = $this->emptyToNull($joinRow['supplier_name'] ?? null);
		$companyId = $properties['oid']   ?? null;
		$vatId     = $properties['taxid'] ?? null;

		if ($name === null && $companyId === null && $vatId === null)
		{
			$this->debug("supplier ndx={$supplierNdx}: no identifiable data, skipping");
			return null;
		}

		// Country — striktně ISO 3166-1 alpha-2 lowercase pro schema validation.
		$country = null;
		$rawCountry = strtolower(trim((string) ($joinRow['country_iso'] ?? '')));
		if (strlen($rawCountry) === 2 && ctype_alpha($rawCountry))
			$country = $rawCountry;

		return [
			'name'      => $name,
			'country'   => $country,
			'companyId' => $companyId,
			'taxId'     => null,
			'vatId'     => $vatId,
			'govEBoxId' => $properties['govDataBox'] ?? null,
		];
	}

	/**
	 * IČO/DIČ/govDataBox z e10_base_properties. Sub-set logiky z
	 * PersonsRunner::loadProperties — duplikováno kvůli nezávislosti runnerů.
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
			' AND [property] IN %in', ['oid', 'taxid', 'govDataBox'],
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
	 * Post-apply PATCH pro pole, která exchange applier nenastavuje:
	 *
	 * - docState 70/80 — applier umí jen 10/40 (applyOptions.targetDocState).
	 * - accounting_account — účet ze starého `debsAccountId`. Importuje se
	 *   u všech položek s vyplněným účtem (bez ohledu na druh položky) a
	 *   nekontroluje se account_level — stará data jsou autoritativní,
	 *   nesoulad ohlídá měkká kontrola účtovacího enginu.
	 *
	 * Hook se volá při create i update, PATCH je tedy idempotentní.
	 */
	protected function afterApplied(array $oldRow, int $newId, CrudClient $crud): void
	{
		$target  = $this->mapDocState($oldRow);
		$insert  = $this->insertDocState($oldRow);
		$account = $this->resolveAccountingAccount($oldRow);
		if ($account !== null)
			$account = $this->claimAccountForNewId($newId, $account, $oldRow);

		if ($account === null)
		{
			// Jen docState — projde i v readOnly stavu (docState je system
			// pole, z $data se filtruje), dance není potřeba.
			if ($target === $insert)
				return;

			if ($this->isDryRun())
			{
				$this->debug("DRY-RUN: would PATCH " . self::NEW_ITEMS_TABLE . "/{$newId} docState={$target}");
				return;
			}

			$crud->patch(self::NEW_ITEMS_TABLE, $newId, ['docState' => $target]);
			$this->debug("item {$oldRow['ndx']}: post-apply PATCH docState {$insert} → {$target}");
			return;
		}

		if ($this->isDryRun())
		{
			$this->debug("DRY-RUN: would PATCH " . self::NEW_ITEMS_TABLE
				. "/{$newId} accounting_account={$account}, docState {$insert} → {$target}");
			return;
		}

		$this->patchFieldsRespectingState($newId, ['accounting_account' => $account], $insert, $target, $crud);
		$this->debug("item {$oldRow['ndx']}: post-apply PATCH accounting_account={$account}, docState → {$target}");
	}

	/**
	 * Backfill účtu do už naimportovaných položek — LocalIdMap hit přeskakuje
	 * exchange apply, takže afterApplied neběží. Volá se při každém běhu
	 * `items`; když už účet sedí, nic se neposlá (idempotence bez PATCH storm).
	 * docState se tu (kromě dočasného mezikroku) nemění.
	 */
	protected function afterSkippedExisting(array $oldRow, int $newId, CrudClient $crud): void
	{
		$account = $this->resolveAccountingAccount($oldRow);
		if ($account !== null)
			$account = $this->claimAccountForNewId($newId, $account, $oldRow);
		if ($account === null)
			return;

		if ($this->isDryRun())
		{
			$this->debug("DRY-RUN: would backfill PATCH " . self::NEW_ITEMS_TABLE
				. "/{$newId} accounting_account={$account} (old item {$oldRow['ndx']})");
			return;
		}

		$row = $crud->show(self::NEW_ITEMS_TABLE, $newId);
		if ($row === null)
		{
			$this->warn("item {$oldRow['ndx']}: nový záznam {$newId} nenalezen (404), backfill skip");
			return;
		}

		$currentState = (int) ($row['docState'] ?? 10);
		if ($currentState === 90)
		{
			$this->debug("item {$oldRow['ndx']}: nový záznam {$newId} je ve stavu Smazáno, backfill skip");
			return;
		}

		// API vynechává NULL pole z odpovědi → ?? 0 pokrývá nenastavený účet.
		if ((int) ($row['accounting_account'] ?? 0) === $account)
			return;

		$this->patchFieldsRespectingState($newId, ['accounting_account' => $account], $currentState, $currentState, $crud);
		$this->debug("item {$oldRow['ndx']}: backfill PATCH accounting_account={$account} (docState={$currentState})");
	}

	/**
	 * PATCH non-system polí s ohledem na readOnly docState (40/70/90):
	 * CrudController odmítá zápis non-system polí do záznamu v readOnly
	 * stavu (DOCUMENT_READONLY). Pro readOnly stav se udělá mezikrok přes
	 * 80 (V opravě) a návrat do $finalState — stavový automat to dovoluje
	 * (40/70/90 → 80, 80 → 40/70/90). Samotný docState PATCH přes readOnly
	 * check projde, protože docState je system pole a z $data se filtruje.
	 */
	/**
	 * Claim účtu pro novou položku v rámci běhu (first-wins). Vrací účet,
	 * pokud je položka volná nebo už má stejný účet; null + warn při
	 * konfliktu (sloučené duplicity starých položek s různými účty —
	 * vyžaduje ruční kontrolu dat).
	 */
	private function claimAccountForNewId(int $newId, int $account, array $oldRow): ?int
	{
		$seen = $this->accountSeenByNewId[$newId] ?? null;
		if ($seen === null)
		{
			$this->accountSeenByNewId[$newId] = $account;
			return $account;
		}
		if ($seen === $account)
			return $account;

		$this->warn("item {$oldRow['ndx']}: konflikt uctu — nova polozka {$newId} uz ma v tomto behu"
			. " accounting_account={$seen}, ignoruji {$account} (sloucene duplicity, zkontroluj rucne)");
		return null;
	}

	private function patchFieldsRespectingState(int $newId, array $fields, int $currentState, int $finalState, CrudClient $crud): void
	{
		if (in_array($currentState, [10, 80], true))
		{
			$payload = $fields;
			if ($finalState !== $currentState)
				$payload['docState'] = $finalState;
			$crud->patch(self::NEW_ITEMS_TABLE, $newId, $payload);
			return;
		}

		// readOnly → mezikrok V opravě, pak zápis polí + návrat jedním PATCHem
		$crud->patch(self::NEW_ITEMS_TABLE, $newId, ['docState' => 80]);
		$payload = $fields;
		if ($finalState !== 80)
			$payload['docState'] = $finalState;
		$crud->patch(self::NEW_ITEMS_TABLE, $newId, $payload);
	}

	/**
	 * Nový ndx účtu (economy_accounting_accounts) pro položku, nebo null.
	 * Zdroj: e10_witems_items.debsAccountId (extension z e10doc/core).
	 * Resolve řeší ResolvesAccountingAccount.
	 */
	private function resolveAccountingAccount(array $oldRow): ?int
	{
		return $this->resolveAccountingAccountNumber(
			(string) ($oldRow['debsAccountId'] ?? ''),
			"item {$oldRow['ndx']}",
		);
	}

	private function mapDocState(array $oldRow): int
	{
		$old = (int) ($oldRow['docState'] ?? 0);
		if (isset(self::DOC_STATE_MAP[$old]))
			return self::DOC_STATE_MAP[$old];

		$this->warn("item {$oldRow['ndx']}: unknown old docState={$old}, defaulting to 40 (V pořádku)");
		return 40;
	}

	private function insertDocState(array $oldRow): int
	{
		return $this->mapDocState($oldRow) === 10 ? 10 : 40;
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

	private function moneyOrNull(mixed $value): ?float
	{
		if ($value === null || $value === '')
			return null;
		// 0.0 je validní cena; negativní filtruje applier (ItemValidator >= 0).
		return (float) $value;
	}
}
