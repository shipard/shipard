<?php

namespace e10doc\accBal\libs;

use \Shipard\Form\TableForm, \Shipard\Form\Wizard, \Shipard\Utils\Json;


/**
 * class ImportJsonBalanceWizard
 */
class ImportJsonBalanceWizard extends Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->doIt();
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
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$this->addInputMemo('jsonConfiguration', 'JSON konfigurace', TableForm::coFullSizeY);
		$this->closeForm ('padd5');
	}

	public function doIt ()
	{
		$cfgText = $this->recData['jsonConfiguration'];

		$errorMsg = '';
		$idm = new \e10doc\accBal\libs\ImportJsonBalanceEngine($this->app());
		if (!$idm->setCfgText($cfgText, $errorMsg))
		{
			$this->addMessage($errorMsg);
			return;
		}

		$idm->import();

		// -- close wizard
		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'tables/e10doc.accBal.balances';
		$hdr ['info'][] = ['class' => 'title', 'value' => 'Přidat novou definici saldokonta z JSON konfigurace'];
		$hdr ['info'][] = ['class' => 'info', 'value' => ['text' => 'Vložte konfigurační JSON text', 'icon' => 'icon-plus-square']];

		return $hdr;
	}
}
