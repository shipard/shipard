<?php

namespace mac\iot\libs;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class IotBoxCfgUpdater
 * @package mac\lan\libs
 */
class ThingCfgUpdater extends Utility
{
	/** @var \mac\iot\TableThings */
	var $tableThings;

	/** @var \mac\lan\TableDevices */
	var $tableDevices;

	/** @var \mac\lan\TableDevicesIOPorts */
	var $tableIOPorts;

	var $formatVersion = '1';

	var $thingRecData = NULL;
	var $cfgRecData = NULL;
	var $thingCfg = NULL;
	var $oldConfigVersion = '';

	var $thingsKinds;
	var $thingsTypes;
	var $thingsItemsTypes;


	public function init()
	{
		$this->tableThings = $this->app()->table('mac.iot.things');
		$this->tableDevices = $this->app()->table('mac.lan.devices');
		$this->tableIOPorts = $this->app()->table('mac.lan.devicesIOPorts');

		$this->thingsKinds = $this->app()->cfgItem('mac.iot.things.kinds');
		$this->thingsTypes = $this->app()->cfgItem('mac.iot.things.types');
		$this->thingsItemsTypes = $this->app()->cfgItem('mac.iot.things.itemsTypes');
	}

	function getThingCfg($thingNdx)
	{
		$exist = $this->db()->query('SELECT * FROM [mac_iot_thingsCfg] WHERE [thing] = %i', $thingNdx)->fetch();
		if ($exist)
		{
			$cfgData = json_decode($exist['thingCfgData'], TRUE);
			if ($cfgData && isset($cfgData['configVersion']))
				$this->oldConfigVersion = $cfgData['configVersion'];
			return $exist->toArray();
		}

		$insert = ['thing' => $thingNdx];
		$this->db()->query('INSERT INTO [mac_iot_thingsCfg] ', $insert);

		$exist = $this->db()->query('SELECT * FROM [mac_iot_thingsCfg] WHERE [thing] = %i', $thingNdx)->fetch();
		if ($exist)
			return $exist->toArray();

		return NULL;
	}

	function updateThingCfg($updateData)
	{
		$this->db()->query('UPDATE [mac_iot_thingsCfg] SET ', $updateData, ' WHERE [ndx] = %i', $this->cfgRecData['ndx']);

		return 1;
	}

	public function updateOne($thingRecData)
	{
		$this->thingRecData = $thingRecData;
		$this->cfgRecData = $this->getThingCfg($thingRecData['ndx']);

		$this->createCfg();

		$cfgDataText = json::lint($this->thingCfg);
		$updateData = ['thingCfgData' => $cfgDataText, 'thingCfgDataVer' => sha1($cfgDataText), 'thingCfgDataTimestamp' => new \DateTime()];
		$this->updateThingCfg($updateData);
	}

	function createCfg()
	{
		$thingKind = $this->app()->cfgItem('mac.iot.things.kinds.'.$this->thingRecData['thingKind']);
		$thingType = $this->app()->cfgItem('mac.iot.things.types.'.$thingKind['thingType']);

		$this->thingCfg = [
			'thingNdx' => $this->thingRecData['ndx'],
			'thingId' => $this->thingRecData['id'],
			'coreType' => $thingType['coreType'],
			'typeId' => $thingType['id'],
			'valuesTopics' => [],
			'items' => [],
			//'thingType' => $this->thingRecData['macDeviceType'],
		];



		$q = [];
		array_push($q, 'SELECT tItems.*, ');
		array_push($q, ' ioPorts.mqttTopic AS ioPortMqttTopic');
		array_push($q, ' FROM [mac_iot_thingsItems] AS tItems');
		array_push($q, ' LEFT JOIN [mac_lan_devicesIOPorts] AS ioPorts ON tItems.ioPort = ioPorts.ndx');
		array_push($q, ' WHERE [thing] = %i', $this->thingRecData['ndx']);
		array_push($q, ' ORDER BY [rowOrder], ndx');

		$itemsRows = $this->db()->query($q);
		foreach ($itemsRows as $ir)
		{
			$thingItemType = $this->thingsItemsTypes[$ir['itemType']];

			$item = [
				'type' => $ir['itemType'],
			];

			if (isset($thingItemType['dir']))
				$item['dir'] = $thingItemType['dir'];

			$ioPortTopic = NULL;

			if ($thingItemType['type'] === 'ioPort')
			{
			}

			if ($thingItemType['type'] === 'camera')
			{
				$item['topic'] = $this->tableIOPorts->mqttTopicBegin().'sensors/'.$this->thingRecData['id'].'/'.'cam'.$ir['camera'];
				$this->thingCfg['items']['values'][$item['topic']] = $item;

				$this->thingCfg['valuesTopics'][] = $item['topic'];
			}
			else
			if ($thingItemType['role'] === 'value')
			{
				$item['topic'] = ($ir['ioPortMqttTopic']) ? $ir['ioPortMqttTopic'] : 'ERROR';

				$this->thingCfg['valuesTopics'][] = $item['topic'];

				$this->thingCfg['items']['values'][$item['topic']] = $item;
			}
			elseif ($thingItemType['role'] === 'open')
			{
				$item['topic'] = ($ir['ioPortMqttTopic']) ? $ir['ioPortMqttTopic'] : 'ERROR';
				$this->thingCfg['items']['open'][] = $item;
			}
			else
			{
				$item['topic'] = ($ir['ioPortMqttTopic']) ? $ir['ioPortMqttTopic'] : 'ERROR';
				$this->thingCfg['items'][$thingItemType['role']][] = $item;
			}
		}

		$ver = md5(json_encode($this->thingCfg));
		$this->thingCfg['configVersion'] = $ver;
	}
}
