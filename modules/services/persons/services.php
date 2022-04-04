<?php

namespace services\persons;


/**
 * Class ModuleServices
 * @package services\subjects
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function cliInitialImportCZ ()
	{
		//$this->installDataPackages();

		echo "cliInitialImportCZ \n";


		$ip = new \services\persons\libs\cz\InitialImportPersonsCZ($this->app);

		$companyId = $this->app->arg('companyId');
		if ($companyId)
		{
			$ip->companyId = $companyId;
			$ip->debug = 1;
		}	
		$ip->initialImport();

		return TRUE;
	}

	public function cliOnlinePersonRegsDownload ()
	{
		$e = new \services\persons\libs\OnlinePersonRegsDownloadService($this->app);

		$debug = $this->app->arg('debug');
		if ($debug)
			$e->debug = 1;

		$countryId = $this->app->arg('country');
		if ($countryId === FALSE)
			$countryId = 'cz';
		$personId = $this->app->arg('personId');
		if ($personId !== FALSE)
		{
			$e->setPersonId($countryId, $personId);
			$e->downloadOnePerson();
		}
		else
		{
			$maxDuration = intval($this->app->arg('max-duration'));
			if ($maxDuration)
				$e->maxDuration = $maxDuration;
			$e->downloadBlock();
		}

		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'initial-import-cz': return $this->cliInitialImportCZ();
			case 'online-person-regs-download': return $this->cliOnlinePersonRegsDownload();
		}

		parent::onCliAction($actionId);
	}
}
