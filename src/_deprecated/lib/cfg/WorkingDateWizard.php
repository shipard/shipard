<?php

namespace lib\cfg;

require_once __APP_DIR__ . '/e10-modules/e10doc/core/core.php';

use E10\utils;
use E10Doc\Core\e10utils, E10\TableForm;


/**
 * Class WorkingDateWizard
 * @package lib\cfg
 */
class WorkingDateWizard extends \E10\Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->stepResult['lastStep'] = 1;
			if ($this->saveDocument())
				$this->stepResult ['reloadPage'] = 1;
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

		$this->openForm ();
		$this->addInput ('workingDate', 'Pracovní datum', TableForm::INPUT_STYLE_DATE);
		$this->closeForm ();
	}

	protected function saveDocument ()
	{
		$workingDate = $this->recData['workingDate'];

		if (utils::dateIsBlank($workingDate))
			$workingDate = NULL;
		else
		if (utils::today('Y-m-d') === $workingDate)
			$workingDate = NULL;

		$this->app->setUserParam('wd', $workingDate);

		return TRUE;
	}

	public function createHeader ()
	{
		$hdr = ['icon' => 'system/iconCalendar'];

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Nastavit pracovní datum'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Pracovní nahrazuje dnešní datum'];

		return $hdr;
	}
}
