<?php

namespace integrations\hooks\in;


/**
 * Class ModuleServices
 * @package integrations\hooks\in
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	function runAllHooks()
	{
		$ite = new \integrations\hooks\in\services\RunHooks($this->app);
		$ite->run();

		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'run-all-hooks': return $this->runAllHooks();
		}

		parent::onCliAction($actionId);
	}
}
