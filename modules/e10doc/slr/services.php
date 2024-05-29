<?php
namespace e10doc\slr;


/**
 * class ModuleServices
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function cliRunImport()
	{
		$paramImportNdx = intval($this->app->arg('importNdx'));
		if (!$paramImportNdx)
		{
			echo "ERROR: missing param `--importNdx`\n";
			return FALSE;
		}

		$e = new \e10doc\slr\libs\ImportEngine($this->app());
		$e->setImportNdx($paramImportNdx);
		$e->run();
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'run-import': return $this->cliRunImport();
		}

		parent::onCliAction($actionId);
	}
}


