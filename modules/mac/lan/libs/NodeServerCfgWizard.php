<?php

namespace mac\lan\libs;
use \e10\TableForm;


/**
 * Class NodeServerCfgWizard
 * @package mac\lan\libs
 */
class NodeServerCfgWizard extends \E10\Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->stepResult['lastStep'] = 1;
			if ($this->confirmChanges())
			{
				$this->stepResult ['close'] = 1;
			}
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

		$this->openForm (self::ltNone);
		$this->addInput('placebo', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);


		$une = new \mac\lan\libs\NodeServerCfgUpdater($this->app());
		$une->init();
		$une->getChanges();

		if ($une->changes && $une->changes['cnt'])
		{
			$this->addContent([[
				'type' => 'table', 'pane' => 'e10-pane e10-pane-table',
				'table' => $une->changes['table'], 'header' => $une->changes['header']
			]], 0, FALSE, 0);
		}

		$this->closeForm ();
	}

	protected function confirmChanges ()
	{
		$une = new \mac\lan\libs\NodeServerCfgUpdater($this->app());
		$une->init();
		$une->confirmChanges();

		return TRUE;
	}

	public function createHeader ()
	{
		$hdr = ['icon' => 'icon-send'];

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Potvrdit změny v nastavení'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Uvedené změny v nastvení node serverů budou potvrzeny a odeslány'];

		return $hdr;
	}
}
