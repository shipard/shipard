<?php

namespace mac\swlan\libs;

use e10\Utility, \e10\utils, \mac\swcore\libs\SWUtils;


/**
 * Class SWInfoLan
 * @package mac\swlan\libs
 */
class SWInfoLan extends Utility
{
	var $swNdx;
	var $onDevices = [];

	/** @var \mac\lan\TableDevices */
	var $tableDevices;
	/** @var \mac\swcore\libs\SWUtils */
	var $swUtils;

	public function init()
	{
		$this->tableDevices = $this->app()->table('mac.lan.devices');
		$this->swUtils = new \mac\swcore\libs\SWUtils($this->app());
	}

	public function setSW($swNdx)
	{
		$this->swNdx = $swNdx;
	}

	function loadDevices()
	{
		$q = [];
		array_push($q, 'SELECT devicesSW.*,');
		array_push($q, ' devices.fullName AS deviceName, devices.id AS deviceId, devices.deviceKind,');
		array_push($q, ' swVersions.versionNumber, swVersions.versionName, swVersions.versionNameShort,');
		array_push($q, ' swVersions.lifeCycle AS versionLifeCycle');
		array_push($q, ' FROM [mac_swlan_devicesSW] AS devicesSW');
		array_push($q, ' LEFT JOIN [mac_lan_devices] AS [devices] ON devicesSW.device = devices.ndx');
		array_push($q, ' LEFT JOIN [mac_sw_swVersions] AS swVersions ON devicesSW.swVersion = swVersions.ndx');
		array_push($q, ' WHERE devicesSW.[sw] = %i', $this->swNdx);
		array_push($q, ' AND devicesSW.[active] = %i', 1);
		array_push($q, ' ORDER BY [devices].[id], [devices].[fullName], devicesSW.ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$deviceInfo = [
				'text' => $r['deviceName'],
				'suffix' => $r['deviceId'],
				'icon' => $this->tableDevices->tableIcon($r),
			];

			$versionLabels = [];

			if ($r['swVersion'])
			{
				$versionInfo = ['class' => '', 'text' => '???'];
				if ($r['versionNameShort'] !== '')
				{
					$versionInfo['text'] = $r['versionNameShort'];
					if ($r['versionName'] !== '')
						$versionInfo['title'] = $r['versionName'];
				}
				elseif ($r['versionName'] !== '')
				{
					$versionInfo['text'] = $r['versionName'];
				}
				elseif ($r['versionNumber'] !== '')
				{
					$versionInfo['text'] = $r['versionNumber'];
				}
				else
				{
					$versionInfo['text'] = '#' . $r['swVersion'];
				}

				$versionLabels[] = $versionInfo;

				if ($r['versionLifeCycle'] !== SWUtils::lcActive && $r['versionLifeCycle'] !== SWUtils::lcUnknown)
				{
					$this->swUtils->lcLabel($r['versionLifeCycle'], $versionLabels);
				}
			}
			else
			{
				$versionLabels[] = ['text' => '???'];
			}

			$item = [
				'device' => $deviceInfo,
				'version' => $versionLabels,
				'date' => utils::datef($r['dateBegin'])
			];

			$this->onDevices[$r['device']] = $item;
		}
	}



	public function run()
	{
		$this->loadDevices();
	}

}