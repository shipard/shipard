<?php

namespace lib\cfg;

require_once __APP_DIR__ . '/e10-modules/e10doc/core/core.php';

use E10Doc\Core\e10utils, E10\TableForm;


/**
 * Class AddFiscalYearWizard
 * @package lib\cfg
 */
class AddVatPeriodWizard extends \E10\Wizard
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
		$this->recData['vatReg'] = $this->app->testGetParam ('__vatReg');

		$this->openForm ();
			$this->addInput ('calendarYear', 'Rok', TableForm::INPUT_STYLE_INT);

			$vatRegs = $this->app()->cfgItem ('e10doc.base.taxRegs.vat', []);
			$vatRegsEnum = [];
			foreach ($vatRegs as $vr)
				$vatRegsEnum[$vr['ndx']] = $vr['taxId'];

			$this->addInputEnum2('vatReg', 'DIČ', $vatRegsEnum, TableForm::INPUT_STYLE_OPTION);
		$this->closeForm ();
	}

	protected function saveDocument ()
	{
		$year = intval ($this->recData['calendarYear']);
		if ($year < 1993 || $year > 2099)
		{
			$this->addMessage('Rok musí být v rozsahu 1993 až 2099.');
			return FALSE;
		}

		$tableTaxPeriods = $this->app()->table ('e10doc.base.taxperiods');
		$tableTaxPeriods->createPeriod ($year, $this->recData['vatReg']);

		return TRUE;
	}

	public function createHeader ()
	{
		$hdr = ['icon' => 'system/iconCalendar'];

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Nové období DPH'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Zadejte kalendářní rok, pro který chcete vytvořit období DPH.'];

		return $hdr;
	}
}
