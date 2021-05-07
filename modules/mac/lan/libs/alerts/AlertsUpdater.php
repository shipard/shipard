<?php

namespace mac\lan\libs\alerts;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class AlertsUpdater
 * @package mac\lan\libs\alerts
 */
class AlertsUpdater extends Utility
{
	var $alertsTypes;

	public function init()
	{
		$this->alertsTypes = $this->app()->cfgItem('mac.lan.alerts.types');
	}

	public function runAll()
	{
		$this->db()->begin();
		foreach ($this->alertsTypes as $atNdx => $atCfg)
		{
			if (!isset($atCfg['objectClass']))
				continue;

			/** @var \mac\lan\libs\alerts\Core $o */
			$o = $this->app()->createObject($atCfg['objectClass']);
			if (!$o)
				continue;

			$o->init();
			$o->detectAll();
		}
		$this->db()->commit();
	}
}

