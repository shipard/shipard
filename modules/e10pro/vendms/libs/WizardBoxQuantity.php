<?php

namespace e10pro\vendms\libs;
use \Shipard\Form\Wizard, \Shipard\Form\TableForm;



/**
 * class WizardBoxQuantity
 */
class WizardBoxQuantity extends Wizard
{
  //var $eventRecData = NULL;

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
		$this->recData['boxNdx'] = $this->app->testGetParam('boxNdx');
		$this->recData['itemNdx'] = $this->app->testGetParam('itemNdx');

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('boxNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
			$this->addInput('itemNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
			$this->addInput('quantity', 'Množství', self::INPUT_STYLE_INT, /*TableForm::coHidden*/0, 120);
		$this->closeForm ();
	}

	public function generate ()
	{
    $this->doIt();

		$this->stepResult ['close'] = 1;
		$this->stepResult ['refreshDetail'] = 1;
		$this->stepResult['lastStep'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-play';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Příjem zboží do automatu'];

		return $hdr;
	}

	public function doIt ()
	{
		$quantity = intval($this->recData['quantity']);
		if (!$quantity)
			return;

		$moveType = 1;
		if ($quantity < 0)
			$moveType = 2;

		$journalItem = [
      'created' => new \DateTime(),
      'vm' => 1,
      'item' => $this->recData['itemNdx'],
      'box' => $this->recData['boxNdx'],
      'moveType' => $moveType, 'quantity' => $quantity,
    ];

    $this->app()->db()->query('INSERT INTO [e10pro_vendms_vendmsJournal] ', $journalItem);
	}
}
