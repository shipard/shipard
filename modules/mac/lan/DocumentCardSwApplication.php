<?php

namespace mac\lan;


/**
 * Class DocumentCardSwApplication
 * @package mac\lan
 */
class DocumentCardSwApplication extends \e10\DocumentCard
{
	var $tableDevices;
	var $devices = [];
	var $appLicenses;

	public function createContentHeader ()
	{
		$title = ['icon' => $this->table->tableIcon ($this->recData), 'text' => $this->recData ['fullName']];
		$this->addContent('title', ['type' => 'line', 'line' => $title]);
		//$this->addContent('subTitle', ['type' => 'line', 'line' => '#'.$this->recData ['id']]);
	}

	public function createContent_Devices ()
	{
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
					$device[] = ['text' => $d['lanShortName'], 'icon' => 'icon-sitemap', 'class' => 'e10-small'];

				if ($d['placeFullName'])
					$device[] = ['icon' => 'icon-map-marker', 'text' => $d['placeFullName'], 'class' => 'e10-small'];

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
			$title = [['icon' => 'icon-download', 'text' => 'Nainstalováno na zařízeních']];
			$h = ['#' => '#', 'device' => 'Zařízení'];
			if ($this->recData['license'] === 3)
				$h['license'] = 'Licence';

			$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
					'header' => $h, 'table' => $table, 'title' => $title]);
		}
	}

	public function createContentBody ()
	{
		$this->loadDevices();

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
		array_push ($q, ' WHERE [i2] = %i', $this->recData['ndx']);
		array_push ($q, ' AND properties.[property] = %i', 3, ' AND properties.deleted = 0');
		array_push ($q, ' AND devices.[docStateMain] = %i', 2);
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
		$this->appLicenses = $this->loadSwLicenses ($devicesPks, $this->recData['ndx']);
	}

	public function loadSwLicenses ($devicesPks, $applicationNdx)
	{
		$list = [];
		if (!count($devicesPks))
			return $list;

		$ql[] = 'SELECT docLinks.*, licenses.id as licenseId, licenses.application as appNdx FROM [e10_base_doclinks] AS docLinks ';
		array_push ($ql, ' LEFT JOIN mac_lan_swLicenses AS licenses ON docLinks.srcRecId = licenses.ndx');
		array_push ($ql, ' WHERE srcTableId = %s', 'mac.lan.swLicenses', ' AND dstTableId = %s', 'mac.lan.devices');
		array_push ($ql, ' AND docLinks.linkId = %s', 'e10pro-lan-licenses-devices');
		array_push ($ql, ' AND licenses.application = %i', $applicationNdx);
		array_push ($ql, ' AND dstRecId IN %in', $devicesPks);

		$rows = $this->db()->query ($ql);
		foreach ($rows as $r)
		{
			$listNdx = $r['dstRecId'];
			$li = ['id' => $r['licenseId']];

			$list[$listNdx] = $li;
		}

		return $list;
	}
}
