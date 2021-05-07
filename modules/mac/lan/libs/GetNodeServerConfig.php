<?php

namespace mac\lan\libs;

use e10\Utility;


/**
 * Class GetNodeServerConfig
 * @package mac\lan
 */
class GetNodeServerConfig extends Utility
{
	public $result = ['success' => 0];

	public function run ()
	{
		$serverNdx = intval($this->app->requestPath(4));
		$cfg = $this->db()->query ('SELECT * FROM [mac_lan_devicesCfgNodes] WHERE device = %i', $serverNdx)->fetch();

		if ($cfg)
		{
			$this->result ['cfg'] = ['ver' => $cfg['newDataVer'],'cfg' => json_decode($cfg['newData'], TRUE)];
			$this->result ['success'] = 1;

			$this->db()->query('UPDATE [mac_lan_devicesCfgNodes] SET runningData = newData, runningDataVer = newDataVer,',
				' runningTimestamp = NOW(), applyNewData = 0',
				' WHERE [ndx] = %i', $cfg['ndx']);
		}
	}
}
