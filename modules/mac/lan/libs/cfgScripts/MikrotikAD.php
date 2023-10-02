<?php

namespace mac\lan\libs\cfgScripts;

use e10\Utility;


/**
 * Class Mikrotik
 * @package mac\lan\libs\cfgScripts
 */
class MikrotikAD extends \mac\lan\libs\cfgScripts\CoreCfgScript
{
	// -- deviceMode
	CONST dmSwitch = 0, dmCAPUnmanaged = 1, dmAPBridge = 2, dmRouter = 3, dmNone = 99;
	var $deviceMode = self::dmNone;

	// -- wifiMode
	CONST wmNone = 0, wmCAP = 1, wmManual = 2, wmAutoLAN = 3;
	var $wifiMode = self::wmNone;

	CONST wrmNone = 0, wrmWireless = 1, wrmWifiWave2 = 2;
	var $wirelessMode = self::wrmNone;

	var $scriptModeSignature = ' -- !!! UNCONFIGURED !!! --';
	var $userLogin = 'admin';
	var $csActiveRoot = '';

	var $isRouter = 0;


	public function setDevice($deviceRecData, $lanCfg)
	{
		parent::setDevice($deviceRecData, $lanCfg);

		if (isset ($this->deviceCfg['userLogin']))
			if (strlen ($this->deviceCfg['userLogin']))
				$this->userLogin = $this->deviceCfg['userLogin'];

		$this->deviceMode = intval($this->deviceCfg['mode'] ?? 0);
		$this->wifiMode = intval($this->deviceCfg['wifi'] ?? 0);
		$this->isRouter = intval($this->deviceMode == self::dmRouter);

		if ($this->adCfg && isset($this->adCfg['wirelessMode']))
		{
			if ($this->adCfg['wirelessMode'] === 'wireless')
				$this->wirelessMode = self::wrmWireless;
			elseif ($this->adCfg['wirelessMode'] === 'wifiwave2')
				$this->wirelessMode = self::wrmWifiWave2;
		}
	}

	function createData_Init_Identity()
	{
		$root = '/system identity';
		$item = ['type' => 'set',
			'params' => [
				'name' => $this->lanDeviceCfg['id'],
			]
		];
		$this->cfgData[$root][] = $item;

		$root = '/system clock';
		$item = ['type' => 'set',
			'params' => [
				'time-zone-name' => 'Europe/Prague',
			]
		];
		$this->cfgData[$root][] = $item;
	}

	function createData_Init_Services()
	{
		$root = '/ip service';
		$item = ['type' => 'set','params' => ['telnet' => NULL, 'disabled' => 'yes',]];
		$this->cfgData[$root][] = $item;
		$item = ['type' => 'set','params' => ['ftp' => NULL, 'disabled' => 'yes',]];
		$this->cfgData[$root][] = $item;
		$item = ['type' => 'set','params' => ['api' => NULL, 'disabled' => 'yes',]];
		$this->cfgData[$root][] = $item;
		$item = ['type' => 'set','params' => ['api-ssl' => NULL, 'disabled' => 'yes',]];
		$this->cfgData[$root][] = $item;
		$item = ['type' => 'set','params' => ['winbox' => NULL, 'disabled' => 'yes',]];
		$this->cfgData[$root][] = $item;

		$adminsRangesSSH = array_merge ($this->lanCfg['ipRangesManagement'], $this->lanCfg['ipRangesAdmins']);
		$adminsRangesWWW = array_merge ($this->lanCfg['ipRangesManagement'], $this->lanCfg['ipRangesAdmins']);

		if (isset($this->deviceCfg['capsmanClient']) && intval($this->deviceCfg['capsmanClient']) && isset($this->lanCfg['mainServerWifiControlIp']))
			$adminsRangesSSH[] = $this->lanCfg['mainServerWifiControlIp'].'/32';
		elseif (isset($this->deviceCfg['wifi']) && intval($this->deviceCfg['wifi']) == 1 && isset($this->lanCfg['mainServerWifiControlIp']))
			$adminsRangesSSH[] = $this->lanCfg['mainServerWifiControlIp'].'/32';

		if (isset($this->deviceCfg['managementWWWAddrList']) && intval($this->deviceCfg['managementWWWAddrList']))
			$this->addAddressListIPs($this->deviceCfg['managementWWWAddrList'], $adminsRangesWWW);
		if (isset($this->deviceCfg['managementSSHAddrList']) && intval($this->deviceCfg['managementSSHAddrList']))
			$this->addAddressListIPs($this->deviceCfg['managementSSHAddrList'], $adminsRangesSSH);

		if (count($adminsRangesWWW))
		{
			$item = ['type' => 'set',
				'params' => [
					'www' => NULL,
					'address' => implode(',', $adminsRangesWWW),
					'port' => '30080'
				]
			];
			$this->cfgData[$root][] = $item;
		}
		else
		{
			$item = ['type' => 'set','params' => ['www' => NULL, 'disabled' => 'yes',]];
			$this->cfgData[$root][] = $item;
		}

		if (count($adminsRangesSSH))
		{
			$item = ['type' => 'set',
				'params' => [
					'ssh' => NULL,
					'address' => implode(',', $adminsRangesSSH),
					'port' => '30022'
				]
			];
			$this->cfgData[$root][] = $item;
		}
		else
		{
			$item = ['type' => 'set','params' => ['ssh' => NULL, 'disabled' => 'yes',]];
			$this->cfgData[$root][] = $item;
		}
	}

