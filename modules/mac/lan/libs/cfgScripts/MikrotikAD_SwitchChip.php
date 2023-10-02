<?php

namespace mac\lan\libs\cfgScripts;

use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;

/**
 * class MikrotikAD_SwitchChip
 */
class MikrotikAD_SwitchChip extends \mac\lan\libs\cfgScripts\MikrotikAD
{
	var $csActiveRoot = '';
	var $rootsInfo = [];
	var $vlansPorts = [];

	public function initRoots()
	{
		$this->rootsInfo ['/system identity'] = [
			'mandatoryColumns' => ['name']
		];

		$this->rootsInfo ['/ip service'] = [
			'ignoredColumns' => [],
		];

		$this->rootsInfo ['/ip address'] = [
			'mandatoryColumns' => ['address', 'interface', 'network']
		];

		$this->rootsInfo ['/interface bridge'] = [
			'mandatoryColumns' => ['fast-forward', 'name'],
			'updateColumns' => ['comment']
		];

		if ($this->deviceMode === self::dmAPBridge)
		{
			if ($this->wirelessMode === self::wrmWireless)
			{
				$this->rootsInfo ['/interface wireless security-profiles'] = [
					'mandatoryColumns' => ['wpa2-pre-shared-key', 'name'],
					'updateColumns' => ['comment']
				];
			}
			elseif ($this->wirelessMode === self::wrmWifiWave2)
			{
				$this->rootsInfo ['/interface wifiwave2 security'] = [
					'mandatoryColumns' => ['passphrase', 'name'],
					'updateColumns' => ['comment']
				];
			}
		}

		$this->rootsInfo ['/interface vlan'] = [
			'mandatoryColumns' => ['interface', 'name', 'vlan-id'],
			'updateColumns' => ['comment']
		];

		$this->rootsInfo ['/interface bridge port'] = [
			'mandatoryColumns' => ['bridge', 'interface'],
			'updateColumns' => [/*'pvid',*/ 'comment']
		];

		$this->rootsInfo ['/interface ethernet switch vlan'] = [
			'mandatoryColumns' => ['ports', 'switch', 'vlan-id'],
			'updateColumns' => ['comment']
		];

		$this->rootsInfo ['/ip firewall filter'] = [
			'ignoredColumns' => ['comment'],
			'updateColumns' => ['comment']
		];

		$this->rootsInfo ['/ip route'] = [
			'mandatoryColumns' => ['gateway'],
			'updateColumns' => ['distance', 'comment']
		];

		$this->rootsInfo ['/ip pool'] = [
			'mandatoryColumns' => ['name'],
			'updateColumns' => ['ranges', 'comment', 'next-pool']
		];

		$this->rootsInfo ['/ip dhcp-server'] = [
			'mandatoryColumns' => ['address-pool', 'name', 'interface'/*, 'disabled' - removed in ROS7 */],
			'updateColumns' => ['authoritative', 'comment', 'lease-time']
		];

		$this->rootsInfo ['/ip dhcp-server network'] = [
			'mandatoryColumns' => ['address', 'gateway'],
			'updateColumns' => ['comment']
		];

		$this->rootsInfo ['/ip dhcp-server lease'] = [
			'mandatoryColumns' => ['mac-address', 'server'],
			'updateColumns' => ['address', 'comment'],
			'caseInsensitiveColumns' => ['mac-address'],
		];
	}

	public function setDevice($deviceRecData, $lanCfg)
	{
    $this->scriptModeSignature = 'Switch-Chip';

		parent::setDevice($deviceRecData, $lanCfg);
	}

