<?php

namespace e10\persons\libs\register;

use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Json;
use \Shipard\Utils\World;


/**
 * class PersonRegister
 */
class PersonRegister extends Utility
{
  var $registerData = NULL;
  var $generalFailure = FALSE;
  var $useOfficesAutoLoading = 0;

  /** @var \e10\persons\TablePersonsContacts */
  var $tablePersonsContact;

  var $personOid = '';
  var $personVATIDs = [];
  var $personNdx = 0;
  var $personRecData = NULL;
  var $personNames = [];
  var $personMainAddress = [];
  var $personOffices = [];
  var $missingOffices = [];
  var $personBA = [];
  var $missingBA = [];

  var $addDocState = 1000;
  var $addDocStateMain = 0;

  var $diff = ['msgs' => [], 'updates' => []];

  protected function init()
  {
    $this->useOfficesAutoLoading = intval($this->app()->cfgItem ('options.persons.useOfficesAutoLoading', 0));
    $this->tablePersonsContact = $this->app()->table('e10.persons.personsContacts');
  }

  public function setPersonNdx($personNdx)
  {
    $this->init();

    $this->personNdx = $personNdx;
    $this->personRecData = $this->app()->loadItem($this->personNdx, 'e10.persons.persons');
    $this->loadPersonOid();
    $this->loadPersonVatIDs();
    $this->loadPersonNames();

    if ($this->generalFailure)
      return;

    $this->loadContacts();
    $this->loadBA();

    $this->loadByOid($this->personOid);
    if ($this->generalFailure)
      return;

    $this->checkOffices();
    $this->checkBA();
  }

  public function addPerson($personId)
  {
    $this->init();

    $this->loadByOid($personId);
    if (!$this->registerData)
    {

      return;
    }

    $this->addPerson_saveBase();

    // -- address
    foreach ($this->registerData['address'] as $addr)
    {
      if (!$this->useOfficesAutoLoading && $addr['type'] !== 0)
        continue;

      $this->addAddress($addr);
    }

    // -- bank accounts
    $baIds = [];
    foreach ($this->registerData['bankAccounts'] as $ba)
      $baIds[] = $ba['bankAccount'];
    $this->addBankAccounts($baIds);
  }

  protected function addPerson_saveBase()
  {
    $newPerson = [];
		$newPerson ['person'] = [];
		$newPerson ['person']['company'] = 1;
		$newPerson ['person']['fullName'] = $this->registerData['person']['fullName'];
		$newPerson ['person']['docState'] = $this->addDocState;
		$newPerson ['person']['docStateMain'] = $this->addDocStateMain;

		$newPerson ['ids'][] = ['type' => 'oid', 'value' => $this->registerData['person']['oid']];
    if (isset($this->registerData['person']['vatID']) && $this->registerData['person']['vatID'] !== '')
		  $newPerson ['ids'][] = ['type' => 'taxid', 'value' => $this->registerData['person']['vatID']];

    $this->personNdx = \E10\Persons\createNewPerson ($this->app, $newPerson);
    $this->personRecData = $this->app()->loadItem($this->personNdx, 'e10.persons.persons');
  }

  public function loadByOid($id)
  {
    $url = 'https://data.shipard.org/persons/cz/'.htmlspecialchars($id).'/json';
    $result = $this->httpGet($url, FALSE);
    if (!$result)
    {
      $this->generalFailure = TRUE;
      return;
    }
    $data = Json::decode($result);
    if (!$data)
    {
      $this->generalFailure = TRUE;
      return;
    }

    if (!isset($data['status']))
    {
      $this->generalFailure = TRUE;
      return;
    }

    if (!intval($data['status']))
    {
      $this->generalFailure = TRUE;
      return;
    }

    $this->registerData = $data;
  }

