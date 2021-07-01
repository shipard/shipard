<?php

namespace mac\iot\libs;

use e10\Utility, \e10\utils, \e10\json, \mac\iot\TableSensors;


/**
 * Class IoTSensorsDataReceiver
 * @package mac\iot\libs
 */
class IoTSensorsDataReceiver extends Utility
{
	var $result = ['success' => 0];

	public function run ()
	{
		$data = json_decode($this->app()->postData(), TRUE);
		if (!$data)
			return;

		$serverNdx = intval($data['serverId']);
		if (!$serverNdx)
			return;

		$srv = $this->db()->query('SELECT lan FROM [mac_lan_devices] WHERE [ndx] = %i', $serverNdx)->fetch();
		if (!$srv)
			return;

		$now = new \DateTime();

		foreach ($data['sensorsData'] as $sensorData)
		{
			if (isset($sensorData['ndx']))
			{
				$this->db()->query('UPDATE [mac_iot_sensorsValues] SET [value] = %f', $sensorData['value'],
					', [time] = %t', $now, ', [counter] = [counter] + 1',
					' WHERE [ndx] = %i', $sensorData['ndx']);
				continue;
			}


			$q = [];
			array_push ($q, 'SELECT sensors.ndx');
			array_push ($q, ' FROM [mac_iot_sensors] AS [sensors]');
			array_push ($q, ' WHERE 1');
			array_push ($q, ' AND sensors.srcMqttTopic = %s', $sensorData['topic']);
			array_push ($q, ' AND sensors.docStateMain <= %i', 2);
			array_push ($q, ' AND sensors.srcLan = %i', $srv['lan']);

			$needInsert = 1;
			$rows = $this->db()->query($q);
			foreach ($rows as $r)
			{
				$this->db()->query('UPDATE [mac_iot_sensorsValues] SET [value] = %f', $sensorData['value'],
					', [time] = %t', $now, ', [counter] = [counter] + 1',
					' WHERE [ndx] = %i', $r['ndx']);
				$needInsert = 0;
				break;
			}

			if ($needInsert)
			{
				$newSensor = [
					'valueStyle' => 0,
					'srcLan' => $srv['lan'],
					'srcMqttTopic' => $sensorData['topic'],
					'docState' => 1000, 'docStateMain' => 0,
				];

				/** @var $tableSensors \mac\iot\TableSensors */
				$tableSensors = $this->app()->table('mac.iot.sensors');
				$newSensorNdx = $tableSensors->dbInsertRec($newSensor);
				$tableSensors->docsLog($newSensorNdx);

				$newSensor = ['ndx' => $newSensorNdx, 'value' => $sensorData['value'], 'time' => $now, 'counter' => 1];
				$this->db()->query('INSERT INTO [mac_iot_sensorsValues] ', $newSensor);
			}
		}

		$this->result ['success'] = 1;
	}
}
