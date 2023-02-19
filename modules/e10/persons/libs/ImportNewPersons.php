<?php

namespace e10\persons\libs;

use e10\str, e10\Utility, e10\json, e10\utils;


/**
 * class ImportNewPersons
 */
class ImportNewPersons extends Utility
{
  public function importAddress()
  {
    /** @var \e10\persons\TablePersonsContacts */
    $tablePersonsContact = $this->app()->table('e10.persons.personsContacts');
    $this->db()->query('DELETE FROM e10_persons_personsContacts');

    $q = [];
    array_push($q, 'SELECT * FROM [e10_persons_address]');
    array_push($q, ' WHERE tableid = %s', 'e10.persons.persons');
    array_push($q, ' ORDER BY [recid], [ndx]');
/*
    {"id": "tableid", "sql": "tableid", "name": "Tabulka", "type": "string", "len": 48, "options": ["ascii"]},
    {"id": "recid", "sql": "recid", "name": "Řádek", "type": "int"},
*/
    $cntr = 1;
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $newAddress = [
        'ndx' => $r['ndx'],
        'person' => $r['recid'],
        'adrSpecification' => $r['specification'],
        'adrStreet' => $r['street'],
        'adrCity' => $r['city'],
        'adrZipCode' => $r['zipcode'],
        'adrCountry' => $r['worldCountry'],

        'flagAddress' => 1,

        'onTop' => 99,

        'docState' => $r['docState'],
        'docStateMain' => $r['docStateMain'],
      ];

      if ($newAddress['docState'] < 1000)
      {
        $newAddress['docState'] = 4000;
        $newAddress['docStateMain'] = 2;
      }

      $oldAddressType = intval($r['type']);
      /*
        "0": {"name": "", "icon":  "address"},
        "1": {"name": "Sídlo", "icon": "addressResidence"},
        "2": {"name": "Pobočka", "icon": "addressBranchOffice"},
        "3": {"name": "Korespondenční adresa", "icon": "addressMailingAddress"},
        "4": {"name": "Doručovací adresa", "icon": "addressShippingAddress"},
        "99": {"name": "Provozovna", "icon": "addressWorkshop"}
      */
      if ($oldAddressType === 1)
        $newAddress['flagMainAddress'] = 1;
      elseif ($oldAddressType === 0)
        $newAddress['flagMainAddress'] = 1;
      elseif ($oldAddressType === 2)
        $newAddress['flagOffice'] = 1;
      elseif ($oldAddressType === 3 || $oldAddressType === 4)
        $newAddress['flagPostAddress'] = 1;
      elseif ($oldAddressType === 99)
      {
        $newAddress['flagOffice'] = 1;
        $newAddress['id1'] = $r['specification'];
        $newAddress['adrSpecification'] = '';
      }

      $tablePersonsContact->checkBeforeSave($newAddress);
      echo sprintf('%5d', $cntr).': '.json_encode($newAddress)."\n";
      $this->db()->query('INSERT INTO e10_persons_personsContacts', $newAddress);

      $cntr++;
      /*
    {"id": "", "name": "Upřesnění", "type": "string", "len": 160},
    {"id": "", "name": "Ulice", "type": "string", "len": 250},
    {"id": "", "name": "Město", "type": "string", "len": 90},
    {"id": "", "name": "PSČ", "type": "string", "len": 20},
    {"id": "", "name": "Země", "type": "int", "reference": "e10.world.countries"},

	  {"id": "lat", "name": "Zeměpisná šířka", "type": "number", "dec": 7},
	  {"id": "lon", "name": "Zeměpisná délka", "type": "number", "dec": 7},
	  {"id": "locState", "name": "Stav zaměření na mapě", "type": "enumInt",
		  "enumValues": {"0": "Nezaměřeno", "1": "Zaměřeno", "2": "Nelze zaměřit"}},
	  {"id": "locTime", "name": "Okamžik zaměření", "type": "timestamp"},
	  {"id": "locHash", "name": "Hash adresy", "type": "string", "len": 32, "options": ["ascii"]},

*/
    }
  }

  public function importBankAccounts()
  {
    $this->db()->query('DELETE FROM e10_persons_personsBA');


    $q = [];
    array_push($q, 'SELECT * FROM [e10_base_properties]');
    array_push($q, ' WHERE tableid = %s', 'e10.persons.persons');
    array_push($q, ' AND [property] = %s', 'bankaccount');
    //array_push($q, ' AND [recid] = %s', 'bankaccount');
    array_push($q, ' ORDER BY [recid], [ndx]');

    $cntr = 1;
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $newBA = [
        'person' => $r['recid'],
        'bankAccount' => $r['valueString'],
        'docState' => 4000, 'docStateMain' => 2,
      ];


      echo sprintf('%5d', $cntr).': '.json_encode($newBA)."\n";
      $this->db()->query('INSERT INTO e10_persons_personsBA', $newBA);

      $cntr++;
    }
  }

  public function cleanOldBankAccounts()
  {
    $q = [];
    array_push($q, 'DELETE FROM [e10_base_properties]');
    array_push($q, ' WHERE tableid = %s', 'e10.persons.persons');
    array_push($q, ' AND [property] = %s', 'bankaccount');
    $this->db()->query($q);
  }

  public function cleanOldValidity()
  {
    $q = [];
    array_push($q, 'DELETE FROM [e10_persons_personsValidity]');
    $this->db()->query($q);
  }

	public function run ()
	{
    $this->importAddress();
    $this->importBankAccounts();
    $this->cleanOldBankAccounts();
    $this->cleanOldValidity();
	}

  public function postUpdateConfig(&$newConfig)
  {
    // $testNewPersons = intval($this->app()->cfgItem ('options.persons.testNewPersons', 0));
    $doIt = intval($newConfig ['options']['persons']['testNewPersons'] ?? 0);
    if (!$doIt)
      return;

    $newConfig ['dataModel']['tables']['e10doc.core.heads']['cols']['otherAddress1']['reference'] = 'e10.persons.personsContacts';
    $newConfig ['dataModel']['tables']['e10doc.core.heads']['cols']['otherAddress1']['comboViewer'] = 'combo';

    unset($newConfig ['e10']['base']['propertiesGroups']['payments']);
    unset($newConfig ['e10']['base']['properties']['bankaccount']);
  }
}
