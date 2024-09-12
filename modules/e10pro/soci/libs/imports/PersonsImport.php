<?php

namespace e10pro\soci\libs\imports;
use \Shipard\Base\Utility;
require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';


/**
 * class PersonsImport
 */
class PersonsImport extends Utility
{
  /** @var \e10\persons\TablePersons */
  var $tablePersons;
  /** @var \e10pro\soci\TableEntries */
  var $tableEntries;

  var $sociPeriod = 'AY1';
  var $sociPeriodId = '-24/25';

  var $personLabelNdx = 2;

  var $fileName;

  var $originalColumnIds = [];

  var $cnEmail = -1;
  var $cnPhone = -1;
  var $cnFirstName = -1;
  var $cnLastName = -1;
  var $cnFullName = -1;
  var $cnWoId = -1;
  var $cnNote = -1;
  var $cnBirthday = -1;
  var $cnPaymentSymbol1 = -1;
  var $cnDateTimeCreated = -1;
  var $cnPrice = -1;

  var $woPersonsCounter = 1000;

  /*
    [0] => PRIJMENI
    [1] => JMENO
    [2] => TRIDA
  */

  protected function detectColumnsIds($cols)
  {
    foreach ($cols as $colNum => $colName)
    {
      $cn = trim($colName);
      $this->originalColumnIds[$colNum] = $cn;
      if ($cn === 'JMENO')
        $this->cnFirstName = $colNum;
      elseif ($cn === 'Příjmení + Jméno')
        $this->cnFullName = $colNum;
      elseif ($cn === 'PRIJMENI')
        $this->cnLastName = $colNum;
      elseif ($cn === 'TRIDA')
        $this->cnWoId = $colNum;
      elseif ($cn === 'Třída')
        $this->cnWoId = $colNum;
    }

    //print_r($cols);
  }

  protected function importOneRow($cols)
  {
    //echo " * CSV: ".json_encode($cols)."\n";
    $data = [];
    if ($this->cnFullName >= 0 && isset($cols[$this->cnFullName]))
    {
      $nameParts = explode(' ', $cols[$this->cnFullName]);

      $data['lastName'] = trim (array_shift($nameParts));
      $data['firstName'] = implode(' ', $nameParts);
      $data['firstName'] = str_replace('  ', ' ', $data['firstName']);
    }
    if ($this->cnFirstName >= 0 && isset($cols[$this->cnFirstName]))
      $data['firstName'] = trim ($cols[$this->cnFirstName]);
    if ($this->cnLastName >= 0 && isset($cols[$this->cnLastName]))
      $data['lastName'] = trim ($cols[$this->cnLastName]);
    if ($this->cnWoId >= 0 && isset($cols[$this->cnWoId]))
      $data['woId'] = trim ($cols[$this->cnWoId]);

    $newPersonNdx = 0;
    $newPersonNdx = $this->searchPersonByName($data);

    if (!$newPersonNdx)
      $newPersonNdx = $this->createPerson ($data);

    $personWoId = $data['woId'].$this->sociPeriodId;
    $existedWO = $this->db()->query('SELECT * FROM e10mnf_core_workOrders WHERE docNumber = %s', $personWoId)->fetch();
    if ($existedWO)
    {
      $wondx = $existedWO['ndx'];
      $existInWO = $this->db()->query('SELECT woPersons.*, wo.title AS woTitle FROM [e10mnf_core_workOrdersPersons] AS woPersons',
																			' LEFT JOIN [e10mnf_core_workOrders] AS wo ON woPersons.workOrder = wo.ndx',
																			' WHERE 1',
                                      ' AND wo.[usersPeriod] = %s', $this->sociPeriod,
                                      ' AND woPersons.person = %i', $newPersonNdx)->fetch();

      if (!$existInWO)
      {
        $woPerson = ['workOrder' => $wondx, 'rowOrder' => $this->woPersonsCounter, 'person' => $newPersonNdx];
        $this->db()->query('INSERT INTO [e10mnf_core_workOrdersPersons] ', $woPerson);
      }
    }

    $this->woPersonsCounter += 100;

    // -- person label
    if ($this->personLabelNdx)
    {
      $labelExist = $this->db()->query('SELECT * FROM e10_base_clsf WHERE tableid = %s', 'e10.persons.persons',
                                        ' AND clsfItem = %i', $this->personLabelNdx, ' AND [group] = %s', 'personsTags',
                                        ' AND recid = %i', $newPersonNdx)->fetch();
      if (!$labelExist)
      {
        $label = [
          'clsfItem' => $this->personLabelNdx, 'group' => 'personsTags',
          'tableid' => 'e10.persons.persons', 'recid' => $newPersonNdx,
        ];
        $this->db()->query('INSERT INTO [e10_base_clsf] ', $label);
      }
    }
  }

	public function createPerson ($entryRecData)
	{
    //echo "* ERD: ".json_encode($entryRecData)."\n";

		$newPerson ['person'] = [];
		$newPerson ['person']['company'] = 0;
		$newPerson ['person']['firstName'] = $entryRecData['firstName'];
		$newPerson ['person']['lastName'] = $entryRecData['lastName'];
		$newPerson ['person']['fullName'] = $entryRecData['lastName'].' '.$entryRecData['firstName'];
		$newPerson ['person']['docState'] = 4000;
		$newPerson ['person']['docStateMain'] = 2;

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

    while ($cols = fgetcsv($file, null, ','))
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

  function searchPersonByName($data)
	{
    $q = [];
    array_push($q, 'SELECT * FROM [e10_persons_persons]');
    array_push($q, ' WHERE [firstName] = %s', $data['firstName']);
    array_push($q, ' AND [lastName] = %s', $data['lastName']);
    array_push($q, ' AND [docState] = %i', 4000);

    $exist = $this->db()->query($q)->fetch();
    if ($exist)
      return $exist['ndx'];

    return 0;
  }

  public function run()
  {
    $this->tablePersons = $this->app()->table('e10.persons.persons');

    $this->doImport();
  }
}
