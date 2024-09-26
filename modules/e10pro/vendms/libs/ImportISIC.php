<?php

namespace e10pro\vendms\libs;
use \Shipard\Base\Utility;
require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';


/**
 * class ImportISIC
 */
class ImportISIC extends Utility
{
  /** @var \e10\persons\TablePersons */
  var $tablePersons;
  /** @var \e10pro\soci\TableEntries */
  var $tableEntries;

  var $sociPeriod = 'AY1';

  var $personLabelIsicNdx = 0;
  var $personLabelIticNdx = 0;

  var $fileName;

  var $originalColumnIds = [];

  var $cnEmail = -1;
  var $cnPhone = -1;
  var $cnFirstName = -1;
  var $cnLastName = -1;
  var $cnBeforeName = -1;
  var $cnAfterName = -1;
  var $cnFullName = -1;
  var $cnPaymentSymbol1 = -1;

  var $cnChipIdMifare = -1;
  var $cnChipIdEMMarine = -1;
  var $cnCardId = -1;
  var $cnCardType = -1;

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
      elseif ($cn === 'Titul před jménem')
        $this->cnBeforeName = $colNum;
      elseif ($cn === 'Titul za jménem')
        $this->cnAfterName = $colNum;
      elseif ($cn === 'E-mail')
        $this->cnEmail = $colNum;
      elseif ($cn === 'Telefonní číslo')
        $this->cnPhone = $colNum;
      elseif ($cn === 'Číslo čipu Mifare')
        $this->cnChipIdMifare = $colNum;
      elseif ($cn === 'Číslo čipu EM-Marine')
        $this->cnChipIdEMMarine = $colNum;
      elseif ($cn === 'Číslo průkazu')
        $this->cnCardId = $colNum;
      elseif ($cn === 'Typ průkazu')
        $this->cnCardType = $colNum;
    }
  }

  protected function importOneRow($cols)
  {
    //echo " * CSV: ".json_encode($cols)."\n";
    $data = [];
    if ($this->cnFirstName >= 0 && isset($cols[$this->cnFirstName]))
      $data['firstName'] = trim ($cols[$this->cnFirstName]);
    if ($this->cnLastName >= 0 && isset($cols[$this->cnLastName]))
      $data['lastName'] = trim ($cols[$this->cnLastName]);
    if ($this->cnBeforeName >= 0 && isset($cols[$this->cnBeforeName]))
      $data['beforeName'] = trim ($cols[$this->cnBeforeName]);
    if ($this->cnAfterName >= 0 && isset($cols[$this->cnAfterName]))
      $data['afterName'] = trim ($cols[$this->cnAfterName]);
    if ($this->cnEmail >= 0 && isset($cols[$this->cnEmail]))
      $data['email'] = trim ($cols[$this->cnEmail]);
    if ($this->cnPhone >= 0 && isset($cols[$this->cnPhone]))
      $data['phone'] = $this->colValuePhone ($cols[$this->cnPhone]);
    if ($this->cnChipIdMifare >= 0 && isset($cols[$this->cnChipIdMifare]))
      $data['chipIdMifare'] = $cols[$this->cnChipIdMifare];
    if ($this->cnCardType >= 0 && isset($cols[$this->cnCardType]))
      $data['cardType'] = trim ($cols[$this->cnCardType]);

    if ($this->cnCardId >= 0 && isset($cols[$this->cnCardId]))
    {
      $fid = trim ($cols[$this->cnCardId]);
      $data['id'] = substr($fid, 0, 1).substr($fid, -7);
    }
    $newPersonNdx = 0;
    $newPersonNdx = $this->searchPersonByName($data);

    if (!$newPersonNdx)
      $newPersonNdx = $this->createPerson ($data);

    // -- person label
    $personLabelNdx = 0;
    if (substr($data['cardType'] ?? '', 0, 4) === 'ISIC')
      $personLabelNdx = $this->personLabelIsicNdx;
    elseif (substr($data['cardType'] ?? '', 0, 4) === 'ITIC')
      $personLabelNdx = $this->personLabelIticNdx;

    if ($personLabelNdx)
    {
      $labelExist = $this->db()->query('SELECT * FROM e10_base_clsf WHERE tableid = %s', 'e10.persons.persons',
                                        ' AND clsfItem = %i', $personLabelNdx, ' AND [group] = %s', 'personsTags',
                                        ' AND recid = %i', $newPersonNdx)->fetch();
      if (!$labelExist)
      {
        $label = [
          'clsfItem' => $personLabelNdx, 'group' => 'personsTags',
          'tableid' => 'e10.persons.persons', 'recid' => $newPersonNdx,
        ];
        $this->db()->query('INSERT INTO [e10_base_clsf] ', $label);
      }
    }
  }

	public function createPerson ($entryRecData)
	{
		$newPerson ['person'] = [];
		$newPerson ['person']['company'] = 0;
		$newPerson ['person']['firstName'] = $entryRecData['firstName'];
		$newPerson ['person']['lastName'] = $entryRecData['lastName'];
    $newPerson ['person']['beforeName'] = $entryRecData['beforeName'] ?? '';
    $newPerson ['person']['afterName'] = $entryRecData['afterName'] ?? '';
    if ($newPerson ['person']['beforeName'] !== '' || $newPerson ['person']['afterName'] !== '')
      $newPerson ['person']['complicatedName'] = 1;
    $newPerson ['person']['id'] = $entryRecData['id'] ?? '';
		$newPerson ['person']['docState'] = 4000;
		$newPerson ['person']['docStateMain'] = 2;

    if (isset($entryRecData['email']) && $entryRecData['email'] !== '')
      $newPerson ['contacts'][] = ['type' => 'email', 'value' => $entryRecData['email']];
    if (isset($entryRecData['phone']) && $entryRecData['phone'] !== '')
      $newPerson ['contacts'][] = ['type' => 'phone', 'value' => $entryRecData['phone']];

    if ($this->app()->debug)
      echo "* person: ".json_encode($newPerson)."\n";

    $newPersonNdx = 0;
		$newPersonNdx = \E10\Persons\createNewPerson ($this->app, $newPerson);
		$this->tablePersons->docsLog ($newPersonNdx);

    if ($newPersonNdx)
    {
      if (isset($entryRecData['chipIdMifare']) && $entryRecData['chipIdMifare'] !== '')
			$newContact = [
        'property' => 'chipid', 'group' => 'chips', 'tableid' => 'e10.persons.persons', 'recid' => $newPersonNdx,
                      'valueString' => $entryRecData['chipIdMifare'], 'created' => new \DateTime ()
      ];
			$this->db()->query ("INSERT INTO [e10_base_properties]", $newContact);
    }

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
      $cnt++;

      if ($this->app()->debug && $cnt > 20)
        break;
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

  function searchPersonById($data)
	{
    $q = [];
    array_push($q, 'SELECT * FROM [e10_persons_persons]');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [id] = %s', $data['id']);
    array_push($q, ' AND [docState] = %i', 4000);

    $exist = $this->db()->query($q)->fetch();
    if ($exist)
      return $exist['ndx'];

    return 0;
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

    $phoneNumber = str_replace('++', '+', $phoneNumber);

    return $phoneNumber;
  }

  public function run()
  {
    $this->tablePersons = $this->app()->table('e10.persons.persons');

    $this->doImport();
  }
}
