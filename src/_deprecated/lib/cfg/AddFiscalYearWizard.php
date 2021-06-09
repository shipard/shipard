<?php

namespace lib\cfg;

require_once __APP_DIR__ . '/e10-modules/e10doc/core/core.php';

use E10Doc\Core\e10utils, E10\TableForm;


/**
 * Class AddFiscalYearWizard
 * @package lib\cfg
 */
class AddFiscalYearWizard extends \E10\Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->stepResult['lastStep'] = 1;
			if ($this->saveDocument())
				$this->stepResult ['close'] = 1;
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
		$this->setFlag ('formStyle', 'e10-formStyleWizard');

		$this->openForm ();
			$this->addInput ('fiscalYear', 'Rok', TableForm::INPUT_STYLE_INT);
		$this->closeForm ();
	}

	protected function saveDocument ()
	{
		$year = intval ($this->recData['fiscalYear']);
		if ($year < 1980 || $year > 2099)
		{
			$this->addMessage("Rok musí být v rozsahu 1980 až 2099.");
			return FALSE;
		}

		$exist = e10utils::todayFiscalYear($this->app(), "$year-01-01");
		if ($exist)
		{
			$this->addMessage("Fiskální rok $year již existuje.");
			return FALSE;
		}

		$tableFiscalYears = $this->app()->table ('e10doc.base.fiscalyears');
		$tableFiscalYears->createYear ($year);

		return TRUE;
	}

	public function createHeader ()
	{
		$hdr = ['icon' => 'system/iconCalendar'];

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Nové účetní období'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Zadejte rok, pro který chcete vytvořit nové účetní období.'];

		return $hdr;
	}
}
