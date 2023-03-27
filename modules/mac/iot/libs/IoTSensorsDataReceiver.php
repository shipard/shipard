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
		$ltz = date_default_timezone_get();
		$localTimezone = new \DateTimeZone($ltz);

		foreach ($data['sensorsData'] as $sensorData)
		{
			$sensorNdx = intval($sensorData['sensorNdx'] ?? 0);
			if ($sensorData['value'] === true)
				$sensorData['value'] = 1;
			elseif ($sensorData['value'] === false)
				$sensorData['value'] = 0;

			if ($sensorNdx)
			{
				$sensorRecData = $this->app()->loadItem($sensorData['sensorNdx'], 'mac.iot.sensors');
				if (!$sensorRecData)
				{
					error_log("__SENSOR `{$sensorData['sensorNdx']}` NOT FOUND ___");
					continue;
				}
				$valueFloat = floatval($sensorData['value']);

				$this->db()->query('UPDATE [mac_iot_sensorsValues] SET [value] = %f', $valueFloat,
					', [time] = %t', $now, ', [counter] = [counter] + 1',
					' WHERE [ndx] = %i', $sensorData['sensorNdx']);

				if ($sensorRecData['saveToDb'])
				{
					$ts = new \DateTime('@'.$sensorData['time'] / 1000);
					$ts->setTimezone($localTimezone);

					$valueChanged = 1;
					$lastValue = $this->db()->query('SELECT * FROM [mac_iot_sensorsValuesHistory] WHERE [sensor] = %i', $sensorData['sensorNdx'],
																					' ORDER BY ndx DESC LIMIT 1')->fetch();
					if ($lastValue && $lastValue['valueNum'] == $valueFloat)
						$valueChanged = 0;

					$valueInt = intval($sensorData['value']);
					$newValue = [
						'sensor' => $sensorData['sensorNdx'],
						'valueNum' => $valueFloat, 'valueInt' => $valueInt,
						'time' => $ts,
						'year' => intval($ts->format('Y')), 'month' => intval($ts->format('m')),
						'day' => intval($ts->format('d')), 'hour' => intval($ts->format('H')),
						'valueChanged' => $valueChanged,
					];

					$this->db()->query('INSERT INTO [mac_iot_sensorsValuesHistory]', $newValue);
				}

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
				if ($sensorData['value'] === '' || $sensorData['value'] === false || $sensorData['value'] === 'nan')
					$sensorData['value'] = 0;
				if (is_string($sensorData['value']))
					$sensorData['value'] = floatval($sensorData['value']);
				$this->db()->query('UPDATE [mac_iot_sensorsValues] SET [value] = %f', $sensorData['value'],
					', [time] = %t', $now, ', [counter] = [counter] + 1',
					' WHERE [ndx] = %i', $r['ndx']);
				$needInsert = 0;
				break;
			}

			if ($needInsert)
			{
				$newSensor = [
					'quantityType' => 0,
					'srcLan' => $srv['lan'],
					'srcMqttTopic' => $sensorData['topic'],
					'docState' => 1000, 'docStateMain' => 0,
				];

				if (str_starts_with($sensorData['topic'], 'shp/sensors/va/cams/'))
				{ // shp/sensors/va/cams/CAM-ID/files-size
					$topicParts = explode('/', $sensorData['topic']);
					$camId = $topicParts[4];
					$valueId = $topicParts[5];

					$existedDevice = $this->db()->query('SELECT * FROM [mac_lan_devices] WHERE [id] = %s', $camId, ' AND [docState] = %i', 4000)->fetch();
					if ($existedDevice)
					{
						$newSensor['device'] = $existedDevice['ndx'];
						if ($existedDevice['place'])
							$newSensor['place'] = $existedDevice['place'];
					}

					if ($valueId === 'files-size')
					{
						$newSensor['fullName'] = 'Velikost video archívu '.$camId;
						$newSensor['shortName'] = 'Velikost video archívu '.$camId;
						$newSensor['idName'] = 'video-archive-files-size-'.$camId;
						$newSensor['quantityType'] = 100;
					}
					elseif ($valueId === 'hourly-files-size')
					{
						$newSensor['fullName'] = 'Hodinová velikost videa '.$camId;
						$newSensor['shortName'] = 'Hodinová velikost videa '.$camId;
						$newSensor['idName'] = 'video-hourly-files-size-'.$camId;
						$newSensor['quantityType'] = 100;
					}
				}

				/** @var \mac\iot\TableSensors $tableSensors */
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
