<?php

namespace mac\lan\libs\cfgScripts;

use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;


/**
 * Class Mikrotik
 * @package mac\lan\libs\cfgScripts
 */
class MikrotikAD extends \mac\lan\libs\cfgScripts\CoreCfgScript
{
	// -- deviceMode
	CONST dmSwitch = 0, dmCAPUnmanaged_OBSOLETE = 1, dmAPBridge = 2, dmRouter = 3, dmNone = 99;
	var $deviceMode = self::dmNone;

	// -- wifiMode
	CONST wmNone = 0, wmCAP = 1, wmManual = 2, wmAutoLAN = 3;
	var $wifiMode = self::wmNone;

	CONST wrmNone = 0, wrmWireless = 1, wrmWifiWave2 = 2, wrmWifi = 3;
	var $wirelessMode = self::wrmNone;

	var $scriptModeSignature = ' -- !!! UNCONFIGURED !!! --';

	var $lcMainLanUserNdx = 0;

	var $rootsInfo = [];
	var $csActiveRoot = '';

	var $isRouter = 0;


	public function setDevice($deviceRecData, $lanCfg)
	{
		parent::setDevice($deviceRecData, $lanCfg);

		$this->lcMainLanUserNdx = $this->lanCfg['lanRecData']['lcUserMikrotik'] ?? 0;

		$this->deviceMode = intval($this->deviceCfg['mode'] ?? 0);
		$this->wifiMode = intval($this->deviceCfg['wifi'] ?? 0);
		$this->isRouter = intval($this->deviceMode == self::dmRouter);

		if ($this->adCfg && isset($this->adCfg['wirelessMode']))
		{
			if ($this->adCfg['wirelessMode'] === 'wireless')
				$this->wirelessMode = self::wrmWireless;
			elseif ($this->adCfg['wirelessMode'] === 'wifiwave2')
				$this->wirelessMode = self::wrmWifiWave2;
			elseif ($this->adCfg['wirelessMode'] === 'wifi')
				$this->wirelessMode = self::wrmWifi;
		}

		// -- mng VLAN mac addr
		$q = [];
		array_push($q, 'SELECT * FROM [mac_lan_devicesPorts]');
		array_push($q, ' WHERE device = %i', $deviceRecData['ndx']);
		array_push($q, ' AND portKind = %i', 10); // VLAN
		array_push($q, ' AND mac != %s', '');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->macBridge = $r['mac'];
			break;
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

		$root = '/system/clock/';
		$item = ['type' => 'set',
			'params' => [
				'time-zone-name' => 'Europe/Prague',
			]
		];
		$this->cfgData[$root][] = $item;
	}

