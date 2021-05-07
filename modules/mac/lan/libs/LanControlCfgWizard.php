<?php

namespace mac\lan\libs;
use \e10\TableForm;


/**
 * Class LanControlCfgWizard
 * @package mac\lan\libs
 */
class LanControlCfgWizard extends \E10\Wizard
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


		$lcu = new \mac\lan\libs\LanControlCfgUpdater($this->app());
		$lcu->getRequestsStates();

		if ($lcu->requestsStates && $lcu->requestsStates['cnt'])
		{
			$this->addContent([[
				'type' => 'table', 'pane' => 'e10-pane e10-pane-table',
				'table' => $lcu->requestsStates['table'], 'header' => $lcu->requestsStates['header']
			]], 0, FALSE, 0);
		}

		$this->closeForm ();
	}

	protected function confirmChanges ()
	{
		$lcu = new \mac\lan\libs\LanControlCfgUpdater($this->app());
		$lcu->confirmChanges();

		return TRUE;
	}

	public function createHeader ()
	{
		$hdr = ['icon' => 'icon-send'];

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Odeslat změny v nastavení'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Uvedené změny v nastavení aktivních prvků budou postupně provedeny'];

		return $hdr;
	}
}
