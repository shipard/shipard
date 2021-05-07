<?php

namespace mac\lan\libs\alerts;
use e10\Utility, \e10\utils, \e10\json, \mac\lan\libs\alerts\AlertsUtils;



/**
 * Class WatchdogTimeout
 * @package mac\lan\libs\alerts
 */
class WatchdogTimeout extends \mac\lan\libs\alerts\Core
{
	var $watchdogs;

	public function init()
	{
		parent::init();
		$this->alertType = AlertsUtils::atWatchdogTimeout;
		$this->watchdogs = $this->app()->cfgItem('mac.lan.watchdogs');
	}

	function detectAll()
	{
		foreach ($this->watchdogs as $wdId => $wdCfg)
		{
			$limit = clone $this->now;
			$limit->sub(new \DateInterval('PT2M'));

			$q = [];
			array_push($q, 'SELECT [wd].*, devices.fullName AS deviceName, devices.id AS deviceId, devices.deviceKind AS deviceKind');
			array_push($q, ' FROM [mac_lan_watchdogs] AS [wd]');
			array_push($q, ' LEFT JOIN mac_lan_devices AS devices ON wd.device = devices.ndx');
			array_push($q, ' WHERE 1');
			array_push ($q, ' AND [wd].[watchdog] = %s', $wdId);
			array_push ($q, ' AND [wd].[time1] < %t', $limit);

			$rows = $this->db()->query($q);
			foreach ($rows as $r)
			{
				$deviceKind = $r['deviceKind'];
				$dk = $this->devicesKinds[$deviceKind];
				$scope = $dk['alertsScope'];
				$timeout = isset($wdCfg['timeouts'][$scope]) ? $wdCfg['timeouts'][$scope] : 0;
				if (!$timeout)
					continue;

				$time1Limit = new \DateTime($timeout.' minutes ago');
				if ($r['time1'] < $time1Limit)
				{
					$this->addDeviceAlert($r['device'], $r['deviceKind'], AlertsUtils::atWatchdogTimeout, $wdCfg['ndx']);
				}
			}
		}

		parent::detectAll();
	}
}
