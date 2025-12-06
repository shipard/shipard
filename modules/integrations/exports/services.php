<?php

namespace integrations\exports;


/**
 * class ModuleServices
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	protected function cliExport()
	{
		$exportNdx = $this->app->arg('exportNdx');
		if (!$exportNdx)
		{
			echo "Param `exportNdx` not found";
			return FALSE;
		}

		$downloader = new \integrations\exports\libs\ExportEngine($this->app);
		$downloader->setExport($exportNdx);
		$downloader->run();

		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'export-data': return $this->cliExport();
		}

		parent::onCliAction($actionId);
	}
}
