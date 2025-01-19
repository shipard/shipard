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
		echo "cliInitialImportCZ \n";

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
