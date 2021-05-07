<?php

namespace integrations\core;


/**
 * Class ModuleServices
 * @package integrations\core
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	function runAllTasks()
	{
		$ite = new \integrations\services\core\RunTasks($this->app);
		$ite->run();

		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'run-all-tasks': return $this->runAllTasks();
		}

		parent::onCliAction($actionId);
	}

	function onCronEver()
	{
		$this->runAllTasks();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'ever': $this->onCronEver(); break;
		}
		return TRUE;
	}
}
