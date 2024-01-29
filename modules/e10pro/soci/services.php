<?php

namespace e10pro\soci;

/**
 * class ModuleServices
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function importEntries()
	{
		$fileParam = $this->app()->arg('file');
		if (!$fileParam)
		{
			echo "Missing `--file` param!\n";
			return FALSE;
		}
		$entryToParam = $this->app()->arg('entryTo');
		if (!$entryToParam)
		{
			echo "Missing `--entryTo` param!\n";
			return FALSE;
		}

		$e = new \e10pro\soci\libs\EntriesImport($this->app());
		$e->fileName = $fileParam;
		$e->setEntryTo($entryToParam);
		$e->run();
	}

	public function invoicesFromEntries()
	{
    $ie = new \e10pro\soci\libs\EntriesInvoicingEngine($this->app());
    $ie->init();
		$ie->generateAll();
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'import-entries': return $this->importEntries();
			case 'invoices-from-entries': return $this->invoicesFromEntries();
		}

		parent::onCliAction($actionId);
	}

	public function onCronEver ()
	{
		//$this->sendEntriesEmails();
	}

	public function onStats()
	{
		//$this->dataSourceStatsCreate();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'ever': $this->onCronEver(); break;
			case 'stats': $this->onStats(); break;
		}
		return TRUE;
	}
}
