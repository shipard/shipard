<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\BaseExchangeRunner;
use imports\newShipard\libs\LocalIdMap;

final class PersonsRunner extends BaseExchangeRunner
{
	/** Hodnota persons.json:ndx — fixní pro tableNdx kolumnu v e10_persons_contacts. */
	private const PERSONS_TABLE_NDX = 1000;

	/** ID tabulky pro e10_base_properties lookup. */
	private const PERSONS_TABLE_ID = 'e10.persons.persons';

	/** Tabulka v novém Shipardu — pro post-apply PATCH na docState. */
	private const NEW_PERSONS_TABLE = 'base_persons_persons';

	/**
	 * Mapování starého docState (e10.base.defaultDocStatesArchive) na nový
	 * (core.system.docStatesArchive). 9800 (Smazáno) je filtrováno ze
	 * source query a do mapy nepatří.
	 */
	private const DOC_STATE_MAP = [
		1000 => 10,  // Rozpracováno → Koncept
		4000 => 40,  // Potvrzeno    → V pořádku
		8000 => 80,  // V opravě     → V opravě
		9000 => 70,  // V archívu    → V archívu
	];

	protected function entityType(): string   { return LocalIdMap::ENTITY_PERSON; }
	protected function exchangeFlow(): string { return 'persons'; }
	protected function exchangeType(): string { return 'person'; }
	protected function savedIdKey(): string   { return 'savedPersonId'; }
	protected function entityLabel(): string  { return 'person'; }

	protected function sourceAlias(): string { return 'p'; }

	protected function sourceQuery(): array
	{
		return [
			'SELECT p.* FROM [e10_persons_persons] p'
			. ' WHERE p.[docState] != %i', 9800,           // ne smazané
			' AND p.[personType] IN %in', [1, 2],          // jen Člověk + Firma
		];
	}

