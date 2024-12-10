<?php

namespace mac\lan\libs\cfgScripts;

use \Shipard\Utils\Utils;


/**
 * Class Mikrotik_router
 * @package mac\lan\libs\cfgScripts
 */
class Mikrotik_router extends \mac\lan\libs\cfgScripts\Mikrotik
{
	CONST vfSW = 0, vfHW = 1;

	var $vlanFiltering = self::vfSW;

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
			'updateColumns' => ['pvid', 'comment']
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

		if ($this->ipv6Enabled)
		{
			$this->rootsInfo ['/interface list'] = [
				'mandatoryColumns' => ['name'], 'updateColumns' => ['comment'],
			];
			$this->rootsInfo ['/interface list member'] = [
				'mandatoryColumns' => ['member', 'list'],
			];
			$this->rootsInfo ['/ipv6 address'] = [
				'mandatoryColumns' => ['address', 'interface']
			];
			$this->rootsInfo ['/ipv6 firewall address-list'] = [
				'mandatoryColumns' => ['address', 'list'],
				'updateColumns' => ['comment'],
			];
			$this->rootsInfo ['/ipv6 firewall filter'] = [
				'ignoredColumns' => ['comment'],
				'updateColumns' => ['comment']
			];
		}
	}

	public function setDevice($deviceRecData, $lanCfg)
	{
		parent::setDevice($deviceRecData, $lanCfg);
		if (isset ($this->deviceCfg['vlanFiltering']))
			$this->vlanFiltering = intval($this->deviceCfg['vlanFiltering']);
	}

	function createData()
	{
		$this->initRoots();

		$this->createData_Init_Identity();
		$this->createData_Init_Services();

		if ($this->vlanFiltering == self::vfSW)
		{
			$this->createData_Interfaces_SW_Vlans();
		}
		elseif ($this->vlanFiltering == self::vfHW)
		{
			$this->createData_Interfaces_HW_Vlans();
		}

		$this->createData_Interfaces_Addresses();
		$this->createData_Firewall();
		$this->createData_Gateways();
		$this->createData_DHCP();
		$this->createData_DHCP_Leases();

		if ($this->ipv6Enabled)
		{
			$this->createData_Interfaces_Addresses6();
			$this->createData_Firewall6_InterfaceList();
			$this->createData_Firewall6_AddrLists();
			$this->createData_Firewall6_Filter();
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
			if ($portCfg['portKind'] !== 5 && $portCfg['portKind'] !== 6)
				continue;

			$portRole = $portCfg['portRole'];
			$vlans = [];
			if (isset($portCfg['vlans']) && count($portCfg['vlans']))
				foreach ($portCfg['vlans'] as $vn)
					$vlans[] = $vn;

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
				$item = ['type' => 'add',
					'params' => [
						'bridge' => 'bridge1',
						'interface' => $portCfg['portId'],
						'hw' => 'yes',
					]
				];
				$this->cfgData[$root][] = $item;
			}

			if (count($vlans))
			{
				foreach ($vlans as $vn)
				{
					if (!isset($vlansPorts[$vn]) || !in_array($portCfg['portId'], $vlansPorts[$vn]))
						$vlansPorts[$vn][] = $portCfg['portId'];
				}
			}
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

	function createData_Interfaces_Addresses6()
	{
		$usedAddresses = [];
		$root = '/ipv6 address';

		foreach ($this->lanCfg['addrRanges6'] as $ar)
		{
			if ($ar['rangeType'] !== 0)
				continue;
			$interface = (isset($ar['vlan'])) ? 'IFB_VLAN'.$ar['vlan'] : 'XXXX';
			$item = ['type' => 'add',
				'params' => [
					'address' => $ar['prefix'].'1',
					'interface' => $interface,
				]
			];

			if (in_array($item['params']['address'], $usedAddresses))
				continue;

			$this->cfgData[$root][] = $item;
		}
	}

	function createData_Firewall6_AddrLists()
	{
		$root = '/ipv6 firewall address-list';

		$address_list = [
			['a' => '::/128', 						'c' => 'shp-dc: unspecified address'],
			['a' => '::1/128', 						'c' => 'shp-dc: lo'],
			['a' => 'fec0::/10', 					'c' => 'shp-dc: site-local'],
			['a' => '::ffff:0.0.0.0/96', 	'c' => 'shp-dc: ipv4-mapped'],
			['a' => '::/96', 							'c' => 'shp-dc: ipv4 compat'],
			['a' => '100::/64', 					'c' => 'shp-dc: discard only'],
			['a' => '2001:db8::/32', 			'c' => 'shp-dc: documentation'],
			['a' => '2001:10::/28',				'c' => 'shp-dc: ORCHID'],
			['a' => '3ffe::/16', 					'c' => 'shp-dc: 6bone'],
		];

		foreach ($address_list as $al)
		{
			$item = ['type' => 'add',
				'params' => [
					'address' => $al['a'], 'comment' => $al['c'], 'list' => 'bad_ipv6',
				]
			];
			$this->cfgData[$root][] = $item;
		}
	}

	function createData_Firewall6_Filter()
	{
		$root = '/ipv6 firewall filter';

		$stdRules = [
			['action' => 'accept', 'chain' => 'input', 'connection-state' => 'established,related,untracked', 'comment' => 'shp-dc: accept established,related,untracked',],
			['action' => 'drop', 'chain' => 'input', 'connection-state' => 'invalid', 'comment' => 'shp-dc: drop invalid',],
			['action' => 'accept', 'chain' => 'input', 'protocol' => 'icmpv6', 'comment' => 'shp-dc: accept ICMPv6',],
			['action' => 'accept', 'chain' => 'input', 'protocol' => 'udp', 'port' => '33434-33534', 'comment' => 'shp-dc: accept UDP traceroute',],
			['action' => 'accept', 'chain' => 'input', 'protocol' => 'udp', 'dst-port' => '546', 'src-address' => 'fe80::/10', 'comment' => 'shp-dc: accept DHCPv6-Client prefix delegation',],
			['action' => 'accept', 'chain' => 'input', 'protocol' => 'udp', 'dst-port' => '500,4500', 'comment' => 'shp-dc: accept IKE',],
			['action' => 'accept', 'chain' => 'input', 'protocol' => 'ipsec-ah', 'comment' => 'shp-dc: accept ipsec AH',],
			['action' => 'accept', 'chain' => 'input', 'protocol' => 'ipsec-esp', 'comment' => 'shp-dc: accept ipsec ESP',],
			['action' => 'accept', 'chain' => 'input', 'ipsec-policy' => 'in,ipsec', 'comment' => 'shp-dc: accept all that matches ipsec policy',],
			['action' => 'drop', 'chain' => 'input', 'in-interface-list' => '!LAN6', 'comment' => 'shp-dc: drop everything else not coming from LAN',],
			['action' => 'accept', 'chain' => 'forward', 'connection-state' => 'established,related,untracked', 'comment' => 'shp-dc: accept established,related,untracked',],
			['action' => 'drop', 'chain' => 'forward', 'connection-state' => 'invalid', 'comment' => 'shp-dc: drop invalid',],
			['action' => 'drop', 'chain' => 'forward', 'src-address-list' => 'bad_ipv6', 'comment' => 'shp-dc: drop packets with bad src ipv6',],
			['action' => 'drop', 'chain' => 'forward', 'dst-address-list' => 'bad_ipv6', 'comment' => 'shp-dc: drop packets with bad dst ipv6',],
			['action' => 'drop', 'chain' => 'forward', 'protocol' => 'icmpv6', 'hop-limit' => 'equal:1', 'comment' => 'shp-dc: rfc4890 drop hop-limit=1',],
			['action' => 'accept', 'chain' => 'forward', 'protocol' => 'icmpv6', 'comment' => 'shp-dc: accept ICMPv6',],
			['action' => 'accept', 'chain' => 'forward', 'protocol' => '139', 'comment' => 'shp-dc: accept HIP',],
			['action' => 'accept', 'chain' => 'forward', 'protocol' => 'udp', 'dst-port' => '500,4500', 'comment' => 'shp-dc: accept IKE',],
			['action' => 'accept', 'chain' => 'forward', 'protocol' => 'ipsec-ah', 'comment' => 'shp-dc: accept ipsec AH',],
			['action' => 'accept', 'chain' => 'forward', 'protocol' => 'ipsec-esp', 'comment' => 'shp-dc: accept ipsec ESP',],
			['action' => 'accept', 'chain' => 'forward', 'ipsec-policy' => 'in,ipsec', 'comment' => 'shp-dc: accept all that matches ipsec policy',],
		];
		foreach ($stdRules as $rule)
		{
			$item = ['type' => 'add',
				'params' => $rule,
			];
			$this->cfgData[$root][] = $item;
		}

		// -- SERVERS
		// todo

		// -- DROP OTHERS
		$item = ['type' => 'add',
			'params' => ['action' => 'drop', 'chain' => 'forward', 'in-interface-list' => '!LAN6', 'comment' => 'shp-dc: drop everything else not coming from LAN',],
		];
		$this->cfgData[$root][] = $item;
	}

	function createData_Firewall6_InterfaceList()
	{
		$root = '/interface list';

		$item = ['type' => 'add',
			'params' => [
				'name' => 'LAN6', 'comment' => 'VLAN intefaces with enabled ipv6',
			]
		];
		$this->cfgData[$root][] = $item;

		$root = '/interface list member';

		$usedIfaces = [];
		foreach ($this->lanCfg['addrRanges6'] as $ar)
		{
			if ($ar['rangeType'] !== 0)
				continue;
			$interface = (isset($ar['vlan'])) ? 'IFB_VLAN'.$ar['vlan'] : 'XXXX';
			if (in_array($interface, $usedIfaces))
				continue;
			$item = ['type' => 'add',
				'params' => [
					'list' => 'LAN6',
					'interface' => $interface,
				]
			];
			$this->cfgData[$root][] = $item;
		}
	}

	public function createScript($initMode = FALSE)
	{
		parent::createScript($initMode);

		$this->createData();

		if ($this->initMode)
		{
			$this->script .= "### macGen: {$this->macGen}; script mode: router (old); class: ".Utils::getClassNameShort($this::class)." ###\n";
			$this->script .= "### ipv6: ".($this->ipv6Enabled ? 'ENABLED' : 'unsupported')." ###\n";
			$this->script .= "\n";
		}

		$this->createScript_Init_Identity();
		$this->createScript_Init_Services();

		if ($this->vlanFiltering == self::vfSW)
		{
			$this->createScript_Interfaces_SW_Vlans();
		}
		elseif ($this->vlanFiltering == self::vfHW)
		{
			$this->createScript_Interfaces_HW_Vlans();
		}

		$this->createScript_Interfaces_Addresses();
		$this->createScript_Firewall();
		$this->createScript_Gateways();
		$this->createScript_DHCP();
		$this->createScript_DHCP_Leases();

		if ($this->ipv6Enabled)
		{
			$this->createScript_Firewall6();
			$this->createScript_Interfaces_Addresses6();
		}

		$this->createScript_Init_User();
	}

	function createScript_Interfaces_Addresses()
	{
		$this->csActiveRoot = '/ip address';
		$this->createScriptForRoot();
	}

	function createScript_Interfaces_Addresses6()
	{
		$this->csActiveRoot = '/ipv6 address';
		$this->createScriptForRoot();
	}

	function createScript_Firewall6()
	{
		$this->csActiveRoot = '/interface list';
		$this->createScriptForRoot();
		$this->csActiveRoot = '/interface list member';
		$this->createScriptForRoot();

		$this->csActiveRoot = '/ipv6 firewall address-list';
		$this->createScriptForRoot();

		$this->csActiveRoot = '/ipv6 firewall filter';
		$cnt = $this->createScriptForRoot();
		//if ($cnt)
			//$this->script .= "/ip firewall filter move [/ip firewall filter find action=accept] [/ip firewall filter find comment=\"DROP ALL\"]\n\n";
	}

	function createScript_Interfaces_SW_Vlans()
	{
		$this->csActiveRoot = '/interface bridge';
		$this->createScriptForRoot();

		$this->csActiveRoot = '/interface vlan';
		$this->createScriptForRoot();

		$this->csActiveRoot = '/interface bridge port';
		$this->createScriptForRoot();
	}

	function createScript_Interfaces_HW_Vlans()
	{
		$this->csActiveRoot = '/interface bridge';
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
