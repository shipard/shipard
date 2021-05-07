<?php

namespace mac\lan\libs;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class GetLanControlConfig
 * @package mac\lan\libs
 */
class GetLanControlConfig extends Utility
{
	public $result = ['success' => 0];
	var $lanCfg = NULL;

	public function run ()
	{
		$serverNdx = intval($this->app->requestPath(4));
		if (!$serverNdx)
			return;

		$lc = new \mac\lan\libs\LanControlCfgUpdater($this->app());
		$serverInfo = $lc->getServerInfo($serverNdx);

		$this->result ['cfg'] = $serverInfo;
		$this->result ['success'] = 1;
	}
}
