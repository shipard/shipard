<?php

namespace e10pro\soci\libs;
use \Shipard\Base\Utility;
use Shipard\Utils\Utils;
use \Shipard\UI\Core\UIUtils;
require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';


class EntriesImport extends Utility
{
  /** @var \e10\persons\TablePersons */
  var $tablePersons;
  /** @var \e10pro\soci\TableEntries */
  var $tableEntries;

  var $fileName;

  var $entryToNdx = 0;
  var $entryKindNdx = 0;

  var $originalColumnIds = [];

  var $cnEmail = -1;
  var $cnPhone = -1;
  var $cnFirstName = -1;
  var $cnLastName = -1;
  var $cnNote = -1;
  var $cnBirthday = -1;
  var $cnPaymentSymbol1 = -1;
  var $cnDateTimeCreated = -1;
  var $cnPrice = -1;

  var $woPersonsCounter = 1000;

  /*
    [0] => Var. symbol
    [1] => Jméno
    [2] => Příjmení
    [3] => Datum narození
    [4] => Email
    [5] => Telefon
    [6] => Čas vytvoření
    [7] => Cena
    [8] => Poznámky
    [9] => Platba - suma
    [10] => Základní cena
    [11] => Poznámky organizace
    [12] => Místo konání
    [13] => Místnost
    [14] => Cvičitel
    [15] => Začátek hodiny
    [16] => Konec hodiny
    [17] => Den v týdnu
  */

  public function setEntryTo($entryToDocNumber)
  {
    $existedWO = $this->db()->query('SELECT * FROM e10mnf_core_workOrders WHERE docNumber = %s', $entryToDocNumber)->fetch();
    if (!$existedWO)
      return;
    $this->entryToNdx = intval($existedWO['ndx']);
  }

  protected function detectColumnsIds($cols)
  {
    foreach ($cols as $colNum => $colName)
    {
      $cn = trim($colName);
      $this->originalColumnIds[$colNum] = $cn;
      if ($cn === 'Jméno')
        $this->cnFirstName = $colNum;
      elseif ($cn === 'Příjmení')
        $this->cnLastName = $colNum;
      elseif ($cn === 'Datum narození')
        $this->cnBirthday = $colNum;
      elseif ($cn === 'Email')
        $this->cnEmail = $colNum;
      elseif ($cn === 'Telefon')
        $this->cnPhone = $colNum;
      elseif ($cn === 'Poznámky')
        $this->cnNote = $colNum;
      elseif ($cn === 'Var. symbol')
        $this->cnPaymentSymbol1 = $colNum;
      elseif ($cn === 'Čas vytvoření')
        $this->cnDateTimeCreated = $colNum;
      elseif ($cn === 'Cena')
        $this->cnPrice = $colNum;
    }

    //print_r($cols);
  }

  protected function importOneRow($cols)
  {
    $entry = [];
    if ($this->cnFirstName >= 0 && isset($cols[$this->cnFirstName]))
      $entry['firstName'] = trim ($cols[$this->cnFirstName]);
    if ($this->cnLastName >= 0 && isset($cols[$this->cnLastName]))
      $entry['lastName'] = trim ($cols[$this->cnLastName]);
    if ($this->cnEmail >= 0 && isset($cols[$this->cnEmail]))
      $entry['email'] = trim ($cols[$this->cnEmail]);
    if ($this->cnPhone >= 0 && isset($cols[$this->cnPhone]))
      $entry['phone'] = $this->colValuePhone ($cols[$this->cnPhone]);
    if ($this->cnPaymentSymbol1 >= 0 && isset($cols[$this->cnPaymentSymbol1]))
      $entry['docNumber'] = trim ($cols[$this->cnPaymentSymbol1]);
    if ($this->cnBirthday >= 0 && isset($cols[$this->cnBirthday]))
      $entry['birthday'] = $this->colValueDate ($cols[$this->cnBirthday]);
    if ($this->cnNote >= 0 && isset($cols[$this->cnNote]) && $cols[$this->cnNote] !== '')
      $entry['note'] = trim ($cols[$this->cnNote]);
    if ($this->cnDateTimeCreated >= 0 && isset($cols[$this->cnDateTimeCreated]))
      $entry['dateIssue'] = $this->colValueDate ($cols[$this->cnDateTimeCreated], 80);

    if ($this->cnPrice >= 0 && isset($cols[$this->cnPrice]))
    {
      $price = intval($cols[$this->cnPrice]);
      if ($price == 1500 || $price == 3000)
        $entry['saleType'] = 1;
      if ($price < 2000)
        $entry['paymentPeriod'] = 1;
    }

    $entry['source'] = 3;
    $entry['entryPeriod'] = 1;
    $entry['entryTo'] = $this->entryToNdx;
    $entry['entryKind'] = 1;
    $entry['docState'] = 4000;
		$entry['docStateMain'] = 2;

    $newPersonNdx = 0;
    if (($entry['email'] ?? '') !== '')
    {
      $newPersonNdx = $this->searchPerson('contacts', 'email', $entry['email']);
      if ($newPersonNdx)
        echo "   ##### e-mail `{$entry['email']}` exist!\n";
    }
    if (!$newPersonNdx)
      $newPersonNdx = $this->createPerson ($entry);

    $entry['dstPerson'] = $newPersonNdx;

    echo "* entry: ".json_encode($entry)."\n";

    $this->db()->query ('INSERT INTO [e10pro_soci_entries] ', $entry);
    $entryNdx = intval ($this->db()->getInsertId ());
    $this->tableEntries->docsLog($entryNdx);

    $woPerson = ['workOrder' => $this->entryToNdx, 'rowOrder' => $this->woPersonsCounter, 'person' => $newPersonNdx];
    $this->db()->query('INSERT INTO [e10mnf_core_workOrdersPersons] ', $woPerson);

    $this->woPersonsCounter += 100;
  }

