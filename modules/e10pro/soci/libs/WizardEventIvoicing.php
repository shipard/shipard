<?php

namespace e10pro\soci\libs;

use \Shipard\Form\Wizard, \Shipard\Form\TableForm;



/**
 * class WizardEventIvoicing
 */
class WizardEventIvoicing extends Wizard
{
  var $eventRecData = NULL;

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
		$this->recData['eventNdx'] = $this->focusedPK;
    $this->eventRecData = $this->app()->loadItem(intval($this->focusedPK), 'e10mnf.core.workOrders');

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('eventNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		$this->closeForm ();
	}

	public function generate ()
	{
    $this->eventRecData = $this->app()->loadItem(intval($this->focusedPK), 'e10mnf.core.workOrders');

    $this->doIt();

		$this->stepResult ['close'] = 1;
		$this->stepResult ['refreshDetail'] = 1;
		$this->stepResult['lastStep'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-play';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Vystavit faktury'];
		$hdr ['info'][] = ['class' => 'info', 'value' => $this->eventRecData['title']];

		return $hdr;
	}

	public function doIt ()
	{
    $ie = new \e10pro\soci\libs\EntriesInvoicingEngine($this->app());
    $ie->init();
		$ie->generateAll(0, $this->recData['eventNdx']);
	}
}
