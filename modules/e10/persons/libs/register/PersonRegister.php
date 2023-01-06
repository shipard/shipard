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

  var $personOid = '';
  var $personNdx = 0;
  var $personRecData = NULL;
  var $personOffices = [];
  var $missingOffices = [];


  public function setPersonNdx($personNdx)
  {
    $this->personNdx = $personNdx;
    $this->personRecData = $this->app()->loadItem($this->personNdx, 'e10.persons.persons');
    $this->loadPersonOid();
    $this->loadContacts();

    $this->loadByOid($this->personOid);

    $this->checkOffices();
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

  protected function loadPersonOid ()
	{
		$q[] = 'SELECT * FROM [e10_base_properties] AS props';
		array_push ($q, ' WHERE [recid] = %i', $this->personRecData['ndx']);
		array_push ($q, ' AND [tableid] = %s', 'e10.persons.persons', 'AND [group] = %s', 'ids', ' AND property = %s', 'oid');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['valueString'] === '')
				continue;
			$this->personOid = trim($r['valueString']);
      break;
		}
	}

  protected function loadContacts()
  {
    $q [] = 'SELECT [contacts].* ';
		array_push ($q, ' FROM [e10_persons_personsContacts] AS [contacts]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [contacts].[person] = %i', $this->personNdx);
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

      $newAddress = [
        'person' => $this->personNdx,
        'adrSpecification' => $officeData['specification'],
        'adrStreet' => $officeData['street'],
        'adrCity' => $officeData['city'],
        'adrZipCode' => $officeData['zipcode'],
        'adrCountry' => World::countryNdx($this->app(), $officeData['country']),

        'flagAddress' => 1,
        'flagOffice' => 1,

        'id1' => $officeData['natId'],

        'docState' => 4000,
        'docStateMain' => 2,
      ];

      $this->db()->query('INSERT INTO e10_persons_personsContacts', $newAddress);
    }
  }
}
