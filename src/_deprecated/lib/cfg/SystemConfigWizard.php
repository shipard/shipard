<?php

namespace lib\cfg;
use E10\utils, E10\TableForm, E10\FormAppOptions;


/**
 * Class SystemConfigWizard
 * @package lib\cfg
 */
class SystemConfigWizard extends \E10\Wizard
{
	protected $tableAppOptions;
	var $initConfig;
	var $installModule;
	var $demoData = [];
	var $installEnum = [];

	public function loadDemo ()
	{
		$this->installEnum ['none'] = 'Nastavit databázi pro ostrý provoz';

		if (is_file (__APP_DIR__ . '/config/createApp.json'))
		{
			$cfgString = file_get_contents ('config/createApp.json');
			$this->initConfig = json_decode ($cfgString, TRUE);
		}
		else
			$this->initConfig = NULL;

		if (!$this->initConfig)
			return;

		if (str_starts_with ($this->initConfig['installModule'], 'pkgs.'))
			$this->initConfig['installModule'] = substr($this->initConfig['installModule'], 5); // TODO: remove in new hosting

		$installModuleId = str_replace ('.', '/', $this->initConfig['installModule']);
		$installModulePath = __SHPD_MODULES_DIR__ . $installModuleId;
		$cfgString = file_get_contents ($installModulePath . '/' . 'module.json');
		if (!$cfgString)
			return;

		$this->installModule = json_decode ($cfgString, TRUE);
		if (!isset ($this->installModule['demoData']))
			return;

		foreach ($this->installModule['demoData'] as $ddId => $dd)
		{
			$this->demoData[$ddId] = $dd;
			$this->installEnum[$ddId] = $dd['name'];
			if (!isset($this->recData['installType']))
				$this->recData['installType'] = $ddId;
		}
	}

	public function doneStep ()
	{
		if (!isset ($this->recData['installType']) || $this->recData['installType'] !== 'none')
			return 1;
		return 2;
	}

	public function doStep ()
	{
		if ($this->pageNumber === $this->doneStep ())
		{
			$this->saveDocument();
			$this->stepResult ['restartApp'] = 1;
			$this->stepResult['lastStep'] = 1;
		}
	}

	public function renderForm ()
	{
		if ($this->pageNumber === 0)
			$this->renderFormWelcome ();
		else
			if ($this->pageNumber === $this->doneStep())
				$this->renderFormDone ();
			else
				$this->renderFormAppConfig();
	}

	public function renderFormWelcome ()
	{
		$this->loadDemo();

		if (!isset($this->recData['installType']))
			$this->recData['installType'] = 'none';

		$this->setFlag ('formStyle', 'e10-formStyleWizard');

		$this->openForm (TableForm::ltVertical);
		$this->addInputEnum2 ('installType', 'Způsob nastavení databáze', $this->installEnum);
		$this->closeForm ();
	}

	public function renderFormAppConfig ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleWizard');

		$this->openForm ();

		$appOptions = $this->app()->cfgItem ('appOptions');
		foreach ($appOptions as $i1 => $o1)
		{
			if (!isset ($o1['options']))
				continue;
			foreach ($o1['options'] as $i2 => $o2)
			{
				if (!isset ($o2['importance']))
					continue;
				if ($this->app()->cfgItem ("options.$i1.{$o2['cfgKey']}", NULL) === NULL  ||
					(isset($o2['unset']) && $appOptions['options'][$i1][$o2['cfgKey']] == $o2['unset']))
				{
					$prefixKey = $i1.'_';

					if (isset ($o2 ['options']) && !isset ($this->recData[$prefixKey.$o2 ['cfgKey']]))
						$this->recData[$prefixKey.$o2 ['cfgKey']] = key($o2 ['options']);

					FormAppOptions::addCfgInput ($this, $o2, $prefixKey, FALSE);
				}
			}
		}

		$this->closeForm ();
	}

	protected function saveDocument ()
	{
		if ($this->doneStep() === 1)
			$this->installDemoData();
		else
			$this->saveCfg();
	}

	protected function saveCfg ()
	{
		$this->tableAppOptions = new \E10\TblAppOptions ($this->app());

		foreach ($this->recData as $cfgId => $cfgValue)
		{
			if ($cfgId === 'uploadedFiles')
				continue;

			$cfgKeyParts = explode ('_', $cfgId);

			$o = $this->app()->cfgItem ('appOptions.'.$cfgKeyParts[0]);
			$fileName = $this->tableAppOptions->appOptionFileName ($cfgKeyParts[0], $o);

			$cfg = utils::loadCfgFile($fileName);
			if ($cfg === FALSE)
				$cfg = [];

			$cfg[$cfgKeyParts[1]] = $cfgValue;

			file_put_contents($fileName, utils::json_lint (json_encode ($cfg)));
			chmod($fileName, 0660);
		}

		$this->updateConfiguration ();
	}

	protected function installDemoData ()
	{
		utils::setAppStatus ('DEMO');
		utils::dsCmd($this->app(), 'installDemoData', ['type' => $this->recData['installType']]);
	}

	public function installDataPackage ($packageId)
	{
		$pkgFileName = __APP_DIR__.'/e10-modules/'.$packageId.'.json';
		$installer = new \lib\DataPackageInstaller ($this->app);
		$installer->setFileName($pkgFileName);
		$installer->run();
	}

	public function updateConfiguration ()
	{
		\E10\updateConfiguration($this->app());
		$this->app()->loadConfig();
		\E10\updateConfiguration($this->app());

		$wd = __APP_DIR__;
		exec ("cd $wd && e10-app moduleService --service=appUpgrade");
	}

	public function createHeader ()
	{
		$hdr = ['icon' => 'icon-magic'];

		switch ($this->pageNumber)
		{
			case 0:
				$hdr ['info'][] = ['class' => 'title', 'value' => 'Vyberte, jak bude databáze nastavena'];
				$hdr ['info'][] = ['class' => 'info', 'value' => 'Pokud se chcete s aplikací seznámit, zvolte demonstrační data.'];
				$hdr ['info'][] = ['class' => 'info', 'value' => 'Později budete moci databázi znovu zinicializovat pro ostrý provoz.'];
				break;
			case 1:
				$hdr ['info'][] = ['class' => 'title', 'value' => 'Úvodní nastavení aplikace'];
				$hdr ['info'][] = ['class' => 'info', 'value' => 'Aby všechno správně fungovalo, musíte zadat některé důležité informace'];
				$hdr ['info'][] = ['class' => 'info', 'value' => ''];
				break;
		}

		return $hdr;
	}
}
