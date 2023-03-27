<?php

namespace mac\iot\libs;

use e10\Utility, \Shipard\Utils\Utils, \e10\json, e10\uiutils;
use \mac\iot\libs\IotDeviceCfgUpdater;

/**
 * Class IotDeviceCfgUpdaterZigbee
 */
class IotDeviceCfgUpdaterZigbee extends IotDeviceCfgUpdater
{
	var $deviceInfo = NULL;

	protected function createDataModel()
	{
		$topicBegin = 'zigbee2mqtt';

		if ($this->iotDeviceRecData['nodeServer'])
		{
			$nodeServerRecData = $this->app()->loadItem($this->iotDeviceRecData['nodeServer'], 'mac.lan.devices');
			if ($nodeServerRecData)
			{
				$macDeviceCfg = json_decode($nodeServerRecData['macDeviceCfg'], TRUE);
				if (isset($macDeviceCfg['zigbee2MQTTBaseTopic']) && $macDeviceCfg['zigbee2MQTTBaseTopic'] !== '')
					$topicBegin = $macDeviceCfg['zigbee2MQTTBaseTopic'];
			}
		}

		$topic = $topicBegin.'/'.$this->iotDeviceRecData['friendlyId'];
		$this->dataModel['deviceTopic'] = $topic;

		$this->dataModel['properties'] = [];

		if (!$this->deviceInfo || !isset($this->deviceInfo['definition']['exposes']))
			return;

		foreach ($this->deviceInfo['definition']['exposes'] as $e)
		{
			if (isset($e['features']))
			{
				if (isset($e['type']))
					$this->dataModel['type'] = $e['type'];

				foreach ($e['features'] as $feature)
				{
					$this->createDataModel_DoItem($feature, 'controls');
				}
				continue;
			}

			$this->createDataModel_DoItem($e, 'properties');
		}

		if (isset($this->deviceInfo['definition']['options']))
		{
			$simulatedBrightnessOption = Utils::searchArray($this->deviceInfo['definition']['options'], 'name', 'simulated_brightness');
			if ($simulatedBrightnessOption)
			{
				$this->dataModel['properties']['action_brightness_delta'] = [
					'itemType' => 'action',
					'data-type' => "enum",
					'enum' => ['__changed__value__' => 'Změna hodnoty'],
					'order' => 2,
				];
			}
		}

		$this->dataModel['properties'] = \E10\sortByOneKey($this->dataModel['properties'], 'order', TRUE);
	}

	protected function createDataModel_DoItem($item, $itemType)
	{
		if (!isset($item['name']) || !isset($item['type']))
			return;

		$access = intval($item['access'] ?? 0);
		if (!$access)
			return;

		$order = 0;

		$finalItemType = $itemType;
		if ($access === 7 && $itemType === 'properties')
		{
			$finalItemType = 'settings';
			$order = 500;
		}
		elseif ($access === 1 && $itemType === 'properties' && $item['name'] === 'action')
		{
			$finalItemType = 'action';
			$order = 1;
		}
		elseif ($access === 1 && $itemType === 'properties')
		{
			$finalItemType = 'info';
			$order = 1000;
		}

		$order = $order + count($this->dataModel['properties']);

		$p = [];
		$propertyId = $item['property'];
		if (isset($item['description']))
			$p['description'] = $item['description'];

		$p['itemType'] = $finalItemType;

		if ($item['type'] === 'binary')
		{
			$p['data-type'] = 'binary';
			$p['enumSet'] = [];
			$p['enumGet'] = [];
			if (isset($item['value_on']))
			{
				$p['value-on'] = $item['value_on'];
				$p['enumGet'][$item['value_on']] = ['title' => $item['value_on']];
				$p['enumSet'][$item['value_on']] = ['title' => $item['value_on']];
			}
			if (isset($item['value_on']))
			{
				$p['value-off'] = $item['value_off'];
				$p['enumGet'][$item['value_off']] = ['title' => $item['value_off']];
				$p['enumSet'][$item['value_off']] = ['title' => $item['value_off']];
			}
			if (isset($item['value_toggle']))
			{
				$p['value-toggle'] = $item['value_toggle'];
				$p['enumSet'][$item['value_toggle']] = ['title' => $item['value_toggle']];
			}
		}
		elseif ($item['type'] === 'numeric')
		{
			$p['data-type'] = 'numeric';
			$p['value-min'] = $item['value_min'] ?? 0;
			$p['value-max'] = $item['value_max'] ?? 0;
		}
		elseif ($item['type'] === 'enum')
		{
			$p['data-type'] = 'enum';
			$p['enum'] = [];
			foreach ($item['values'] as $v)
			{
				$p['enum'][$v] = $this->actionEnumItem ($v);//['title' => $v];
			}
		}

		$this->applyDevicePropertyHints($propertyId, $p);

		$p['order'] = $order;

		if (count($p))
			$this->dataModel['properties'][$propertyId] = $p;
	}

	protected function actionEnumItem ($actionId)
	{
		$item = ['title' => $actionId];

		/*
		$hints = [
			'brightness_move_up' => ['loopAction' => 1, 'stopAction' => 'brightness_stop'],
			'brightness_move_down' => ['loopAction' => 1, 'stopAction' => 'brightness_stop'],
			'arrow_left_hold' => ['loopAction' => 1, 'stopAction' => 'arrow_left_release'],
			'arrow_right_hold' => ['loopAction' => 1, 'stopAction' => 'arrow_right_release'],
		];

		if (isset($hints[$actionId]))
			$item = array_merge($item, $hints[$actionId]);
		*/

		return $item;
	}

	protected function applyDevicePropertyHints($propertyId, &$property)
	{
		if ($propertyId === 'temperature' || $propertyId === 'humidity' || $propertyId === 'occupancy' || $propertyId === 'illuminance_lux')
			$this->dataModel['sensors'][] = $propertyId;
	}

	public function update($iotDeviceRecData, &$update)
	{
		parent::update($iotDeviceRecData, $update);

		$this->deviceInfo = json_decode($this->cfgRecData['deviceInfoData'], TRUE);

		$this->createDataModel();
		if ($iotDeviceRecData['deviceTopic'] !== $this->dataModel['deviceTopic'])
			$update['deviceTopic'] = $this->dataModel['deviceTopic'];

		$finalCfg = [
			'dataModel' => $this->dataModel,
		];

		$newDeviceCfg = ['cfgData' => Json::lint($finalCfg), 'cfgDataTimestamp' => new \DateTime()];
		$newDeviceCfg['cfgDataVer'] = sha1($newDeviceCfg['cfgData']);
		if ($newDeviceCfg['cfgDataVer'] !== $this->cfgRecData['cfgDataVer'])
			$this->iotDevicesUtils->setIotDeviceCfg($this->iotDeviceRecData['ndx'], $newDeviceCfg);
	}
}