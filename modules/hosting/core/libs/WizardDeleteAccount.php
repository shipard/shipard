<?php

namespace hosting\core\libs;

use \Shipard\Form\Wizard;


/**
 * Class WizardDeleteAccount
 * @package hosting\core\libs
 */
class WizardDeleteAccount extends Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->doRemove();
		}
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'system/actionDelete';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Zrušení uživatelského účtu'];

		return $hdr;
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
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addCheckBox('confirm', 'Ano, opravdu chci účet smazat', '1', self::coRight);
		$this->closeForm ();
	}

	public function doRemove ()
	{
		$this->stepResult ['lastStep'] = 1;

		if (!intval($this->recData['confirm']))
		{
			$this->addMessage('Musíte odsouhlasit smazání účtu. Zkuste to prosím znovu.');
			return;
		}

		/** @var \e10\persons\TablePersons $tablePersons */
		$tablePersons = $this->app()->table('e10.persons.persons');
		$personRecData = $tablePersons->loadItem($this->app()->userNdx());
		if (!$personRecData)
			return;

		$this->app()->db()->query('UPDATE [e10_persons_persons] SET [docState] = %i', 9800, ', docStateMain = %i', 4, ' WHERE [ndx] = %i', $this->app()->userNdx());
		$tablePersons->docsLog($this->app()->userNdx());
		$this->app()->db()->query('DELETE FROM [e10_persons_sessions] WHERE [person] = %i', $this->app()->userNdx());

		$this->addMessage('Účet by smazán. Prosím obnovte stránku...');
		$this->stepResult ['lastStep'] = 999;
	}
}
