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
			$exist = $this->db()->query('SELECT [ndx], [importState], [valid] FROM [services_persons_persons] WHERE [oid] = %s', $personId,
																	' AND [country] = %i', $countryNdx)->fetch();
		}
		if ($exist)
		{
			if ($exist['importState'] === 0 && $exist['valid'])
				$this->refreshImport($exist['ndx']);

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
			if (!isset($old[$key]) || $value !== $old[$key])
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
		if ($this->debug)
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
				}
			}
			else
			{
				$insert = [
					'addressId' => $oneAddr['addressId'],
					'person' => $this->personNdx,
					'type' => $oneAddr['type'],

					'street' => $oneAddr['street'],
					'city' => $oneAddr['city'],
					'zipcode' => $oneAddr['zipcode'],
					'country' => $oneAddr['country'],
					'specification' => $oneAddr['specification'] ?? '',
					'natAddressGeoId' => $oneAddr['natAddressGeoId'] ?? 0,
				];
				if (isset($oneAddr['natId']))
					$insert['natId'] = $oneAddr['natId'];
				if (isset($oneAddr['validFrom']))
					$insert['validFrom'] = $oneAddr['validFrom'];
				if (isset($oneAddr['validTo']))
					$insert['validTo'] = $oneAddr['validTo'];

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