	protected function buildCanonical(array $oldRow): ?array
	{
		$oldNdx = (int) $oldRow['ndx'];
		$personType = $this->mapPersonType((int) ($oldRow['personType'] ?? 0));

		// Validační gate: fyzická osoba musí mít firstName + lastName, jinak
		// applier vrátí 422 validation_failed. Chybějící pole se deterministicky
		// odvodí z fullName (skip by osobu vynechal z LocalIdMap → doklady na ni
		// pak nemají pin a končí jako ambiguous-header, viz task 22 dodatek v2).
		// Skip zůstává jen pro prázdný fullName.
		if ($personType === 'person')
		{
			$first = trim((string) ($oldRow['firstName'] ?? ''));
			$last  = trim((string) ($oldRow['lastName']  ?? ''));
			if ($first === '' || $last === '')
			{
				$fullName = trim((string) ($oldRow['fullName'] ?? ''));
				if ($fullName === '')
				{
					$this->warn("person {$oldNdx}: missing firstName/lastName and empty fullName "
						. 'for personType=Člověk, skipping');
					return null;
				}
				// Vedoucí titulové tokeny (Ing., MUDr., prof., …) se pro odvození
				// přeskočí; ≥2 zbylé tokeny: první = jméno, zbytek = příjmení;
				// 1 token: obojí. Když jsou všechny tokeny tituly, bere se vše.
				$tokens = preg_split('/\s+/', $fullName) ?: [$fullName];
				$nameTokens = $tokens;
				while (count($nameTokens) > 1 && preg_match('/^\p{L}+\.$/u', $nameTokens[0]))
					array_shift($nameTokens);
				$derivedFirst = $nameTokens[0];
				$derivedLast  = count($nameTokens) > 1
					? implode(' ', array_slice($nameTokens, 1)) : $nameTokens[0];
				if ($first === '')
					$oldRow['firstName'] = $first = $derivedFirst;
				if ($last === '')
					$oldRow['lastName'] = $last = $derivedLast;
				$this->warn("person {$oldNdx}: missing firstName/lastName, derived from "
					. "fullName '{$fullName}' → first='{$first}' last='{$last}'");
			}
		}

		$properties   = $this->loadProperties($oldNdx);
		$addresses    = $this->loadAddresses($oldNdx);
		$bankAccounts = $this->loadBankAccounts($oldNdx);
		$contacts     = $this->loadContacts($oldNdx);
		$country      = $this->resolveCountry($addresses);

		$payload = [
			'format'        => 'shpd.persons.person',
			'formatVersion' => '1.0',

			'source' => [
				'kind'        => 'import.oldShipard',
				'fetchedAt'   => date('c'),
				'registryRef' => (string) $oldNdx,
			],

			'personType' => $personType,
			'country'    => $country,
			'personId'   => $this->emptyToNull($oldRow['id'] ?? null),  // Kód osoby ze starého DS

			'companyId'         => $properties['oid'] ?? null,
			'taxId'             => null,
			'vatId'             => $properties['taxid'] ?? null,
			'courtRegistration' => null,
			'govEBoxId'         => $properties['govDataBox'] ?? null,

			'name'     => $this->buildNameObject($oldRow),
			'personal' => $this->buildPersonalObject($properties, $personType),

			'contact' => [
				'email' => $properties['email'] ?? null,
				'phone' => $properties['phone'] ?? null,
				'web'   => $properties['web']   ?? null,
			],

			'status' => [
				'isClosed'   => (int) ($oldRow['personCanceled'] ?? 0) === 1,
				'closedDate' => $this->dateToString($oldRow['personCancelDate'] ?? null),
				'isOwn'      => $this->resolveIsOwn($oldNdx, $personType, $properties),
				// `status.docState` v canonical PersonApplier ignoruje při create —
				// rozhodující je `applyOptions.targetDocState`. Posíláme jen ostatní
				// status pole, ať není payload zavádějící.
			],

			'addresses'    => $addresses,
			'bankAccounts' => $bankAccounts,
			'contacts'     => $contacts,

			'applyOptions' => [
				'mergeStrategy'  => 'fullSync',
				// Migrace má autoritativní staré ndx — párovat jen přes
				// identifikátory (IČO/DIČ), NE podle jména. FO bez IČO mají běžně
				// shodná jména (obsluha je rozlišuje datem narození / č. dokladu);
				// párování podle jména by různé osoby slučovalo. Idempotenci mezi
				// běhy drží LocalIdMap; doklady pinnou partnera přes useExisting.
				'matchStrategy'  => 'identifiersOnly',
				// Schema applieru dovolí jen 10 nebo 40. Pro 70 (Archív) a 80
				// (V opravě) provedeme post-apply PATCH — viz afterApplied().
				'targetDocState' => $this->insertDocState($oldRow),
				'createPersonId' => true,
				'rejectOnIssues' => ['error'],
			],
		];

		return $payload;
	}

	/**
	 * Po apply provede generic CRUD PATCH pokud target docState není 10/40.
	 *
	 * State transitions v core.system.docStatesArchive:
	 *   10 (Koncept)   → goto [40, 70, 90]
	 *   40 (V pořádku) → goto [80, 70, 90]
	 *   80 (V opravě)  → goto [40, 70, 90]
	 *
	 * Insert proběhl s 10 nebo 40 (`insertDocState`). Pokud cíl je 70 nebo
	 * 80, transition z 40 je vždy povolen — jednofázový PATCH.
	 */
	protected function afterApplied(array $oldRow, int $newId, \imports\newShipard\libs\CrudClient $crud): void
	{
		$target = $this->mapDocState($oldRow);
		$insert = $this->insertDocState($oldRow);
		if ($target === $insert)
			return;

		if ($this->isDryRun())
		{
			$this->debug("DRY-RUN: would PATCH " . self::NEW_PERSONS_TABLE . "/{$newId} docState={$target}");
			return;
		}

		$crud->patch(self::NEW_PERSONS_TABLE, $newId, ['docState' => $target]);
		$this->debug("person {$oldRow['ndx']}: post-apply PATCH docState {$insert} → {$target}");
	}

