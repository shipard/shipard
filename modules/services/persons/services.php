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

	public function cliPersonAdd ()
	{
    $e = new \services\persons\libs\PersonData($this->app());

		$debug = $this->app->arg('debug');
		if ($debug)
			$e->debug = 1;

		$personId = $this->app->arg('personId');
		if ($personId != '')
		{
			$e->addPersonFromReg($personId);
		}
		else
		{
			echo "ERROR: no `personNdx` param...\n";
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
		else
		{
			echo "ERROR: no `personNdx` param...\n";
		}

		return TRUE;
	}

	public function cliRefreshImportRES()
	{
		$ip = new \services\persons\libs\cz\InitialImportPersonsCZ($this->app);
		$ip->refreshImportRES();

		return TRUE;
	}

	public function downloadRegsChangeSets()
	{
		$rc = new \services\persons\libs\cz\RegsChangesCZ($this->app());
		$rc->downloadChangeSets();
	}

	public function downloadRegsChangeSetsContents()
	{
		$rc = new \services\persons\libs\cz\RegsChangesCZ($this->app());
		$rc->downloadChangeSetsContents();
	}

	public function prepareRegsChangeItems()
	{
		$rc = new \services\persons\libs\cz\RegsChangesCZ($this->app());
		$rc->prepareChangeSetsItems();
	}

	public function doChangeSetItems($fromFile = 0)
	{
		$maxCount = intval($this->app->arg('maxCount'));
		if (!$maxCount)
			$maxCount = 20;
		$rc = new \services\persons\libs\cz\RegsChangesCZ($this->app());
		if ($fromFile === 1)
		{ // ARES
			$rc->doChangeSetItemsFromFiles($maxCount);
		}
		elseif ($fromFile === 2)
		{ // RES
			$rc->doChangeSetItemsFromRES($maxCount);
		}
		else
		{
			$addOnly = intval($this->app->arg('addOnly'));
			$rc->doChangeSetItems($maxCount, $addOnly);
		}
	}

	public function doChangeSetItemsDone()
	{
		$now = new \DateTime();
		$q = [];
		array_push($q, 'SELECT changes.ndx, cntChanges');
		array_push($q, ' FROM services_persons_regsChanges AS changes');
		array_push($q, ' WHERE (SELECT COUNT(*) FROM services_persons_regsChangesItems WHERE changes.ndx = services_persons_regsChangesItems.regsChangeSet AND done = 1) = cntChanges');
		array_push($q, ' AND changeState != %i', 3);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->db()->query ('UPDATE services_persons_regsChanges SET changeState = %i, ', 3, 'tsDone = %t', $now,
													' WHERE ndx = %i', $r['ndx']);
			if ($this->app()->debug)
				echo "# ".\dibi::$sql."\n";
		}
	}

	protected function onCronMorning()
	{
		$this->downloadRegsChangeSets();
	}

	protected function onCronEver()
	{
		$this->downloadRegsChangeSetsContents();
		$this->prepareRegsChangeItems();
		$this->doChangeSetItemsDone();
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
			case 'person-add': return $this->cliPersonAdd();
			case 'refresh-import-res': return $this->cliRefreshImportRES();
			case 'download-regs-change-sets': return $this->downloadRegsChangeSets();
			case 'download-regs-change-sets-contents': return $this->downloadRegsChangeSetsContents();
			case 'prepare-regs-change-items': return $this->prepareRegsChangeItems();
			case 'do-regs-change-set-items': return $this->doChangeSetItems();
			case 'do-regs-change-set-items-from-file': return $this->doChangeSetItems(1);
			case 'do-regs-change-set-items-from-res': return $this->doChangeSetItems(2);
			case 'do-regs-change-set-items-done': return $this->doChangeSetItemsDone();
		}

		parent::onCliAction($actionId);
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'morning':  $this->onCronMorning(); break;
			case 'ever':   $this->onCronEver(); break;
		}
		return TRUE;
	}
}