  protected function colValuePhone($phone)
  {
    $p = trim($phone);
    $p = str_replace(' ', '', $p);
    $phoneNumber = $p;

    if (strlen($p) === 9)
      $phoneNumber = '+420 '.substr($p, 0, 3).' '.substr($p, 3, 3).' '.substr($p, 6, 3);
    elseif (strlen($p) === 12)
      $phoneNumber = '+'.substr($p, 0, 3).' '.substr($p, 3, 3).' '.substr($p, 6, 3).' '.substr($p, 9, 3);
    elseif (strlen($p) === 13 && $p[0] === '+')
      $phoneNumber = '+'.substr($p, 0, 4).' '.substr($p, 4, 3).' '.substr($p, 7, 3).' '.substr($p, 10, 3);

    return $phoneNumber;
  }

  protected function colValueDate($d, $centuryLimit = 20)
  {
    $parts = explode('/', $d);
    if (count($parts) === 3)
    {
      $year = intval($parts[2]);
      if ($year < $centuryLimit)
        $year += 2000;
      else
        $year += 1900;
      $month = intval($parts[0]);
      $day = intval($parts[1]);
      $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
      return $dateStr;
    }

    $dd = new \DateTime($d);
    if ($dd)
      return $dd->format('Y-m-d');
    return NULL;
  }

	public function createPerson ($entryRecData)
	{
		$newPerson ['person'] = [];
		$newPerson ['person']['company'] = 0;
		$newPerson ['person']['firstName'] = $entryRecData['firstName'];
		$newPerson ['person']['lastName'] = $entryRecData['lastName'];
		$newPerson ['person']['fullName'] = $entryRecData['lastName'].' '.$entryRecData['firstName'];
		$newPerson ['person']['docState'] = 4000;
		$newPerson ['person']['docStateMain'] = 2;

    $newPerson ['ids'][] = ['type' => 'birthdate', 'value' => $entryRecData ['birthday'] ?? NULL];
    if ($entryRecData['email'] !== '')
      $newPerson ['contacts'][] = ['type' => 'email', 'value' => $entryRecData['email']];
    if ($entryRecData['phone'] !== '')
      $newPerson ['contacts'][] = ['type' => 'phone', 'value' => $entryRecData['phone']];

    echo "* person: ".json_encode($newPerson)."\n";

    $newPersonNdx = 0;
		$newPersonNdx = \E10\Persons\createNewPerson ($this->app, $newPerson);
		$this->tablePersons->docsLog ($newPersonNdx);

		return $newPersonNdx;
	}

  protected function doImport()
  {
		$cnt = 0;
    $file = fopen($this->fileName, "r");

    /*
    while ($row = fgetcsv($fp)) {
    $csvArray[] = $row;
    }
    */

    while ($cols = fgetcsv($file, null, ';'))
    {
      if ($cnt === 0)
      {
        $this->detectColumnsIds($cols);
        $cnt = 1;
        continue;
      }


      $this->importOneRow($cols);
      //print_r($cols);
    }
  }

  function searchPerson($group, $id, $value)
	{
		$q[] = 'SELECT props.recid';

		array_push ($q,	' FROM [e10_base_properties] AS props');
		array_push ($q,	' LEFT JOIN [e10_persons_persons] AS persons ON props.recid = persons.ndx');
		array_push ($q,	' WHERE 1');
		array_push ($q,	' AND [tableid] = %s', 'e10.persons.persons', ' AND [valueString] = %s', $value);
		array_push ($q,	' AND [group] = %s', $group, ' AND property = %s', $id);
		array_push ($q, ' AND [persons].docState = %i', 4000);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			return $r['recid'];
		}

		return 0;
	}

  public function run()
  {
    if (!$this->entryToNdx)
    {
      echo "ERROR: invalid workorder ndx\n";
      return;
    }

    $this->tablePersons = $this->app()->table('e10.persons.persons');
    $this->tableEntries = $this->app()->table('e10pro.soci.entries');


    $this->doImport();
  }
}
