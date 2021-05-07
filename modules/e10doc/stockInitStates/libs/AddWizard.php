<?php

namespace e10doc\stockInitStates\libs;
use e10doc\core\libs\E10Utils;


class AddWizard extends \Shipard\Form\Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->createInitStates ($this->recData ['fiscalYear']);
		}
	}

	public function renderForm ()
	{
		$this->recData ['fiscalYear'] = 0;
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		//$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$warehouses = $this->app()->cfgItem ('e10doc.warehouses', []);


		$this->openForm ();
			$this->addInputEnum2 ('fiscalYear', 'Účetní období', E10Utils::fiscalYearEnum ($this->app), self::INPUT_STYLE_OPTION);
			$this->addSeparator(self::coH1);
			$this->addStatic('Sklady:');
			foreach ($warehouses as $whNdx => $wh)
			{
				$this->addCheckBox('wh_'.$whNdx, $wh['fullName'], 1);
				$this->recData['wh_'.$whNdx] = 1;
			}
		$this->closeForm ();
	}

	public function createInitStates ($fiscalYear)
	{
		$inv = new InitStatesEngine ($this->app);

		foreach ($this->recData as $key => $value)
		{
			if (substr($key, 0, 3) !== 'wh_')
				continue;
			$parts = explode('_', $key);
			$whNdx = intval($parts[1]);

			if (!$whNdx || !intval($value))
				continue;
			$inv->createInitState ($fiscalYear, $whNdx);
		}

		$this->stepResult ['close'] = 1;
	}
}

