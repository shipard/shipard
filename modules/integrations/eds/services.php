<?php

namespace integrations\eds;


/**
 * class ModuleServices
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	protected function edsCliDownload()
	{
		$setNdx = $this->app->arg('setNdx');
		if (!$setNdx)
		{
			echo "Param `setNdx` not found";
			return FALSE;
		}

		$downloader = new \integrations\eds\libs\EDSDownloader($this->app);
		$downloader->setDataSet($setNdx);
		$downloader->run();

		return TRUE;
	}

	protected function edsAllDownload()
	{
		$now = new \DateTime();
		$q = [];
		array_push($q, 'SELECT [sets].ndx');
		array_push($q, ' FROM [integrations_eds_sets] AS [sets]');
		array_push($q, ' LEFT JOIN [integrations_eds_data] AS [data] ON [data].[edsSet] = [sets].ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [sets].[docState] = %i', 4000);
		array_push($q, ' AND (');
		array_push($q, ' [data].[dateNextUpdate] IS NULL');
		array_push($q, ' OR [data].[dateNextUpdate] <= %t', $now);
		array_push($q, ' )');
		$setsToUpdate = $this->app->db()->query($q);
		foreach ($setsToUpdate as $setRow)
		{
			$setNdx = $setRow['ndx'];
			$downloader = new \integrations\eds\libs\EDSDownloader($this->app);
			$downloader->setDataSet($setNdx);
			$downloader->run();
		}

		return TRUE;
	}

	protected function edsCliPostProcess()
	{
		$setNdx = $this->app->arg('setNdx');
		if (!$setNdx)
		{
			echo "Param `setNdx` not found";
			return FALSE;
		}

		$downloader = new \integrations\eds\libs\EDSDownloader($this->app);
		$downloader->setDataSet($setNdx);
		$downloader->postProcess();

		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'eds-download': return $this->edsCliDownload();
			case 'eds-download-all': return $this->edsAllDownload();
			case 'eds-post-process': return $this->edsCliPostProcess();
		}

		parent::onCliAction($actionId);
	}

	function onCronEver()
	{
		$this->edsAllDownload();
	}

	public function TMP_onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'ever': $this->onCronEver(); break;
		}
		return TRUE;
	}
}
