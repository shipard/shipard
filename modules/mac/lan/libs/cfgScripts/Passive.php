<?php

namespace mac\lan\libs\cfgScripts;

use e10\Utility, \e10\utils;


/**
 * Class Passive
 * @package mac\lan\libs\cfgScripts
 */
class Passive extends \mac\lan\libs\cfgScripts\CoreCfgScript
{
	public function createScript($initMode = FALSE)
	{
		parent::createScript($initMode);
		$this->script = '#';
	}

	function cfgParser()
	{
		return new \mac\lan\libs\cfgScripts\parser\Passive($this->app());
	}
}
