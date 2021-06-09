<?php

namespace mac\lan;

use \e10\utils;

/**
 * Class DocumentCardUnknownSwPackage
 * @package mac\lan
 */
class DocumentCardUnknownSwPackage extends \e10\DocumentCard
{
	var $tableDevices;
	var $devices = [];

	public function createContentHeader ()
	{
		$title = ['icon' => $this->table->tableIcon ($this->recData), 'text' => $this->recData ['fullName']];
		$this->addContent('title', ['type' => 'line', 'line' => $title]);
		//$this->addContent('subTitle', ['type' => 'line', 'line' => '#'.$this->recData ['id']]);
	}


	public function createContent_Devices ()
	{
		$this->loadDevices();

		$table = [];


		if (count($this->devices))
		{
			foreach ($this->devices as $deviceNdx => $d)
			{
				$deviceRow = [];
				$device = [];
				$device[] = [
					'text' => $d['name'], 'suffix' => $d['id'], 'icon' => $d['icon'], 'class' => 'block',
					'docAction' => 'edit', 'pk' => $d['ndx'], 'table' => 'mac.lan.devices',
				];

				if ($d['lanShortName'])
					$device[] = ['text' => $d['lanShortName'], 'icon' => 'system/iconSitemap', 'class' => 'e10-small'];

				if ($d['placeFullName'])
					$device[] = ['icon' => 'system/iconMapMarker', 'text' => $d['placeFullName'], 'class' => 'e10-small'];

				if ($d['deviceTypeName'])
					$device[] = ['icon' => 'icon-comment-o', 'text' => $d['deviceTypeName'], 'class' => 'e10-small'];

				$deviceRow['device'] = $device;

				$table[] = $deviceRow;
			}
		}

		if (count($table))
		{
			$title = [['icon' => 'system/actionDownload', 'text' => 'Nainstalováno na zařízeních']];
			$h = ['#' => '#', 'device' => 'Zařízení'];
			if ($this->recData['license'] === 3)
				$h['license'] = 'Licence';

			$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
				'header' => $h, 'table' => $table, 'title' => $title]);
		}
	}

	public function createContentBody ()
	{
		$this->createContent_Devices();
	}

	public function createContent ()
	{
		$this->tableDevices = $this->app()->table ('mac.lan.devices');

		$this->createContentHeader ();
		$this->createContentBody ();
	}

	public function loadDevices ()
	{
		$devicesPks = [];
		$q[] = 'SELECT properties.*, devices.fullName as deviceFullName, devices.id as deviceId, devices.deviceKind, devices.deviceTypeName,';
		array_push ($q, ' places.fullName as placeFullName, lans.shortName as lanShortName');
		array_push ($q, ' FROM [mac_lan_devicesProperties] AS properties');
		array_push ($q, ' LEFT JOIN mac_lan_devices AS devices ON properties.device = devices.ndx');
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON devices.place = places.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON devices.lan = lans.ndx');
		array_push ($q, ' WHERE [s1] = %s', $this->recData['s1']);
		array_push ($q, ' AND properties.[property] = %i', 3, ' AND properties.deleted = 0');
		array_push ($q, ' ORDER BY devices.fullName, properties.ndx');
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$deviceNdx = $r['device'];
			if (!isset($this->devices[$deviceNdx]))
			{
				$device = [
					'name' => $r['deviceFullName'], 'ndx' => $deviceNdx, 'id' => $r['deviceId'], 'icon' => $this->tableDevices->tableIcon($r),
					'lanShortName' => $r['lanShortName'], 'placeFullName' => $r['placeFullName'], 'deviceTypeName' => $r['deviceTypeName']
				];
				$this->devices[$deviceNdx] = $device;
				$devicesPks[] = $deviceNdx;
			}
		}
	}
}
