<?php

namespace hosting\core\libs;

use \Shipard\Form\Wizard;


/**
 * Class WizardEnableCreatingDatabases
 * @package hosting\core\libs
 */
class WizardEnableCreatingDatabases extends Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->doEnable();
		}
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'system/iconDatabase';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Povolit vytváření nových databází'];

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

		$this->recData['partner'] = intval($this->app()->testGetParam('partnerNdx'));

		$this->openForm ();
			$this->addCheckBox('confirm', 'Souhlasím s podmínkami provozu služby', '1', self::coRight);
		$this->closeForm ();
	}

	public function doEnable ()
	{
		$this->stepResult ['lastStep'] = 1;

		if ($this->app()->hasRole('hstngdb'))
			return;

		if (!intval($this->recData['confirm']))
		{
			$this->addMessage('Musíte odsouhlasit podmínky provozu služby. Zkuste to prosím znovu.');
			return;
		}	

		/** @var \e10\persons\TablePersons $tablePersons */
		$tablePersons = $this->app()->table('e10.persons.persons');
		$personRecData = $tablePersons->loadItem($this->app()->userNdx());
		if (!$personRecData)
			return;

		$roles = explode('.', $personRecData['roles']);
		if (!count($roles))
			return;

		$roles[] = 'hstngdb';
		$rolesStr = implode('.', $roles);
		$this->app()->db()->query('UPDATE [e10_persons_persons] SET [roles] = %s', $rolesStr, ' WHERE [ndx] = %i', $this->app()->userNdx());
		$tablePersons->docsLog($this->app()->userNdx());

		$this->stepResult ['close'] = 1;
	}
}
