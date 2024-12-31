<?php

namespace e10doc\accBal\libs;
use \Shipard\Form\Wizard, \e10doc\core\libs\E10Utils;


/**
 * class ResetBalanceWizard
 */
class ResetBalanceWizard extends Wizard
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
		$this->recData['fiscalYear'] = E10utils::todayFiscalYear($this->app());
		$enumFiscalYears = E10utils::fiscalYearEnum ($this->app());

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
  		$this->addInputEnum2 ('fiscalYear', 'Účetní období pro přegenerování saldokonta', $enumFiscalYears, self::INPUT_STYLE_OPTION);
		$this->closeForm ();
	}

	public function doIt ()
	{
		$rae = new \e10doc\debs\libs\ReAccountingDocsEngine($this->app());
		$rae->init();
    $rae->setFiscalYear($this->recData['fiscalYear']);
		$rae->run();

		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$hdr = ['icon' => 'cmnbkpRegenerateOpenedPeriod'];

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Přegenerování saldokonta'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Akce přegeneruje saldokonto pro vybrané účetní období'];

		return $hdr;
	}
}