	function createData()
	{
		$this->initRoots();

    $this->createScript_ScriptMode();
		$this->createData_Init_Identity();
		$this->createData_Init_Services();

		if ($this->deviceMode === self::dmAPBridge)
		{
			$this->createData_UnmanagedWifi();
			$this->createData_APBridgeInterfaces();
		}
		else
			$this->createData_Interfaces_HW_Vlans();

		$this->createData_Interfaces_Addresses();

    if ($this->isRouter)
    {
			$this->createData_Firewall();

			$this->createData_Gateways();
		  $this->createData_DHCP();
		  $this->createData_DHCP_Leases();
    }
		elseif ($this->deviceMode === self::dmSwitch)
		{
			$this->createData_Gateways();
		}

		$this->createData_DNS();
	}

	function createData_UnmanagedWifi()
	{
		if ($this->wifiMode === self::wmManual)
		{
			if ($this->wirelessMode === self::wrmWireless)
			{
				// /interface wireless security-profiles
				// add authentication-types=wpa-psk,wpa2-psk mode=dynamic-keys name=wlan-password supplicant-identity="" wpa2-pre-shared-key=abcd1234
				$root = '/interface wireless security-profiles';
				$item = ['type' => 'add',
					'params' => [
						'name' => 'wlan-password',
						'authentication-types' => 'wpa-psk,wpa2-psk',
						'wpa2-pre-shared-key' => $this->deviceCfg['wifiPassword'],
					]
				];
				$this->cfgData[$root][] = $item;

				// /interface wireless
				// set wlan1 band=2ghz-b/g/n country="czech republic" disabled=no frequency=auto installation=indoor mode=ap-bridge security-profile=wlan-password ssid=uno wireless-protocol=802.11
				// set wlan2 band=5ghz-a/n/ac country="czech republic" disabled=no frequency=auto installation=indoor mode=ap-bridge security-profile=wlan-password ssid=due wireless-protocol=802.11
				// set wlan3 band=5ghz-a/n/ac channel-width=20/40mhz-XX country="czech republic" disabled=no frequency=auto installation=indoor mode=ap-bridge security-profile=wlan-password ssid=tre wireless-protocol=802.11

				$root = '/interface wireless';
				$ports = \e10\sortByOneKey($this->lanDeviceCfg['ports'], 'portId', TRUE, TRUE);
				foreach ($ports as $portNdx => $portCfg)
				{
					if ($portCfg['portKind'] !== 1)
						continue;

					$item = ['type' => 'set '.$portCfg['portId'],
						'params' => [
							'country' => 'czech republic',
							'disabled' => 'no',
							'installation' => 'indoor',
							'mode' => 'ap-bridge',
							'security-profile' => 'wlan-password',
							'ssid' => $this->deviceCfg['wifiSSID'],
						]
					];

					$this->cfgData[$root][] = $item;
				}
			}
			elseif ($this->wirelessMode === self::wrmWifiWave2)
			{
				// /interface wifiwave2 security
				// add authentication-types=wpa-psk,wpa2-psk disabled=no name=wifi-password passphrase=abcd1234
				$root = '/interface wifiwave2 security';
				$item = ['type' => 'add',
					'params' => [
						'name' => 'wlan-password',
						'disabled' => 'no',
						'authentication-types' => 'wpa-psk,wpa2-psk',
						'passphrase' => $this->deviceCfg['wifiPassword'],
					]
				];
				$this->cfgData[$root][] = $item;

				// /interface wifiwave2
				// set wifi1 channel.band=5ghz-ax .skip-dfs-channels=10min-cac .width=20/40/80mhz configuration.country=Czech .mode=ap .ssid=xxx disabled=no security=wlan-password
				// set wifi2 channel.band=2ghz-ax .skip-dfs-channels=10min-cac .width=20/40mhz configuration.country=Czech .mode=ap .ssid=xxx disabled=no security=wlan-password
				$root = '/interface wifiwave2';
				$ports = \e10\sortByOneKey($this->lanDeviceCfg['ports'], 'portId', TRUE, TRUE);
				foreach ($ports as $portNdx => $portCfg)
				{
					if ($portCfg['portKind'] !== 1)
						continue;

					$item = ['type' => 'set '.$portCfg['portId'],
						'params' => [
							'configuration.country' => 'Czech',
							'disabled' => 'no',
							'configuration.mode' => 'ap',
							'configuration.ssid' => $this->deviceCfg['wifiSSID'],
							'security' => 'wlan-password',
						]
					];

					$this->cfgData[$root][] = $item;
				}
			}
		}
	}

