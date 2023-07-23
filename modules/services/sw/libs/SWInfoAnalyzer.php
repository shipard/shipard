<?php

namespace services\sw\libs;

use \e10\utils, \e10\json, \e10\Utility, \mac\swcore\libs\SWUtils;


/**
 * Class SWInfoAnalyzer
 * @package services\sw\libs
 */
class SWInfoAnalyzer extends Utility
{
	var $srcData = NULL;
	var $protocol = [];
	var $error = FALSE;

	/** @var \mac\swcore\libs\SWUtils */
	var $swUtils;

	var $saInfo = '';

	// -- os
	var $osFamily;
	var $osFamilyCfg;
	var $osName = '';
	var $osVersion = '';

	// -- sw
	var $swName = '';
	var $swIDs = NULL;
	var $swVersion = '';

	// -- result
	var $swNdx = 0;
	var $swSUID = '';
	var $swVersionNdx = 0;
	var $swVersionSUID = '';

	public function setSrcData($srcData)
	{
		$this->srcData = $srcData;
	}

	function addProtocol($id, $data)
	{
		$this->protocol[] = ['id' => $id, 'data' => $data];
	}

	function checkCoreType()
	{
		if (!isset($this->srcData['_saInfo']))
		{
			$this->addProtocol('check-saInfo', 'No `_saInfo` found');
			$this->error = TRUE;
			return;
		}

		if ($this->srcData['_saInfo'] === 'sw')
		{
			$this->saInfo = 'sw';
			$this->addProtocol('check-saInfo', 'saInfo is sw (sofware)');
		}
		elseif ($this->srcData['_saInfo'] === 'os')
		{
			$this->saInfo = 'os';
			$this->addProtocol('check-saInfo', 'saInfo is os (operating system)');
		}
		else
		{
			$this->addProtocol('check-saInfo', "Invalid `_saInfo` value `{$this->srcData['_saInfo']}`");
			$this->error = TRUE;
			return;
		}
	}

	function checkOS()
	{
		$this->checkOSCore();
		if ($this->error)
			return;
		$this->checkOSLoad();
	}

	function checkOSCore()
	{
		if (!$this->srcData['_saOS'])
		{
			$this->addProtocol('check-saOS', 'No `_saOS` found');
			$this->error = TRUE;
			return;
		}

		$this->osFamily = $this->swUtils->osFamily($this->srcData['_saOS']);
		if ($this->osFamily === SWUtils::osfError)
		{
			$this->addProtocol('check-saOS', "Invalid `_saOS` value `{$this->srcData['_saOS']}`; no OS found");
			$this->error = TRUE;
			return;
		}

		$this->osFamilyCfg = $this->swUtils->osFamilyCfg($this->srcData['_saOS']);
		$this->addProtocol('check-saOS', "OS is `{$this->osFamilyCfg['sn']}`");

		if ($this->osFamily === SWUtils::osfLinux)
		{
			if (!isset($this->srcData['os_release-name']))
			{
				$this->addProtocol('check-saOS', "Missing `os_release-name` value; detect OS name failed");
				$this->error = TRUE;
				return;
			}
			$this->osName = $this->srcData['os_release-name'];

			if (!isset($this->srcData['os_release-version']))
			{
				$this->addProtocol('check-saOS', "Missing `os_release-version` value; detect OS version failed");
				$this->error = TRUE;
				return;
			}
			$this->osVersion = $this->srcData['os_release-version'];
		}
		elseif ($this->osFamily === SWUtils::osfWindows)
		{
			if (!isset($this->srcData['WindowsProductName']))
			{
				$this->addProtocol('check-saOS', "Missing `WindowsProductName` value; detect OS name failed");
				$this->error = TRUE;
				return;
			}
			$this->osName = $this->srcData['WindowsProductName'];

			if (!isset($this->srcData['OsVersion']) && !isset($this->srcData['WindowsBuildLabEx']))
			{
				$this->addProtocol('check-saOS', "Missing `OsVersion` or `WindowsBuildLabEx` value; detect OS version failed");
				$this->error = TRUE;
				return;
			}
			if (isset($this->srcData['OsVersion']))
				$this->osVersion = $this->srcData['OsVersion'];
			else
			{
				$pointPos = strpos($this->srcData['WindowsBuildLabEx'], '.');
				if ($pointPos)
					$this->osVersion = '10.0.'.substr($this->srcData['WindowsBuildLabEx'], 0, $pointPos);
				else
					$this->osVersion = $this->srcData['WindowsBuildLabEx'];
			}
		}
		elseif ($this->osFamily === SWUtils::osfSynology)
		{
			if (str_starts_with($this->srcData['osName'], 'DSM '))
			{
				$this->osName = 'DSM';
				$this->osVersion = trim(substr($this->srcData['osName'], 4));
			}
		}
		elseif(isset($this->srcData['osName']) && isset($this->srcData['version-os']))
		{
			$this->osName = $this->srcData['osName'];
			$this->osVersion = $this->srcData['version-os'];
		}
		else
		{
			$this->addProtocol('check-saOS', "OS family `{$this->srcData['_saOS']}` is not supported");
			$this->error = TRUE;
			return;
		}

		$this->addProtocol('check-saOS', "OS name is `{$this->osName}`");
		$this->addProtocol('check-saOS', "OS version is `{$this->osVersion}`");

	}

