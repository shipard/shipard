<?php

namespace mac\lan\libs\alerts;
use e10\Utility, \e10\utils, \e10\json, \mac\lan\libs\alerts\AlertsUtils, mac\swcore\libs\SWUtils;


/**
 * Class UpgradeOS
 * @package mac\lan\libs\alerts
 */
class UpgradeOS extends \mac\lan\libs\alerts\Core
{
	public function init()
	{
		parent::init();
		$this->alertType = AlertsUtils::atOSUpgrade;
	}

	function detectAll()
	{
		$q = [];
		array_push($q, 'SELECT devicesSW.*,');
		array_push($q, ' devices.deviceKind');
		array_push($q, ' FROM [mac_swlan_devicesSW] AS devicesSW');
		array_push($q, ' LEFT JOIN [mac_lan_devices] AS [devices] ON devicesSW.device = devices.ndx');
		array_push($q, ' LEFT JOIN [mac_sw_sw] AS sw ON devicesSW.sw = sw.ndx');
		array_push($q, ' LEFT JOIN [mac_sw_swVersions] AS swVersions ON devicesSW.swVersion = swVersions.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND devicesSW.[active] = %i', 1);
		array_push($q, ' AND sw.swClass = %i', SWUtils::swcOS);
		array_push($q, ' AND swVersions.lifeCycle IN %in', [SWUtils::lcObsolete, SWUtils::lcEnded]);
		array_push($q, ' ORDER BY [devices].[id], [devices].[fullName], devicesSW.ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->addDeviceAlert($r['device'], $r['deviceKind'], AlertsUtils::atOSUpgrade, 0);
		}

		parent::detectAll();
	}
}