	function createData_Init_SNMP()
	{
		$root = '/snmp/';
		$item = ['type' => 'set',
			'params' => [
				'enabled' => 'yes',
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
		$wifiModes = [0 => 'none', 1 => 'CAPSMAN client', 2 => 'manual', 3 => 'auto/from LAN'];
		$wirelessModes = [0 => "none", 1 => 'wireless', 2 => 'wifiwave2', 3 => 'wifi ROS >= 7.13'];

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

		$this->csActiveRoot = '/system/clock/';
		$this->createScriptForRoot();
	}

	function createScript_Init_SNMP()
	{
		$this->csActiveRoot = '/snmp/';
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

		$s = '';

		// -- add main user
		$s = "### main user + ssh public key ###\n";
		if (!$this->lcMainLanUserNdx)
		{
			$s .= "### ERROR: no main user on device! ###\n";
		}
		else
		{
			$mainLanUser = $this->loadLanUser($this->lcMainLanUserNdx);
			$s .= $this->createScript_Init_User_AddOne($mainLanUser);
		}

		$initUsersScript = [
			'title' => 'Přidání uživatele',
			'script' => $s,
		];

		$this->scripsUtils[] = $initUsersScript;
	}

	function createScript_Init_User_AddOne($lanUserData)
	{
		$te = new \mac\admin\libs\TokensEngine($this->app());
		$validTokens = $te->loadLANValidTokens($this->deviceRecData['lan']);

		$s = '';

		$userNdx = $lanUserData['user']['ndx'] ?? 0;
		$login = $lanUserData['user']['login'];

		if (!count($lanUserData['pubKeys']))
		{
			$s .= "### ERROR: no public key for user `{$login}` ###\n";
			return $s;
		}

		if ($login !== 'admin')
			$s .= "/user/add name=".$login.' group=full'.' password="'.Utils::createToken(10, TRUE).'"'."\n";

		/*
		if (isset($this->lanCfg['mainServerMacDeviceCfg']['serverFQDN']) && $this->lanCfg['mainServerMacDeviceCfg']['serverFQDN'] != '')
		{
			$httpsPort = (isset($this->lanCfg['mainServerMacDeviceCfg']['httpsPort']) && (intval($this->lanCfg['mainServerMacDeviceCfg']['httpsPort']))) ? intval($this->lanCfg['mainServerMacDeviceCfg']['httpsPort']) : 443;
			$keysUrl = 'https://'.$this->lanCfg['mainServerMacDeviceCfg']['serverFQDN'];
			if ($httpsPort !== 443)
				$keysUrl .= ':'.$httpsPort;

			$keysUrl .= '/';
			$keysUrl .= $validTokens[0] ?? '---';
			$keysUrl .= '/lc-ssh/';
			$keysUrl .= 'shn_ssh_key.pub';
		}
		*/

		foreach ($lanUserData['pubKeys'] as $pubKey)
		{
			$keyUrl = 'https://'.$_SERVER['HTTP_HOST'].$this->app()->urlRoot.'/feed/lan-user-pub-key/';
			$keyUrl .= $this->deviceNdx.'/'.$userNdx.'/'.$pubKey['ndx'].'/'.$validTokens[0].'/pub-key-'.$userNdx.'-'.$pubKey['ndx'].'.pub';

			$s .= "/tool/fetch url=\"".$keyUrl."\" user=".$login." mode=https dst-path=shn_ssh_key.pub\n";
			$s .= "/user/ssh-keys/import public-key-file=shn_ssh_key.pub user=".$login."\n";
			$s .= "\n";
		}

		return $s;
	}

	function createScript_Reset_Device()
	{
		if (!$this->initMode)
		return;

		$te = new \mac\admin\libs\TokensEngine($this->app());
		$validTokens = $te->loadLANValidTokens($this->deviceRecData['lan']);

		// -- device reset
		$s = "### device reset ###\n";
		if (isset($validTokens[0]))
		{
			$initScriptUrl = 'https://'.$_SERVER['HTTP_HOST'].$this->app()->urlRoot.'/feed/lan-device-init-script/'.$this->deviceNdx.'/'.$validTokens[0].'/init-'.$this->deviceNdx.'.rsc';
			$s .= "/tool/fetch url=".$initScriptUrl." mode=https dst-path=init-".$this->deviceNdx.".rsc\n";
			$s .= '/system/reset-configuration caps-mode=no keep-users=yes no-defaults=yes skip-backup=yes run-after-reset=init-'.$this->deviceNdx.'.rsc'."\n";
			$s .= "\n";
		}
		else
		{
			$s = "### ERROR: no valid auth token!!! ###\n";
		}

		$resetDeviceScript = [
			'title' => 'Reset zařízení',
			'script' => $s,
		];

		$this->scripsUtils[] = $resetDeviceScript;
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

		$cmdSeparator = ' ';
		if (str_ends_with ($this->csActiveRoot, '/'))
			$cmdSeparator = '/';

		if ($cmdSeparator === '/')
			$s .= $this->csActiveRoot;
		else
			$s .= $this->csActiveRoot.$cmdSeparator;
		$s .= $item['type'];

		foreach ($item['params'] as $key => $value)
		{
			if ($cmdSeparator !== '/')
				$s .= $cmdSeparator;
			else
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

	protected function initScriptAfterVerSuffix()
	{
		$s = '';

		$shipardCfgVersion = sha1($this->script);
		$s .= "### set shipard-cfg-version: #$shipardCfgVersion ###\n";
		$s .= "/system/note/set note=\"shipard-cfg-version: $shipardCfgVersion\" show-at-login=yes\n";

		return $s;
	}

	protected function loadLanUser ($lanUserNdx)
	{
		$lanUser = [
			'user' => $this->app()->loadItem($lanUserNdx, 'mac.admin.lanUsers'),
			'pubKeys' => [],
		];

		$rows = $this->db()->query('SELECT * FROM [mac_admin_lanUsersPubKeys] WHERE [lanUser] = %i', $lanUserNdx);
		foreach ($rows as $r)
		{
			$lanUser['pubKeys'][] = [
				'ndx' => $r['ndx'],
				'name' => $r['name'],
				'key' => $r['key'],
			];
		}

		return $lanUser;
	}
}
