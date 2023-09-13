<?php

namespace mac\lan\libs\cfgScripts;

use e10\Utility;


/**
 * Class CoreCfgScript
 * @package mac\lan\libs\cfgScripts
 */
class CoreCfgScript extends Utility
{
	var $script = '';
	var $scriptUpgrade = '';

	var $deviceCfg = NULL;
	var $initMode = FALSE;

	var $lanCfg = NULL;
	var $lanDeviceCfg = NULL;
	var $deviceNdx = 0;
	var $deviceRecData = NULL;

	var $cfgData = [];
	var $cfgRunningConfig = NULL;

	public function setDevice($deviceRecData, $lanCfg)
	{
		$this->deviceRecData = $deviceRecData;
		$this->deviceNdx = $deviceRecData['ndx'];
		$this->lanCfg = $lanCfg;

		$this->cfgRunningConfig = NULL;
		$existedData = $this->db()->query('SELECT * FROM [mac_lan_devicesCfgScripts] WHERE [device] = %i', $this->deviceNdx)->fetch();
		if ($existedData)
		{
			$this->cfgRunningConfig = json_decode($existedData['runningData'], TRUE);
		}

		if ($lanCfg)
		{
			if (isset($this->lanCfg['devices'][$this->deviceNdx]))
				$this->lanDeviceCfg = $this->lanCfg['devices'][$this->deviceNdx];

			$this->deviceCfg = json_decode($deviceRecData['macDeviceCfg'], TRUE);
			if (!$this->deviceCfg)
				$this->deviceCfg = [];
		}
	}

	public function createScript($initMode = FALSE)
	{
		$this->initMode = $initMode;
		$this->script = '';
		$this->scriptUpgrade = '';
	}

	function cfgParser()
	{
		return NULL;
	}

	function addAddressListIPs($addressListNdx, &$dst, $addMask = '/32')
	{
		$q[] = 'SELECT [rows].*, [addr].ipAddress ';
		array_push($q,' FROM [mac_lan_ipAddressListsRows] AS [rows]');
		array_push($q,' LEFT JOIN [mac_lan_ipAddress] AS [addr] ON [rows].[address] = [addr].ndx');
		array_push($q,' WHERE [rows].[addressList] = %i', $addressListNdx);
		array_push($q,' AND [addr].[docState] = %i', 4000);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$dst[] = $r['ipAddress'].$addMask;
		}
	}
}
