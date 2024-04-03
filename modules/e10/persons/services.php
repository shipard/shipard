<?php

namespace E10\Persons;
use \Shipard\Utils\Utils;
use \Shipard\Utils\World;



class ModuleServices extends \E10\CLI\ModuleServices
{
	public function checkFirstUser ()
	{
		if ($this->initConfig)
		{
			// -- admin
			$admin = ['person' => $this->initConfig ['admin']];
			$admin ['person']['company'] = 0;
			$admin ['person']['roles'] = 'admin.all';
			$this->createNewPerson ($admin);

			// -- owner
			$owner = [
				'person' => [
					'company' => 1,
					'fullName' => $this->initConfig ['createRequest']['companyName'],
				],
				'address' => [
					[
						'street' => $this->initConfig ['createRequest']['street'],
						'city' => $this->initConfig ['createRequest']['city'],
						'zipcode' => $this->initConfig ['createRequest']['zipcode'],
						'country' => $this->initConfig ['createRequest']['country'],
						'country' => $this->initConfig ['createRequest']['country'],
						'worldCountry' => World::countryNdx($this->app(), $this->initConfig ['createRequest']['country']),
					]
				],
				'ids' => [
					['type' => 'oid', 'value' => $this->initConfig ['createRequest']['companyId']],
				],
				'contacts' => [
					['type' => 'email', 'value' => $this->initConfig ['admin']['login']],
				],
			];
			if (isset($this->initConfig ['createRequest']['companyId']) && $this->initConfig ['createRequest']['companyId'] !== '')
				$owner['ids'][] = 	['type' => 'taxid', 'value' => $this->initConfig ['createRequest']['vatId']];

			$this->createNewPerson ($owner);
		}
	}

	protected function createNewPerson ($personData)
	{
		/** @var \e10\persons\TablePersons $tablePersons */
		$tablePersons = $this->app()->table('e10.persons.persons');
		$newPerson = [];

		$personHead = $personData ['person'];
		Utils::addToArray ($newPerson, $personHead, 'firstName');
		Utils::addToArray ($newPerson, $personHead, 'lastName');
		Utils::addToArray ($newPerson, $personHead, 'fullName', '');
		Utils::addToArray ($newPerson, $personHead, 'company', 0);
		Utils::addToArray ($newPerson, $personHead, 'accountType', 2);

		if ($newPerson ['company'] == 0)
			$newPerson ['fullName'] = $newPerson ['firstName'].' '.$newPerson ['lastName'];

		if (isset ($personHead ['roles']))
			$newPerson ['roles'] = $personHead ['roles'];
		if (isset ($personHead ['login']))
		{
			$newPerson ['login'] = $personHead ['login'];
			$newPerson ['loginHash'] = md5(strtolower(trim($personHead ['login'])));
			$newPerson ['accountState'] = 1;
		}

		$newPerson ['docState'] = 4000;
		$newPerson ['docStateMain'] = 2;

		$newPersonNdx = $tablePersons->dbInsertRec($newPerson);

		// -- contactInfo
		if (isset($personData ['contacts']))
		{
			forEach ($personData ['contacts'] as $contact)
			{
				$newContact = [
					'property' => $contact ['type'], 'group' => 'contacts',
					'tableid' => 'e10.persons.persons', 'recid' => $newPersonNdx,
					'valueString' => $contact ['value'], 'created' => new \DateTime (),
				];
				$this->db()->query ("INSERT INTO [e10_base_properties]", $newContact);
			}
		}

		// -- address
		if (isset ($personData ['address']))
		{
			/** @var \e10\persons\TablePersonsContacts */
			$tablePersonsContact = $this->app->table('e10.persons.personsContacts');
			$cntAddr = 0;
			forEach ($personData ['address'] as $address)
			{
				$newAddress = [
					'person' => $newPersonNdx,
					'adrSpecification' => $address['specification'] ?? '',
					'adrStreet' => $address['street'] ?? '',
					'adrCity' => $address['city'] ?? '',
					'adrZipCode' => $address['zipcode'] ?? '',
					'adrCountry' => $address['worldCountry'] ?? World::countryNdx($this->app, $this->app->cfgItem ('options.core.ownerDomicile', 'cz')),
					'flagAddress' => 1,
					'onTop' => 99,
					'docState' => 4000, 'docStateMain' => 2,
				];

				if ($cntAddr === 0)
					$newAddress['flagMainAddress'] = 1;

				$tablePersonsContact->checkBeforeSave($newAddress);
				$this->app->db->query ('INSERT INTO [e10_persons_personsContacts]', $newAddress);
				$cntAddr++;
			}
		}

		// -- identification
		if (isset ($personData ['ids']))
		{
			forEach ($personData ['ids'] as $id)
			{
				if ($id ['type'] === 'birthdate')
					continue;
				$newId = [
					'property' => $id ['type'], 'group' => 'ids',
					'tableid' => 'e10.persons.persons', 'recid' => $newPersonNdx,
					'valueString' => $id ['value'], 'created' => new \DateTime (),
				];
				$this->db()->query ("INSERT INTO [e10_base_properties]", $newId);
			}
		}

		$newPersonsRecData = $tablePersons->loadItem($newPersonNdx);
		$tablePersons->checkAfterSave2 ($newPersonsRecData);

		return $newPersonNdx;
	}

