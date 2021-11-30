<?php

namespace mac\lan;


use e10\Utility;


/**
 * Class LanInfoDownload
 * @package mac\lan
 */
class LanInfoDownload extends Utility
{
	public $result = ['success' => 0];

	public function run ()
	{
		$time = time();

		$lanInfo = ['ranges' => []];

		$rows = $this->app()->db->query ('SELECT ndx FROM [mac_lan_devices] WHERE [deviceKind] = %i', 7, ' AND [nodeSupport] = %i', 1,  ' AND [docState] != %i', 9800);
		foreach ($rows as $r)
		{
			$itemKey = 'lanDevicesInfo_' . $r['ndx'];
			$serverInfo = $this->app->cache->getDataItem($itemKey);
			if (!$serverInfo)
				continue;

			$t = $serverInfo['t'];
			$dt = $time - $t;

			if ($dt > 300)
				continue;
			if (!isset($serverInfo['ranges']))
				continue;

			$lanInfo['ranges'] += $serverInfo['ranges'];
		}

		$this->result ['lanInfo'] = $lanInfo;
		$this->result ['success'] = 1;
	}
}
