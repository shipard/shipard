<?php

namespace e10pro\vendms\libs;
use \Shipard\Base\Utility;
require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';


/**
 * class ImportPersonsIds
 */
class ImportPersonsIds extends Utility
{
  /** @var \e10\persons\TablePersons */
  var $tablePersons;

  var $personLabelIsicNdx = 0;
  var $fileName;

  var $originalColumnIds = [];

  var $cnFirstName = -1;
  var $cnLastName = -1;
  var $cnPaymentSymbol1 = -1;


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
      elseif ($cn === 'Osobní číslo')
        $this->cnPaymentSymbol1 = $colNum;
    }
  }

  protected function importOneRow($cols)
  {
    //echo " * CSV: `$this->cnPaymentSymbol1`".json_encode($cols)."\n";
    $data = [];
    if ($this->cnFirstName >= 0 && isset($cols[$this->cnFirstName]))
      $data['firstName'] = trim ($cols[$this->cnFirstName]);
    if ($this->cnLastName >= 0 && isset($cols[$this->cnLastName]))
      $data['lastName'] = trim ($cols[$this->cnLastName]);
    if ($this->cnPaymentSymbol1 >= 0 && isset($cols[$this->cnPaymentSymbol1]))
      $data['symbol1'] = trim ($cols[$this->cnPaymentSymbol1]);

    $personNdx = 0;
    $personNdx = $this->searchPersonByName($data);

    if ($personNdx)
    {
      $this->updatePerson ($personNdx, $data);
    }
    else
    {
      //echo "* Person not exist: ".json_encode($data)."\n";

      $newPerson ['person'] = [];
      $newPerson ['person']['company'] = 0;
      $newPerson ['person']['firstName'] = $data['firstName'];
      $newPerson ['person']['lastName'] = $data['lastName'];
      $newPerson ['person']['id'] = '';
      $newPerson ['person']['docState'] = 4000;
      $newPerson ['person']['docStateMain'] = 2;

      if ($this->app()->debug)
        echo "* new person: ".json_encode($newPerson, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";

      $newPersonNdx = 0;
      $newPersonNdx = \E10\Persons\createNewPerson ($this->app, $newPerson);
      $this->tablePersons->docsLog ($newPersonNdx);

      if ($newPersonNdx)
      {
        if (isset($data['symbol1']) && $data['symbol1'] !== '')
        {
          $newProp = [
            'property' => 'paysym1', 'group' => 'paysyms', 'tableid' => 'e10.persons.persons', 'recid' => $personNdx,
            'valueString' => $data['symbol1'], 'created' => new \DateTime ()
          ];
          $this->db()->query ("INSERT INTO [e10_base_properties]", $newProp);
        }
      }

      $personNdx = $newPersonNdx;
    }

    if ($this->personLabelIsicNdx)
    {
      $labelExist = $this->db()->query('SELECT * FROM e10_base_clsf WHERE tableid = %s', 'e10.persons.persons',
                                        ' AND clsfItem = %i', $this->personLabelIsicNdx, ' AND [group] = %s', 'personsTags',
                                        ' AND recid = %i', $personNdx)->fetch();
      if (!$labelExist)
      {
        $label = [
          'clsfItem' => $this->personLabelIsicNdx, 'group' => 'personsTags',
          'tableid' => 'e10.persons.persons', 'recid' => $personNdx,
        ];
        $this->db()->query('INSERT INTO [e10_base_clsf] ', $label);
      }
    }
  }

	public function updatePerson ($personNdx, $data)
	{
    if (isset($data['symbol1']) && $data['symbol1'] !== '')
    {
      $exist = $this->db()->query('SELECT * FROM [e10_base_properties] WHERE [tableid] = %s', 'e10.persons.persons',
          ' AND [group] = %s', 'paysyms',
          ' AND [property] = %s ', 'paysym1',
          ' AND [recid] = %i', $personNdx)->fetch();

      if ($exist)
      {
        //echo "PROP EXIST...\n";
      }
      else
      {
        $newProp = [
          'property' => 'paysym1', 'group' => 'paysyms', 'tableid' => 'e10.persons.persons', 'recid' => $personNdx,
          'valueString' => $data['symbol1'], 'created' => new \DateTime ()
        ];
        $this->db()->query ("INSERT INTO [e10_base_properties]", $newProp);
      }
    }
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

  public function run()
  {
    $this->tablePersons = $this->app()->table('e10.persons.persons');

    $this->doImport();
  }
}