	public function onAppUpgrade ()
	{
		$s = [];
		$s [] = ['end' => '2023-12-20', 'sql' => "update e10_persons_address set docState = 4000, docStateMain = 2 where docState = 0"];
		$s [] = ['end' => '2022-12-31', 'sql' => "update e10_persons_address set docState = 4000, docStateMain = 2 where docState = 2"];
		$s [] = ['end' => '2023-06-01', 'sql' => "update e10_persons_personsContacts set onTop = 99 where onTop = 0"];
		$this->doSqlScripts ($s);

		// check owner persons properties
		$ownerPerson = $this->app->cfgItem ('options.core.ownerPerson', 0);
		if ($ownerPerson)
		{
			$this->checkPersonProperty($ownerPerson, 'contacts', 'email', $this->app->cfgItem ('options.core.ownerEmail', ''));
			$this->checkPersonProperty($ownerPerson, 'contacts', 'phone', $this->app->cfgItem ('options.core.ownerPhone', ''));
			$this->checkPersonProperty($ownerPerson, 'contacts', 'web', $this->app->cfgItem ('options.core.ownerWeb', ''));
			$this->checkPersonProperty($ownerPerson, 'ids', 'taxid', $this->app->cfgItem ('options.core.ownerVATID', ''));
		}

		$this->checkSystemGroup ('e10-system-admins');
		$this->checkSystemGroup ('e10-support-shipard');

		$this->checkCategories();
	}

	public function checkSystemGroup ($groupId)
	{
		$allGroups = $this->app->cfgItem ('e10.persons.systemGroups', []);
		$groupDef = \E10\searchArray($allGroups, 'id', $groupId);
		if (!$groupDef)
			return;

		$group = $this->app->db()->query('SELECT * FROM [e10_persons_groups] WHERE [systemGroup] = %s', $groupId, ' AND [docState] != %i', 9800)->fetch();
		if (!$group)
		{
			$g = ['name' => $groupDef['name'], 'systemGroup' => $groupId, 'docState' => 4000, 'docStateMain' => 2];
			$this->app->db()->query('INSERT INTO [e10_persons_groups]', $g);
			$group = $this->app->db()->query('SELECT * FROM [e10_persons_groups] WHERE [systemGroup] = %s', $groupId, ' AND [docState] != %i', 9800)->fetch();
		}

		// -- check persons
		$personsInGroup = $this->app->db()->query('SELECT * FROM [e10_persons_personsgroups] WHERE [group] = %i', $group['ndx']);
		if (count($personsInGroup))
			return;

		$emails = ['david@sebik.cz', 'libor@mitrengovi.cz'];
		$rows = $this->app->db()->query('SELECT * FROM [e10_persons_persons] WHERE [login] IN %in', $emails, ' AND [docState] = 4000', ' AND [accountState] = 1');
		foreach ($rows as $r)
		{
			$item = ['group' => $group['ndx'], 'person' => $r['ndx']];
			$this->app->db()->query('INSERT INTO [e10_persons_personsgroups]', $item);
		}
	}

