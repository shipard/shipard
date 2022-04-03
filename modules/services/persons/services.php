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

	public function cliOnlinePersonUpdate ()
	{
		//$this->installDataPackages();

		$e = new \services\persons\libs\OnlinePersonUpdateEngine($this->app);

		$countryId = $this->app->arg('country');
		if ($countryId === FALSE)
			$countryId = 'cz';
		$personId = $this->app->arg('personId');
		$e->setPersonId($countryId, $personId);
		
		//$e->debug = 1;
		$e->run();

		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'initial-import-cz': return $this->cliInitialImportCZ();
			case 'online-person-update': return $this->cliOnlinePersonUpdate();
		}

		parent::onCliAction($actionId);
	}
}
