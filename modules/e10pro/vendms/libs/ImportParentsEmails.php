<?php

namespace e10pro\vendms\libs;
use \Shipard\Base\Utility;
use \Shipard\Utils\Str;
require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';


/**
 * class ImportParentsEmails
 */
class ImportParentsEmails extends Utility
{
  /** @var \e10\persons\TablePersons */
  var $tablePersons;

  var $personLabelIsicNdx = 0;
  var $fileName;

  var $originalColumnIds = [];

  var $cnParentName = -1;
  var $cnEmail = -1;
  var $cnStudents = -1;


  protected function detectColumnsIds($cols)
  {
    foreach ($cols as $colNum => $colName)
    {
      $cn = trim($colName);
      $this->originalColumnIds[$colNum] = $cn;
      if ($cn === 'Jméno osoby')
        $this->cnParentName = $colNum;
      elseif ($cn === 'Primární e-mail')
        $this->cnEmail = $colNum;
      elseif ($cn === 'Děti ve škole')
        $this->cnStudents = $colNum;
    }
  }

  protected function importOneRow($cols)
  {
    $data = [];
    if ($this->cnParentName >= 0 && isset($cols[$this->cnParentName]))
    {
      $rows = preg_split("/[\n]+/", $cols[$this->cnParentName]);
      $data['parentName'] = trim ($rows[0]);
    }
    if ($this->cnEmail >= 0 && isset($cols[$this->cnEmail]))
    {
      $data['email'] = trim ($cols[$this->cnEmail]);
    }
    if ($this->cnStudents >= 0 && isset($cols[$this->cnStudents]))
    {
      $data['students'] = preg_split("/[,]+/", $cols[$this->cnStudents]);//explode(',', trim($cols[$this->cnStudents]));
    }
    $personNdx = 0;

    echo " * ".json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";

    foreach ($data['students'] as $student)
    {
      $student = trim($student);
      if ($student === '')
        continue;

      //echo "# ".$student;

      $q = [];
      array_push($q, 'SELECT * FROM [e10_persons_persons]');
      array_push($q, ' WHERE [fullName] = %s', $student);
      array_push($q, ' AND [docState] = %i', 4000);

      $exist = $this->db()->query($q)->fetch();
      if (!$exist)
      {
        //echo "Student not found: $student\n";
        continue;
      }

      $this->updatePerson($exist['ndx'], $data);
    }
  }

	public function updatePerson ($personNdx, $data)
	{
    if (isset($data['email']) && $data['email'] !== '')
    {
      $exist = $this->db()->query('SELECT * FROM [e10_persons_personsContacts] WHERE [flagContact] = %i', 1,
          ' AND [contactEmail] = %s', $data['email'],
          ' AND [person] = %i', $personNdx)->fetch();

      if ($exist)
      {
        //echo "CONTACT EXIST...\n";
      }
      else
      {
				$newContact = [
					'person' => $personNdx,
          'contactName' => $data['parentName'],
          'contactRole' => 'Rodič',
          'contactEmail' => $data['email'],
					'flagContact' => 1,
          'systemOrder' => 99,
					'docState' => 4000, 'docStateMain' => 2,
				];

				$this->app->db->query ('INSERT INTO [e10_persons_personsContacts]', $newContact);
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

  public function run()
  {
    $this->tablePersons = $this->app()->table('e10.persons.persons');

    $this->doImport();
  }
}