	public function checkPersonProperty ($personNdx, $group, $property, $value)
	{
		if ($value == '')
			return;

		$q[] = 'SELECT * FROM [e10_base_properties] WHERE ';
		array_push($q, '[recid] = %i', $personNdx, 'AND [group] = %s', $group, ' AND [property] = %s', $property);

		$exist = $this->app->db()->query ($q)->fetch();
		if ($exist)
			return;

		$rec = [
			'tableid' => 'e10.persons.persons', 'recid' => $personNdx, 'group' => $group, 'property' => $property,
			'valueString' => $value
		];

		$this->app->db()->query ('INSERT INTO [e10_base_properties] ', $rec);
	}

	function checkCategories()
	{
		$catTypes = $this->app->cfgItem ('e10.persons.categories.types', NULL);
		if (!$catTypes)
			return;

		foreach ($catTypes as $catNdx => $catType)
		{
			if ($catNdx == 99)
				continue;

			$exist = $this->app->db()->query ('SELECT * FROM [e10_persons_categories] WHERE docState != 9800 AND [categoryType] = %i', $catNdx)->fetch();
			if ($exist)
				continue;

			$item = ['fullName' => $catType['name'], 'categoryType' => $catNdx, 'docState' => 4000, 'docStateMain' => 2];
			$this->app->db()->query ('INSERT INTO [e10_persons_categories] ', $item);
		}
	}

	public function onCreateDataSource ()
	{
		$this->checkFirstUser ();
		return TRUE;
	}

	public function geoCode ($fromCli = 0)
	{
		$debug = 0;
		$limit = 0;

		if ($fromCli)
		{
			$debug = intval($this->app()->arg('debug'));
			$limit = intval($this->app()->arg('limit'));
		}

		if (!$limit)
			$limit = 20;

		if ($fromCli)
			echo ":: debug is `{$debug}`, limit is `{$limit}`\n\n";

		$testNewPersons = intval($this->app()->cfgItem ('options.persons.testNewPersons', 0));

		if ($testNewPersons)
		{
			if ($debug > 1)
				echo ":: new persons\n";

			$personRecData = NULL;
			/** @var \e10\persons\TablePersonsContacts */
			$tableContacts = $this->app->table('e10.persons.personsContacts');
			/** @var \e10\persons\TablePersons */
			$tablePersons = $this->app->table('e10.persons.persons');

			$q = [];
			array_push ($q, 'SELECT * FROM [e10_persons_personsContacts] AS [contacts]');
			array_push ($q, ' WHERE 1');
			array_push ($q, ' AND adrLocState = %i', 0, ' AND [flagAddress] = %i', 1);
			array_push ($q, ' AND docState = %i', 4000);
			if ($fromCli)
				array_push ($q, ' ORDER BY ndx ASC');
			else
			array_push ($q, ' ORDER BY ndx DESC');

			$cnt = 1;
			$rows = $this->app->db()->query ($q);
			forEach ($rows as $r)
			{
				if ($debug)
				{
					$personRecData = $tablePersons->loadItem($r['person']);
					echo "* #".$r['person'].' / '.$personRecData['fullName'];
				}

				if (!$tableContacts->geoCode ($r, $debug))
				{
					// TODO: message
				}

				if ($debug)
					echo "\n";
				if ($debug > 1)
					echo "\n";

				$cnt++;
				if ($cnt > $limit)
					break;

				usleep(150000); // max 5 request per second
			}
		}
		else
		{
			/** @var \e10\persons\TableAddress */
			$tableAddress = $this->app->table('e10.persons.address');

			$q[] = 'SELECT * FROM [e10_persons_address] AS [address]';
			array_push ($q, ' WHERE locState = 0');
			array_push ($q, ' ORDER BY ndx DESC');

			$cnt = 0;
			$rows = $this->app->db()->query ($q);
			forEach ($rows as $r)
			{
				if (!$tableAddress->geoCode ($r))
				{
					// TODO: message
				}

				$cnt++;
				if ($cnt > $limit)
					break;

				usleep(150000); // max 5 request per second
			}
		}
	}