	function createData_APBridgeInterfaces()
	{
		$root = '/interface bridge';
		$item = ['type' => 'add',
			'params' => [
				'name' => 'bridge1',
				'protocol-mode' => 'none',
			]
		];
		$this->cfgData[$root][] = $item;

		$root = '/interface bridge port';

		$ports = \e10\sortByOneKey($this->lanDeviceCfg['ports'], 'portId', TRUE, TRUE);
		foreach (/*$this->lanDeviceCfg['ports']*/$ports as $portNdx => $portCfg)
		{
			if ($portCfg['portKind'] !== 5 && $portCfg['portKind'] !== 6 && $portCfg['portKind'] !== 1)
				continue; // only ETH/SFP ports & wifi

			$item = ['type' => 'add',
				'params' => [
					'bridge' => 'bridge1',
					'interface' => $portCfg['portId'],
				]
			];

			$this->cfgData[$root][] = $item;
		}

		$root = '/ip dhcp-client';
		$item = ['type' => 'add',
			'params' => [
				'interface' => 'bridge1',
			]
		];
		$this->cfgData[$root][] = $item;
	}

	function createData_Interfaces_HW_Vlans()
	{
		if (!$this->isRouter && $this->deviceMode !== self::dmSwitch)
			return;

		$vlansOnPorts = ['native' => [], 'trunk' => [], 'mng' => [], 'all' => []];
		foreach ($this->lanDeviceCfg['ports'] as $portNdx => $portCfg)
		{
			if (!isset($portCfg['vlans']) || !count($portCfg['vlans']))
				continue;

			$portRole = '';

			if ($portCfg['portKind'] === 5 || $portCfg['portKind'] === 6)
			{
				if ($portCfg['portRole'] === 10)
					$portRole = 'native';
				elseif ($portCfg['portRole'] === 20 || $portCfg['portRole'] === 30 || $portCfg['portRole'] === 40)
					$portRole = 'trunk';
				//elseif ($portCfg['portRole'] === 30 && isset($portCfg['untaggedVlan']))
				//	$portRole = 'native';
			}
			elseif ($portCfg['portKind'] === 10)
				$portRole = 'mng';

			foreach ($portCfg['vlans'] as $vlanNumber)
			{
				$portId = (($portRole == 'mng') ? 'bridge1':$portCfg['portId']);
				$vlansOnPorts[$portRole][$vlanNumber][$portCfg['number']] = $portId;
				$vlansOnPorts['all'][$vlanNumber][$portCfg['number']] = $portId;
				if ($portCfg['portRole'] === 30 && isset($portCfg['untaggedVlan']) && $portCfg['untaggedVlan'] == $vlanNumber)
					$vlansOnPorts['native'.'_'][$vlanNumber][] = $portId;
				elseif ($portRole == 'mng')
					$vlansOnPorts['trunk'.'_'][$vlanNumber][] = $portId;
				else
					$vlansOnPorts[$portRole.'_'][$vlanNumber][] = $portId;
			}
		}


		$root = '/interface bridge';
		$item = ['type' => 'add',
			'params' => [
				'name' => 'bridge1',
			]
		];
		$this->cfgData[$root][] = $item;


		$root = '/interface bridge port';
		$vlansPorts = [];

		foreach ($this->lanDeviceCfg['ports'] as $portNdx => $portCfg)
		{
			$portRole = $portCfg['portRole'];
			$vlans = [];
			if (isset($portCfg['vlans']) && count($portCfg['vlans']))
				foreach ($portCfg['vlans'] as $vn)
					$vlans[] = $vn;
			$item = ['type' => 'add',
				'params' => [
					'bridge' => 'bridge1',
					'interface' => $portCfg['portId'],
					//'hw' => 'yes',
				]
			];

			if ($portRole === 10 && isset($portCfg['vlans']) && count($portCfg['vlans']))
			{
				$item['params']['pvid'] = implode(',', $portCfg['vlans']);
				$item['params']['frame-types'] = 'admit-only-untagged-and-priority-tagged';
			}

			if ($portRole === 20 || $portRole === 30) // uplink / downlink
			{
				if ($portRole === 30 && isset($portCfg['untaggedVlan']))
				{
					$item['params']['pvid'] = $portCfg['untaggedVlan'];//json_encode($portCfg); // implode(',', $portCfg['vlans']);
					$item['params']['frame-types'] = 'admit-all';
				}
				else
					$item['params']['frame-types'] = 'admit-only-vlan-tagged';
			}

			if ($portCfg['portKind'] == 5 || $portCfg['portKind'] == 6)
				$this->cfgData[$root][] = $item;

			if (count($vlans))
			{
				foreach ($vlans as $vn)
				{
					if (!isset($vlansPorts[$vn]) || !in_array($portCfg['portId'], $vlansPorts[$vn]))
					{
						$portId = (($portCfg['portKind'] === 10) ? 'bridge1' : $portCfg['portId']);
						$vlansPorts[$vn][] = $portId;
					}
				}
			}
		}

		$root = '/interface vlan';
		foreach ($this->lanDeviceCfg['ports'] as $portNdx => $portCfg)
		{
			if (!isset($portCfg['vlans']) || !count($portCfg['vlans']))
				continue;

			if ($portCfg['portKind'] !== 10)
				continue;

			$item = ['type' => 'add',
				'params' => [
					'interface' => 'bridge1',
					'vlan-id' => $portCfg['vlans'][0],
					'name' => $portCfg['portId']
				]
			];

			$this->cfgData[$root][] = $item;
		}

		$root = '/interface bridge vlan';
		ksort($vlansPorts);
		foreach ($vlansOnPorts['trunk_'] as $vlanNumber => $ports)
		{
			$item = ['type' => 'add',
				'params' => [
					'bridge' => 'bridge1',
					'tagged' => implode(',', $ports),
					'vlan-ids' => $vlanNumber,
				]
			];
			$this->cfgData[$root][] = $item;
		}

		$root = '/interface bridge';
		$item = ['type' => 'set bridge1',
			'params' => [
				//'bridge' => 'bridge1',
				'vlan-filtering' => 'yes',
			]
		];
		$this->cfgData[$root][] = $item;

		$this->vlansPorts = $vlansPorts;
	}

