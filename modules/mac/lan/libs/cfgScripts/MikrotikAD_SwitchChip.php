<?php

namespace mac\lan\libs\cfgScripts;

use e10\Utility;


/**
 * class MikrotikAD_SwitchChip
 */
class MikrotikAD_SwitchChip extends \mac\lan\libs\cfgScripts\MikrotikAD
{
	var $csActiveRoot = '';
	var $rootsInfo = [];

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

		$this->createData_Interfaces_HW_Vlans();

		$this->createData_Interfaces_Addresses();

    if ($this->isRouter)
    {
			$this->createData_Firewall();

			$this->createData_Gateways();
		  $this->createData_DHCP();
		  $this->createData_DHCP_Leases();
    }
	}

	function createData_Interfaces_SW_Vlans()
	{

		$root = '/interface bridge';
		foreach ($this->lanCfg['vlans'] as $vlanNdx => $vlanCfg)
		{
			$item =['type' => 'add',
				'params' => [
					'fast-forward' => 'no',
					'name' => 'IFB_VLAN'.$vlanCfg['num']
				]
			];
			if ($vlanCfg['desc'] !== '')
				$item['params']['comment'] = $vlanCfg['desc'];

			$this->cfgData[$root][] = $item;
		}

		$root = '/interface vlan';
		foreach ($this->lanDeviceCfg['ports'] as $portNdx => $portCfg)
		{
			if (!isset($portCfg['vlans']) || !count($portCfg['vlans']))
				continue;

			if ($portCfg['portKind'] !== 5 && $portCfg['portKind'] !== 6)
				continue;

			foreach ($portCfg['vlans'] as $vlanNumber)
			{
				if ($portCfg['portRole'] === 15 || $portCfg['portRole'] === 20 ||
					$portCfg['portRole'] === 30 || $portCfg['portRole'] === 40) // trunk or hybrid port or VLANs list
				{
					$item = ['type' => 'add',
						'params' => [
							'interface' => $portCfg['portId'],
							'name' => 'IFV_' . $portCfg['portId'] . '_' . $vlanNumber,
							'vlan-id' => $vlanNumber,
						]
					];
					$this->cfgData[$root][] = $item;
				}
			}
		}

		$root = '/interface bridge port';
		foreach ($this->lanDeviceCfg['ports'] as $portNdx => $portCfg)
		{
			if (!isset($portCfg['vlans']) || !count($portCfg['vlans']))
				continue;

			if ($portCfg['portKind'] !== 5 && $portCfg['portKind'] !== 6)
				continue;

			foreach ($portCfg['vlans'] as $vlanListIdx => $vlanNumber)
			{
				if ($portCfg['portRole'] === 10 || ($portCfg['portRole'] === 15 && $vlanListIdx == 0)) // access or hybrid port
				{
					$item = ['type' => 'add',
						'params' => [
							'bridge' => 'IFB_VLAN' . $vlanNumber,
							'interface' => $portCfg['portId'],
							'pvid' => $vlanNumber
						]
					];
					$this->cfgData[$root][] = $item;
				}

				if ($portCfg['portRole'] === 15 || $portCfg['portRole'] === 20 ||
					  $portCfg['portRole'] === 30 || $portCfg['portRole'] === 40) // trunk or hybrid port or VLANs list
				{
					$item = ['type' => 'add',
						'params' => [
							'bridge' => 'IFB_VLAN' . $vlanNumber,
							'interface' => 'IFV_' . $portCfg['portId'] . '_' . $vlanNumber
						]
					];
					$this->cfgData[$root][] = $item;
				}
			}
		}


	}

	function createData_Interfaces_HW_Vlans()
	{
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
			}
			elseif ($portCfg['portKind'] === 10)
				$portRole = 'mng';

			foreach ($portCfg['vlans'] as $vlanNumber)
			{
				$portId = (($portRole == 'mng') ? 'switch1-cpu':$portCfg['portId']);
				$vlansOnPorts[$portRole][$vlanNumber][$portCfg['number']] = $portId;
				$vlansOnPorts['all'][$vlanNumber][$portCfg['number']] = $portId;
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
			//if ($portCfg['portKind'] !== 5 && $portCfg['portKind'] !== 6)
			//	continue;

			$portRole = $portCfg['portRole'];
			$vlans = [];
			if (isset($portCfg['vlans']) && count($portCfg['vlans']))
				foreach ($portCfg['vlans'] as $vn)
					$vlans[] = $vn;
			/*
			if ($portRole === 10)
			{ // native vlan
				$item = ['type' => 'add',
					'params' => [
						'bridge' => 'bridge1',
						'interface' => $portCfg['portId'],
						'hw' => 'yes',
						'pvid' => $portCfg['vlans'][0],
					]
				];
				$this->cfgData[$root][] = $item;

				$vlans[] = $portCfg['vlans'][0];
			}
			elseif ($portRole === 70 || $portRole === 90 || $portRole === 20 || $portRole === 30 || $portRole === 40)
			{ // local port
				*/
				$item = ['type' => 'add',
					'params' => [
						'bridge' => 'bridge1',
						'interface' => $portCfg['portId'],
						//'hw' => 'yes',
					]
				];
				$this->cfgData[$root][] = $item;
			//}

			if (count($vlans))
			{
				foreach ($vlans as $vn)
				{
					if (!isset($vlansPorts[$vn]) || !in_array($portCfg['portId'], $vlansPorts[$vn]))
						$vlansPorts[$vn][] = $portCfg['portId'];
				}
			}
		}





		$root = '/interface ethernet switch vlan';

		$allPorts = $vlansOnPorts['all'];
		ksort ($allPorts);
		foreach ($allPorts as $vlanNum => $vlan)
		{
			ksort($vlan);
			$ports = "";
			foreach ($vlan as $port)
			{
				if (strlen ($ports))
					$ports .= ",";
				$ports .= $port;
			}

			$item =['type' => 'add',
				'params' => [
					'ports' => $ports,
					'vlan-id' => $vlanNum,
					//'learn' => 'yes'
				]
			];
			$vlanCfg = \e10\searchArray($this->lanCfg['vlans'], 'num', $vlanNum);
			if ($vlanCfg['desc'] !== '')
				$item['params']['comment'] = $vlanCfg['desc'];

			$this->cfgData[$root][] = $item;
		}





		$root = '/interface bridge vlan';
		ksort($vlansPorts);
		foreach ($vlansPorts as $vlanNumber => $ports)
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

		$root = '/interface vlan';
		foreach ($this->lanCfg['vlans'] as $vlanNdx => $vlanCfg)
		{
			$item = ['type' => 'add',
				'params' => [
					'interface' => 'bridge1',
					'vlan-id' => $vlanCfg['num'],
					'name' => 'IFB_VLAN'.$vlanCfg['num'],
				]
			];
			if ($vlanCfg['desc'] !== '')
				$item['comment'] = $vlanCfg['desc'];

			$this->cfgData[$root][] = $item;
		}

		/*
		$this->script .= "/interface bridge\n";
		$this->script .= "set bridge1 vlan-filtering=yes\n";
		$this->script .= "\n";
		$this->script .= "\n";
		*/
	}

	function createData_Interfaces_Addresses()
	{
    if (!isset($this->lanDeviceCfg['addresses']) || ! count($this->lanDeviceCfg['addresses']))
    {

      return;
    }


		$usedAddresses = [];
		$root = '/ip address';
		foreach ($this->lanDeviceCfg['addresses'] as $addressCfg)
		{
			$interface = isset($addressCfg['vlan']) ? 'IFB_VLAN'.$addressCfg['vlan'] : $addressCfg['portId'];
			$item = ['type' => 'add',
				'params' => [
					'address' => $addressCfg['ip'],
					'interface' => $interface,
					'network' => $addressCfg['network'],
				]
			];
			$usedAddresses [] = $item['params']['address'];
			$this->cfgData[$root][] = $item;
		}

		foreach ($this->lanCfg['dhcp']['pools'] as $poolId => $poolCfg)
		{
			$interface = (isset($poolCfg['vlan'])) ? 'IFB_VLAN'.$poolCfg['vlan'] : 'XXXX';
			$item = ['type' => 'add',
				'params' => [
					'address' => $poolCfg['addressPrefix'].'1'.'/24',
					'interface' => $interface,
					'network' => $poolCfg['addressPrefix'].'0',
				]
			];

			if (in_array($item['params']['address'], $usedAddresses))
				continue;

			$this->cfgData[$root][] = $item;
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

	public function createScript($initMode = FALSE)
	{
		parent::createScript($initMode);

		$this->createData();



		$this->createScript_Init_User();

		$this->createScript_Init_Identity();
		$this->createScript_Init_Services();

		$this->createScript_Interfaces_HW_Vlans();

		$this->createScript_Interfaces_Addresses();
		$this->createScript_Firewall();
		$this->createScript_Gateways();
		$this->createScript_DHCP();
		$this->createScript_DHCP_Leases();
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
		if (!isset($this->lanDeviceCfg['gateways']) || !count($this->lanDeviceCfg['gateways']))
			return;

		$this->csActiveRoot = '/ip route';
		$this->createScriptForRoot();
	}
}
