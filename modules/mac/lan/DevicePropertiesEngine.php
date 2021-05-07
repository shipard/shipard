<?php

namespace mac\lan;


use E10\Utility, e10\utils, e10\str;


/**
 * Class DevicePropertiesEngine
 * @package mac\lan
 */
class DevicePropertiesEngine extends Utility
{
	CONST ptDeviceName = 1, ptDeviceDescription = 2, ptInstalledSw = 3,
				ptOS = 100, ptOSVersion = 101;

	var $installPackages = NULL;

	protected function swName ($s)
	{
		if ($s[2] !== ':')
			return $s;

		$parts = explode (':', $s);
		if (implode(':', $parts) !== $s)
			return $s;

		$name = '';

		foreach ($parts as $p)
		{
			if ($p === '00')
				continue;
			$name .= hex2bin($p);
		}
		$name = iconv('CP1250', 'UTF-8', $name);
		return $name;
	}

	protected function doSw ($r)
	{
		//echo ("----DO-SW \n");
		$data = json_decode($r['data'], TRUE);
		$founded = [];
		foreach ($data['items'] as $item)
		{
			$pkgName = $this->swName($item['name']);
			$pkgNdx = $this->doSwCheckPackage ($r, $pkgName);
			$founded[] = $pkgNdx;
		}

		// -- set not found as deleted
		$q[] = 'UPDATE mac_lan_devicesProperties SET [deleted] = 1, dateUninstall = NOW()';
		array_push($q, ' WHERE device = %i', $r['device']);
		array_push($q, ' AND [property] = %i', self::ptInstalledSw);
		array_push($q, ' AND [deleted] = 0');
		array_push($q, ' AND [ndx] NOT IN %in', $founded);
		$this->db()->query($q);
	}

	protected function doSwCheckPackage ($r, $pkgName)
	{
		if (1)
		{
			$info = ['property' => self::ptInstalledSw, 's1' => $pkgName, 'source' => 1];
			return $this->setSwPackage ($r['device'], $info);
		}

		return 0;
	}

	protected function setSwPackage ($deviceNdx, $info)
	{
		$info['device'] = $deviceNdx;

		$q[] = 'SELECT * FROM mac_lan_devicesProperties ';
		array_push($q, ' WHERE device = %i', $deviceNdx);
		array_push($q, ' AND [property] = %i', $info['property']);

		if (isset($info['i1']))
			array_push($q, ' AND [i1] = %i', $info['i1']);
		else
			array_push($q, ' AND [s1] = %s', $info['s1']);

		$existed = $this->db()->query ($q)->fetch();
		if ($existed)
		{
			$info['dateUpdate'] = new \DateTime();
			$info['dateCheck'] = new \DateTime();

			$this->db()->query ('UPDATE mac_lan_devicesProperties SET ', $info, ' WHERE ndx = %i', $existed['ndx']);
			$ndx = $existed['ndx'];
		}
		else
		{
			$info['dateCreate'] = new \DateTime();
			$info['dateUpdate'] = new \DateTime();
			$info['dateCheck'] = new \DateTime();

			$this->db()->query ('INSERT INTO mac_lan_devicesProperties', $info);
			$ndx = intval ($this->db()->getInsertId ());
		}

		return $ndx;
	}

	protected function doSystem ($r)
	{
		$data = json_decode($r['data'], TRUE);
		foreach ($data['items'] as $key => $value)
		{
			if ($key === 'desc')
				$this->doSystemDesc($r, $value);
		}
	}

	protected function doSystemDesc ($r, $value)
	{
		if (substr($value, 0, 9) !== 'Hardware:')
			return; // windows only (for now?)

		$swVersionInfo =  strstr($value, 'Software:');
		$swVersionInfoParts = explode (' ', $swVersionInfo);

		$os = 'win';
		$osVersion = '';

		$windowsBuild = '';
		if ($swVersionInfoParts[2] === 'Version')
			$windowsBuild = $swVersionInfoParts[3].'.'.$swVersionInfoParts[5];
		elseif ($swVersionInfoParts[3] === 'Version')
			$windowsBuild = $swVersionInfoParts[4].'.'.$swVersionInfoParts[6];

		switch ($windowsBuild)
		{
			case '5.1.2600' : $osVersion = 'wxp'; break;
			case '6.1.7601' : $osVersion = 'w7'; break;
			case '6.2.9200' : $osVersion = 'w8'; break;
			case '6.3.9600' : $osVersion = 'w81'; break;
			case '6.3.10240':
			case '6.3.10586':
			case '6.3.14393':
			case '6.3.15063':
			case '6.3.16288': $osVersion = 'w10'; break;
		}

		if ($osVersion === '')
			return;

		$info = ['property' => self::ptOS, 'key1' => $os, 'key2' => $osVersion, 'source' => 1];
		$this->setProperty($r['device'], $info);
	}

	protected function setProperty ($deviceNdx, $info)
	{
		$info['device'] = $deviceNdx;
		$existed = $this->db()->query ('SELECT * FROM mac_lan_devicesProperties WHERE device = %i', $deviceNdx, ' AND [property] = %i', $info['property'])->fetch();
		if ($existed)
		{
			$info['dateUpdate'] = new \DateTime();
			$info['dateCheck'] = new \DateTime();

			$this->db()->query ('UPDATE mac_lan_devicesProperties SET ', $info, ' WHERE ndx = %i', $existed['ndx']);
		}
		else
		{
			$info['dateCreate'] = new \DateTime();
			$info['dateUpdate'] = new \DateTime();
			$info['dateCheck'] = new \DateTime();

			$this->db()->query ('INSERT INTO mac_lan_devicesProperties', $info);
		}
	}

	public function doUnchecked ($reset)
	{
		$q[] = 'SELECT * FROM [mac_lan_devicesInfo] WHERE 1';
		if (!$reset)
			array_push($q, ' AND [checked] = 0');
		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			if ($r['infoType'] === 'system')
				$this->doSystem($r);
			elseif ($r['infoType'] === 'sw')
				$this->doSw($r);

			$this->db()->query ('UPDATE [mac_lan_devicesInfo] SET [checked] = 1 WHERE [ndx] = %i', $r['ndx']);
		}
	}

	public function doInstallPackages ($reset = 0)
	{
		if (!$this->installPackages)
			return;

		$ip = utils::loadCfgFile('config/~mac.lan.swInstallPackages.json');
		$this->installPackages = $ip['mac']['lan']['swInstallPackages'];

		$q[] = 'SELECT * FROM [mac_lan_devicesProperties]';
		array_push ($q, '  WHERE [property] = %i', self::ptInstalledSw);
		if (!$reset)
			array_push ($q, '  AND [i1] = %i', 0);

		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$pkg = $this->searchInstallPackage ($r['s1']);
			if ($pkg === FALSE)
			{
				$this->db()->query ('UPDATE [mac_lan_devicesProperties] SET',
						' [i1] = 0, [i2] = 0',
						' WHERE [ndx] = %i', $r['ndx']);
				continue;
			}
			$this->db()->query ('UPDATE [mac_lan_devicesProperties] SET',
					' [i1] = %i,', $pkg['ndx'],
					' [i2] = %i', $pkg['app'],
					' WHERE [ndx] = %i', $r['ndx']);
		}
	}

	public function searchInstallPackage ($name)
	{
		if (!$this->installPackages)
			return FALSE;

		foreach ($this->installPackages as $pkg)
		{
			foreach ($pkg['names'] as $n)
			{
				if (str::substr(str_replace("\0", '', $name), 0, str::strlen($n)) === $n)
				{
					return $pkg;
				}
			}
		}

		return FALSE;
	}
}