	function checkOSLoad()
	{
		$q = [];
		array_push($q, 'SELECT * FROM [mac_sw_sw] AS [software]');
		array_push($q, ' WHERE [software].[swClass] = %i', SWUtils::swcOS);
		array_push($q, ' AND [software].[osFamily] = %i', $this->osFamily);
		array_push($q, ' AND (');
		array_push($q, ' [software].[fullName] = %s', $this->osName);
		array_push($q, ' OR EXISTS (SELECT ndx FROM mac_sw_swNames WHERE [software].ndx = [sw] AND [name] = %s', $this->osName, ')');
		array_push($q, ')');
		array_push($q, ' AND [software].[docState] = %i', 4000);

		$cnt = 0;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->addProtocol('search-os', "Found `{$r['fullName']}` (#{$r['ndx']} / {$r['suid']})");
			$cnt++;
		}

		if ($cnt > 1)
		{
			$this->addProtocol('search-os', "Multiple OS found; FAILED");
			$this->error = TRUE;
			return;
		}
		elseif ($cnt === 0)
		{
			$this->addProtocol('search-os', "No OS found");
			$this->error = TRUE;
			return;
		}

		$this->swNdx = $r['ndx'];
		$this->swSUID = $r['suid'];

		// -- version
		$q = [];
		array_push($q, 'SELECT * FROM [mac_sw_swVersions]');
		array_push($q, ' WHERE [sw] = %i', $this->swNdx);
		array_push($q, ' AND [versionNumber] = %s', $this->osVersion);