	function createData_Interfaces_Addresses()
	{
    if (!isset($this->lanDeviceCfg['addresses']) || ! count($this->lanDeviceCfg['addresses']))
    {

      return;
    }

		$usedAddresses = [];

		if ($this->deviceMode === self::dmSwitch)
		{
			$root = '/ip address';
			foreach ($this->lanDeviceCfg['addresses'] as $addressCfg)
			{
				$interface = isset($addressCfg['vlan']) ? 'IFB_VLAN'.$addressCfg['vlan'] : $addressCfg['portId'];
				$item = ['type' => 'add',
					'params' => [
						'address' => $addressCfg['ip'],
						'interface' => $addressCfg['portId'],
						'network' => $addressCfg['network'],
					]
				];
				$usedAddresses [] = $item['params']['address'];
				$this->cfgData[$root][] = $item;
			}
		}
	}

	function createData_DNS()
	{
		if ($this->deviceMode === self::dmSwitch)
		{
			$root = '/ip dns';
			foreach ($this->lanDeviceCfg['addresses'] as $address)
			{
				if (isset ($address['vlan']) && $address['vlan'] == $this->lanCfg['vlanManagement'])
				{
					if (isset ($address['gw']) && strlen ($address['gw']))
					{
						$item = ['type' => 'set',
							'params' => [
								'servers' => $address['gw']
							]
						];
						$this->cfgData[$root][] = $item;
						break;
					}
				}
			}
		}
	}

