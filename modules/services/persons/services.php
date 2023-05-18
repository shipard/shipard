<?php

namespace services\persons;
use \Shipard\Utils\Utils;

/**
 * Class ModuleServices
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function cliInitialImportCZ ()
	{
		echo "cliInitialImportCZ \n";

		$ip = new \services\persons\libs\cz\InitialImportPersonsCZ($this->app);

		$companyId = $this->app->arg('companyId');
		if ($companyId)
		{
			$ip->companyId = $companyId;
			$ip->debug = 1;
		}

		$maxCount = intval($this->app->arg('maxCount'));
		if ($maxCount)
		{
			$ip->maxCount = $maxCount;
		}

		$begin = new \DateTime();
		echo "### START: ".$begin->format('Y-m-d H:i:s')."\n";
		$ip->initialImport();
		$end = new \DateTime();
		echo "### END: ".$end->format('Y-m-d H:i:s')."\n";

		$len = Utils::dateDiffShort($begin, $end);
		echo "### TOTAL LEN: ".$len."\n";
		return TRUE;
	}

	public function cliDailyImportCZ()
	{
		$fileName = $this->app->arg('file');
		if (!$fileName)
		{
			echo "Param `--file` not found.\n";
			return FALSE;
		}

		$ip = new \services\persons\libs\cz\InitialImportPersonsCZ($this->app);
		$ip->dailyImport($fileName);

		return TRUE;
	}

	public function cliOnlinePersonRegsDownload ()
	{
		$e = new \services\persons\libs\OnlinePersonRegsDownloadService($this->app);

		$debug = $this->app->arg('debug');
		if ($debug)
			$e->debug = 1;

		$personNdx = intval($this->app->arg('personNdx'));
		if ($personNdx)
		{
			$e->setPersonNdx($personNdx);
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

	public function cliPersonRegsImport ()
	{
		$e = new \services\persons\libs\PersonRegsImportService($this->app);

		$debug = $this->app->arg('debug');
		if ($debug)
			$e->debug = 1;

		$personNdx = intval($this->app->arg('personNdx'));
		if ($personNdx)
		{
			$e->personNdx = $personNdx;
			$e->importOnePerson();
		}
		else
		{
			$maxDuration = intval($this->app->arg('max-duration'));
			if ($maxDuration)
				$e->maxDuration = $maxDuration;

			$e->importBlock();
		}

		return TRUE;
	}

	public function cliPersonRefresh ()
	{
    $e = new \services\persons\libs\PersonData($this->app());

		$debug = $this->app->arg('debug');
		if ($debug)
			$e->debug = 1;

		$personNdx = intval($this->app->arg('personNdx'));
		if ($personNdx)
		{
			$e->personNdx = $personNdx;
			$e->refreshImport($personNdx);
		}

		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'initial-import-cz': return $this->cliInitialImportCZ();
			case 'daily-import-cz': return $this->cliDailyImportCZ();
			case 'online-person-regs-download': return $this->cliOnlinePersonRegsDownload();
			case 'person-regs-import': return $this->cliPersonRegsImport();
			case 'person-refresh': return $this->cliPersonRefresh();
		}

		parent::onCliAction($actionId);
	}
}
