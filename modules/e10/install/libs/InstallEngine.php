<?php

namespace e10\install\libs;

use e10\utils, e10\Utility;


/**
 * Class InstallEngine
 * @package e10\install\libs
 */
class InstallEngine extends Utility
{
	public function checkUpgradeToNewSystem()
	{
		$cntPackages = $this->db()->query ('SELECT COUNT(*) AS cnt FROM [e10_install_packages]')->fetch();
		if ($cntPackages && $cntPackages['cnt'])
			return FALSE;

		$dataPackages = [];
		foreach ($this->app->dataModel->model ['modules'] as $moduleId => $moduleName)
		{
			$module = $this->loadModule($moduleId);
			if ($module === FALSE)
				continue;

			if (!isset ($module['data']))
				continue;

			forEach ($module['data'] as $packageId)
			{
				$this->checkUpgradeToNewSystem_AddPackage ($packageId,$dataPackages);
			}
		}

		foreach ($dataPackages as $packageId)
		{
			$item = [
				'packageId' => $packageId, 'packageVersion' => '0.0.1utns',
				'packageCheckSum' => sha1_file(__SHPD_MODULES_ROOT__.$packageId.'.json')
			];

			$this->db()->query ('INSERT INTO [e10_install_packages] ', $item);
		}

		return TRUE;
	}

	protected function checkUpgradeToNewSystem_AddPackage ($packageId, &$dataPackages)
	{
		if (in_array($packageId, $dataPackages))
			return;

		$dataPackages[] = $packageId;

		$pkgFileName = __SHPD_MODULES_DIR__.$packageId.'.json';
		$pkg = $this->loadCfgFile($pkgFileName);

		if (!isset ($pkg['includes']))
			return;

		foreach ($pkg['includes'] as $includePackageId)
		{
			$this->checkUpgradeToNewSystem_AddPackage($includePackageId, $dataPackages);
		}
	}

	protected function loadModule ($moduleId)
	{
		$moduleFileName = __SHPD_MODULES_DIR__.str_replace('.', '/', $moduleId).'/module.json';
		return utils::loadCfgFile ($moduleFileName);
	}

	public function checkDataPackages()
	{
		foreach ($this->app->dataModel->model ['modules'] as $moduleId => $moduleName)
		{
			$module = $this->loadModule($moduleId);
			if ($module === FALSE)
				continue;

			if (!isset ($module['dataPackages']))
				continue;

			forEach ($module['dataPackages'] as $packageId)
			{
				$this->checkDataPackage($packageId);
			}
		}
	}

	protected function checkDataPackage($packageId)
	{
		$installed = $this->app()->db()->query('SELECT * FROM [e10_install_packages] WHERE [packageId] = %s', $packageId)->fetch();
		if ($installed)
		{

		}
		else
		{
			$installer = new \e10\install\libs\DataPackageInstaller ($this->app());
			$installer->installDataPackage($packageId);
			$installer->cleanUp();
		}
	}
}