	function createData_Firewall()
	{
		$root = '/ip firewall filter';
		// -- DNS drop on WAN ports
		foreach ($this->lanDeviceCfg['ports'] as $portNdx => $portCfg)
		{
			if ($portCfg['portKind'] !== 5 && $portCfg['portKind'] !== 6 && $portCfg['portRole'] !== 90)
				continue;

			$portRole = $portCfg['portRole'];
			if ($portRole === 90)
			{ // wan/internet
				$item = ['type' => 'add',
					'params' => [
						'action' => 'drop',
						'chain' => 'input',
						'comment' => 'Filtrace DNS - TCP',
						'dst-port' => '53',
						'in-interface' => $portCfg['portId'],
						'protocol' => 'tcp'
					]
				];
				$this->cfgData[$root][] = $item;

				$item['params']['comment'] = 'Filtrace DNS - UDP';
				$item['params']['protocol'] = 'udp';
				$this->cfgData[$root][] = $item;
			}
		}

		$item = ['type' => 'add',
			'params' => [
				'action' => 'accept',
				'chain' => 'forward',
				'comment' => 'Povoleni navazanych spojeni odkudkoliv',
				'connection-state' => 'established,related',
			]
		];
		$this->cfgData[$root][] = $item;

		// -- enable WAN/internet
		foreach ($this->lanDeviceCfg['ports'] as $portNdx => $portCfg)
		{
			if ($portCfg['portKind'] !== 5 && $portCfg['portKind'] !== 6 && $portCfg['portRole'] !== 90)
				continue;

			$portRole = $portCfg['portRole'];
			if ($portRole === 90)
			{ // wan/internet
				$item = ['type' => 'add',
					'params' => [
						'action' => 'accept',
						'chain' => 'forward',
						'comment' => 'Povoleni Internetu',
						'out-interface' => $portCfg['portId'],
					]
				];
				$this->cfgData[$root][] = $item;
			}
		}

		$item = ['type' => 'add',
			'params' => [
				'action' => 'accept',
				'chain' => 'forward',
				'comment' => 'Povoleni prichozich dst-nat',
				'connection-nat-state' => 'dstnat',
			]
		];
		$this->cfgData[$root][] = $item;

		// -- VLAN filtering
		if ($this->lanCfg['vlanAdmins'])
		{
			$item = ['type' => 'add',
				'params' => [
					'action' => 'accept',
					'chain' => 'forward',
					'comment' => 'Povoleni spravcu site vsude',
					'in-interface' => 'IFB_VLAN'.$this->lanCfg['vlanAdmins'],
				]
			];
			$this->cfgData[$root][] = $item;
		}

		foreach ($this->lanCfg['vlansPublic'] as $vlanNdx => $vlanCfg)
		{ // public vlans
			$item = ['type' => 'add',
				'params' => [
					'action' => 'accept',
					'chain' => 'forward',
					'comment' => 'Povoleni verejne VLAN '.$vlanCfg['num'].': '.$vlanCfg['desc'],
					'out-interface' => 'IFB_VLAN'.$vlanCfg['num'],
				]
			];
			$this->cfgData[$root][] = $item;
		}

		foreach ($this->lanCfg['vlans'] as $vlanNdx => $vlanCfg)
		{
			if (!isset($vlanCfg['incomingVlans']))
				continue;

			foreach ($vlanCfg['incomingVlans'] as $ivNum)
			{
				$srcVlan = \e10\searchArray($this->lanCfg['vlans'], 'num', $ivNum);
				$comment = $vlanCfg['desc'].' <-- '.$srcVlan['desc'];

				$item = ['type' => 'add',
					'params' => [
						'action' => 'accept',
						'chain' => 'forward',
						'out-interface' => 'IFB_VLAN'.$vlanCfg['num'],
						'in-interface' => 'IFB_VLAN'.$srcVlan['num'],
						'comment' => $comment,
					]
				];
				$this->cfgData[$root][] = $item;
			}
		}

		$item = ['type' => 'add',
			'params' => [
				'action' => 'drop',
				'chain' => 'forward',
				'comment' => 'DROP ALL',
			]
		];
		$this->cfgData[$root][] = $item;
	}

