<?php

namespace e10doc\inventory\libs;
use \Shipard\Form\Wizard, \e10doc\core\libs\E10Utils;


/**
 * class ResetInventoryWizard
 */
class ResetInventoryWizard extends Wizard
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
  		$this->addInputEnum2 ('fiscalYear', 'Účetní období pro přepočet zásob', $enumFiscalYears, self::INPUT_STYLE_OPTION);
		$this->closeForm ();
	}

	public function doIt ()
	{
		$eng = new \e10doc\inventory\libs\InventoryStatesEngine ($this->app);
		$eng->createInventoryJournal($this->recData['fiscalYear']);

		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$hdr = ['icon' => 'cmnbkpRegenerateOpenedPeriod'];

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Přepočet zásob'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Akce přepočítá zásoby pro vybrané účetní období'];

		return $hdr;
	}
}