	/**
	 * Cílový docState v novém Shipardu (po případném post-apply PATCH).
	 * Neznámé staré hodnoty → 40 (V pořádku) + warning.
	 */
	private function mapDocState(array $oldRow): int
	{
		$old = (int) ($oldRow['docState'] ?? 0);
		if (isset(self::DOC_STATE_MAP[$old]))
			return self::DOC_STATE_MAP[$old];

		$this->warn("person {$oldRow['ndx']}: unknown old docState={$old}, defaulting to 40 (V pořádku)");
		return 40;
	}

	/**
	 * Hodnota pro `applyOptions.targetDocState` při apply. Schema dovoluje
	 * jen 10 / 40 — pro 70/80 nejdřív vložíme 40, pak PATCH (viz afterApplied).
	 */
	private function insertDocState(array $oldRow): int
	{
		return $this->mapDocState($oldRow) === 10 ? 10 : 40;
	}

	/** Cache výsledku ownerPersonNdx() — resolveIsOwn() ho volá per osoba. */
	private ?int $ownerNdx = null;

	/**
	 * Old ndx vlastní osoby ze starého configu (config/appOptions.core.json,
	 * klíč ownerPerson → cfgItem 'options.core.ownerPerson'). 0 = nenastaveno.
	 *
	 * Primárně cfgItem; fallback na přímé čtení JSON souboru, kdyby cfgItem
	 * nebyl v CLI kontextu naplněn.
	 */
	private function ownerPersonNdx(): int
	{
		if ($this->ownerNdx !== null)
			return $this->ownerNdx;

		$ndx = (int) $this->app()->cfgItem('options.core.ownerPerson', 0);
		if ($ndx <= 0)
		{
			$file = __APP_DIR__ . '/config/appOptions.core.json';
			if (is_file($file))
			{
				$json = json_decode((string) @file_get_contents($file), true);
				if (is_array($json) && isset($json['ownerPerson']))
					$ndx = (int) $json['ownerPerson'];
			}
		}
		return $this->ownerNdx = $ndx;
	}

	/**
	 * Rozhodne, zda osoba má být označena jako vlastní firma (is_own=1 v novém
	 * Shipardu). True jen pro řádek odpovídající options.core.ownerPerson.
	 *
	 * Nový Shipard má na is_own striktní pravidla (PersonDocument):
	 *   - jen JEDNA osoba smí být vlastní (singleton, jinak is_own_duplicate),
	 *   - musí být typu Firma (jinak is_own_not_company),
	 *   - při targetDocState 40 vyžaduje companyId (jinak own_company_id_required).
	 * Owner z konfigu je vždy jediný, takže singleton držíme automaticky. Typ
	 * a IČO ověříme tady — při nesplnění příznak nenastavíme (typ) / varujeme
	 * (IČO), ať neshodíme celý import osob na 422.
	 *
	 * @param array<string, string> $properties
	 */
	private function resolveIsOwn(int $oldNdx, string $personType, array $properties): bool
	{
		$ownerNdx = $this->ownerPersonNdx();
		if ($ownerNdx <= 0 || $oldNdx !== $ownerNdx)
			return false;

		if ($personType !== 'company')
		{
			$this->warn("person {$oldNdx}: je vlastník (options.core.ownerPerson), ale není typu "
				. "Firma — nový Shipard vyžaduje pro is_own firmu. Příznak nenastaven; "
				. "oprav data nebo nastav vlastní firmu ručně.");
			return false;
		}

		if (empty($properties['oid']))
			$this->warn("person {$oldNdx}: vlastní firma bez IČO (companyId) — applier ji "
				. "při docState 40 odmítne (own_company_id_required). Doplň IČO ve zdroji.");

		$this->info("person {$oldNdx}: označeno jako vlastní firma (is_own=1).");
		return true;
	}

