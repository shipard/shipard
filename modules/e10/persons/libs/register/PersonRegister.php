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

  var $useStandardizedAddress = 0;


  var $diff = ['msgs' => [], 'updates' => []];

  protected function init()
  {
    $this->useOfficesAutoLoading = intval($this->app()->cfgItem ('options.persons.useOfficesAutoLoading', 0));
    $this->tablePersonsContact = $this->app()->table('e10.persons.personsContacts');
    $this->useStandardizedAddress = intval($this->app()->cfgItem ('options.persons.useStandardizedAddress', 0));
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
    $newAddress = $this->createAddressFromReg($addressData);
    $newNdx = $this->tablePersonsContact->dbInsertRec($newAddress);
    $this->tablePersonsContact->docsLog($newNdx);
    //$this->db()->query('INSERT INTO e10_persons_personsContacts', $newAddress);
  }

  protected function createAddressFromReg($regAddr)
  {
    $newAddress = [
      'person' => $this->personNdx,
      'validFrom' => $regAddr['validFrom'] ?? NULL,
      'validTo' => $regAddr['validTo'] ?? NULL,
      'flagAddress' => 1,
      'flagMainAddress' => 0,
      'flagOffice' => 0,
      'onTop' => 99,

      'id1' => $regAddr['natId'],

      'docState' => 4000,
      'docStateMain' => 2,
    ];

    $countryId = $regAddr['country'] ?? 'cz';
    if ($countryId === '')
      $countryId = 'cz';
    $newAddress['adrCountry'] = World::countryNdx($this->app(), $countryId);

    $newAddress['flagStandardized'] = intval($regAddr['standardized']);
    $newAddress['natAddressGeoId'] = $regAddr['natAddressGeoId'];
    $newAddress['saStreetName'] = $regAddr['saStreetName'];
    $newAddress['saHouseNr'] = $regAddr['saHouseNr'];
    $newAddress['saCityPartName'] = $regAddr['saCityPartName'];
    $newAddress['saCityPart2Name'] = $regAddr['saCityPart2Name'];
    $newAddress['saCityName'] = $regAddr['saCityName'];
    $newAddress['saZipCodeId'] = $regAddr['saZipCodeId'];

    $newAddress['saStreetId'] = $regAddr['saStreetId'];
    $newAddress['saCityPartId'] = $regAddr['saCityPartId'];
    $newAddress['saCityPart2Id'] = $regAddr['saCityPart2Id'];
    $newAddress['saCityId'] = $regAddr['saCityId'];

    $newAddress['saAdmUnit11Id'] = $regAddr['saLaUnit11Id'];
    $newAddress['saAdmUnit11Ndx'] = $this->admUnitNdx($regAddr['saLaUnit11Id'], 11);
    $newAddress['saAdmUnit10Id'] = $regAddr['saLaUnit10Id'];
    $newAddress['saAdmUnit10Ndx'] = $this->admUnitNdx($regAddr['saLaUnit10Id'], 10);

    $newAddress['adrSpecification'] = $regAddr['specification'] ?? '';

		// -- 'old' columns
    if ($regAddr['source'] == 0)
    { // OLD adress, use OLD columns
      $newAddress['adrStreet'] = $regAddr['street'] ?? '';
      $newAddress['adrCity'] = $regAddr['city'] ?? '';
      $newAddress['adrZipCode'] = $regAddr['zipcode'] ?? '';
    }
    else
    { // NEW adress, use NEW columns
      $newAddress['adrStreet'] = $regAddr['saStreetName'] ?? '';
      if ($newAddress['adrStreet'] === '' && $newAddress['saCityPartName'] !== '')
        $newAddress['adrStreet'] = $regAddr['saCityPartName'];

      if ($newAddress['saHouseNr'] !== '')
      {
        if ($newAddress['adrStreet'] === '')
        {
          $newAddress['adrStreet'] = $regAddr['saHouseNr1Type'] == 1 ? 'č.ev. ' : 'č.p. ';
        }
        else
          $newAddress['adrStreet'] .= ' ';
        if ($regAddr['saHouseNr1Type'] == 1)
          $newAddress['adrStreet'] .= 'č.ev. ';
        $newAddress['adrStreet'] .= $regAddr['saHouseNr'];
      }
      $newAddress['adrCity'] = $regAddr['saCityName'] ?? '';
      $newAddress['adrZipCode'] = $regAddr['saZipCodeId'] ?? '';
    }

    if ($regAddr['type'] == 0)
      $newAddress['flagMainAddress'] = 1;
    elseif ($regAddr['type'] === 1)
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

    if (isset($regAddr['wgs84lat']) && isset($regAddr['wgs84lng']))
    {
      $newAddress['adrLocLat'] = $regAddr['wgs84lat'] ?? 0.0;
      $newAddress['adrLocLon'] = $regAddr['wgs84lng'] ?? 0.0;
      $newAddress['adrLocState'] = 1;
      $newAddress['adrLocHash'] = $this->tablePersonsContact->geoCodeLocHash ($newAddress);
    }
    else
    {
      $newAddress['adrLocLat'] = 0.0;
      $newAddress['adrLocLon'] = 0.0;
      $newAddress['adrLocState'] = 0;
      $newAddress['adrLocHash'] = '';
    }
    $newAddress['adrLocHash'] = $this->tablePersonsContact->geoCodeLocHash ($newAddress);
    $newAddress['adrLocTime'] = NULL;

    Json::polish($newAddress);

    return $newAddress;
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
        $newBA['validFrom'] = Utils::createDateTime($baData['validFrom']);
      if (!Utils::dateIsBlank($baData['validTo']))
        $newBA['validTo'] = Utils::createDateTime($baData['validTo']);

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

    if (isset($this->registerData['person']['validTo']) && !Utils::dateIsBlank($this->registerData['person']['validTo']))
    {
      if (!isset($this->personRecData['personCanceled']) || !$this->personRecData['personCanceled'])
      {
        $this->addDiffMsg('Osoba je od '.Utils::datef($this->registerData['person']['validTo']).' zrušena');
        $update['personCanceled'] = 1;
        $update['personCancelDate'] = $this->registerData['person']['validTo'];
      }
    }

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

    if ($this->useStandardizedAddress)
    {
      if ($cma['flagStandardized'] == 0 && $mar['standardized'] > 0)
      {
        $newAddrFromReg = $this->createAddressFromReg($mar);
        $this->addDiffMsg('Přepnutí sídla na standardizovanou adresu: '.$this->tablePersonsContact->addressTextOneLine($newAddrFromReg));
        $this->makeDiff_CoreAddress($cma, $newAddrFromReg, $update, TRUE);
        $this->diff['updates']['e10.persons.personsContacts'][] = ['update' => $update, 'ndx' => $cma['ndx']];
        return;
      }
    }

    $newAddrFromReg = $this->createAddressFromReg($mar);
    $this->makeDiff_CoreAddress($cma, $newAddrFromReg, $update);

    if (count($update))
      $this->diff['updates']['e10.persons.personsContacts'][] = ['update' => $update, 'ndx' => $cma['ndx']];
  }

  protected function admUnitNdx($admUnitId, $level)
  {
    $unit = $this->app()->db()->query('SELECT ndx FROM [e10_world_admUnits] WHERE country = %i', 60,
                                        ' AND admUnitId = %s ', $admUnitId,
                                        ' AND level = %i', $level)->fetch();
    if ($unit)
      return $unit['ndx'];
    return 0;
  }

  protected function makeDiff_CoreAddress($currentAddr, $newRegAddr, &$update, $disableMsgs = FALSE)
  {
    Json::polish($currentAddr);
    foreach ($currentAddr as $k => $v)
    {
      if (!isset($newRegAddr[$k]))
        continue;
      if ($v != $newRegAddr[$k])
      {
        if (!$disableMsgs)
          $this->addDiffMsg('Změna '.$k.' z `'.$v.'` na `'.$newRegAddr[$k].'`');
        $update[$k] = $newRegAddr[$k];
      }
    }
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

      if ($this->useStandardizedAddress)
      {
        if ($cpo['flagStandardized'] == 0 && $registerOffice['standardized'] > 0)
        {
          $newAddrFromReg = $this->createAddressFromReg($registerOffice);
          $this->addDiffMsg('Přepnutí pobočky `'.$cpo['id1'].'` na standardizovanou adresu: '.$this->tablePersonsContact->addressTextOneLine($newAddrFromReg));
          $this->makeDiff_CoreAddress($cpo, $newAddrFromReg, $update, TRUE);
          $this->diff['updates']['e10.persons.personsContacts'][] = ['update' => $update, 'ndx' => $cpo['ndx']];
          continue;
        }
      }
      $newAddrFromReg = $this->createAddressFromReg($registerOffice);
      $this->makeDiff_CoreAddress($cpo, $newAddrFromReg, $update);

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
