<?php

namespace mac\lan\libs\cfgScripts;

use e10\Utility;


/**
 * Class Mikrotik_switch_crs1_crs2
 * @package mac\lan\libs\cfgScripts
 */
class Mikrotik_switch_crs1_crs2 extends \mac\lan\libs\cfgScripts\Mikrotik
{

	public function initRoots()
	{
		$this->rootsInfo ['/system identity'] = [
			'mandatoryColumns' => ['name']
		];
		$this->rootsInfo ['/ip pool'] = [
			'mandatoryColumns' => ['name'],
			'updateColumns' => ['ranges', 'comment', 'next-pool']
		];

		$this->rootsInfo ['/interface bridge'] = [
			'mandatoryColumns' => ['name'],
			'updateColumns' => ['comment']
		];
		$this->rootsInfo ['/interface bridge port'] = [
			'mandatoryColumns' => ['bridge', 'interface', 'hw'],
			'updateColumns' => ['comment']
		];

		$this->rootsInfo ['/interface ethernet switch ingress-vlan-translation'] = [
			'mandatoryColumns' => ['ports', 'customer-vid', 'new-customer-vid', 'sa-learning'],
			'updateColumns' => ['comment']
		];

		$this->rootsInfo ['/interface ethernet switch egress-vlan-tag'] = [
			'mandatoryColumns' => ['tagged-ports', 'vlan-id'],
			'updateColumns' => ['comment']
		];

		$this->rootsInfo ['/interface ethernet switch vlan'] = [
			'mandatoryColumns' => ['ports', 'learn'],
			'updateColumns' => ['comment']
		];

		$this->rootsInfo ['/interface ethernet switch'] = [
			'mandatoryColumns' => ['drop-if-invalid-or-src-port-not-member-of-vlan-on-ports']
		];

		$this->rootsInfo ['/interface vlan'] = [
			'mandatoryColumns' => ['interface', 'vlan-id', 'name']
		];

		$this->rootsInfo ['/ip address'] = [
			'mandatoryColumns' => ['address', 'interface']
		];

		$this->rootsInfo ['/ip route'] = [
			'mandatoryColumns' => ['gateway']
		];

		$this->rootsInfo ['/ip route'] = [
			'mandatoryColumns' => ['gateway']
		];

		$this->rootsInfo ['/ip dns'] = [
			'mandatoryColumns' => ['servers']
		];
	}

	function createData()
	{
		$this->initRoots();

		$this->createData_Init_Identity();
		$this->createData_Init_Services();

		$this->createData_Interface_Bridge();
		$this->createData_Interfaces_Vlans();

		$this->createData_Management_Device();

		$this->createData_Gateway();
		$this->createData_DNS();
	}

	function createData_Interface_Bridge()
	{
		$root = '/interface bridge';
		$item =['type' => 'add',
			'params' => [
				'name' => 'IFB_VLANS',
				'comment' => 'VLAN switching'
			]
		];
		$this->cfgData[$root][] = $item;

		$root = '/interface bridge port';
		foreach ($this->lanDeviceCfg['ports'] as $portNdx => $portCfg)
		{
			if (!isset($portCfg['vlans']) || !count($portCfg['vlans']))
				continue;

			if ($portCfg['portKind'] !== 5 && $portCfg['portKind'] !== 6)
				continue;

			$item =['type' => 'add',
				'params' => [
					'bridge' => 'IFB_VLANS',
					'interface' => $portCfg['portId'],
					'hw' => 'yes'
				]
			];

			if (strlen ($portCfg['desc']))
				$item['params']['comment'] = $portCfg['desc'];
			elseif ($portCfg['portRole'] == 10)
			{
				$vlanCfg = \e10\searchArray($this->lanCfg['vlans'], 'num', $portCfg['vlans'][0]);
				if ($vlanCfg['desc'] !== '')
					$item['params']['comment'] = 'Native VLAN: '.$vlanCfg['desc'];
			}

			$this->cfgData[$root][] = $item;
		}
	}

	function createData_Interfaces_Vlans()
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

		$root = '/interface ethernet switch ingress-vlan-translation';

		ksort ($vlansOnPorts['native']);
		foreach ($vlansOnPorts['native'] as $vlanNum => $vlan)
		{
			ksort($vlan);
			$portsStr = "";
			foreach ($vlan as $port)
			{
				if (strlen ($portsStr))
					$portsStr .= ",";
				$portsStr .= $port;
			}

			$item =['type' => 'add',
				'params' => [
					'ports' => $portsStr,
					'customer-vid' => '0',
					'new-customer-vid' => $vlanNum,
					'sa-learning' => 'yes'
				]
			];
			$vlanCfg = \e10\searchArray($this->lanCfg['vlans'], 'num', $vlanNum);
			if ($vlanCfg['desc'] !== '')
				$item['params']['comment'] = $vlanCfg['desc'];

			$this->cfgData[$root][] = $item;
		}