	// ── Helpers (mapping) ──────────────────────────────────────────────────

	private function mapPersonType(int $oldType): string
	{
		return match ($oldType) {
			2 => 'company',
			1 => 'person',
			default => 'company',  // 0/3 jsou filtered v sourceQuery, defensive default
		};
	}

	/**
	 * Načte vlastnosti osoby z e10_base_properties.
	 *
	 * Mapování klíčů (z e10.persons.propertiesDefs.json):
	 *   - ids/oid       → IČO            → companyId
	 *   - ids/taxid     → DIČ            → vatId
	 *   - ids/pid       → Rodné číslo    → personal.nationalId
	 *   - ids/idcn      → Číslo OP       → personal.idCardNumber
	 *   - ids/birthdate → Datum narození → personal.birthDate (čte se z valueDate)
	 *   - contacts/email      → contact.email
	 *   - contacts/phone      → contact.phone
	 *   - contacts/web        → contact.web
	 *   - contacts/govDataBox → govEBoxId
	 *
	 * Multi-value properties (např. víc emailů): bereme první nenulovou
	 * hodnotu po `ORDER BY ndx ASC` (deterministic, předvídatelné).
	 *
	 * @return array<string, string>
	 */
	private function loadProperties(int $personNdx): array
	{
		$rows = $this->db()->query(
			'SELECT [group], [property], [valueString], [valueDate], [ndx]'
			. ' FROM [e10_base_properties]'
			. ' WHERE [tableid] = %s', self::PERSONS_TABLE_ID,
			' AND [recid] = %i', $personNdx,
			' AND [property] IN %in', ['oid', 'taxid', 'pid', 'idcn', 'birthdate', 'email', 'phone', 'web', 'govDataBox'],
			' ORDER BY [ndx] ASC',
		)->fetchAll();

		$result = [];
		foreach ($rows as $r)
		{
			$row = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;
			$prop = (string) ($row['property'] ?? '');
			if ($prop === '' || isset($result[$prop]))
				continue;

			// birthdate je typ 'date' v propertiesDefs → hodnota ve valueDate.
			// Ostatní jsou 'text' → valueString.
			if ($prop === 'birthdate')
			{
				$val = $this->dateToString($row['valueDate'] ?? null);
				if ($val !== null)
					$result[$prop] = $val;
				continue;
			}

			$val = trim((string) ($row['valueString'] ?? ''));
			if ($val !== '')
				$result[$prop] = $val;
		}
		return $result;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function loadAddresses(int $personNdx): array
	{
		// cca2 je ISO 3166-1 alpha-2 (2 znaky); c.id je flexibilnější (varchar 10)
		// a může nést delší kódy. Nový schema vyžaduje striktně ^[a-z]{2}$.
		$rows = $this->db()->query(
			'SELECT pc.*, c.[cca2] AS country_iso'
			. ' FROM [e10_persons_personsContacts] pc'
			. ' LEFT JOIN [e10_world_countries] c ON pc.[adrCountry] = c.[ndx]'
			. ' WHERE pc.[person] = %i', $personNdx,
			' AND pc.[docState] != %i', 9800,
			' AND pc.[flagAddress] = %i', 1,
			' ORDER BY pc.[systemOrder], pc.[ndx]',
		)->fetchAll();

		$addresses = [];
		foreach ($rows as $r)
		{
			$row = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;
			$mapped = $this->mapAddress($row);
			if ($mapped !== null)
				$addresses[] = $mapped;
		}
		return $addresses;
	}

	/**
	 * Mapuje řádek e10_persons_personsContacts na canonical address.
	 * Vrátí null pokud adresa nelze namapovat (např. úplně prázdná).
	 */
	private function mapAddress(array $row): ?array
	{
		// Určení addressType:
		//   flagMainAddress=1 → 1 (Sídlo)
		//   flagOffice=1      → 3 (Provozovna), vyžaduje IČP v id1
		//   jinak             → 2 (Doručovací)
		$addressType = 2;
		$placeRegType = null;
		$placeRegId = null;

		if ((int) ($row['flagMainAddress'] ?? 0) === 1)
		{
			$addressType = 1;
		}
		elseif ((int) ($row['flagOffice'] ?? 0) === 1)
		{
			$icp = trim((string) ($row['id1'] ?? ''));
			if ($icp !== '')
			{
				$addressType = 3;
				$placeRegType = 'ICP';
				$placeRegId = $icp;
			}
			// flagOffice=1 ale bez id1 → downgrade na 2 (Doručovací).
			// PersonValidator vyžaduje placeRegId pro type 3.
		}

		// Country — striktně ISO 3166-1 alpha-2 lowercase (2 znaky). Pokud zdroj
		// nemá validní cca2, raději null než risk schema validation failure.
		$country = null;
		$rawCountry = strtolower(trim((string) ($row['country_iso'] ?? '')));
		if (strlen($rawCountry) === 2 && ctype_alpha($rawCountry))
			$country = $rawCountry;

		return [
			'addressType'   => $addressType,
			'name'          => $this->emptyToNull($row['adrSpecification'] ?? null),
			'placeRegType'  => $placeRegType,
			'placeRegId'    => $placeRegId,
			'isStandardized' => (int) ($row['flagStandardized'] ?? 0) === 1,

			'street'             => $this->emptyToNull($row['adrStreet'] ?? null),
			'houseNumber'        => $this->emptyToNull($row['saHouseNr'] ?? null),
			'orientationNumber'  => null,
			'city'               => $this->emptyToNull($row['adrCity'] ?? null),
			'cityPart'           => $this->emptyToNull($row['saCityPartName'] ?? null),
			'district'           => null,
			'zip'                => $this->emptyToNull($row['adrZipCode'] ?? null),
			'country'            => $country,
			'registryCode'       => null,  // RÚIAN ADM — mapping out of scope
			'divisionCode'       => null,  // ZÚJ — mapping out of scope

			'latitude'  => $this->numberOrNull($row['adrLocLat'] ?? null),
			'longitude' => $this->numberOrNull($row['adrLocLon'] ?? null),
			'manualGps' => (int) ($row['adrLocManual'] ?? 0) === 1,

			'displayLine'  => null,
			'displayBlock' => null,

			'orderPos'   => (int) ($row['systemOrder'] ?? 0),
			'validFrom'  => $this->dateToString($row['validFrom'] ?? null),
			'validTo'    => $this->dateToString($row['validTo'] ?? null),
			'note'       => null,
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function loadBankAccounts(int $personNdx): array
	{
		$rows = $this->db()->query(
			'SELECT * FROM [e10_persons_personsBA]'
			. ' WHERE [person] = %i', $personNdx,
			' AND [docState] != %i', 9800,
			' ORDER BY [ndx]',
		)->fetchAll();

		$banks = [];
		foreach ($rows as $r)
		{
			$row = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;
			$accountNumber = trim((string) ($row['bankAccount'] ?? ''));

			// PersonValidator vyžaduje iban OR accountNumber. Bez obou → skip
			// (jinak applier vrátí 422 bank_account_id_missing).
			if ($accountNumber === '')
				continue;

			$banks[] = [
				'name'          => null,
				'accountNumber' => $accountNumber,
				'iban'          => null,   // starý personsBA nemá IBAN
				'bic'           => null,
				'currency'      => 'CZK',  // ISO 4217 uppercase v canonical
				'source'        => 0,      // manual
				'orderPos'      => 0,
				'validFrom'     => $this->dateToString($row['validFrom'] ?? null),
				'validTo'       => $this->dateToString($row['validTo'] ?? null),
			];
		}
		return $banks;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function loadContacts(int $personNdx): array
	{
		$rows = $this->db()->query(
			'SELECT * FROM [e10_persons_contacts]'
			. ' WHERE [tableNdx] = %i', self::PERSONS_TABLE_NDX,
			' AND [recNdx] = %i', $personNdx,
			' AND [docState] != %i', 9800,
			' ORDER BY [ndx]',
		)->fetchAll();

		$contacts = [];
		foreach ($rows as $r)
		{
			$row = is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : (array) $r;

			$email = $this->emptyToNull($row['email'] ?? null);
			$phone = $this->emptyToNull($row['phone'] ?? null);
			$nameRaw = $this->emptyToNull($row['name'] ?? null);

			// Skip kontakty, které nemají vůbec nic (žádné jméno, email ani telefon).
			if ($nameRaw === null && $email === null && $phone === null)
				continue;

			// Schema vyžaduje contacts[].name (minLength 1). Fallback 'Kontakt' aby
			// row prošla validation pokud má email/phone ale prázdný name.
			$name = $nameRaw ?? 'Kontakt';

			$contacts[] = [
				'name'      => $name,
				'role'      => $this->emptyToNull($row['role'] ?? null),
				'email'     => $email,
				'phone'     => $phone,
				'note'      => null,
				'orderPos'  => 0,
				'validFrom' => null,
				'validTo'   => null,
			];
		}
		return $contacts;
	}

	private function buildNameObject(array $oldRow): array
	{
		return [
			'fullName'    => trim((string) ($oldRow['fullName'] ?? '')),
			'titleBefore' => $this->emptyToNull($oldRow['beforeName'] ?? null),
			'firstName'   => $this->emptyToNull($oldRow['firstName'] ?? null),
			'middleName'  => $this->emptyToNull($oldRow['middleName'] ?? null),
			'lastName'    => $this->emptyToNull($oldRow['lastName'] ?? null),
			'titleAfter'  => $this->emptyToNull($oldRow['afterName'] ?? null),
		];
	}

	/**
	 * Personal block — jen pro personType=person. Hodnoty se čerpají z
	 * e10_base_properties (ne z hlavičky persons):
	 *   - pid       → nationalId (Rodné číslo)
	 *   - birthdate → birthDate (Datum narození)
	 *   - idcn      → idCardNumber (Číslo OP)
	 *
	 * Sloupec `personalId` ve starém persons table NENÍ rodné číslo —
	 * je to obecné "osobní číslo" (HR/zaměstnanecké ID) a ignorujeme ho.
	 *
	 * @param array<string, string> $properties
	 */
	private function buildPersonalObject(array $properties, string $personType): ?array
	{
		if ($personType !== 'person')
			return null;

		return [
			'birthDate'    => $properties['birthdate'] ?? null,
			'nationalId'   => $properties['pid']       ?? null,
			'idCardNumber' => $properties['idcn']      ?? null,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $addresses
	 */
	private function resolveCountry(array $addresses): string
	{
		// Preferuj country z adresy typu Sídlo (addressType=1)
		foreach ($addresses as $addr)
		{
			if (($addr['addressType'] ?? 0) === 1 && !empty($addr['country']))
				return $addr['country'];
		}
		// Fallback: první adresa s country
		foreach ($addresses as $addr)
		{
			if (!empty($addr['country']))
				return $addr['country'];
		}
		return 'cz';
	}

	// ── Helpers (utility) ──────────────────────────────────────────────────

	private function emptyToNull(mixed $value): ?string
	{
		if ($value === null)
			return null;
		$trimmed = trim((string) $value);
		return $trimmed === '' ? null : $trimmed;
	}

	private function numberOrNull(mixed $value): ?float
	{
		if ($value === null || $value === '')
			return null;
		$f = (float) $value;
		return $f === 0.0 ? null : $f;
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
