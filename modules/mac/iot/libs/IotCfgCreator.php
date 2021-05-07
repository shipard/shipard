<?php

namespace mac\iot\libs;

use e10\Utility, \e10\utils, \e10\json;
use function E10\searchArray;


/**
 * Class IotCfgCreator
 * @package mac\iot\libs
 */
class IotCfgCreator extends Utility
{
	/** @var \mac\lan\TableDevices */
	var $tableDevices;
	/** @var \mac\lan\TableDevicesIOPorts */
	var $tableIOPorts;

	var $cfg = [];
	var $iotBoxes = NULL;

	public function init()
	{
		$this->tableDevices = $this->app()->table('mac.lan.devices');
		$this->tableIOPorts = $this->app()->table('mac.lan.devicesIOPorts');
	}

	public function setParams($serverNdx, $lanNdx)
	{
	}

	public function createConfig()
	{
		$this->cfg = [
			'version' => 2,
			'engineCfg' => [],
			'things' => [],
		];

		$this->createConfigThings();
		$this->createConfigIotBoxes();
	}

	function createConfigThings()
	{
		$q = [];
		array_push($q, 'SELECT things.*, thingsCfg.thingCfgData, thingsCfg.thingCfgDataVer');
		array_push($q, ' FROM [mac_iot_things] AS [things]');
		array_push($q, ' LEFT JOIN [mac_iot_thingsCfg] AS [thingsCfg] ON things.ndx = thingsCfg.thing');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND things.docState = %i', 4000);
		array_push($q, ' ORDER BY things.ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$cfg = json_decode($r['thingCfgData'], TRUE);
			if (!$cfg)
			{
				continue;
			}

			foreach ($cfg['valuesTopics'] as $valueTopic)
			{
				$this->cfg['engineCfg']['valuesTopics'][$valueTopic] = $r['id'];
			}

			$this->cfg['things'][$r['id']] = $cfg;
		}
	}

	function createConfigIotBoxes()
	{
		if (!$this->iotBoxes)
			return;

		foreach ($this->iotBoxes as $iotBoxNdx => $iotBoxCfg)
		{
			$deviceRecData = $this->tableDevices->loadItem($iotBoxNdx);
			$macDeviceTypeCfg = $this->tableDevices->macDeviceTypeCfg($iotBoxCfg['cfg']['deviceType']);
			if (!isset($macDeviceTypeCfg['things']))
				continue;

			foreach ($macDeviceTypeCfg['things'] as $thingCfg)
			{
				$newThingCfg = [
					'thingNdx' => 0,
					'iotBoxNdx' => $iotBoxNdx,
					'coreType' => $thingCfg['id'],
					'typeId' => $thingCfg['typeId'],
					'items' => [],
				];
				$thingId = $iotBoxNdx.'_'.$deviceRecData['id'];

				//				if (substr($item['topic'], 0, strlen($this->thingCfg['primaryValuesTopic'])) !== $this->thingCfg['primaryValuesTopic'])
//					$this->thingCfg['valuesTopics'][] = $item['topic'];

				foreach ($thingCfg['items'] as $groupId => $group)
				{
					$newThingItem = [];
					foreach ($group as $oneItem)
					{
						$ioPort = NULL;
						if (isset($oneItem['ioPortId']))
							$ioPort = searchArray($iotBoxCfg['cfg']['ioPorts'], 'portId', $oneItem['ioPortId']);
						if ($ioPort)
						{
							if (isset($ioPort['valueTopic']))
								$newThingItem['topic'] = $ioPort['valueTopic'];
							else
								$newThingItem['topic'] = $this->tableIOPorts->mqttTopicBegin().'iot-boxes/'.$deviceRecData['id'].'/'.$ioPort['portId'];

							if (isset($oneItem['valueType']))
								$newThingItem['type'] = $oneItem['valueType'];
						}

						if ($groupId === 'values')
						{
							$newThingCfg['items'][$groupId][$ioPort['valueTopic']] = $newThingItem;
						}
						else
						{
							$newThingCfg['items'][$groupId][] = $newThingItem;
						}
					}
				}

				$this->cfg['things'][$thingId] = $newThingCfg;
			}
		}
	}
}


