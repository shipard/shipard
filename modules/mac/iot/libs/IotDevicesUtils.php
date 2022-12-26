<?php

namespace mac\iot\libs;

use \Shipard\Base\Utility, \Shipard\Utils\Utils, \Shipard\Utils\json;


/**
 * Class ZigbeeLogAnalyzer
 */
class IotDevicesUtils extends Utility
{
	public function searchVendor ($deviceType, $vendorId)
	{
		$vendors = $this->app()->cfgItem('mac.iot.devices.vendors.'.$deviceType, NULL);
		if (!$vendors)
			return NULL;

		$vendorId2 = \strtolower($vendorId);
		if (isset($vendors[$vendorId2]))
		{
			$v = $vendors[$vendorId2];
			$v['id'] = $vendorId2;
			return $v;
		}

		return NULL;
	}

	public function models($deviceType, $deviceVendor)
	{
		$fn = __SHPD_MODULES_DIR__.'/mac/iot/config/devices/'.$deviceType.'/'.$deviceVendor.'/_models.json';
		if (!is_readable($fn))
			return NULL;

		$models = json_decode(file_get_contents($fn), TRUE);
		if (!$models)
			return NULL;

		return $models;
	}

	public function searchModel ($deviceType, $deviceVendor, $modelId1, $modelId2 = '')
	{
		$models = $this->models($deviceType, $deviceVendor);
		if (!$models)
			return NULL;

		$model = $models[$modelId1] ?? $models[$modelId2] ?? NULL;
		if (!$model)
			return NULL;

		$model['id'] = $modelId1;

		return $model;
	}

	function getIotDeviceCfg($deviceNdx)
	{
		if (!$deviceNdx)
			return NULL;

		$exist = $this->db()->query('SELECT * FROM [mac_iot_devicesCfg] WHERE [iotDevice] = %i', $deviceNdx)->fetch();
		if ($exist)
		{
			//$cfgData = json_decode($exist['cfgData'], TRUE);
			return $exist->toArray();
		}

		$insert = ['iotDevice' => $deviceNdx];
		$this->db()->query('INSERT INTO [mac_iot_devicesCfg] ', $insert);

		$newData = $this->getIotDeviceCfg($deviceNdx);
		if ($newData)
			return $newData;

		return NULL;
	}

	public function setIotDeviceCfg($deviceNdx, $updateData)
	{
		if (!$deviceNdx)
			return;
		$this->db()->query('UPDATE [mac_iot_devicesCfg] SET ', $updateData, ' WHERE [ndx] = %i', $deviceNdx);
	}

	public function deviceDataModel($deviceNdx)
	{
		if (!$deviceNdx)
			return NULL;

		$cfgDataRec = $this->getIotDeviceCfg($deviceNdx);
		$cfgData = json_decode($cfgDataRec['cfgData'], TRUE);
		if (!$cfgData)
			$cfgData = [];
		return $cfgData['dataModel'] ?? NULL;
	}

	public function deviceEvents($deviceNdx, $eventType)
	{
		$dataModel = $this->deviceDataModel($deviceNdx);

		$events = [];

		if (!$dataModel || !isset($dataModel['properties']))
			return $events;


		foreach ($dataModel['properties'] as $pid => $p)
		{
			if (isset($p['eventType']) && $p['eventType'] !== $eventType)
				continue;

			$events[$pid] = $p;
		}

		return $events;
	}

	public function deviceProperties($deviceNdx)
	{
		$dataModel = $this->deviceDataModel($deviceNdx);

		$events = [];

		if (!$dataModel || !isset($dataModel['properties']))
			return $events;

		foreach ($dataModel['properties'] as $pid => $p)
		{
			if (isset($p['itemType']) && $p['itemType'] !== 'controls')
				continue;

			$events[$pid] = $p;
		}

		return $events;
	}

