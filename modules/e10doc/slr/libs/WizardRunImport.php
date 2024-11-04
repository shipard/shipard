<?php
namespace e10doc\slr\libs;



/**
 * class WizardRunImport
 */
class WizardRunImport extends \Shipard\Form\Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->runImport();
		}
		if ($this->pageNumber == 2)
		{
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
		//$this->setFlag ('maximize', 1);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$importNdx = intval($this->focusedPK);
		$this->recData['importNdx'] = strval($importNdx);

		$this->openForm ();
			$this->addInput('importNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 120);
		$this->closeForm ();
	}

	public function runImport ()
	{
		$importNdx = intval($this->recData['importNdx']);

		/** @var \e10doc\slr\TableImports */
		$tableImports = $this->app()->table('e10doc.slr.imports');
		$e = $tableImports->importEngine ($importNdx);
		if (!$e)
		{
			error_log("##### INVALID IMPORT ENGINE!");
			return;
		}
		$e->setImportNdx($importNdx);
		$e->run();
		$e->createAccDocs();
		$e->createBalanceDoc();

		$this->stepResult ['close'] = 1;
		$this->stepResult ['refreshDetail'] = 1;
	}
}

