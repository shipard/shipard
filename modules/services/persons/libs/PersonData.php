<?php

namespace services\persons\libs;

use Monolog\Handler\Curl\Util;
use \Shipard\Utils\Json;
use \Shipard\Utils\World;
use \Shipard\Utils\Utils;

/**
 * @class PersonData
 */
class PersonData extends \services\persons\libs\CoreObject
{
	var $personNdx = '';
	var $personId = '';
  var $countryId = '';
  var $debug = 0;

  var $data = NULL;
	var $dataShow = NULL;
	var $dataExport = NULL;

	var ?\services\persons\libs\LogRecord $logRecord = NULL;


  public function setPersonNdx($personNdx)
  {
    $this->personNdx = $personNdx;
  }

	public function loadById (string $countryCode, string $personId)
	{
		$countryNdx = 60;
		$exist = NULL;

		if ($personId[0] === '_')
		{
			$iid = substr($personId, 1);
			$exist = $this->db()->query('SELECT [ndx], [importState], [valid] FROM [services_persons_persons]',
																	' WHERE [iid] = %s', $iid,
																	' AND [country] = %i', $countryNdx)->fetch();
		}
		elseif ($personId[0] === '*')
		{
			$ndx = intval(substr($personId, 1));
			$exist = $this->db()->query('SELECT [ndx], [importState], [valid] FROM [services_persons_persons]',
																	' WHERE [ndx] = %i', $ndx,
																	' AND [country] = %i', $countryNdx)->fetch();
		}
		else
		{
			$exist = $this->db()->query('SELECT [ndx], [importState], [valid], [newDataAvailable] FROM [services_persons_persons] WHERE [oid] = %s', $personId,
																	' AND [country] = %i', $countryNdx)->fetch();
		}
		if ($exist)
		{
			if ($exist['importState'] === 0 && $exist['valid'])
				$this->refreshImport($exist['ndx']);
			elseif ($exist['newDataAvailable'])
			{
				$this->refreshImport($exist['ndx']);
			}

			$this->setPersonNdx($exist['ndx']);
			$this->load();
		}
	}

	public function refreshImport($personNdx)
	{
		// -- download
		$downloadEngine = new \services\persons\libs\OnlinePersonRegsDownloadService($this->app);
		$downloadEngine->setPersonNdx($personNdx);
		$downloadEngine->newDataAvailable = 1;
		$downloadEngine->downloadOnePerson();

		// -- import
		$importEngine = new \services\persons\libs\PersonRegsImportService($this->app);
		$importEngine->personNdx = $personNdx;
		$importEngine->importOnePerson();
	}

	public function addPersonFromReg($personId, $countryNdx = 60)
	{
		$exist = $this->db()->query('SELECT [ndx], [importState], [valid] FROM [services_persons_persons]',
																' WHERE [oid] = %s', $personId,
																' AND [country] = %i', $countryNdx)->fetch();

		if ($exist)
		{
			$this->refreshImport($exist['ndx']);
			return;
		}

		$iid = Utils::createToken(8, FALSE, TRUE);

		$insert['valid'] = TRUE;
		$now = new \DateTime();
		$newPerson = [
			'country' => $countryNdx,
			'oid' => $personId,
		];
		$newPerson['created'] = $now;
		$newPerson['updated'] = $now;
		$newPerson['iid'] = $iid;
		$newPerson['vatState'] = 99;

		$this->db()->query('INSERT INTO [services_persons_persons] ', $newPerson);
		$newNdx = intval ($this->db()->getInsertId ());

		$insertId = [
			'person' => $newNdx,
			'idType' => 2,
			'id' => $personId,
		];
		$this->db()->query('INSERT INTO [services_persons_ids]', $insertId);

		$this->refreshImport($newNdx);
	}