	public function devicesGroupProperties($devicesGroupNdx)
	{
		$events = [];
		$devices = $this->db()->query('SELECT * FROM [mac_iot_devicesGroupsItems] WHERE devicesGroup = %i', $devicesGroupNdx);

		foreach ($devices as $d)
		{
			$deviceNdx = $d['iotDevice'];
			$dataModel = $this->deviceDataModel($deviceNdx);

			if (!$dataModel || !isset($dataModel['properties']))
				continue;

			foreach ($dataModel['properties'] as $pid => $p)
			{
				if (isset($p['itemType']) && $p['itemType'] !== 'controls')
					continue;
				if (in_array($pid, $events))
					continue;

				$events[$pid] = $p;
			}
		}
		return $events;
	}


	public function deviceProperty($deviceNdx, $propertyId)
	{
		$properties = $this->deviceProperties($deviceNdx);

		if (!$properties)
			return NULL;

		return $properties[$propertyId] ?? NULL;
	}

	public function deviceSetPropertyValue ($eventRecData, $deviceProperty)
	{
		$enumSetValue = $deviceProperty['enumSet'][$eventRecData['iotDevicePropertyValueEnum']] ?? NULL;

		if (isset($deviceProperty['valueClass']))
		{
			$o = $this->app()->createObject($deviceProperty['valueClass']);
			if ($o)
			{
				$value = $o->enumValue ($eventRecData, $deviceProperty, $enumSetValue);
				if ($value !== '')
					return $value;
			}
		}

		if ($enumSetValue && isset($enumSetValue['value']))
			return $enumSetValue['value'];
		else
			return $eventRecData['iotDevicePropertyValueEnum'];
	}

	public function deviceModel($deviceRecData)
	{
		$models = $this->models($deviceRecData['deviceType'], $deviceRecData['deviceVendor']);
		if (isset($models[$deviceRecData['deviceModel']]))
			return $models[$deviceRecData['deviceModel']];

		return NULL;
	}

	public function sceneTopic($sceneNdx)
	{
		$r = $this->app()->loadItem($sceneNdx, 'mac.iot.scenes');
		$topic = 'shp/scenes/';
		if ($r)
		{
			$topic .= $r['friendlyId'] === '' ? 'scene'.$r['ndx'] : $r['friendlyId'];
		}
		else
			$topic .= '---UNKOWN-SCENE-'.$sceneNdx.'---';

		return $topic;
	}

	public function placeTopic($placeNdx)
	{
		$r = $this->app()->loadItem($placeNdx, 'e10.base.places');
		$topic = 'shp/places/';
		if ($r)
		{
			$topic .= $r['id'] === '' ? 'place'.$r['ndx'] : $r['id'];
		}
		else
			$topic .= '---UNKOWN-PLACE-'.$placeNdx.'---';

		return $topic;
	}

	public function iotSetupTopic($setupNdx)
	{
		$r = $this->app()->loadItem($setupNdx, 'mac.iot.setups');
		$topic = 'shp/setups/';
		if ($r)
		{
			$topic .= $r['id'] === '' ? 'setup'.$r['ndx'] : $r['id'];
		}
		else
			$topic .= '---UNKOWN-SETUP-'.$setupNdx.'---';

		return $topic;
	}

	public function iotSetupActions($setupNdx)
	{
		$setup = $this->app()->loadItem($setupNdx, 'mac.iot.setups');

		$setupType = $this->app()->cfgItem('mac.iot.setups.types.'.$setup['setupType'], NULL);
		if ($setupType && isset($setupType['actions']))
			return $setupType['actions'];

		return [];
	}

	public function iotSetupRequests($iotSetupNdx)
	{
		if (!$iotSetupNdx)
			return [];

		$setup = $this->app()->loadItem($iotSetupNdx, 'mac.iot.setups');
		if ($setup)
		{
			$setupType = $this->app()->cfgItem('mac.iot.setups.types.'.$setup['setupType'], NULL);
			if ($setupType && isset($setupType['requests']))
				return $setupType['requests'];
		}

		return [];
	}
}