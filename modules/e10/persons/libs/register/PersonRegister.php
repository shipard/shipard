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

  /** @var \e10\persons\TablePersonsContacts */
  var $tablePersonsContact;

  var $personOid = '';
  var $personNdx = 0;
  var $personRecData = NULL;
  var $personOffices = [];
  var $missingOffices = [];
  var $personBA = [];
  var $missingBA = [];

  protected function init()
  {
    $this->tablePersonsContact = $this->app()->table('e10.persons.personsContacts');
  }

  public function setPersonNdx($personNdx)
  {
    $this->init();

    $this->personNdx = $personNdx;
    $this->personRecData = $this->app()->loadItem($this->personNdx, 'e10.persons.persons');
    $this->loadPersonOid();
    $this->loadContacts();
    $this->loadBA();

    $this->loadByOid($this->personOid);

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
		$newPerson ['person']['docState'] = 1000;
		$newPerson ['person']['docStateMain'] = 0;

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

    return $this->personOid;
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

  protected function checkOffices()
  {
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

    $this->tablePersonsContact->checkBeforeSave($newAddress);
    $this->db()->query('INSERT INTO e10_persons_personsContacts', $newAddress);
  }

  protected function checkBA()
  {
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
}
