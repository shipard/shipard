<?php

namespace e10doc\cmnbkp\libs;
use \E10\Wizard, \E10\TableForm, \e10Doc\core\e10utils;


/**
 * Class InitStatesWizard
 * @package e10doc\cmnbkp\libs
 */
class InitStatesWizard extends Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->doIt ();
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
		$this->recData['focusedDocNdx'] = $this->focusedPK;
		$this->recData['fiscalYear'] = e10utils::todayFiscalYear($this->app());
		$this->recData['closeDocuments'] = 1;
		$this->recData['resetClosedDocuments'] = 0;
		$enumFiscalYears = e10utils::fiscalYearEnum ($this->app());

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
		$this->addInput ('focusedDocNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		$this->addInputEnum2 ('fiscalYear', 'Účetní období', $enumFiscalYears, self::INPUT_STYLE_OPTION);
		$this->addCheckBox('closeDocuments', 'Uzavřít doklady');
		$this->addCheckBox('resetClosedDocuments', 'Přegenerovat i uzavřené doklady');
		$this->closeForm ();
	}

	public function doIt ()
	{
		$eng = new \e10doc\cmnbkp\libs\InitStatesBalanceEngine ($this->app);
		$eng->setParams($this->recData['fiscalYear']);

		if ($this->recData['closeDocuments'])
			$eng->closeDocs = TRUE;

		if ($this->recData['resetClosedDocuments'])
			$eng->resetClosedDocs = TRUE;

		$eng->run();

		$this->stepResult ['close'] = 1;
	}
}
