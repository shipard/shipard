<?php

namespace e10pro\property;

use E10\TableForm;


/**
 * Class AddDeprecationsWizard
 * @package e10pro\property
 */
class AddDeprecationsWizard extends \E10\Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->stepResult['lastStep'] = 1;
			if ($this->saveDocument())
				$this->stepResult ['close'] = 1;
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
		$this->setFlag ('formStyle', 'e10-formStyleWizard');
		$this->setFlag ('maximize', 1);

		$this->openForm ();
		$h = [
			'icon' => '',
			'dateAccounting' => 'Datum',
			'rowType' => 'Typ',
			'accChange' => ' Pohyb Ú',
			'accChangeComputed' => ' OK Pohyb Ú',
			'accBalance' => ' Zůstatek Ú',
			'taxChange' => ' Pohyb D',
			'taxBalance' => ' Zůstatek D'
		];
		$propertyNdx = $this->app->testGetParam('__property');

		$de = new \e10pro\property\DepreciationsEngine ($this->app());
		$de->init();
		$de->setProperty($propertyNdx);
		$de->createDeprecations($propertyNdx);

		$this->recData['propertyNdx'] = $propertyNdx;
		$this->addInput('propertyNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		$this->addStatic(['type' => 'table', 'header' => $h, 'table' => $de->deprecations]);
		$this->closeForm ();
	}

	protected function saveDocument ()
	{
		return TRUE;
	}

	public function createHeader ()
	{
		$hdr = ['icon' => 'icon-sort-amount-desc'];

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Vytvoření plánu odpisů majetku'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Automaticky bude vytvořen plán odpisů majetku.'];

		return $hdr;
	}
}
