<?php

namespace mac\swlan\libs;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class InfoQueueSanitizer
 * @package mac\swlan\libs
 */
class InfoQueueSanitizer extends Utility
{
	/** @var \mac\swlan\TableInfoQueue */
	var $tableInfoQueue;

	var $recData = NULL;
	var $dataSanitized = [];

	var $enabledKeysSW = [
		'DisplayName', 'Name', 'DisplayVersion', 'Version', 'Publisher', 'URLUpdateInfo', 'URLUpdateInfo', '_saOS'
	];

	var $enabledKeysOS = [
		0 => [ // windows
			'_saOS',
			'WindowsProductName', 'OsName', 'OsType', 'OsVersion', 'OsBuildNumber',
			'WindowsCurrentVersion', 'WindowsBuildLabEx', 'WindowsEditionId',
		],
		2 => [ // linux
			'_saOS',
			'Virtualization', 'Operating System', 'Kernel	Linux', 'Architecture',
			'os_release-name', 'os_release-version_id', 'os_release-version_codename',
			'os_release-version', 'os_release-id', 'os_release-id_like', 'debian-version',
		],
		3 => [ // mikrotik
			'_saOS', 'osName', 'version-os', 'device-arch', 'device-type',
			'platform',
		],
		4 => [ // edgecore
			'_saOS', 'osName', 'version-os', 'device-arch', 'device-type',
			'Loader Version',
		],
		5 => [ // NAS
			'_saOS', 'osName', 'version-os', 'device-arch', 'device-type',
		],
		100 => [ // IoTBox
			'_saOS', 'osName', 'version-os', 'device-arch', 'device-type',
		],
		1000 => [ // ipcams
			'_saOS', 'osName', 'version-os', 'device-type',
		],
	];

	public function init()
	{
		$this->tableInfoQueue = $this->app()->table('mac.swlan.infoQueue');
	}

	protected function doOne($ndx)
	{
		$this->dataSanitized = [];

		$this->recData = $this->tableInfoQueue->loadItem($ndx);
		if (!$this->recData)
			return;

		$dataOriginal = json_decode($this->recData['dataOriginal'], TRUE);
		if (!$dataOriginal)
			return;

		// -- create list
		foreach ($dataOriginal as $key => $value)
		{
			if ($this->recData['osInfo'])
				$this->doOneValueOS($key, $value);
			else
				$this->doOneValueSW($key, $value);
		}

		if (!isset($dataOriginal['_saOS']) && !isset($this->dataSanitized['_saOS']))
			$this->dataSanitized['_saOS'] = 'windows';

		$this->doCleanUpSanitizedData();

		// -- update
		$update = [
			'dataSanitized' => json::lint($this->dataSanitized),
			//'docState' => 1200, 'docStateMain' => 1,
		];
		$update['checksumSanitized'] = sha1($update['dataSanitized']);

		$this->db()->query('UPDATE [mac_swlan_infoQueue] SET ', $update, ' WHERE [ndx] = %i', $this->recData['ndx']);
	}

	protected function doOneValueSW($key, $value)
	{
		if (preg_match('{[A-Z0-9]{8}-([A-Z0-9]{4}-){3}[A-Z0-9]{12}}', strtoupper($value), $matches))
		{
			if (isset($matches[0]))
			{
				if (!isset($this->dataSanitized['uuid']))
					$this->dataSanitized['uuid'][] = $matches[0];
				elseif (!in_array($matches[0], $this->dataSanitized['uuid']))
					$this->dataSanitized['uuid'][] = $matches[0];
			}
		}

		if (!in_array($key, $this->enabledKeysSW))
			return;

		$this->dataSanitized[$key] = $value;
	}

	protected function doOneValueOS($key, $value)
	{
		if (!in_array($key, $this->enabledKeysOS[$this->recData['osFamily']]))
			return;

		$this->dataSanitized[$key] = $value;
	}

	protected function doAll()
	{
		$q = [];
		array_push($q, 'SELECT ndx FROM [mac_swlan_infoQueue] ');
		array_push($q, ' WHERE [docState] = %i', 1000);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->doOne($r['ndx']);
		}
	}

	protected function doCleanUpSanitizedData()
	{
		if ($this->recData['osInfo'])
		{
			$this->dataSanitized['_saInfo'] = 'os';

			if (isset($this->dataSanitized['OsName']) && strstr($this->dataSanitized['OsName'], 'Windows 11'))
			{ // windows 10 / 11
				if (isset($this->dataSanitized['WindowsProductName']))
					$this->dataSanitized['WindowsProductName'] = str_replace('Windows 10', 'Windows 11', $this->dataSanitized['WindowsProductName']);
			}
		}
		else
		{
			$this->dataSanitized['_saInfo'] = 'sw';

			if (isset($this->dataSanitized['Name']) && isset($this->dataSanitized['DisplayName'])
				&& $this->dataSanitized['Name'] === $this->dataSanitized['DisplayName'])
				unset($this->dataSanitized['DisplayName']);

			if (isset($this->dataSanitized['DisplayName']) && !isset($this->dataSanitized['Name']))
			{
				$this->dataSanitized['Name'] = $this->dataSanitized['DisplayName'];
				unset($this->dataSanitized['DisplayName']);
			}

			if (isset($this->dataSanitized['DisplayVersion']) && !isset($this->dataSanitized['Version']))
			{
				$this->dataSanitized['Version'] = $this->dataSanitized['DisplayVersion'];
				unset($this->dataSanitized['DisplayVersion']);
			}

			if (isset($this->dataSanitized['Name']) && isset($this->dataSanitized['Version']))
			{
				$version = $this->dataSanitized['Version'];
				$verPos = strpos($this->dataSanitized['Name'], $version);
				if ($verPos != FALSE)
				{
					$verPosEnd = $verPos + strlen($this->dataSanitized['Version']);
					if (!isset($this->dataSanitized['Name'][$verPosEnd]) ||
						$this->dataSanitized['Name'][$verPosEnd] === ' ' ||
						$this->dataSanitized['Name'][$verPosEnd] === ')')
					{
						$nameClean = substr($this->dataSanitized['Name'], 0, $verPos - 1);
						$nameClean .= substr($this->dataSanitized['Name'], $verPosEnd);
						if (substr($nameClean, -2) === ' )')
							$nameClean = substr($nameClean, 0, -2);

						$nameClean = rtrim($nameClean, " -:");
						$nameClean = str_replace('  ', ' ', $nameClean);

						$this->dataSanitized['NameClean'] = $nameClean;
					}
				}
			}
		}
	}

	public function run()
	{
		$this->doAll();
	}
}
