<?php

namespace mac\lan\libs\reports;

use e10\utils;


/**
 * class ReportDevices
 */
class ReportDevices extends \mac\lan\Report
{
	var $data = [];
	var $lanNdx = 0;

	var $devicesKinds;

	var $deviceKind = 0;

	function init ()
	{
		$this->devicesKinds = $this->app()->cfgItem('mac.lan.devices.kinds');

		$this->addParamDevicesKinds();
		parent::init();

		$this->deviceKind = intval($this->reportParams ['deviceKind']['value']);

		if ($this->deviceKind)
		{
			$dk = $this->devicesKinds[$this->deviceKind];

			$this->setInfo('param', 'Druh', $this->reportParams ['deviceKind']['activeTitle']);
			$this->setInfo('icon', $dk['icon'] ?? 'tables/mac.lan.devices');
		}
		else
			$this->setInfo('icon', 'tables/mac.lan.devices');
	}

	function createContent ()
	{
		$this->loadData();

		switch ($this->subReportId)
		{
			case '':
			case 'overview': $this->createContent_Overview(); break;
		}

		$this->setInfo('title', 'Síťová zařízení');
	}

	function createContent_Overview ()
	{
		$h = [
			'#' => '#',
			'id' => 'ID',
			'dn' => 'Zařízení',
			'evNum' => 'Ev. č.',
		];

		if (!$this->deviceKind)
			$h['dk'] = 'Druh';

		$h['place'] = 'Místo';

		$h['rack'] = 'Rack';

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $this->data]);
	}

	public function loadData ()
	{
		$q [] = 'SELECT devices.*, places.fullName as placeFullName, lans.shortName as lanShortName,';

		array_push ($q, ' [racks].fullName AS rackName');
		array_push ($q, ' FROM [mac_lan_devices] as devices');
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON devices.place = places.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON devices.lan = lans.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_racks AS [racks] ON [devices].[rack] = [racks].ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [devices].docState NOT IN %in', [9000, 9800]);

		if ($this->deviceKind)
			array_push ($q, ' AND [devices].[deviceKind] = %i', $this->deviceKind);


		// -- lan
		//if ($this->queryParam ('lan'))
		array_push ($q, ' AND [devices].[lan] = %i', $this->reportParams ['lan']['value']);

		array_push ($q, ' ORDER BY [devices].[id], [devices].[fullName]');

		$rows = $this->app->db()->query($q);

		$lastRangeNdx = -1;

		foreach ($rows as $r)
		{
			$dk = $this->devicesKinds[$r['deviceKind']] ?? NULL;
			$item = [
				'id' => $r['id'],
				'evNum' => $r['evNumber'],
				'dn' => $r['fullName'],
				'dk' => $dk['name'] ?? '',
				'place' => $r['placeFullName'],
				'rack' => $r['rackName'],
			];

			$this->data[]= $item;
		}
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'overview', 'icon' => 'icon-table', 'title' => 'Přehled'];

		return $d;
	}

	protected function addParamDevicesKinds ()
	{
		$enum = [];
		foreach ($this->devicesKinds as $dkId => $dk)
		{
			$enum[$dkId] = $dk['pluralName'];
		}
		$this->addParam('switch', 'deviceKind', ['title' => 'Druh zařízení', 'switch' => $enum]);
	}

}
