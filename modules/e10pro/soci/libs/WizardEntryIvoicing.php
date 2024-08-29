<?php

namespace e10pro\soci\libs;
use \Shipard\Form\Wizard, \Shipard\Form\TableForm;


/**
 * class WizardEntryIvoicing
 */
class WizardEntryIvoicing extends Wizard
{
  var $entryRecData = NULL;

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
    $this->entryRecData = $this->app()->loadItem(intval($this->focusedPK), 'e10pro.soci.entries');

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('entryNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		$this->closeForm ();
	}

	public function generate ()
	{
    $this->entryRecData = $this->app()->loadItem(intval($this->recData['entryNdx']), 'e10pro.soci.entries');

    $this->doIt();

		$this->stepResult ['close'] = 1;
		$this->stepResult ['refreshDetail'] = 1;
		$this->stepResult['lastStep'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-play';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Vystavit fakturu pro přihlášku'];
		$hdr ['info'][] = ['class' => 'info', 'value' => $this->entryRecData['lastName'].' '.$this->entryRecData['firstName']];

		return $hdr;
	}

	public function doIt ()
	{
    $ie = new \e10pro\soci\libs\EntriesInvoicingEngine($this->app());
    $ie->init();
		$ie->generateAll($this->recData['entryNdx']);
	}
}