		$root = '/interface ethernet switch egress-vlan-tag';

		ksort ($vlansOnPorts['trunk']);
		foreach ($vlansOnPorts['trunk'] as $vlanNum => $vlan)
		{
			ksort($vlan);
			$portsStr = "";
			foreach ($vlan as $port)
			{
				$portsStr .= $port.",";
			}

			$item =['type' => 'add',
				'params' => [
					'tagged-ports' => $portsStr.'switch1-cpu',
					'vlan-id' => $vlanNum
				]
			];
			$vlanCfg = \e10\searchArray($this->lanCfg['vlans'], 'num', $vlanNum);
			if ($vlanCfg['desc'] !== '')
				$item['params']['comment'] = $vlanCfg['desc'];

			$this->cfgData[$root][] = $item;
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
					'learn' => 'yes'
				]
			];
			$vlanCfg = \e10\searchArray($this->lanCfg['vlans'], 'num', $vlanNum);
			if ($vlanCfg['desc'] !== '')
				$item['params']['comment'] = $vlanCfg['desc'];

			$this->cfgData[$root][] = $item;
		}

		$root = '/interface ethernet switch';
		$portsStr = "";
		foreach ($this->lanDeviceCfg['ports'] as $portNdx => $portCfg)
		{
			if (!isset($portCfg['vlans']) || !count($portCfg['vlans']))
				continue;

			if ($portCfg['portKind'] !== 5 && $portCfg['portKind'] !== 6)
				continue;

			if (strlen ($portsStr))
				$portsStr .= ",";
			$portsStr .= $portCfg['portId'];
		}
		$item =['type' => 'set',
			'params' => [
				'drop-if-invalid-or-src-port-not-member-of-vlan-on-ports' => $portsStr
			]
		];

		$this->cfgData[$root][] = $item;
	}

	function createData_Management_Device()
	{
		$root = '/interface vlan';

		foreach ($this->lanDeviceCfg['ports'] as $portNdx => $portCfg)
		{
			if (!isset($portCfg['vlans']) || !count($portCfg['vlans']))
				continue;

			if ($portCfg['portKind'] !== 10)
				continue;

			$item = ['type' => 'add',
				'params' => [
					'interface' => 'IFB_VLANS',
					'vlan-id' => $portCfg['vlans'][0],
					'name' => 'IFV_MNG'
				]
			];

			$this->cfgData[$root][] = $item;
		}

		$root = '/ip address';

		foreach ($this->lanDeviceCfg['addresses'] as $addressNdx => $addressCfg)
		{
			if (isset ($addressCfg['vlan']) && $addressCfg['vlan'] == $this->lanCfg['vlanManagement'])
			{
				$item = ['type' => 'add',
					'params' => [
						'address' => $addressCfg['ip'],
						'interface' => 'IFV_MNG'
					]
				];

				$this->cfgData[$root][] = $item;
			}
		}
	}

	function createData_Gateway()
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

	function createData_DNS()
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

	public function createScript($initMode = FALSE)
	{
		parent::createScript($initMode);

		$this->createData();

		$this->createScript_Init_User();

		$this->createScript_Init_Identity();
		$this->createScript_Init_Services();

		$this->createScript_Interface_Bridge();
		$this->createScript_Interfaces_Vlans();

		$this->createScript_Management_Device();

		$this->createScript_Gateway();
		$this->createScript_DNS();
	}

	function createScript_Interface_Bridge()
	{
		$this->csActiveRoot = '/interface bridge';
		$this->createScriptForRoot();

		$this->csActiveRoot = '/interface bridge port';
		$this->createScriptForRoot();
	}

	function createScript_Interfaces_Vlans()
	{
		$this->csActiveRoot = '/interface ethernet switch ingress-vlan-translation';
		$this->createScriptForRoot();

		$this->csActiveRoot = '/interface ethernet switch egress-vlan-tag';
		$this->createScriptForRoot();

		$this->csActiveRoot = '/interface ethernet switch vlan';
		$this->createScriptForRoot();

		$this->csActiveRoot = '/interface ethernet switch';
		$this->createScriptForRoot();
	}

	function createScript_Management_Device()
	{
		$this->csActiveRoot = '/interface vlan';
		$this->createScriptForRoot();

		$this->csActiveRoot = '/ip address';
		$this->createScriptForRoot();
	}

	function createScript_Gateway()
	{
		$this->csActiveRoot = '/ip route';
		$this->createScriptForRoot();
	}

	function createScript_DNS()
	{
		$this->csActiveRoot = '/ip dns';
		$this->createScriptForRoot();
	}

}
