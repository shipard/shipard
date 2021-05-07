<?php

namespace E10\Persons;
use \Shipard\Utils\Utils;


function createNewPerson2 ($app, $personData)
{
	$newPerson = array ();

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

	$app->db->query ("INSERT INTO [e10_persons_persons]", $newPerson);
	$newPersonNdx = intval ($app->db->getInsertId ());

	// -- contactInfo
	if (isset($personData ['contacts']))
	{
		forEach ($personData ['contacts'] as $contact)
		{
			$newContact = array ('property' => $contact ['type'], 'group' => 'contacts', 'tableid' => 'e10.persons.persons', 'recid' => $newPersonNdx,
													'valueString' => $contact ['value'], 'created' => new \DateTime ());
			$app->db->query ("INSERT INTO [e10_base_properties]", $newContact);
		}
	}

	// -- address
	if (isset ($personData ['address']))
	{
		forEach ($personData ['address'] as $address)
		{
			$newAddress = array ('tableid' => 'e10.persons.persons', 'recid' => $newPersonNdx);
			Utils::addToArray ($newAddress, $address, 'specification', '');
			Utils::addToArray ($newAddress, $address, 'street', '');
			Utils::addToArray ($newAddress, $address, 'city', '');
			Utils::addToArray ($newAddress, $address, 'zipcode', '');
			Utils::addToArray ($newAddress, $address, 'country', '');
			$app->db->query ("INSERT INTO [e10_persons_address]", $newAddress);
		}
	}

	// -- identification
	if (isset ($personData ['ids']))
	{
		forEach ($personData ['ids'] as $id)
		{
			if ($id ['type'] === 'birthdate')
				continue;
			$newId = array ('property' => $id ['type'], 'group' => 'ids', 'tableid' => 'e10.persons.persons', 'recid' => $newPersonNdx,
											'valueString' => $id ['value'], 'created' => new \DateTime ());
			$app->db->query ("INSERT INTO [e10_base_properties]", $newId);
		}
	}
	return $newPersonNdx;
} // createNewPerson2


class ModuleServices extends \E10\CLI\ModuleServices
{
	public function checkFirstUser ()
	{
		if ($this->initConfig)
		{
			$admin = $this->initConfig ['admin']['xf'];
			$admin ['person']['roles'] = 'admin.all';
			createNewPerson2 ($this->app, $admin);

			if (isset($this->initConfig ['owner']))
			{
				$owner = $this->initConfig ['owner']['xf'];
				createNewPerson2 ($this->app, $owner);
			}
		}
	}

	public function onAppUpgrade ()
	{
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

	public function geoCode ()
	{
		$limit = 20;
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

	public function personValidator()
	{
		if ($this->app->model()->table ('e10doc.debs.journal') === FALSE)
			return;

		$personId = intval($this->app->arg('person-id'));

		$e = new \e10\persons\PersonValidator($this->app);
		if ($personId)
			$e->qryPersonId = $personId;
		$e->run();
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

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'geo-code': return $this->geoCode();
			case 'last-persons-use-create': return $this->lastPersonsUseCreate();
			case 'person-validator': return $this->personValidator();
		}

		parent::onCliAction($actionId);
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'services':  $this->onCronServices(); break;
			case 'stats':     $this->onStats(); break;
		}
		return TRUE;
	}
}