	public function personValidator()
	{
		$testNewPersons = intval($this->app->cfgItem ('options.persons.testNewPersons', 0));

		if ($testNewPersons)
		{
			$e = new \e10doc\core\libs\PersonValidator($this->app);
			//$e->maxCount = 5;
			$e->batchCheck();

			return;
		}

		if ($this->app->model()->table ('e10doc.debs.journal') === FALSE)
			return;

		$personId = intval($this->app->arg('person-id'));

		$e = new \e10\persons\PersonValidator($this->app);
		if ($personId)
			$e->qryPersonId = $personId;
		$e->run();
	}

	public function personsRevalidate()
	{
		$testNewPersons = intval($this->app->cfgItem ('options.persons.testNewPersons', 0));
		if (!$testNewPersons)
			return TRUE;

		$e = new \e10doc\core\libs\PersonValidator($this->app);
		$e->maxCount = 10;
		$e->revalidate();

		return TRUE;
	}

	public function lastPersonsUseCreate()
	{
		$lastUseCfg = $this->app->cfgItem('e10.persons.lastUse');
		foreach ($lastUseCfg as $luCfg)
		{
			$lu = $this->app->createObject($luCfg['classId']);
			if (!$lu)
				continue;
			$lu->run();
		}

		$this->app->db->query('UPDATE [e10_persons_persons] SET lastUseDate = NULL');
		$lastUseRows = $this->app->db->query('SELECT person, MAX(lastUseDate) AS lastUseDate FROM e10_persons_personsLastUse GROUP BY person');
		foreach ($lastUseRows as $r)
		{
			$update = ['lastUseDate' => $r['lastUseDate']];
			$this->app->db->query('UPDATE [e10_persons_persons] SET ', $update, ' WHERE ndx = %i', $r['person']);
		}

		// -- categories
		$personsCategories = $this->app->cfgItem ('e10.persons.categories.categories', NULL);
		if ($personsCategories)
		{
			foreach ($personsCategories as $catNdx => $cat)
			{
				if (!isset ($cat['classId']))
					continue;
				$gc = $this->app->createObject($cat['classId']);
				if (!$gc)
					continue;
				$gc->setCategory ($catNdx);
				$gc->run();
			}
		}
	}

	public function onCronServices()
	{
		$this->geoCode();
		$this->personValidator();
	}

	public function onStats()
	{
		$this->lastPersonsUseCreate();
	}

	public function onCronHourly()
	{
		$this->personsRevalidate();
	}

	public function importNewPersons()
	{
		$e = new \e10\persons\libs\ImportNewPersons($this->app);
		$e->run();
	}

	public function importNewPersonsBA()
	{
		$e = new \e10\persons\libs\ImportNewPersons($this->app);
		$e->importBankAccounts();
	}

	public function importNewPersonsBAClean()
	{
		$e = new \e10\persons\libs\ImportNewPersons($this->app);
		$e->cleanOldBankAccounts();
	}

	public function importNewPersonsValidityClean()
	{
		$e = new \e10\persons\libs\ImportNewPersons($this->app);
		$e->cleanOldValidity();
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'geo-code': return $this->geoCode(1);
			case 'last-persons-use-create': return $this->lastPersonsUseCreate();
			case 'person-validator': return $this->personValidator();
			case 'persons-revalidate': return $this->personsRevalidate();
			case 'import-new-persons': return $this->importNewPersons();
			case 'import-new-persons-ba': return $this->importNewPersonsBA();
			case 'import-new-persons-ba-clean': return $this->importNewPersonsBAClean();
			case 'import-new-persons-validity-clean': return $this->importNewPersonsValidityClean();
		}

		parent::onCliAction($actionId);
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'services':  $this->onCronServices(); break;
			case 'stats':     $this->onStats(); break;
			case 'hourly': 		$this->onCronHourly(); break;
		}
		return TRUE;
	}
}


