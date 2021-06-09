<?php

namespace mac\lan;


use \e10\utils;

/**
 * Class DocumentCardSwLicense
 * @package mac\lan
 */
class DocumentCardSwPackage extends \e10\DocumentCard
{
	var $tableDevices;
	var $devices = [];

	public function createContentHeader ()
	{
		$title = ['icon' => $this->table->tableIcon ($this->recData), 'text' => $this->recData ['fullName']];
		$this->addContent('title', ['type' => 'line', 'line' => $title]);
		//$this->addContent('subTitle', ['type' => 'line', 'line' => '#'.$this->recData ['id']]);
	}

	public function createContent_Properties ()
	{
		$table = [];

		if ($this->recData['id'] !== '')
			$table[] = ['property' => 'ID', 'value' => $this->recData['id']];

		if ($this->recData['validFrom'] || $this->recData['validTo'])
		{
			$v = '';
			if (!utils::dateIsBlank($this->recData['validFrom']))
				$v .= utils::datef($this->recData['validFrom']);
			$v .= ' → ';
			if (!utils::dateIsBlank($this->recData['validTo']))
				$v .= utils::datef($this->recData['validTo']);
			$table[] = ['property' => 'Platnost', 'value' => $v];
		}

		$table[] = ['property' => 'Názvy', 'value' => ['code' => '<pre>'.utils::es($this->recData['pkgNames']).'</pre>']];


		$h = ['property' => '_Vlastnost', 'value' => 'Hodnota'];
		$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'header' => $h, 'table' => $table,
			'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']]);
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

				$deviceRow['device'] = $device;

				if ($this->recData['license'] === 3) // commercial
				{
					if (isset($this->appLicenses[$deviceNdx]))
					{
						$li = ['text' => $this->appLicenses[$deviceNdx]['id'], 'icon' => 'icon-certificate'];
						$deviceRow['_options'] = ['class' => 'e10-row-plus'];
					}
					else
					{
						$li = ['text' => 'chybí', 'icon' => 'system/iconWarning'];
						$deviceRow['_options'] = ['class' => 'e10-warning1'];
					}
					$deviceRow['license'] = $li;
				}

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
		$this->createContent_Properties();
		$this->createContent_Devices();
		$this->addContentAttachments ($this->recData ['ndx']);
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
		$q[] = 'SELECT properties.*, devices.fullName as deviceFullName, devices.id as deviceId, devices.deviceKind, ';
		array_push ($q, ' places.fullName as placeFullName, lans.shortName as lanShortName');
		array_push ($q, ' FROM [mac_lan_devicesProperties] AS properties');
		array_push ($q, ' LEFT JOIN mac_lan_devices AS devices ON properties.device = devices.ndx');
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON devices.place = places.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON devices.lan = lans.ndx');
		array_push ($q, ' WHERE [i1] = %i', $this->recData['ndx']);
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
					'lanShortName' => $r['lanShortName'], 'placeFullName' => $r['placeFullName']
				];
				$this->devices[$deviceNdx] = $device;
				$devicesPks[] = $deviceNdx;
			}
		}
		//$this->appLicenses = $this->loadSwLicenses ($devicesPks, $this->recData['ndx']);
	}
}