	function createData_DHCP()
	{
		if (!isset($this->lanCfg['dhcp']))
		{
			return;
		}

		// -- pools
		$root = '/ip pool';
		foreach ($this->lanCfg['dhcp']['pools'] as $poolId => $poolCfg)
		{
			if (!$poolCfg['poolBegin'] || !$poolCfg['poolEnd'])
				continue;
			$ranges = $poolCfg['addressPrefix'].$poolCfg['poolBegin'].'-'.$poolCfg['addressPrefix'].$poolCfg['poolEnd'];

			$item = ['type' => 'add',
				'params' => [
					'name' => $poolId,
					'ranges' => $ranges,
				]
			];
			if ($poolCfg['desc'] !== '')
				$item['params']['comment'] = $poolCfg['desc'];
			$this->cfgData[$root][] = $item;
		}

		// -- dhcp servers
		$root = '/ip dhcp-server';
		foreach ($this->lanCfg['dhcp']['servers'] as $serverId => $serverCfg)
		{
			if (!isset($this->lanCfg['dhcp']['pools'][$serverCfg['pool']]))
				continue;
			$poolCfg = $this->lanCfg['dhcp']['pools'][$serverCfg['pool']];
			if (!$poolCfg['poolBegin'] || !$poolCfg['poolEnd'])
				continue;

			$item = ['type' => 'add',
				'params' => [
					'address-pool' => $serverCfg['pool'],
					'name' => $serverId,
					'interface' => $serverCfg['interface'],
					'authoritative' => 'after-2sec-delay',
					'disabled' => 'no',
					'lease-time' => '30m',
				]
			];
			$this->cfgData[$root][] = $item;
		}

		// -- dhcp-server networks
		$root = '/ip dhcp-server network';
		foreach ($this->lanCfg['dhcp']['pools'] as $poolId => $poolCfg)
		{
			if (!$poolCfg['poolBegin'] || !$poolCfg['poolEnd'])
				continue;

			$item = ['type' => 'add',
				'params' => [
					'address' => $poolCfg['addressRange'],
					'gateway' => $poolCfg['addressPrefix'].'1',
				]
			];
			$this->cfgData[$root][] = $item;
		}
	}

	function createData_DHCP_Leases()
	{
		$root = '/ip dhcp-server lease';
		foreach ($this->lanCfg['dhcp']['servers'] as $serverId => $serverCfg)
		{
			if (!isset($serverCfg['staticLeases']) || !count($serverCfg['staticLeases']))
				continue;

			foreach ($serverCfg['staticLeases'] as $addressCfg)
			{
				$item = ['type' => 'add',
					'params' => [
						'address' => $addressCfg['ip'],
						'mac-address' => $addressCfg['mac'],
						'server' => $serverId,
					]
				];
				if ($addressCfg['desc'] !== '')
					$item['params']['comment'] = $addressCfg['desc'];
				$this->cfgData[$root][] = $item;
			}
		}
	}

