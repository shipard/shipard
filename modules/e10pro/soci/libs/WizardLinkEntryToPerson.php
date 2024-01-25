<?php

namespace e10pro\soci\libs;

use E10\Wizard, E10\TableForm;
use \Shipard\Utils\World;

require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';

/**
 * class WizardLinkEntryToStudent
 */
class WizardLinkEntryToPerson extends Wizard
{
  var $entryRecData = NULL;
  var $newPersonNdx = NULL;

  /** @var \e10\persons\TablePersons */
  var $tablePersons;

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
    $this->recData['personNdx'] = $this->app()->testGetParam('personNdx');
    $this->entryRecData = $this->app()->loadItem(intval($this->focusedPK), 'e10pro.soci.entries');

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('entryNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
      $this->addInput('personNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		$this->closeForm ();
	}

	public function generate ()
	{
    $this->tablePersons = $this->app()->table('e10.persons.persons');
    $this->entryRecData = $this->app()->loadItem(intval($this->recData['entryNdx']), 'e10pro.soci.entries');

    $this->linkPerson();

		$this->stepResult ['close'] = 1;
		$this->stepResult ['refreshDetail'] = 1;
		$this->stepResult['lastStep'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-play';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Propojit přihlášku s existující osobou'];
		$hdr ['info'][] = ['class' => 'info', 'value' => $this->entryRecData['lastName'].' '.$this->entryRecData['firstName']];

		return $hdr;
	}

	public function linkPerson ()
	{
    $personNdx = intval($this->recData['personNdx']);
    $this->app()->db()->query('UPDATE [e10pro_soci_entries] SET [dstPerson] = %i', $personNdx, ' WHERE [ndx] = %i', $this->entryRecData['ndx']);
	}
}