  public function loadPersonOid ($forcePersonNdx = 0)
	{
    $personNdx = ($forcePersonNdx) ? $forcePersonNdx : $this->personRecData['ndx'];

		$q[] = 'SELECT * FROM [e10_base_properties] AS props';
		array_push ($q, ' WHERE [recid] = %i', $personNdx);
		array_push ($q, ' AND [tableid] = %s', 'e10.persons.persons', 'AND [group] = %s', 'ids', ' AND property = %s', 'oid');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['valueString'] === '')
				continue;
			$this->personOid = trim($r['valueString']);
      break;
		}

    if ($this->personOid === '')
      $this->generalFailure = TRUE;

    return $this->personOid;
	}

  protected function loadPersonVatIDs ($forcePersonNdx = 0)
	{
    $personNdx = ($forcePersonNdx) ? $forcePersonNdx : $this->personRecData['ndx'];

		$q[] = 'SELECT * FROM [e10_base_properties] AS props';
		array_push ($q, ' WHERE [recid] = %i', $this->personRecData['ndx']);
		array_push ($q, ' AND [tableid] = %s', 'e10.persons.persons', 'AND [group] = %s', 'ids', ' AND property = %s', 'taxid');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['valueString'] === '')
				continue;
			$this->personVATIDs[$r['valueString']] = ['valid' => 0];
		}
	}

  protected function loadContacts()
  {
    $q [] = 'SELECT [contacts].* ';
		array_push ($q, ' FROM [e10_persons_personsContacts] AS [contacts]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [contacts].[person] = %i', $this->personNdx);
    array_push ($q, ' AND [contacts].[docState] != %i', 9800);
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $ap = [];

      if ($r['adrSpecification'] != '')
        $ap[] = $r['adrSpecification'];
      if ($r['adrStreet'] != '')
        $ap[] = $r['adrStreet'];
      if ($r['adrCity'] != '')
        $ap[] = $r['adrCity'];
      if ($r['adrZipCode'] != '')
        $ap[] = $r['adrZipCode'];

      $at = implode(', ', $ap);

      if ($r['flagOffice'])
      {
        $this->personOffices[$r['ndx']] = $r->toArray();
        $this->personOffices[$r['ndx']]['addressText'] = $at;
      }
      if ($r['flagMainAddress'])
      {
        $this->personMainAddress[$r['ndx']] = $r->toArray();
        $this->personMainAddress[$r['ndx']]['addressText'] = $at;
      }
    }
  }

  protected function loadBA()
  {
    $q [] = 'SELECT [ba].* ';
		array_push ($q, ' FROM [e10_persons_personsBA] AS [ba]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [ba].[person] = %i', $this->personNdx);
    array_push ($q, ' AND [ba].[docState] != %i', 9800);
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $this->personBA[$r['ndx']] = $r->toArray();
      $this->personBA[$r['ndx']]['baText'] = $r['bankAccount'];
    }
  }

	protected function loadPersonNames()
	{
		$this->personNames[] = $this->personRecData['fullName'];

		$q[] = 'SELECT * FROM [e10_base_properties] AS props';
		array_push ($q, ' WHERE [recid] = %i', $this->personNdx);
		array_push ($q, ' AND [tableid] = %s', 'e10.persons.persons', 'AND [group] = %s', 'ids', ' AND property = %s', 'officialName');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['valueString'] === '')
				continue;
			$this->personNames[] = $r['valueString'];
		}
	}

  protected function checkOffices()
  {
    if (!isset($this->registerData['address']))
      return;

    foreach ($this->registerData['address'] as &$a)
    {
      $ap = [];

      if ($a['specification'] != '')
        $ap[] = $a['specification'];
      if ($a['street'] != '')
        $ap[] = $a['street'];
      if ($a['city'] != '')
        $ap[] = $a['city'];
      if ($a['zipcode'] != '')
        $ap[] = $a['zipcode'];

      $at = implode(', ', $ap);
      $a['addressText'] = $at;

      if ($a['type'] === 1)
      {
        $existedOffice = Utils::searchArray($this->personOffices, 'id1', $a['natId']);
        if ($existedOffice)
        {

        }
        else
        {
          $this->missingOffices[] = $a;
        }
      }
    }
  }

  public function addOfficesByNatIds($natIds)
  {
    foreach ($natIds as $natId)
    {
      $officeData = Utils::searchArray($this->registerData['address'], 'natId', $natId);
      if (!$officeData)
        continue;

      $this->addAddress($officeData);
    }
  }

  protected function addAddress($addressData, $flags = NULL)
  {
    $newAddress = [
      'person' => $this->personNdx,
      'adrSpecification' => $addressData['specification'],
      'adrStreet' => $addressData['street'],
      'adrCity' => $addressData['city'],
      'adrZipCode' => $addressData['zipcode'],
      'adrCountry' => World::countryNdx($this->app(), $addressData['country']),

      'validFrom' => $addressData['validFrom'] ?? NULL,
      'validTo' => $addressData['validTo'] ?? NULL,

      'flagAddress' => 1,
      'onTop' => 99,

      'id1' => $addressData['natId'],

      'docState' => 4000,
      'docStateMain' => 2,
    ];

    if ($addressData['type'] === 0)
      $newAddress['flagMainAddress'] = 1;
    elseif ($addressData['type'] === 1)
      $newAddress['flagOffice'] = 1;

    if (!Utils::dateIsBlank($newAddress['validTo']))
    {
      $today = Utils::today('Y-m-d');
      if ($newAddress['validTo'] < $today)
      {
        $newAddress['docState'] = 9000;
        $newAddress['docStateMain'] = 5;
      }
    }

    $this->tablePersonsContact->checkBeforeSave($newAddress);
    $this->db()->query('INSERT INTO e10_persons_personsContacts', $newAddress);
  }

  protected function checkBA()
  {
    if (!isset($this->registerData['bankAccounts']))
      return;

    foreach ($this->registerData['bankAccounts'] as $ba)
    {
      $existedBA = Utils::searchArray($this->personBA, 'bankAccount', $ba['bankAccount']);
      if ($existedBA)
      {
      }
      else
      {
        $this->missingBA[] = $ba;
      }
    }
  }

  public function addBankAccounts($baIds)
  {
    foreach ($baIds as $baId)
    {
      $baData = Utils::searchArray($this->registerData['bankAccounts'], 'bankAccount', $baId);
      if (!$baData)
        continue;

      $newBA = [
        'person' => $this->personNdx,
        'bankAccount' => $baData['bankAccount'],

        'docState' => 4000,
        'docStateMain' => 2,
      ];

      if (!Utils::dateIsBlank($baData['validFrom']))
        $newBA['validFrom'] = $baData['validFrom'];
      if (!Utils::dateIsBlank($baData['validTo']))
        $newBA['validFrom'] = $baData['validTo'];

      $this->db()->query('INSERT INTO e10_persons_personsBA', $newBA);
    }
  }

  public function makeDiff()
  {
    $this->makeDiff_Core();
    $this->makeDiff_MainAddress();
    $this->makeDiff_ExistedOffices();
  }

  public function makeDiff_Core()
  {
    $update = [];

    $nameFound = in_array($this->registerData['person']['fullName'], $this->personNames);
    if (!$nameFound)
    {
      $this->addDiffMsg('Změna názvu z `'.$this->personRecData['fullName'].'` na `'.$this->registerData['person']['fullName'].'`');
      $update['fullName'] = $this->registerData['person']['fullName'];
    }

    if (isset($this->registerData['person']['vatID']) && $this->registerData['person']['vatID'] !== '' && !isset($this->personVATIDs[$this->registerData['person']['vatID']]))
    {
      $this->addDiffMsg('Nové DIČ `'.$this->registerData['person']['vatID'].'`');
      $this->diff['properties']['add'][] = [
        'recid' => $this->personRecData['ndx'],
        'tableid' => 'e10.persons.persons',
        'group' => 'ids', 'property' => 'taxid',
        'valueString' => $this->registerData['person']['vatID'],
        'created' => new \DateTime(),
      ];
    }

    if (count($update))
      $this->diff['updates']['e10.persons.persons'][] = ['update' => $update, 'ndx' => $this->personRecData['ndx']];
  }

  public function makeDiff_MainAddress()
  {
    $update = [];
    $mar = $this->registerData['address'][0];
    $cma = NULL;

    foreach ($this->personMainAddress as $ma)
    {
      if ($ma['docState'] !== 4000)
        continue;
      $cma = $ma;
    }

    if ($cma['adrStreet'] !== $mar['street'])
    {
      $this->addDiffMsg('Změna ulice sídla z `'.$cma['adrStreet'].'` na `'.$mar['street'].'`');
      $update['adrStreet'] = $mar['street'];
    }
    if ($cma['adrCity'] !== $mar['city'])
    {
      $this->addDiffMsg('Změna města sídla z `'.$cma['adrCity'].'` na `'.$mar['city'].'`');
      $update['adrCity'] = $mar['city'];
    }
    if ($cma['adrZipCode'] !== $mar['zipcode'])
    {
      $this->addDiffMsg('Změna PSČ sídla z `'.$cma['adrZipCode'].'` na `'.$mar['zipcode'].'`');
      $update['adrZipCode'] = $mar['zipcode'];
    }

    if (count($update))
      $this->diff['updates']['e10.persons.personsContacts'][] = ['update' => $update, 'ndx' => $cma['ndx']];
  }

  public function makeDiff_ExistedOffices()
  {
    foreach ($this->personOffices as $cpo)
    {
      if (!$cpo['flagOffice'])
        continue;
      if ($cpo['id1'] == '')
        continue;

      $registerOffice = Utils::searchArray($this->registerData['address'], 'natId', $cpo['id1']);
      if (!$registerOffice)
      {
        if ($cpo['docState'] !== 9000)
        {
          $this->addDiffMsg('Provozovna `'.$cpo['id1'].'` neexistuje v registru a bude přesunuta do archívu');
          $update['docState'] = 9000;
          $update['docStateMain'] = 5;

          $this->diff['updates']['e10.persons.personsContacts'][] = ['update' => $update, 'ndx' => $cpo['ndx']];
        }
        continue;
      }

      $update = [];
      if ($cpo['adrStreet'] !== $registerOffice['street'])
      {
        $this->addDiffMsg('Změna ulice provozovny z `'.$cpo['adrStreet'].'` na `'.$registerOffice['street'].'`');
        $update['adrStreet'] = $registerOffice['street'];
      }
      if ($cpo['adrSpecification'] !== $registerOffice['specification'])
      {
        $this->addDiffMsg('Změna názvu provozovny z `'.$cpo['adrSpecification'].'` na `'.$registerOffice['specification'].'`');
        $update['adrSpecification'] = $registerOffice['specification'];
      }
      if ($cpo['adrCity'] !== $registerOffice['city'])
      {
        $this->addDiffMsg('Změna města provozovny z `'.$cpo['adrCity'].'` na `'.$registerOffice['city'].'`');
        $update['adrCity'] = $registerOffice['city'];
      }
      if ($cpo['adrZipCode'] !== $registerOffice['zipcode'])
      {
        $this->addDiffMsg('Změna PSČ provozovny z `'.$cpo['adrZipCode'].'` na `'.$registerOffice['zipcode'].'`');
        $update['adrZipCode'] = $registerOffice['zipcode'];
      }

      $validToPerson = ($cpo['validTo'] ? Utils::createDateTime($cpo['validTo'])->format('Y-m-d') : '');
      $validToRegister = ($registerOffice['validTo'] ? $registerOffice['validTo'] : '');
      if ($validToPerson !== $validToRegister)
      {
        $this->addDiffMsg('Změna platnosti DO provozovny z `'.$validToPerson.'` na `'.$validToRegister.'`');
        $update['validTo'] = $validToRegister;
        $update['docState'] = 9000;
        $update['docStateMain'] = 5;
      }

      if (count($update))
        $this->diff['updates']['e10.persons.personsContacts'][] = ['update' => $update, 'ndx' => $cpo['ndx']];
    }
  }

  protected function addDiffMsg($msg)
  {
    $this->diff['msgs'][] = $msg;
  }

  public function applyDiff()
  {
    foreach ($this->diff['updates'] as $tableId => $updates)
    {
      /** @var \Shipard\Table\DbTable */
      $table = $this->app()->table($tableId);
      foreach ($updates as $oneUpdate)
      {
        $rec = $table->loadItem($oneUpdate['ndx']);
        foreach ($oneUpdate['update'] as $key => $value)
          $rec[$key] = $value;

        $table->dbUpdateRec($rec);
        $table->docsLog($rec['ndx']);
      }
    }

    if (isset($this->diff['properties']['add']))
    {
      foreach ($this->diff['properties']['add'] as $newProperty)
      {
        $this->db()->query('INSERT INTO [e10_base_properties] ', $newProperty);
      }
    }

    $this->setPersonValidity(1);
  }

  public function setPersonValidity($setValidValue = -1)
  {
    if ($setValidValue === -1)
    {
      $valid = 1; // "enumValues": {"0": "Nezkontrolováno", "1": "Ano", "2": "Ne"}},
      if (count($this->diff['msgs']))
        $valid = 2;
    }
    else
    {
      $valid = $setValidValue;
    }

		$item = [
      'valid' => $valid,
      'updated' => new \DateTime(),
      'revalidate' => 0
    ];
    if (isset($this->diff['msgs']))
      $item['msg'] = json::lint($this->diff['msgs']);

		$exist = $this->db()->query('SELECT * FROM [e10_persons_personsValidity] WHERE [person] = %i', $this->personNdx)->fetch();
		if ($exist)
		{
			$this->db()->query ('UPDATE [e10_persons_personsValidity] SET ', $item, ' WHERE ndx = %i', $exist['ndx']);
		}
		else
		{
			$item['person'] = $this->personNdx;
			$item['created'] = new \DateTime();

			$this->db()->query ('INSERT INTO [e10_persons_personsValidity] ', $item);
		}
  }

  public function repair()
  {
    $this->applyDiff();

    if (isset($this->registerData['bankAccounts']))
    {
      $bas = [];
      foreach ($this->registerData['bankAccounts'] as $bai)
        $bas[] = $bai['bankAccount'];
      $this->addBankAccounts($bas);
    }
  }
}
