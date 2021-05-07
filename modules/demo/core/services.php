<?php

namespace demo\core;


/**
 * Class ModuleServices
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onCronDemo ()
	{
		$generator = new \demo\core\libs\Generator($this->app);
		$generator->run();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'demo': $this->onCronDemo(); break;
		}
		return TRUE;
	}
}
