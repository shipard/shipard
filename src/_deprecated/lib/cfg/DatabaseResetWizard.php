<?php

namespace lib\cfg;
use E10\utils, E10\TableForm;


/**
 * Class DatabaseResetWizard
 * @package lib\cfg
 */
class DatabaseResetWizard extends \E10\Wizard
{
	public function doStep ()
	{
		if (!$this->resetEnabled())
		{
			$this->stepResult['lastStep'] = 1;
		}
		else
		if ($this->pageNumber === 1)
		{
			if ($this->saveDocument())
				$this->stepResult ['restartApp'] = 1;
			$this->stepResult['lastStep'] = 1;
		}
	}

	public function renderForm ()
	{
		if ($this->pageNumber === 0)
			$this->renderFormWelcome ();
		else
			$this->renderFormDone ();
	}

	public function renderFormWelcome ()
	{
		if (!$this->resetEnabled())
			return;

		$this->setFlag ('formStyle', 'e10-formStyleWizard');

		$enum = ['1' => 'Ne, nechci', '2' => 'Ne, nechci', '3' => 'Ne, nechci', '9999' => 'ANO, chci databázi znovu inicializovat', '4' => 'Ne, nechci'];
		$this->recData['resetDatabase'] = '1';

		$this->openForm ();
			$this->addInputEnum2 ('resetDatabase', 'Opravdu chcete databázi inicializovat?', $enum, TableForm::INPUT_STYLE_OPTION);
		$this->closeForm ();
	}

	protected function saveDocument ()
	{
		if ($this->recData['resetDatabase'] === '9999')
		{
			utils::setAppStatus ('RESET');
			utils::dsCmd($this->app(), 'resetDataSource');
			return TRUE;
		}

		return FALSE;
	}

	public function createHeader ()
	{
		$hdr = ['icon' => 'system/actionRecycle'];

		if (!$this->resetEnabled())
		{
			$hdr ['info'][] = ['class' => 'title', 'value' => 'Inicializaci databáze nelze provést'];
			$hdr ['info'][] = ['class' => 'info', 'value' => 'Tato databáze je v ostrém provozu a nelze ji znovu inicializovat.'];
			$hdr ['info'][] = ['class' => 'info', 'value' => 'Kontaktujte technickou podporu.'];
		}
		else
		{
			$hdr ['info'][] = ['class' => 'title', 'value' => 'Inicializace databáze'];
			$hdr ['info'][] = ['class' => 'info e10-error', 'value' => 'Pokud budete pokračovat, veškerá data budou SMAZÁNA!'];
			$hdr ['info'][] = ['class' => 'info', 'value' => 'Poté budete moci databázi znovu nastavit.'];
		}

		return $hdr;
	}

	public function resetEnabled ()
	{
		$dsInfo = utils::loadCfgFile('config/dataSourceInfo.json');
		if ($dsInfo === FALSE)
			return FALSE;
		if ($dsInfo['condition'] === 1)
			return FALSE;

		return TRUE;
	}
}
