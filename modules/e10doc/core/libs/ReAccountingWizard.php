<?php

namespace e10doc\core\libs;


use \Shipard\Form\Wizard;


/**
 * class ReAccountingWizard
 */
class ReAccountingWizard extends Wizard
{
  var $docNdx = 0;

	function init()
	{
		$this->docNdx = intval($this->focusedPK);
	}

	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->generateDoc();
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

    $this->recData['docNdx'] = $this->focusedPK;

		$this->openForm ();
			$this->addInput('docNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 120);
		$this->closeForm ();
	}

	public function generateDoc ()
	{
		$this->init();

    $ae = new \e10doc\core\libs\ReAccountingEngine($this->app());
    $ae->init();
    $ae->setDocument($this->recData['docNdx']);
    $ae->run();

		$this->stepResult ['close'] = 1;
    $this->stepResult ['refreshDetail'] = 1;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'icon-refresh';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Přeúčtovat doklad'];

		return $hdr;
	}
}
