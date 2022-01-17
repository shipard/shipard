<?php

namespace mac\iot\libs;

use e10\Utility, \e10\utils, \e10\json, \mac\iot\TableSensors;


/**
 * Class IoTMacStateDataReceiver
 */
class IoTMacStateDataReceiver extends Utility
{
	var $result = ['success' => 0];

	public function run ()
	{
		$data = json_decode($this->app()->postData(), TRUE);
		if (!$data)
			return;

		$serverNdx = intval($data['serverId'] ?? 0);
		if (!$serverNdx)
			return;

		$srv = $this->db()->query('SELECT lan FROM [mac_lan_devices] WHERE [ndx] = %i', $serverNdx)->fetch();
		if (!$srv)
			return;

		$type = $data['type'] ?? '';
		if ($type === '')
			return;

		if ($type === 'set-scene')
		{
			if (!$this->setScene($data))
				return;
		}	
		//	sendToServer({'serverId': serverConfiguration.serverId, 'type': 'set-scene', 'place': placeCfg.ndx, 'scene': sceneCfg.ndx});



		error_log("--MAC--STATE: ".json_encode($data));

		$this->result ['success'] = 1;
	}

	function setScene($data)
	{
		$setupNdx = intval($data['setup'] ?? 0);
		if (!$setupNdx)
			return FALSE;

		$sceneNdx = intval($data['scene'] ?? 0);
		if (!$sceneNdx)
			return FALSE;
	
		$now = new \DateTime();

		$exist = $this->db()->query('SELECT ndx FROM [mac_iot_setupsStates] WHERE [setup] = %i', $setupNdx)->fetch();
		if ($exist)
		{
			$update = ['activeScene' => $sceneNdx, 'activateTime' => $now];
			$this->db()->query('UPDATE [mac_iot_setupsStates] SET ', $update, ' WHERE [ndx] = %i', $exist['ndx']);
		}
		else
		{
			$insert = ['setup' => $setupNdx, 'activeScene' => $sceneNdx, 'activateTime' => $now];
			$this->db()->query('INSERT INTO [mac_iot_setupsStates] ', $insert);
		}

		return TRUE;
	}
}