		$cnt = 0;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($r['versionName'] !== '')
				$this->addProtocol('search-os-version', "Found `{$r['versionName']}` (#{$r['ndx']})");
			else
				$this->addProtocol('search-os-version', "Found `{$r['versionNumber']}` (#{$r['ndx']})");
			$cnt++;
		}

		if ($cnt > 1)
		{
			$this->addProtocol('search-os-version', "Multiple OS versions found; FAILED");
			$this->error = TRUE;
			return;
		}
		elseif ($cnt === 0)
		{
			if ($r['swVersionsMode'] == 0) // auto add versions
			{
				$newVersion = [
					'sw' => $this->swNdx,
					'versionNumber' => $this->osVersion,
					'lifeCycle' => 9,
					'dateRelease' => Utils::today(),
				];

				/** @var \mac\sw\TableSWVersions */
				$tableSWVersions = $this->app()->table('mac.sw.swVersions');

				$newVersionNdx = $tableSWVersions->dbInsertRec($newVersion);
				$newVersion = $tableSWVersions->loadItem($newVersionNdx);
				$tableSWVersions->checkAfterSave2 ($newVersion);
				$tableSWVersions->docsLog ($newVersionNdx);
				$this->addProtocol('check-sw-version', "SW version `{$this->osVersion}` / `{$newVersion['suid']}` added");

				$this->swVersionNdx = $newVersionNdx;
				$this->swVersionSUID = $newVersion['suid'];

				return;
			}

			$this->addProtocol('search-os-version', "No OS version found");
			$this->error = TRUE;
			return;
		}

		$this->swVersionNdx = $r['ndx'];
		$this->swVersionSUID = $r['suid'];
	}

	function checkSW()
	{
		$this->checkSWCore();
		$this->checkSWLoad();
	}

	function checkSWCore()
	{
		// -- name
		if (isset($this->srcData['NameClean']))
			$this->swName = $this->srcData['NameClean'];
		elseif (isset($this->srcData['Name']))
			$this->swName = $this->srcData['Name'];
		elseif (isset($this->srcData['DisplayName']))
			$this->swName = $this->srcData['DisplayName'];

		if ($this->swName === '')
		{
			$this->addProtocol('check-sw', "Missing any name value; detect SW name failed");
			$this->error = TRUE;
			return;
		}

		$this->addProtocol('check-sw', "SW name is `{$this->swName}`");

		// -- ids
		if (isset($this->srcData['uuid']))
		{
			foreach ($this->srcData['uuid'] as $id)
			{
				if ($id === '')
					continue;
				if (!$this->swIDs)
					$this->swIDs = [];
				$this->swIDs[] = $id;
				$this->addProtocol('check-sw', "SW ID is `{$id}`");
			}
		}

		// -- version
		if (isset($this->srcData['Version']))
			$this->swVersion = $this->srcData['Version'];
		elseif (isset($this->srcData['DisplayVersion']))
			$this->swVersion = $this->srcData['DisplayVersion'];
		if ($this->swVersion === '')
			$this->swVersion = '-';

		$this->addProtocol('check-sw-version', "SW version is `{$this->swVersion}`");
	}

	function checkSWLoad()
	{
		$q = [];
		array_push($q, 'SELECT software.* ');
		array_push($q, ' FROM [mac_sw_sw] AS [software]');
		array_push($q, ' WHERE [software].[swClass] != %i', SWUtils::swcOS);
		array_push($q, ' AND [software].[docState] = %i', 4000);

		array_push($q, ' AND (');
		array_push($q, ' [software].[fullName] = %s', $this->swName);
		array_push($q, ' OR EXISTS (SELECT ndx FROM mac_sw_swNames WHERE software.ndx = [sw] AND [name] = %s', $this->swName, ')');
		if ($this->swIDs && count($this->swIDs) === 1)
			array_push($q, ' OR EXISTS (SELECT ndx FROM mac_sw_swIds WHERE software.ndx = [sw] AND [id] = %s', $this->swIDs[0], ')');
		elseif ($this->swIDs && count($this->swIDs) > 1)
			array_push($q, ' OR EXISTS (SELECT ndx FROM mac_sw_swIds WHERE software.ndx = [sw] AND [id] IN %in', $this->swIDs, ')');

		array_push($q, ' OR (', 'software.swVersionsMode = %i', 2,
			' AND EXISTS (SELECT ndx FROM mac_sw_swVersions WHERE software.ndx = [sw] AND [versionNumber] = %s', $this->swName, ')',
			')');

		array_push($q, ')');

		$cnt = 0;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->addProtocol('search-sw', "Found `{$r['fullName']}` (#{$r['ndx']} / {$r['suid']})");
			$cnt++;
		}

		if ($cnt > 1)
		{
			$this->addProtocol('search-sw', "Multiple SW found; FAILED");
			$this->error = TRUE;
			return;
		}
		elseif ($cnt === 0)
		{
			$this->addProtocol('search-sw', "No SW found");
			$this->error = TRUE;
			return;
		}

		$this->swNdx = $r['ndx'];
		$this->swSUID = $r['suid'];

		// -- version
		if ($r['swVersionsMode'] == 2)
		{
			$this->swVersion = $this->swName;
			$this->addProtocol('check-sw-version', "SW version changed to `{$this->swVersion}`");
		}

		$q = [];
		array_push($q, 'SELECT * FROM [mac_sw_swVersions]');
		array_push($q, ' WHERE [sw] = %i', $this->swNdx);
		array_push($q, ' AND [versionNumber] = %s', $this->swVersion);

		$cnt = 0;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($r['versionName'] !== '')
				$this->addProtocol('search-sw-version', "Found `{$r['versionName']}` (#{$r['ndx']})");
			else
				$this->addProtocol('search-sw-version', "Found `{$r['versionNumber']}` (#{$r['ndx']} / {$r['suid']})");
			$cnt++;
		}

		if ($cnt > 1)
		{
			$this->addProtocol('search-sw-version', "Multiple SW versions found; FAILED");
			$this->error = TRUE;
			return;
		}
		elseif ($cnt === 0)
		{
			if ($r['swVersionsMode'] == 0 && $this->swVersion !== '' && $this->swVersion !== '-') // auto add versions
			{
				$newVersion = [
					'sw' => $this->swNdx,
					'versionNumber' => $this->swVersion,
					'lifeCycle' => 9,
					'dateRelease' => Utils::today(),
				];

				/** @var \mac\sw\TableSWVersions */
				$tableSWVersions = $this->app()->table('mac.sw.swVersions');

				$newVersionNdx = $tableSWVersions->dbInsertRec($newVersion);
				$newVersion = $tableSWVersions->loadItem($newVersionNdx);
				$tableSWVersions->checkAfterSave2 ($newVersion);
				$tableSWVersions->docsLog ($newVersionNdx);
				$this->addProtocol('check-sw-version', "SW version `{$this->swVersion}` / `{$newVersion['suid']}` added");

				$this->swVersionNdx = $newVersionNdx;
				$this->swVersionSUID = $newVersion['suid'];

				return;
			}

			$this->addProtocol('search-sw-version', "No SW version found");
			$this->error = TRUE;
			return;
		}

		$this->swVersionNdx = $r['ndx'];
		$this->swVersionSUID = $r['suid'];
	}

	public function run()
	{
		$this->swUtils = new \mac\swcore\libs\SWUtils($this->app());

		$this->checkCoreType();
		if ($this->saInfo === 'os')
			$this->checkOS();
		elseif ($this->saInfo === 'sw')
			$this->checkSW();

		if ($this->error)
			$this->addProtocol('done', 'FAILED');
		else
			$this->addProtocol('done', 'OK');
	}
}