  protected function loadCoreData()
  {
		$q = [];
		array_push ($q, 'SELECT * FROM [services_persons_persons]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [ndx] = %s', $this->personNdx);

		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$this->personId = $r['oid'];

			$p = ['ndx' => $r['ndx'], 'person' => $r->toArray(), 'address' => [], 'ids' => []];
			Json::polish($p['person']);
			// -- address
			$rowsAddr = $this->db()->query ('SELECT * FROM [services_persons_address] WHERE [person] = %i', $r['ndx']);
			foreach ($rowsAddr as $ra)
			{
				$raa = $ra->toArray();

				// -- addressPlace - coordinates
				if ($ra['addressPlaceNdx'])
				{
					$addrPlaceRec = $this->app()->loadItem($ra['addressPlaceNdx'], 'services.locAddr.addrPlaces');
					if ($addrPlaceRec)
					{
						$raa['wgs84lat'] = $addrPlaceRec['wgs84lat'];
						$raa['wgs84lng'] = $addrPlaceRec['wgs84lng'];
					}
				}

				Json::polish($raa);
				$p['address'][] = $raa;
			}
			// -- ids
			$rowsIds = $this->db()->query ('SELECT * FROM [services_persons_ids] WHERE [person] = %i', $r['ndx']);
			foreach ($rowsIds as $rid)
			{
				$rida = $rid->toArray();
				Json::polish($rida);
				$p['ids'][] = $rida;
			}
			// -- bank accounts
			$rowsIds = $this->db()->query ('SELECT * FROM [services_persons_bankAccounts] WHERE [person] = %i', $r['ndx']);
			foreach ($rowsIds as $rid)
			{
				$rba = $rid->toArray();
				Json::polish($rba);
				$p['bankAccounts'][] = $rba;
			}

			$this->data = $p;
			break;
		}
  }

  public function load()
  {
    $this->loadCoreData();
  }

	function prepareDataShow()
	{
		$this->dataShow = $this->data;

		$this->dataShow['person']['country'] = World::countryId($this->app, $this->dataExport['person']['country']);
		$this->dataShow['person']['validFromH'] = Utils::datef($this->dataShow['person']['validFrom'], '%d');
		$this->dataShow['person']['validToH'] = Utils::datef($this->dataShow['person']['validTo'], '%d');
		if (isset($this->dataShow['address']))
		{
			foreach($this->dataShow['address'] as $itemId => &$item)
			{
				$item['country'] = World::countryId($this->app, $item['country']);
				$item['addressText'] = self::addressText($item);
				if ($item['type'] === 0)
				{
					$this->dataShow['person']['primaryAddressText'] = self::addressText($item);
					$item['addressFlags'][] = ['prefix' => 'Sídlo'];
				}
				if ($item['type'] === 1)
					$item['addressFlags'][] = ['prefix' => 'Provozovna'];

				if ($item['natId'] !== '')
					$item['addressFlags'][] = ['prefix' => 'IČP', 'id' => $item['natId']];
			}
		}

		if (isset($this->dataShow['ids']))
		{
			foreach($this->dataShow['ids'] as $itemId => &$item)
			{
				unset($item['ndx']);
				unset($item['person']);

				if ($item['idType'] === self::idtOIDPrimary)
				{
					$this->dataShow['person']['titleIds'][] = ['id' => $item['id'], 'prefix' => 'IČ'];
				}
				elseif ($item['idType'] === self::idtVATPrimary)
				{
					$this->dataShow['person']['titleIds'][] = ['id' => $item['id'], 'prefix' => 'DIČ'];
				}
			}

			if ($this->dataShow['person']['vatState'] === 0)
				$this->dataShow['person']['titleIds'][] = ['id' => '', 'prefix' => 'Neplátce DPH'];
		}

		if (isset($this->dataShow['bankAccounts']))
		{
			foreach($this->dataShow['bankAccounts'] as $itemId => &$item)
			{
				$item['validFromH'] = Utils::datef($item['validFrom'], '%d');
				unset($item['ndx']);
				unset($item['person']);
			}
		}
	}

	function prepareDataExport()
	{
		$this->dataExport = array_merge(['status' => 1], $this->data);
		unset ($this->dataExport['ndx']);
		unset ($this->dataExport['person']['ndx']);
		unset ($this->dataExport['person']['created']);
		unset ($this->dataExport['person']['newDataAvailable']);
		unset ($this->dataExport['person']['importState']);
		$this->dataExport['person']['country'] = World::countryId($this->app, $this->dataExport['person']['country']);

		if (isset($this->dataExport['address']))
		{
			foreach($this->dataExport['address'] as $itemId => &$item)
			{
				unset($item['ndx']);
				unset($item['person']);
				unset($item['addressId']);

				unset($item['addressPlaceNdx']);
				unset($item['saZipCodeNdx']);
				unset($item['saStreetNdx']);
				unset($item['saCityNdx']);
				unset($item['saCityPartNdx']);
				unset($item['saCityPart2Ndx']);
				unset($item['saStreetNoNdx']);
				unset($item['saLaUnit10Ndx']);
				unset($item['saLaUnit11Ndx']);

				$item['country'] = World::countryId($this->app, $item['country']);
			}
		}

		if (isset($this->dataExport['ids']))
		{
			foreach($this->dataExport['ids'] as $itemId => &$item)
			{
				unset($item['ndx']);
				unset($item['person']);
			}
		}

		if (isset($this->dataExport['bankAccounts']))
		{
			foreach($this->dataExport['bankAccounts'] as $itemId => &$item)
			{
				unset($item['ndx']);
				unset($item['person']);
			}
		}
	}

	/**
	 * Build a `shpd.persons.person.v1` canonical payload from $this->data.
	 * Caller must run loadById() (or loadCoreData()) first. Returns the
	 * canonical as an associative array ready for json_encode.
	 *
	 * Output is consumed by Nový Shipard's PersonApplier::apply() — see
	 * docs/exchange-format-persons.md in the shipard/shpd repo.
	 */
	public function createDataInNewFormat(): array
	{
		$person       = $this->data['person']       ?? [];
		$addresses    = $this->data['address']      ?? [];
		$ids          = $this->data['ids']          ?? [];
		$bankAccounts = $this->data['bankAccounts'] ?? [];

		// Country ndx → ISO ("cz", "sk", …). Header is always numeric in
		// $this->data (Json::polish doesn't touch ints); default to CZ.
		$country = $this->countryIdFromNdx($person['country'] ?? 60) ?? 'cz';

		$companyId = $this->normalize($person['oid'] ?? null);
		$vatActive = ((int) ($person['vatState'] ?? 99)) === 1;

		// ids[] → flat fields. idtVATPrimary (0) carries the DIČ string
		// ("CZ12345678"). idtOIDPrimary (2) is redundant with person.oid.
		$taxId = null;
		foreach ($ids as $idRow)
		{
			if ((int) ($idRow['idType'] ?? -1) === self::idtVATPrimary)
			{
				$taxId = $this->normalize($idRow['id'] ?? null);
				break;
			}
		}
		// taxId and vatId share the CZ "DIČ" string; vatId is suppressed
		// when the firm is not a VAT payer.
		$vatId = $vatActive ? ($this->normalize($person['vatID'] ?? null) ?? $taxId) : null;

		// valid=1 → still active; valid=0 → closed (use validTo as closedDate).
		$isClosed = ((int) ($person['valid'] ?? 0)) !== 1;

		return [
			'format'        => 'shpd.persons.person',
			'formatVersion' => '1.0',

			'source' => [
				'kind'        => 'import.shipardRegistry',
				'fetchedAt'   => (new \DateTime())->format(\DateTime::ATOM),
				'registryRef' => $country . '/' . ($companyId ?? ''),
			],

			'personType' => 'company',          // always company per Fáze 1
			'country'    => $country,
			'personId'   => null,

			'companyId'         => $companyId,
			'taxId'             => $taxId,
			'vatId'             => $vatId,
			'courtRegistration' => null,
			'govEBoxId'         => $this->normalize($person['govEBoxId'] ?? null),

			'name' => [
				'fullName' => $this->normalize($person['fullName'] ?? null),
			],

			'status' => [
				'isClosed'   => $isClosed,
				'closedDate' => $isClosed ? $this->dateString($person['validTo'] ?? null) : null,
				'isOwn'      => false,
			],

			'addresses'    => $this->buildNewFormatAddresses($addresses, $country),
			'bankAccounts' => $this->buildNewFormatBankAccounts($bankAccounts),
			'contacts'     => [],
		];
	}

	/**
	 * Map legacy `services_persons_address` rows to canonical Address
	 * sub-objects. All records are emitted — including historical entries
	 * with validTo set — so the new Shipard applier preserves the full
	 * lineage.
	 *
	 * Legacy `type` mapping:
	 *   0 → 1 (Sídlo)
	 *   1 → 3 (Provozovna); natId becomes placeRegId with placeRegType="ICP"
	 */
	private function buildNewFormatAddresses(array $addresses, string $defaultCountry): array
	{
		$out = [];
		foreach ($addresses as $addr)
		{
			$legacyType = (int) ($addr['type'] ?? 0);
			$isProvozovna = ($legacyType === 1);
			$addressType = match ($legacyType) {
				0       => 1,  // Sídlo
				1       => 3,  // Provozovna
				default => 1,
			};

			$countryAddr = $this->countryIdFromNdx($addr['country'] ?? null) ?? $defaultCountry;

			$street   = $this->normalize($addr['saStreetName'] ?? null)
			         ?? $this->normalize($addr['street']       ?? null);
			$houseNr  = $this->normalize($addr['saHouseNr']    ?? null);
			$orientNr = (int) ($addr['saHouseNr2'] ?? 0);
			$city     = $this->normalize($addr['saCityName']     ?? null)
			         ?? $this->normalize($addr['city']           ?? null);
			$cityPart = $this->normalize($addr['saCityPartName'] ?? null);
			$zip      = $this->normalize($addr['zipcode']        ?? null);

			// saCityId is the ZÚJ kód (matches saLaUnit11Id); cast to string
			// because canonical divisionCode is a string FK lookup against
			// world_divisions.code.
			$divisionCode = !empty($addr['saCityId'])        ? (string) $addr['saCityId']        : null;
			$registryCode = !empty($addr['natAddressGeoId']) ? (string) $addr['natAddressGeoId'] : null;

			$out[] = [
				'addressType'    => $addressType,
				'name'           => null,
				'placeRegType'   => $isProvozovna ? 'ICP' : null,
				'placeRegId'     => $isProvozovna ? $this->normalize($addr['natId'] ?? null) : null,
				'isStandardized' => (bool) ($addr['standardized'] ?? false),

				'street'            => $street,
				'houseNumber'       => $houseNr,
				'orientationNumber' => $orientNr > 0 ? (string) $orientNr : null,
				'city'              => $city,
				'cityPart'          => $cityPart,
				'district'          => null,
				'zip'               => $zip,
				'country'           => $countryAddr,
				'registryCode'      => $registryCode,
				'divisionCode'      => $divisionCode,

				'latitude'  => isset($addr['wgs84lat']) ? (float) $addr['wgs84lat'] : null,
				'longitude' => isset($addr['wgs84lng']) ? (float) $addr['wgs84lng'] : null,
				'manualGps' => false,

				'displayLine'  => $this->composeDisplayLine($street, $houseNr, $city, $zip),
				'displayBlock' => $this->composeDisplayBlock($street, $houseNr, $city, $zip),

				'orderPos'  => 0,
				'validFrom' => $this->dateString($addr['validFrom'] ?? null),
				'validTo'   => $this->dateString($addr['validTo']   ?? null),
				'note'      => null,
			];
		}
		return $out;
	}

	private function buildNewFormatBankAccounts(array $accounts): array
	{
		$out = [];
		foreach ($accounts as $bank)
		{
			$out[] = [
				'name'          => null,
				'accountNumber' => $this->normalize($bank['bankAccount'] ?? null),
				'iban'          => null,            // not derivable serverside
				'bic'           => null,
				'currency'      => null,
				'source'        => 2,               // vatRegistry (bank.bankAccountSources)
				'orderPos'      => 0,
				'validFrom'     => $this->dateString($bank['validFrom'] ?? null),
				'validTo'       => $this->dateString($bank['validTo']   ?? null),
			];
		}
		return $out;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────────

	private function countryIdFromNdx(mixed $value): ?string
	{
		if (is_int($value) && $value > 0)
			return strtolower(World::countryId($this->app, $value)) ?: null;
		if (is_string($value) && ctype_digit($value) && (int) $value > 0)
			return strtolower(World::countryId($this->app, (int) $value)) ?: null;
		if (is_string($value) && trim($value) !== '')
			return strtolower(trim($value));
		return null;
	}

	private function composeDisplayLine(?string $street, ?string $house, ?string $city, ?string $zip): ?string
	{
		$left = $street !== null ? ($house !== null ? "$street $house" : $street) : null;
		$right = null;
		if ($city !== null)
			$right = $zip !== null ? ($this->formatZip($zip) . ' ' . $city) : $city;
		elseif ($zip !== null)
			$right = $this->formatZip($zip);
		$parts = array_filter([$left, $right]);
		return $parts === [] ? null : implode(', ', $parts);
	}

	private function composeDisplayBlock(?string $street, ?string $house, ?string $city, ?string $zip): ?string
	{
		$lines = [];
		if ($street !== null)
			$lines[] = $house !== null ? "$street $house" : $street;
		if ($city !== null || $zip !== null)
		{
			$cityLine = trim(($zip !== null ? $this->formatZip($zip) . ' ' : '') . ($city ?? ''));
			if ($cityLine !== '')
				$lines[] = $cityLine;
		}
		return $lines === [] ? null : implode("\n", $lines);
	}

	private function formatZip(string $zip): string
	{
		return (strlen($zip) === 5 && ctype_digit($zip))
			? substr($zip, 0, 3) . ' ' . substr($zip, 3)
			: $zip;
	}

	private function dateString(mixed $value): ?string
	{
		if ($value instanceof \DateTimeInterface)
			return $value->format('Y-m-d');
		if (is_string($value) && $value !== '')
			return $value;
		return null;
	}

	private function normalize(mixed $value): ?string
	{
		if (!is_string($value)) return null;
		$trimmed = trim($value);
		return $trimmed === '' ? null : $trimmed;
	}

	function setCoreInfo(array $data)
  {
		if (!$this->data)
			$this->data = ['person' => []];

		foreach ($data as $k => $v)
			$this->data	['person'][$k] = $v;
	}

	function addAddress(array $address)
	{
		$aid = $address['addressId'];
		if ($this->app()->debug > 1)
		{
			echo "  - addAddress: `{$aid}`".json_encode($address)."\n";
		}
		$this->data	['address'][$aid] = $address;
	}

	function addBankAccount(array $bankAccount)
	{
		$this->data	['bankAccounts'][] = $bankAccount;
	}

	function addID (array $id)
	{
		$this->data	['ids'][] = $id;
	}

	function recordUpdate(array $old, array $new, array &$updateRec, array &$changes)
	{
		Json::polish($old);
		Json::polish($new);

		foreach ($new as $key => $value)
		{
			if (!isset($old[$key]) || $value != $old[$key])
			{
				$updateRec[$key] = $value;
				$changes[$key] = ['from' => $old[$key] ?? '', 'to' => $value];
			}
		}
	}

	public function saveChanges_Core (PersonData $changedPerson)
	{
		if (!isset($changedPerson->data['person']))
			return;

		$update = [];
		$changes = [];
		$this->recordUpdate($this->data['person'], $changedPerson->data['person'], $update, $changes);
		if ($this->app()->debug)
		{
			/*
			echo "--- saveChanges_Core ---\n";
			echo "  - FROM: ".json_encode($this->data['person'])."\n";
			echo "  -   TO: ".json_encode($changedPerson->data['person'])."\n";
			*/
		}
		if (count($update))
		{
			$this->db()->query('UPDATE [services_persons_persons] SET ', $update, ' WHERE [ndx] = %i', $this->personNdx);

			$personData = $this->app()->loadItem($this->personNdx, 'services.persons.persons');
			if ($personData)
			{
				if (!Utils::dateIsBlank($personData['validTo']) && $personData['valid'])
					$this->db()->query('UPDATE [services_persons_persons] SET [valid] = %i', 0, ' WHERE [ndx] = %i', $this->personNdx);
				elseif (!$personData['valid'] && Utils::dateIsBlank($personData['validTo']) && !Utils::dateIsBlank($personData['validFrom']))
					$this->db()->query('UPDATE [services_persons_persons] SET [valid] = %i', 1, ' WHERE [ndx] = %i', $this->personNdx);
			}

			$this->logRecord->addItem('update-person-core', '', ['update' => ['tableId' => 'services.persons.persons', 'changes' => $changes]]);
		}
	}

	function saveChanges_Ids (PersonData $changedPerson)
	{
		if (!isset($changedPerson->data['ids']))
			return;

		$usedIdNdxs = [];

		foreach ($changedPerson->data['ids'] as $oneId)
		{
			$existedId = $this->db()->query('SELECT * FROM [services_persons_ids] WHERE [person] = %i', $this->personNdx,
																			' AND [idType] = %i', $oneId['idType'], ' AND [id] = %s', $oneId['id'])->fetch();
			if ($existedId)
			{
				$usedIdNdxs[] = $existedId['ndx'];
				$update = [];
				$changes = [];
				$this->recordUpdate($existedId->toArray(), $oneId, $update, $changes);
				if (count($update))
				{
					$this->logRecord->addItem('update-person-id', $oneId['id'], ['update' => ['tableId' => 'services.persons.ids', 'changes' => $changes]]);
					$this->db()->query('UPDATE [services_persons_ids] SET ', $update, ' WHERE [ndx] = %i', $existedId['ndx']);
				}
			}
			else
			{
				$insert = [
					'person' => $this->personNdx,
					'idType' => $oneId['idType'],
					'id' => $oneId['id']
				];

				$this->db()->query('INSERT INTO [services_persons_ids]', $insert);
				$newNdx = intval ($this->db()->getInsertId ());

				$usedIdNdxs[] = $newNdx;

				$this->logRecord->addItem('new-person-id', $oneId['id'], ['insert' => ['tableId' => 'services.persons.ids', 'recId' => $newNdx, 'values' => $insert]]);
			}
		}
	}

	function saveChanges_Address (PersonData $changedPerson)
	{
		if (!isset($changedPerson->data['address']))
			return;

		$usedAddrNdxs = [];

		foreach ($changedPerson->data['address'] as $oneAddr)
		{
			$existedAddr = $this->db()->query('SELECT * FROM [services_persons_address] WHERE [person] = %i', $this->personNdx,
																				' AND [addressId] = %s', $oneAddr['addressId'])->fetch();
			if ($existedAddr)
			{
				$usedAddrNdxs[] = $existedAddr['ndx'];
				$update = [];
				$changes = [];
				$this->recordUpdate($existedAddr->toArray(), $oneAddr, $update, $changes);
				if (count($update))
				{
					$this->db()->query('UPDATE [services_persons_address] SET ', $update, ' WHERE [ndx] = %i', $existedAddr['ndx']);
					$this->logRecord->addItem('update-person-address', '', ['update' => ['tableId' => 'services.persons.address', 'recId' => $existedAddr['ndx'], 'changes' => $changes]]);
					if ($this->app()->debug > 1)
					{
						echo "--- saveChanges_Address ---\n";
						echo "  -  FROM: ".json_encode($existedAddr->toArray())."\n";
						echo "  -    TO: ".json_encode($oneAddr)."\n";
						echo "  -UPDATE: ".json_encode($update)."\n";
					}
				}
			}
			else
			{
				$insert = $oneAddr;
				$insert['person'] = $this->personNdx;

				$this->db()->query('INSERT INTO [services_persons_address]', $insert);
				$newNdx = intval ($this->db()->getInsertId ());
				$usedAddrNdxs[] = $newNdx;

				$this->logRecord->addItem('new-person-address', '', ['insert' => ['tableId' => 'services.persons.address', 'recId' => $newNdx, 'values' => $insert]]);
			}
		}
	}

	function saveChanges_BankAccounts (PersonData $changedPerson)
	{
		$usedNdxs = [];
		if (isset($changedPerson->data['bankAccounts']))
		{
			foreach ($changedPerson->data['bankAccounts'] as $oneItem)
			{
				$existed = $this->db()->query('SELECT * FROM [services_persons_bankAccounts] WHERE [person] = %i', $this->personNdx,
																			' AND [bankAccount] = %s', $oneItem['bankAccount'])->fetch();
				if ($existed)
				{
					$usedNdxs[] = $existed['ndx'];
					$update = [];
					$changes = [];
					$this->recordUpdate($existed->toArray(), $oneItem, $update, $changes);
					if (count($update))
					{
						$this->db()->query('UPDATE [services_persons_bankAccounts] SET ', $update, ' WHERE [ndx] = %i', $existed['ndx']);
						$this->logRecord->addItem('update-person-bank-account', $oneItem['bankAccount'], ['update' => ['tableId' => 'services.persons.bankAccounts', 'recId' => $existed['ndx'], 'changes' => $changes]]);
					}
				}
				else
				{
					$insert = [
						'person' => $this->personNdx,
						'bankAccount' => $oneItem['bankAccount'],
						'validFrom' => $oneItem['validFrom'],
					];

					$this->db()->query('INSERT INTO [services_persons_bankAccounts]', $insert);
					$newNdx = intval ($this->db()->getInsertId ());
					$usedNdxs[] = $newNdx;
					$this->logRecord->addItem('new-person-bank-account', $oneItem['bankAccount'], ['insert' => ['tableId' => 'services.persons.bankAccounts', 'recId' => $newNdx, 'values' => $insert]]);
				}
			}
		}
	}

	public function saveChanges (PersonData $changedPerson, \services\persons\libs\LogRecord $logRecord)
	{
		$this->logRecord = $logRecord;

		$this->saveChanges_Core($changedPerson);
		$this->saveChanges_Ids($changedPerson);
		$this->saveChanges_Address($changedPerson);
		$this->saveChanges_BankAccounts($changedPerson);

		$this->db()->query('UPDATE [services_persons_persons] SET [newDataAvailable] = %i', 0,
												', [importState] = 1 WHERE [ndx] = %i', $this->personNdx);
	}
}
