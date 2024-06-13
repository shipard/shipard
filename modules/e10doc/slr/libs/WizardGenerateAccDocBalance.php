<?php
namespace e10doc\slr\libs;

use \Shipard\Form\TableForm, \Shipard\Form\Wizard;


/**
 * class WizardGenerateAccDocBalance
 */
class WizardGenerateAccDocBalance extends Wizard
{
  var $importNdx = 0;

	function init()
	{
		$this->importNdx = ($this->focusedPK) ? $this->focusedPK : $this->recData['importNdx'];
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

		$this->recData['importNdx'] = $this->focusedPK;

		$this->openForm ();
			$this->addInput('importNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 120);
		$this->closeForm ();
	}

	public function generateDoc ()
	{
		$this->init();

    $ae = new \e10doc\slr\libs\AccBalanceEngine($this->app());
    $ae->setImport($this->recData['importNdx']);
    $ae->generateAccBalanceDoc();

		$this->stepResult ['close'] = 1;
    $this->stepResult ['refreshDetail'] = 1;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'icon-refresh';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Vystavit účetní doklad pro závazky'];

		return $hdr;
	}
}
