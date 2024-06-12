<?php
namespace e10doc\slr\libs;

use \Shipard\Form\TableForm, \Shipard\Form\Wizard;


/**
 * class WizardGenerateAccDoc
 */
class WizardGenerateAccDoc extends Wizard
{
  var $empRecNdx = 0;

	function init()
	{
		$this->empRecNdx = ($this->focusedPK) ? $this->focusedPK : $this->recData['empRecNdx'];
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
		$this->recData['empRecNdx'] = $this->focusedPK;

		$this->openForm ();
			$this->addInput('empRecNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		$this->closeForm ();
	}

	public function generateDoc ()
	{
		$this->init();

    $ae = new \e10doc\slr\libs\AccEngine($this->app());
    $ae->setEmpRec($this->recData['empRecNdx']);
    $ae->generateAccDoc();

		$this->stepResult ['close'] = 1;
    $this->stepResult ['refreshDetail'] = 1;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'icon-refresh';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Vystavit mzdový účetní doklad'];

		return $hdr;
	}
}