	function createScript_ScriptMode()
	{
		if (!$this->initMode)
			return;

		$deviceModes = [0 => 'switch', 1 => 'unmanaged', 2 => 'ap/bridge', 3 => 'router'];
		$wifiModes = [0 => 'none', 1 => 'CAP', 2 => 'manual', 3 => 'auto/from LAN'];
		$wirelessModes = [0 => "none", 1 => 'wireless', 2 => 'wifiwave2'];

		$this->script .= "### script mode: {$this->scriptModeSignature} / ".get_class($this)." ###\n";
		$this->script .= "### device mode: ".($deviceModes[$this->deviceMode] ?? 'UNKNOWN').
										"; wifi: ".($wifiModes[$this->wifiMode] ?? 'UNKNOWN').
										"; wireless: ".($wirelessModes[$this->wirelessMode] ?? 'UNKNOWN').
										" ###\n";
		$this->script .= "\n";
	}

	function createScript_Init_Identity()
	{
		$this->csActiveRoot = '/system identity';
		$this->createScriptForRoot();
	}

	function createScript_Init_Services()
	{
		$this->csActiveRoot = '/ip service';
		$this->createScriptForRoot();
	}

	function createScript_Init_User()
	{
		if (!$this->initMode)
			return;

		$tftpAddress = $this->lanCfg['mainServerLanControlIp'];
		if (isset($this->deviceCfg['capsmanClient']) && intval($this->deviceCfg['capsmanClient']) && isset($this->lanCfg['mainServerWifiControlIp']))
			$tftpAddress = $this->lanCfg['mainServerWifiControlIp'];
		elseif (isset($this->deviceCfg['wifi']) && intval($this->deviceCfg['wifi']) == 1 && isset($this->lanCfg['mainServerWifiControlIp']))
			$tftpAddress = $this->lanCfg['mainServerWifiControlIp'];

		// /user/add name=js group=full comment="John Shipard" password=“hfztrbt7h3”

		$this->script .= "### user + ssh public key ###\n";
		$this->script .= "/tool fetch address=".$tftpAddress." src-path=shn_ssh_key.pub user=".$this->userLogin." mode=tftp dst-path=shn_ssh_key.pub\n";
		$this->script .= "/user ssh-keys import public-key-file=shn_ssh_key.pub user=".$this->userLogin."\n";
		$this->script .= "\n";
	}

	function createScriptForRoot()
	{
		if (!isset($this->cfgData[$this->csActiveRoot]))
			return;
		$this->checkRootItems();

		$cnt = 0;
		foreach ($this->cfgData[$this->csActiveRoot] as $oneItem)
		{
			$cnt += $this->createScriptForRootItem($oneItem);
		}

		if ($cnt)
			$this->script .= "\n";

		return $cnt;
	}

	function createScriptForRootItem($item)
	{
		$s = '';

		if ($item['exist'])
			return 0;
			//$s .= '### ';

		$s .= $this->csActiveRoot.' ';
		$s .= $item['type'];

		foreach ($item['params'] as $key => $value)
		{
			$s .= ' ';
			$s .= $key;
			if ($value === NULL)
				continue;

			$s .= '=';
			if (strstr($value, ' ') !== FALSE)
				$s .= '"'.$value.'"';
			else
				$s .= $value;
		}

		$this->script .= $s;
		$this->script .= "\n";

		return 1;
	}

	function checkRootItems()
	{
		foreach ($this->cfgData[$this->csActiveRoot] as &$oneItem)
		{
			$exist = $this->rootItemExist($oneItem);
			$oneItem['exist'] = $exist;
		}
	}

	function rootItemExist($item)
	{
		if (!$this->cfgRunningConfig || $this->initMode)
			return 0;

		if (!isset($this->cfgRunningConfig[$this->csActiveRoot]))
			return 0;

		if (!isset($this->rootsInfo[$this->csActiveRoot]))
			return 0;

		$mc = NULL;
		if (isset($this->rootsInfo[$this->csActiveRoot]['mandatoryColumns']))
			$mc = $this->rootsInfo[$this->csActiveRoot]['mandatoryColumns'];
		else
			$mc = array_keys($item['params']);

		$ic = NULL;
		if (isset($this->rootsInfo[$this->csActiveRoot]['ignoredColumns']))
			$ic = $this->rootsInfo[$this->csActiveRoot]['ignoredColumns'];

		$cic = NULL;
		if (isset($this->rootsInfo[$this->csActiveRoot]['caseInsensitiveColumns']))
			$cic = $this->rootsInfo[$this->csActiveRoot]['caseInsensitiveColumns'];

		foreach ($this->cfgRunningConfig[$this->csActiveRoot] as $rcItem)
		{
			$thisItemEqual = 1;
			foreach ($mc as $mcKey)
			{
				if ($ic && in_array($mcKey, $ic))
					continue;

				if (!isset($rcItem['params'][$mcKey]) && !isset($item['params'][$mcKey]))
					continue;

				if ($cic && in_array($mcKey, $cic))
				{
					if (strcasecmp($item['params'][$mcKey], $rcItem['params'][$mcKey]) !== 0)
					{
						$thisItemEqual = 0;
						break;
					}
				}
				else
				{
					if ((!isset($rcItem['params'][$mcKey]) && isset($item['params'][$mcKey])) || ($item['params'][$mcKey] != $rcItem['params'][$mcKey]))
					{
						$thisItemEqual = 0;
						break;
					}
				}
			}
			if ($thisItemEqual)
				return 1;
		}

		return 0;
	}

	function cfgParser()
	{
		return new \mac\lan\libs\cfgScripts\parser\Mikrotik($this->app());
	}
}
