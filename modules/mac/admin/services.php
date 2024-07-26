<?php

namespace mac\admin;
use e10\json, e10\utils;


/**
 * Class ModuleServices
 * @package mac\lan
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	protected function macTokensUpdate()
	{
    $te = new \mac\admin\libs\TokensEngine($this->app());
    $te->run();
	}

	public function dataSourceStatsCreate()
	{
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'mac-tokens-update': return $this->macTokensUpdate();
		}

		parent::onCliAction($actionId);
	}

	function onCronHourly()
	{
		$this->macTokensUpdate();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'hourly': $this->onCronHourly(); break;
		}
		return TRUE;
	}
}
