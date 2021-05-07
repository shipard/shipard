<?php

namespace mac\lan\libs;
use \e10\TableForm;


/**
 * Class LanControlSetToReloadRunningConfWizard
 * @package mac\lan\libs
 */
class LanControlSetToReloadRunningConfWizard extends \E10\Wizard
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
		$this->closeForm ();
	}

	protected function confirmChanges ()
	{
		$lcu = new \mac\lan\libs\LanControlCfgUpdater($this->app());
		$lcu->setToReloadRunningConf();

		return TRUE;
	}

	public function createHeader ()
	{
		$hdr = ['icon' => 'icon-refresh'];

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Znovu načíst stav všech zařízení'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Zařízení budou přepnuta do stavu, kdy si vyžádají znovunačtení aktuální konfigurace'];

		return $hdr;
	}
}
