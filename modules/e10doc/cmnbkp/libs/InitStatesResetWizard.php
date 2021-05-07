<?php

namespace e10doc\cmnbkp\libs;
use \E10\Wizard, \E10\TableForm, \e10Doc\core\e10utils;
require_once __APP_DIR__ . '/e10-modules/e10doc/core/core.php';


/**
 * Class InitStatesResetWizard
 * @package e10doc\cmnbkp\libs
 */
class InitStatesResetWizard extends Wizard
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

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-refresh';
		$hdr ['info'][] = ['class' => 'title', 'value' => 'Přegenerovat otevření období'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Vyberte účetní období, pro které chcete přehenerovat počáteční stavy'];
		$hdr ['info'][] = ['class' => 'info e10-error', 'value' => 'POZOR: stávající doklady budou smazány a vygenerovány znovu'];
		return $hdr;
	}


	public function renderFormWelcome ()
	{
		$this->recData['focusedDocNdx'] = $this->focusedPK;
		$this->recData['fiscalYear'] = e10utils::todayFiscalYear($this->app());
		$enumFiscalYears = e10utils::fiscalYearEnum ($this->app());

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput ('focusedDocNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
			$this->addInputEnum2 ('fiscalYear', 'Účetní období', $enumFiscalYears, self::INPUT_STYLE_OPTION);
		$this->closeForm ();
	}

	public function doIt ()
	{
		$eng = new \e10doc\cmnbkp\libs\OpenAccPeriodReset ($this->app);
		$eng->fiscalYear = $this->recData['fiscalYear'];

		$eng->run();

		$this->stepResult ['close'] = 1;
	}
}
