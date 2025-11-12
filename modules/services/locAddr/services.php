<?php

namespace services\locAddr;
use \Shipard\Utils\Utils;

/**
 * Class ModuleServices
 */
class ModuleServices extends \E10\CLI\ModuleServices
{

	public function cliInitialDownload()
	{
		echo "=== DOWNLOAD FILES===\n";

		$ie = new \services\locAddr\libs\imports\cz\ImportEngineCZ($this->app);
		$ie->init();
		$ie->download();
		return TRUE;
	}

	public function cliInitialImport ()
	{
		echo "### cliInitialImportCZ \n";

		$ie = new \services\locAddr\libs\imports\cz\ImportEngineCZ($this->app);
		$ie->init();

		$begin = new \DateTime();
		echo "### START: ".$begin->format('Y-m-d H:i:s')."\n";
		$ie->importAll();
		$end = new \DateTime();
		echo "### END: ".$end->format('Y-m-d H:i:s')."\n";

		$len = Utils::dateDiffShort($begin, $end);
		echo "### TOTAL LEN: ".$len."\n";
		return TRUE;
	}

	public function cliImportCanceledAddrPlaces()
	{
		$ie = new \services\locAddr\libs\imports\cz\ImportEngineCZ($this->app);
		$ie->init();
		$ie->importCanceledAddrPlaces();
		return TRUE;
	}

	public function cliImportZujPersons()
	{
		$ie = new \services\locAddr\libs\imports\cz\ImportEngineCZ($this->app);
		$ie->init();
		$ie->importZujPersons();
		return TRUE;
	}

	public function cliZUJChecks()
	{
		$ie = new \services\locAddr\libs\imports\cz\ImportEngineCZ($this->app);
		$ie->init();
		$ie->importZujChecks();
		return TRUE;
	}

	public function cliDropTables ()
	{
		$this->app->db()->query('DROP TABLE IF EXISTS [services_locAddr_addrPlaces]');
		$this->app->db()->query('DROP TABLE IF EXISTS [services_locAddr_streets]');
		$this->app->db()->query('DROP TABLE IF EXISTS [services_locAddr_cities]');
		$this->app->db()->query('DROP TABLE IF EXISTS [services_locAddr_citiesParts]');
		$this->app->db()->query('DROP TABLE IF EXISTS [services_locAddr_laUnits]');
		$this->app->db()->query('DROP TABLE IF EXISTS [services_locAddr_zipCodes]');

		return TRUE;
	}

	public function cliExportAdmUnits ()
	{
		$exporter = new \services\locAddr\libs\AdmUnitsExport($this->app);
		$exporter->country = 60;
		$exporter->export();
		return TRUE;
	}


	protected function onCronMorning()
	{
	}

	protected function onCronEver()
	{
	}

	protected function onCronQueue()
	{
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'initial-download': return $this->cliInitialDownload();
			case 'initial-import': return $this->cliInitialImport();
			case 'drop-tables': return $this->cliDropTables();
			case 'export-adm-units': return $this->cliExportAdmUnits();
			case 'import-canceled-addr-places': return $this->cliImportCanceledAddrPlaces();
			case 'import-zuj-persons': return $this->cliImportZujPersons();
			case 'zuj-checks': return $this->cliZUJChecks();
		}

		parent::onCliAction($actionId);
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'morning':  $this->onCronMorning(); break;
			case 'ever':   $this->onCronEver(); break;
			case 'queue':   $this->onCronQueue(); break;
		}
		return TRUE;
	}
}
