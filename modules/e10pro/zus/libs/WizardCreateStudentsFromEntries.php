<?php

namespace e10pro\zus\libs;

use E10\Wizard, E10\TableForm;
use \Shipard\Utils\World;


/**
 * class WizardCreateStudentsFromEntries
 */
class WizardCreateStudentsFromEntries extends Wizard
{
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
		$this->recData['placebo'] = 1;

		$e = new \e10pro\zus\libs\EntriesEngine($this->app());
		$e->run();


		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('placebo', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);

      if ($e->cntNew)
        $this->addStatic(['text' => 'Bude vytvořeno '.$e->cntNew.' studentů', 'class' => 'padd5']);
      if ($e->cntExisted)
        $this->addStatic(['text' => $e->cntExisted.' přihlášek bude propojeno s existujícími studenty', 'class' => 'padd5']);
      if (!$e->cntNew && !$e->cntExisted)
        $this->addStatic(['text' => 'Není nutné vytvářet žádné studenty', 'class' => 'padd5']);

		$this->closeForm ();
	}

	public function generate ()
	{
		$e = new \e10pro\zus\libs\EntriesEngine($this->app());
		$e->doIt = 1;
		$e->run();

		$this->stepResult ['close'] = 1;
		$this->stepResult ['refreshDetail'] = 1;
		$this->stepResult['lastStep'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'iconGenerateCertificates';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Vytvořit studenty z přihlášek'];

		return $hdr;
	}
}
