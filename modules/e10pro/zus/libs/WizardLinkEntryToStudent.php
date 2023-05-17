<?php

namespace e10pro\zus\libs;

use E10\Wizard, E10\TableForm;
use \Shipard\Utils\World;

require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';

/**
 * class WizardLinkEntryToStudent
 */
class WizardLinkEntryToStudent extends Wizard
{
  var $entryRecData = NULL;
  var $newPersonNdx = NULL;

  /** @var \e10\persons\TablePersons */
  var $tablePersons;
  /** @var \e10\persons\TablePersonsContacts */
  var $tablePersonsContacts;

	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->generate();
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->recData['entryNdx'] = $this->focusedPK;
    $this->entryRecData = $this->app()->loadItem(intval($this->focusedPK), 'e10pro.zus.prihlasky');

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('entryNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		$this->closeForm ();
	}

	public function generate ()
	{
    $this->tablePersons = $this->app()->table('e10.persons.persons');
    $this->tablePersonsContacts = $this->app()->table('e10.persons.personsContacts');
    $this->entryRecData = $this->app()->loadItem(intval($this->recData['entryNdx']), 'e10pro.zus.prihlasky');

    $this->linkStudent();

		$this->stepResult ['close'] = 1;
		$this->stepResult ['refreshDetail'] = 1;
		$this->stepResult['lastStep'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-play';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Propojit přihlášku s existujícím studentem'];
		$hdr ['info'][] = ['class' => 'info', 'value' => $this->entryRecData['lastNameS'].' '.$this->entryRecData['firstNameS']];

		return $hdr;
	}

	public function linkStudent ()
	{
    $studentNdx = 0;
		$pid = $this->entryRecData ['rodneCislo'];
		if (strlen($pid) === 10)
			$pid = substr($this->entryRecData ['rodneCislo'], 0, 6).'/'.substr($this->entryRecData ['rodneCislo'], 6);

		$q = [];
		array_push($q, 'SELECT props.*, persons.fullName AS fullNameS');
		array_push($q, ' FROM e10_base_properties AS props');
		array_push($q, ' LEFT JOIN e10_persons_persons AS persons ON props.recid = persons.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND props.[group] = %s', 'ids');
		array_push($q, ' AND props.[property] = %s', 'pid');
		array_push($q, ' AND props.[tableid] = %s', 'e10.persons.persons');
    array_push($q, ' AND props.[valueString] = %s', $pid);

		$rows = $this->app()->db()->query($q);
		foreach ($rows as $r)
		{
      $labels[] = ['text' => $r['fullNameS'], 'class' => '', 'docAction' => 'edit', 'pk' => $r['recid'], 'table' => 'e10.persons.persons'];

      $studentNdx = $r['recid'];
      break;
    }

    $this->app()->db()->query('UPDATE [e10pro_zus_prihlasky] SET [dstStudent] = %i', $studentNdx, ' WHERE [ndx] = %i', $this->entryRecData['ndx']);
	}
}
