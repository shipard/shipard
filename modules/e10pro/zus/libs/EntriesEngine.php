<?php

namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use \Shipard\Utils\World;
use E10Pro\Zus\zusutils, \e10\utils, \e10\str, \e10\Utility;


/**
 * class EntriesEngine
 */
class EntriesEngine extends Utility
{
  var $entryRecData = NULL;
  /** @var \e10\persons\TablePersons */
  var $tablePersons;
  /** @var \e10\persons\TablePersonsContacts */
  var $tablePersonsContacts;

  var $newPersonNdx = 0;
  var $debug = 0;
  var $doIt = 0;

  var $cntNew = 0;
  var $cntExisted = 0;

  public function generate()
  {
    $q = [];
    array_push($q, 'SELECT * FROM [e10pro_zus_prihlasky]');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND talentovaZkouska = %i', 1);
    array_push($q, ' AND keStudiu = %i', 1);
    array_push($q, ' AND docState = %i', 4000);
    array_push($q, ' AND dstStudent = %i', 0);
    array_push($q, ' AND mistoStudia = %i', 1);

    $cnt = 1;
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      if ($this->debug)
        echo $cnt.'.'.$r['fullNameS'];

      $existedStudentNdx = $this->checkPID($r['rodneCislo']);
      $this->entryRecData = $r->toArray();

      if ($existedStudentNdx)
      {
        if ($this->debug)
          echo "; existed: ".$existedStudentNdx;

        if ($this->doIt)
          $this->linkStudent ($r, $existedStudentNdx);

        $this->cntExisted++;
      }
      else
      {
        if ($this->debug)
          echo "; new: ";

        if ($this->doIt)
          $this->createStudent();

        $this->cntNew++;
      }

      if ($this->debug)
        echo "\n";
      $cnt++;
    }
  }

  protected function checkPID($pid)
  {
    $studentNdx = 0;

		$q = [];
		array_push($q, 'SELECT props.*, persons.fullName AS fullNameS');
		array_push($q, ' FROM e10_base_properties AS props');
		array_push($q, ' LEFT JOIN e10_persons_persons AS persons ON props.recid = persons.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND props.[group] = %s', 'ids');
		array_push($q, ' AND props.[property] = %s', 'pid');
		array_push($q, ' AND props.[tableid] = %s', 'e10.persons.persons');
    array_push($q, ' AND props.[valueString] = %s', $pid);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{

      $studentNdx = $r['recid'];
    }

    return $studentNdx;
  }

	public function linkStudent ($entryRecData, $existedStudentNdx)
	{
    $this->app()->db()->query('UPDATE [e10pro_zus_prihlasky] SET [dstStudent] = %i', $existedStudentNdx, ' WHERE [ndx] = %i', $entryRecData['ndx']);
	}

	public function createStudent ()
	{
		$newPerson ['person'] = [];
		$newPerson ['person']['company'] = 0;
		$newPerson ['person']['firstName'] = $this->entryRecData['firstNameS'];
		$newPerson ['person']['lastName'] = $this->entryRecData['lastNameS'];
		$newPerson ['person']['fullName'] = $this->entryRecData['lastNameS'].' '.$this->entryRecData['firstNameS'];
		$newPerson ['person']['docState'] = 4000;
		$newPerson ['person']['docStateMain'] = 2;

		$newAddress = [];
		$newAddress ['street'] = $this->entryRecData ['street'];
		$newAddress ['city'] = $this->entryRecData ['city'];
		$newAddress ['zipcode'] = $this->entryRecData ['zipcode'];
		$newAddress ['country'] = $this->app()->cfgItem ('options.core.ownerDomicile', 'cz');
		$newAddress ['worldCountry'] = World::countryNdx($this->app(), $this->app()->cfgItem ('options.core.ownerDomicile', 'cz'));

		if ($newAddress ['street'] !== '' || $newAddress ['city'] !== '' || $newAddress ['zipcode'] !== '')
			$newPerson ['address'][] = $newAddress;


		$pid = $this->entryRecData ['rodneCislo'];
		if (strlen($pid) === 10)
			$pid = substr($this->entryRecData ['rodneCislo'], 0, 6).'/'.substr($this->entryRecData ['rodneCislo'], 6);

    $newPerson ['ids'][] = ['type' => 'birthdate', 'value' => $this->entryRecData ['datumNarozeni']];
    $newPerson ['ids'][] = ['type' => 'pid', 'value' => $pid];

    $newPerson ['groups'] = ['@e10pro-zus-groups-students'];

		$this->newPersonNdx = \E10\Persons\createNewPerson ($this->app, $newPerson);
		$this->tablePersons->docsLog ($this->newPersonNdx);

    $this->app()->db()->query('UPDATE [e10pro_zus_prihlasky] SET [dstStudent] = %i', $this->newPersonNdx, ' WHERE [ndx] = %i', $this->entryRecData['ndx']);


		// -- contacs
		$this->addContact($this->newPersonNdx, 'Zákonný zástupce 1', 'M');
		$this->addContact($this->newPersonNdx, 'Zákonný zástupce 2', 'F');

		return $this->newPersonNdx;
	}

	protected function addContact($personNdx, $title, $sfx)
	{
		$newContact = [];

		$newContact['contactName'] = $this->entryRecData['fullName'.$sfx];


		if ($this->entryRecData['email'.$sfx] !== '')
			$newContact['contactEmail'] = $this->entryRecData['email'.$sfx];
		if ($this->entryRecData['phone'.$sfx] !== '')
			$newContact['contactPhone'] = $this->entryRecData['phone'.$sfx];

		if (isset($newContact['contactPhone']) || isset($newContact['contactEmail']))
			$newContact['flagContact'] = 1;


		if ($this->entryRecData['useAddress'.$sfx])
		{
			$newContact['flagAddress'] = 1;
			$newContact['adrStreet'] = $this->entryRecData['street'.$sfx];
			$newContact['adrCity'] = $this->entryRecData['city'.$sfx];
			$newContact['adrZipCode'] = $this->entryRecData['zipcode'.$sfx];
			$newContact['adrCountry'] = 60;
		}

		if (count($newContact))
		{
			$newContact['person'] = $personNdx;
			$newContact['contactRole'] = $title;
			$newContact['docState'] = 4000;
			$newContact['docStateMain'] = 2;

			$this->tablePersonsContacts->dbInsertRec($newContact);
		}
	}

  public function run()
  {
    $this->tablePersons = $this->app()->table('e10.persons.persons');
    $this->tablePersonsContacts = $this->app()->table('e10.persons.personsContacts');

    $this->generate();
  }
}

