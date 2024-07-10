<?php
namespace e10doc\cmnbkp\libs\imports;
use \Shipard\Form\Wizard;


/**
 * class WizardRunImport
 */
class WizardRunImport extends Wizard
{
  var $empRecNdx = 0;

	function init()
	{
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

		$ie = new \e10doc\cmnbkp\libs\imports\ImportHelper($this->app());

		/** @var \e10doc\cmnbkp\libs\imports\cardTrans\ImportCardTrans */
		$importEngine = $ie->createImportFromDocument($this->recData['docNdx']);

		if ($importEngine)
		{
			$importEngine->setDocument($this->recData['docNdx']);
      $importEngine->doImport();
    }

		$this->stepResult ['close'] = 1;
    $this->stepResult ['refreshDetail'] = 1;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'icon-refresh';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Provést import'];

		return $hdr;
	}
}