	function createData_Gateways()
	{
		if (0)
		{ // router
			if (!isset($this->lanDeviceCfg['gateways']) || !count($this->lanDeviceCfg['gateways']))
				return;

			$root = '/ip route';
			foreach ($this->lanDeviceCfg['gateways'] as $gw)
			{
				$distance = $gw['priority'];
				if (!$distance)
					$distance = 1;

				$item = ['type' => 'add',
					'params' => [
						'distance' => $distance,
						'gateway' => $gw['addr'],
					]
				];
				if ($gw['desc'] !== '')
					$item['params']['comment'] = $gw['desc'];

				$this->cfgData[$root][] = $item;
			}
		}
		else
		{
			$root = '/ip route';
			foreach ($this->lanDeviceCfg['addresses'] as $addressNdx => $addressCfg)
			{
				if (isset ($addressCfg['vlan']) && $addressCfg['vlan'] == $this->lanCfg['vlanManagement'])
				{
					if (isset ($addressCfg['gw']) && strlen ($addressCfg['gw']))
					{
						$item = ['type' => 'add',
							'params' => [
								'gateway' => $addressCfg['gw']
							]
						];
						$this->cfgData[$root][] = $item;
					}
				}
			}
		}
	}

	public function createScript($initMode = FALSE)
	{
		parent::createScript($initMode);

		$this->createData();

		$this->createScript_Init_Identity();
		$this->createScript_Init_Services();

		if ($this->wirelessMode == self::wrmWireless)
		{
			$this->csActiveRoot = '/interface wireless security-profiles';
			$this->createScriptForRoot();

			$this->csActiveRoot = '/interface wireless';
			$this->createScriptForRoot();
		}
		elseif ($this->wirelessMode == self::wrmWifiWave2)
		{
			$this->csActiveRoot = '/interface wifiwave2 security';
			$this->createScriptForRoot();

			$this->csActiveRoot = '/interface wifiwave2';
			$this->createScriptForRoot();
		}

		$this->createScript_Interfaces_HW_Vlans();

		$this->createScript_Interfaces_Addresses();
		$this->createScript_Firewall();
		$this->createScript_Gateways();

		$this->csActiveRoot = '/ip dhcp-client';
		$this->createScriptForRoot();

		$this->csActiveRoot = '/ip dns';
		$this->createScriptForRoot();

		$this->createScript_DHCP();
		$this->createScript_DHCP_Leases();

		$this->createScript_Init_User();
	}

	function createScript_Interfaces_Addresses()
	{
		$this->csActiveRoot = '/ip address';
		$this->createScriptForRoot();
	}

	function createScript_Interfaces_HW_Vlans()
	{
		$this->csActiveRoot = '/interface bridge';
		$this->createScriptForRoot();

		$this->csActiveRoot = '/interface bridge port';
		$this->createScriptForRoot();

		$this->csActiveRoot = '/interface ethernet switch vlan';
		$this->createScriptForRoot();


		$this->csActiveRoot = '/interface bridge vlan';
		$this->createScriptForRoot();

		$this->csActiveRoot = '/interface vlan';
		$this->createScriptForRoot();

		/*
		$this->script .= "/interface bridge\n";
		$this->script .= "set bridge1 vlan-filtering=yes\n";
		$this->script .= "\n";
		$this->script .= "\n";
		*/
	}

	function createScript_DHCP()
	{
		if (!isset($this->lanCfg['dhcp']))
		{
			return;
		}

		$this->csActiveRoot = '/ip pool';
		$this->createScriptForRoot();

		$this->csActiveRoot = '/ip dhcp-server';
		$this->createScriptForRoot();

		$this->csActiveRoot = '/ip dhcp-server network';
		$this->createScriptForRoot();
	}

	function createScript_DHCP_Leases()
	{
		$this->csActiveRoot = '/ip dhcp-server lease';
		$this->createScriptForRoot();
	}

	function createScript_Firewall()
	{
		$this->csActiveRoot = '/ip firewall filter';
		$cnt = $this->createScriptForRoot();

		if ($cnt)
		{
			$this->script .= "/ip firewall filter move [/ip firewall filter find action=accept] [/ip firewall filter find comment=\"DROP ALL\"]\n\n";
		}
	}

	function createScript_Gateways()
	{
		$this->csActiveRoot = '/ip route';
		$this->createScriptForRoot();
	}
}
