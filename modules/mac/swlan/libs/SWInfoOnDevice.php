<?php

namespace mac\swlan\libs;

use e10\Utility, \e10\utils, \mac\swcore\libs\SWUtils;
use function E10\sortByOneKey;


/**
 * Class SWInfoOnDevice
 * @package mac\swlan\libs
 */
class SWInfoOnDevice extends Utility
{
	var $deviceNdx;
	var $swList = [];
	var $swInClass = [];
	var $swTable = [];

	/** @var \mac\lan\TableDevices */
	var $tableDevices;
	/** @var \mac\swcore\libs\SWUtils */
	var $swUtils;

	var $cfgSWClass;

	public function init()
	{
		$this->tableDevices = $this->app()->table('mac.lan.devices');
		$this->swUtils = new \mac\swcore\libs\SWUtils($this->app());

		$this->cfgSWClass = $this->app()->cfgItem ('mac.swcore.swClass');
	}

	public function setDevice($deviceNdx)
	{
		$this->deviceNdx = $deviceNdx;
	}

	function loadSW()
	{
		$q = [];
		array_push($q, 'SELECT devicesSW.*,');
		array_push($q, ' sw.fullName AS swFullName, sw.swClass,');
		array_push($q, ' swVersions.versionNumber, swVersions.versionName, swVersions.versionNameShort,');
		array_push($q, ' swVersions.lifeCycle AS versionLifeCycle');
		array_push($q, ' FROM [mac_swlan_devicesSW] AS devicesSW');
		array_push($q, ' LEFT JOIN [mac_sw_sw] AS sw ON devicesSW.sw = sw.ndx');
		array_push($q, ' LEFT JOIN [mac_sw_swVersions] AS swVersions ON devicesSW.swVersion = swVersions.ndx');
		array_push($q, ' WHERE devicesSW.[device] = %i', $this->deviceNdx);
		array_push($q, ' AND devicesSW.[active] = %i', 1);
		array_push($q, ' ORDER BY sw.fullName');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$swInfo = [
				'text' => $r['swFullName'],
				//'suffix' => $r['deviceId'],
				//'icon' => $this->tableDevices->tableIcon($r),
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
				'sw' => $swInfo,
				'version' => $versionLabels,
				'date' => utils::datef($r['dateBegin'])
			];

			$this->swList[$r['sw']] = $item;
			$this->swInClass[$r['swClass']][$r['sw']] = $r['sw'];
		}
	}

	function createTable()
	{
		foreach (sortByOneKey($this->cfgSWClass, 'infoOrder', TRUE) as $swClassNdx => $swClassCfg)
		{
			if (!isset($this->swInClass[$swClassNdx]))
				continue;

			$item = [
				'sw' => ['text' => $swClassCfg['fn'], 'icon' => $swClassCfg['icon']],
				'_options' => [
					'class' => 'subheader', 'colSpan' => ['sw' => 2], 'cellClasses' => ['#' => 'center']]
			];

			if (isset($swClassCfg['icon']))
				$item['#'] = ['text' => '', 'icon' => $swClassCfg['icon']];

			$this->swTable[] = $item;

			$this->addTableSWClass($swClassNdx);
		}
	}

	function addTableSWClass($swClassNdx)
	{
		foreach ($this->swInClass[$swClassNdx] as $swNdx => $swInfo)
		{
			$this->swTable[] = $this->swList[$swNdx];
		}
	}

	public function run()
	{
		$this->loadSW();
		$this->createTable();
	}
}