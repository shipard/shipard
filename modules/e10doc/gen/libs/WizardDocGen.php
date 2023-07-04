<?php

namespace e10doc\gen\libs;
use \Shipard\Form\Wizard;


/**
 * class WizardDocGen
 */
class WizardDocGen extends Wizard
{
  /** @var \e10\persons\TablePersons */
  var $tablePersons;

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
		$this->recData['srcDocNdx'] = $this->focusedPK;
    $this->recData['docGenCfg'] = $this->app()->testGetParam('docGenCfg');

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('srcDocNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 120);
      $this->addInput('docGenCfg', '', self::INPUT_STYLE_STRING, self::coHidden, 120);
		$this->closeForm ();
	}

	public function generate ()
	{
		$e = new \e10doc\gen\libs\DocGenEngine($this->app());
		$e->init();
		$e->setSrcDocumentNdx($this->recData['srcDocNdx']);
		$newDocNdx = $e->generateDoc($this->recData['docGenCfg']);

		$this->stepResult ['close'] = 1;
		$this->stepResult ['refreshDetail'] = 1;
		$this->stepResult['lastStep'] = 1;

		if ($newDocNdx)
		{
			$this->stepResult ['editDocument'] = 1;
			$this->stepResult ['params'] = ['table' => 'e10doc.core.heads', 'pk' => $newDocNdx];
		}
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-play';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Vygenerovat'];

		return $hdr;
	}
}
