<?php

namespace e10\persons\libs\register;

use \Shipard\Utils\Str, \Shipard\Base\Utility, \Shipard\Utils\Json, \Shipard\Utils\Utils;
use \Shipard\Utils\World;


/**
 * class Validator
 */
class Validator extends Utility
{
	CONST validNotCheck = 0, validOK = 1, validError = 2;

  var $personNdx = 0;
	var $personRecData = NULL;
  var $mainAddress = NULL;
  var $country = '';

  var $checkOid = [];
	var $checkVATID = [];

	var $onlineToolsDef;


  public function clear()
	{
		$this->country = '';
    $this->personNdx = 0;
    $this->personRecData = NULL;
		$this->mainAddress = NULL;
	}

  public function setPersonNdx($personNdx)
  {
    $this->personNdx = $personNdx;
  }

	public function checkPerson($repair = 0)
	{
		$this->loadPersonMainAddress();


    $reg = new \e10\persons\libs\register\PersonRegister($this->app());

		if ($this->country !== 'cz')
		{
			if ($this->app()->debug)
				echo "country `{$this->country}` not supported; ";

			$reg->personNdx = $this->personNdx;
			$reg->setPersonValidity(3);
			return;
		}

		$reg->setPersonNdx($this->personNdx);
		if (!$reg->generalFailure)
		{
	    $reg->makeDiff();
  	  $reg->setPersonValidity();

			if ($reg->generalFailure)
				return;

			if ($repair)
			{
				$reg->repair();
			}
		}
		else
			$reg->setPersonValidity(2);
	}

	protected function loadPersonMainAddress()
	{
    $q [] = 'SELECT [contacts].* ';
		array_push ($q, ' FROM [e10_persons_personsContacts] AS [contacts]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [contacts].[person] = %i', $this->personNdx);
    array_push ($q, ' AND [contacts].[docState] != %i', 9800);
    array_push ($q, ' AND [contacts].[flagAddress] = %i', 1);
    array_push ($q, ' AND [contacts].[flagMainAddress] = %i', 1);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
      $this->country = World::countryId($this->app(), $r['adrCountry']);
			$this->mainAddress = $r->toArray();
      break;
		}
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
			$this->addOid($r['valueString']);
		}
	}

	protected function loadPersonVat ()
	{
		$q[] = 'SELECT * FROM [e10_base_properties] AS props';
		array_push ($q, ' WHERE [recid] = %i', $this->personRecData['ndx']);
		array_push ($q, ' AND [tableid] = %s', 'e10.persons.persons', 'AND [group] = %s', 'ids', ' AND property = %s', 'taxid');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['valueString'] === '')
				continue;
			$this->addVATID($r['valueString']);
		}
	}

	public function addOID($oid)
	{
		$this->checkOid[$oid] = ['valid' => 0];
	}

	public function addVATID($oid)
	{
		$this->checkVATID[$oid] = ['valid' => 0];
	}

	public function onlineTools ($personRecData)
	{
		if ($personRecData !== NULL)
		{
			$this->clear();
			$this->personNdx = $personRecData['ndx'];
      $this->personRecData = $personRecData;

			$this->loadPersonMainAddress();
			$this->loadPersonOid();
			$this->loadPersonVat();
		}

		$tools = [];
		$this->onlineToolsDef = $this->loadCfgFile(__SHPD_MODULES_DIR__.'e10/persons/config/e10.persons.onlineTools.json');

		// -- OID
		foreach ($this->checkOid as $oidId => $oidInfo)
		{
			$this->onlineToolsCheck('oid', $oidId, $tools);
		}
		// -- VATID
    /*
		foreach ($this->checkVat as $vatId => $vatInfo)
		{
			$this->onlineToolsCheck('vatid', $vatId, $tools);
		}
    */

		if (!count($tools))
			return FALSE;

		return $tools;
	}

	protected function onlineToolsCheck ($type, $value, &$tools)
	{
		foreach ($this->onlineToolsDef as $t)
		{
			if ($t['type'] !== $type)
				continue;

			$country = ($type === 'vatid') ? strtolower(substr($value, 0, 2)) : $this->country;
			if ($t['country'] !== $country)
				continue;

			$url = str_replace('@ID', $value, $t['weburl']);
			$tool = ['text' => $t['name'], 'url' => $url];

			$tools[] = $tool;
		}
	}
}
