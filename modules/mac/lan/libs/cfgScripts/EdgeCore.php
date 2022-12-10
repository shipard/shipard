<?php

namespace mac\lan\libs\cfgScripts;

use e10\Utility, \e10\utils;


/**
 * Class EdgeCore
 * @package mac\lan\libs\cfgScripts
 */
class EdgeCore extends \mac\lan\libs\cfgScripts\CoreCfgScript
{
	function createData()
	{
		$this->createData_Identity();
		$this->createData_Vlans();
		$this->createData_VLANsAddress();
		$this->createData_Ports();
	}

	function createData_Identity()
	{
		$this->cfgData['flags'][] = 'prompt '.$this->description($this->lanDeviceCfg['id']);
		$this->cfgData['flags'][] = 'hostname '.$this->description($this->lanDeviceCfg['id']);
	}

	function createData_Vlans()
	{
		$this->cfgData['vlansList'][0] = [
			'id' => 'vlan database', 'params' => [],
		];

		$usedVlansNumbers = [];
		foreach ($this->lanDeviceCfg['ports'] as $portNdx => $portCfg)
		{
			if (!isset($portCfg['vlans']) || !count($portCfg['vlans']))
				continue;

			foreach ($portCfg['vlans'] as $vlanNumber)
			{
				if (!in_array($vlanNumber, $usedVlansNumbers))
					$usedVlansNumbers[] = $vlanNumber;
			}
		}

		foreach ($this->lanCfg['vlans'] as $vlanNdx => $vlanCfg)
		{
			if (!in_array($vlanCfg['num'], $usedVlansNumbers))
				continue;

			$isc = '';
			$isc .= 'VLAN '.$vlanCfg['num'];
			$isc .= ' name '.$this->description($vlanCfg['desc']);
			$isc .= ' media ethernet';
			$this->cfgData['vlansList'][0]['params'][] = $isc;
		}
	}

	function createData_VLANsAddress()
	{
		$this->cfgData['vlansSettings'] = [];

		foreach ($this->lanDeviceCfg['addresses'] as $addressCfg)
		{
			if (!isset($addressCfg['vlan']))
				continue;

			if (strstr($addressCfg['ip'], '/') == FALSE)
				$ipAddr = $addressCfg['ip'];
			else
				$ipAddr = strchr($addressCfg['ip'], '/', TRUE);

			$id = 'interface vlan '.$addressCfg['vlan'];
			$p = 'ip address '.$ipAddr.' '.$addressCfg['maskLong'];

			$this->cfgData['vlansSettings'][$id] = ['id' => $id, 'params' => [$p]];
		}

		/*
		foreach ($this->lanDeviceCfg['addresses'] as $addressCfg)
		{
			if (!isset($addressCfg['gw']))
				continue;

			$this->script .= 'ip default-gateway '.$addressCfg['gw']."\n";
		}
		*/
	}

	function createData_Ports()
	{
		$this->cfgData['ports'] = [];

		foreach ($this->lanDeviceCfg['ports'] as $portNdx => $portCfg)
		{
			$portKind = $portCfg['portKind'];
			if ($portKind !== 5 && $portKind !== 6)
				continue;
			//if (!isset($portCfg['vlans']) || !count($portCfg['vlans']))
			//	continue;

			$portRole = $portCfg['portRole'];

			$portId = 'interface ethernet 1/'.$portCfg['number'];
			$portParams = [];

			$vlansToRemove = [];

			$currentVlans = [];
			if (!$this->initMode)
			{
				if (isset($this->cfgRunningConfig['ports'][$portId]))
					$this->detectPortsVlans($this->cfgRunningConfig['ports'][$portId]['params'], $currentVlans);
				foreach ($currentVlans as $cvn)
				{
					if (!in_array($cvn, $portCfg['vlans']))
						$vlansToRemove[] = $cvn;
				}
			}

			if ($portCfg['desc'] !== '')
				$portParams[] = 'description '.$this->description($portCfg['desc']);

			if ($portRole === 10)
			{ // access port
				$portParams[] = 'switchport allowed vlan add '.$portCfg['vlans'][0].' untagged';
				$portParams[] = 'switchport mode access';
				$portParams[] = 'switchport native vlan '.$portCfg['vlans'][0];
				$portParams[] = 'switchport allowed vlan remove 1';

				if(count($vlansToRemove))
				{
					$ivl = $this->interfaceVlansList($vlansToRemove);
					foreach ($ivl as $ivlItem)
						$portParams[] = 'switchport allowed vlan remove '.$ivlItem;
				}
				$portParams[] = 'no shutdown';
			}
			elseif ($portRole === 15)
			{ // access port
				$portParams[] = 'switchport mode hybrid';
				$portParams[] = 'switchport native vlan '.$portCfg['vlans'][0];

				$ivl = $this->interfaceVlansList($portCfg['vlans']);
				foreach ($ivl as $ivlItem)
					$portParams[] = 'switchport allowed vlan add '.$ivlItem.' tagged';

				$portParams[] = 'switchport allowed vlan remove 1';

				if(count($vlansToRemove))
				{
					$ivl = $this->interfaceVlansList($vlansToRemove);
					foreach ($ivl as $ivlItem)
						$portParams[] = 'switchport allowed vlan remove '.$ivlItem;
				}
				$portParams[] = 'switchport allowed vlan add '.$portCfg['vlans'][0].' untagged';
				$portParams[] = 'no shutdown';
			}
			elseif ($portRole === 20 || $portRole === 30)
			{ // trunk - uplink / downlink
				$portParams[] = 'switchport mode trunk';
				$portParams[] = 'no switchport native vlan';

				$ivl = $this->interfaceVlansList($portCfg['vlans']);
				foreach ($ivl as $ivlItem)
					$portParams[] = 'switchport allowed vlan add '.$ivlItem.' tagged';
				$portParams[] = 'switchport allowed vlan remove 1';

				if(count($vlansToRemove))
				{
					$ivl = $this->interfaceVlansList($vlansToRemove);
					foreach ($ivl as $ivlItem)
						$portParams[] = 'switchport allowed vlan remove ' . $ivlItem;
				}
				$portParams[] = 'no shutdown';
			}
			elseif ($portRole === 40)
			{ // vlan list
				$portParams[] = 'switchport mode trunk';
				$portParams[] = 'no switchport native vlan';

				$ivl = $this->interfaceVlansList($portCfg['vlans']);
				foreach ($ivl as $ivlItem)
					$portParams[] = 'switchport allowed vlan add '.$ivlItem.' tagged';
				$portParams[] = 'switchport allowed vlan remove 1';

				if(count($vlansToRemove))
				{
					$ivl = $this->interfaceVlansList($vlansToRemove);
					foreach ($ivl as $ivlItem)
						$portParams[] = 'switchport allowed vlan remove ' . $ivlItem;
				}
				$portParams[] = 'no shutdown';
			}
			else
			{
				$portParams[] = 'shutdown';
			}

			$this->cfgData['ports'][$portId] =['id' => $portId, 'params' => $portParams];
		}
	}

	public function createScript($initMode = FALSE)
	{
		parent::createScript($initMode);

		$this->createData();

		$this->createScript_DefaultGateway();
		$this->createScript_Init_Ssh();
		$this->createScript_Vlans();
		$this->createScript_VLANsAddress();
		$this->createScript_Init_User();
		$this->createScript_Init_Identity();
		$this->createScript_Ports();
	}

	function createScript_Init_Ssh()
	{
		if (!$this->initMode)
			return;

		$this->script .= "ip ssh crypto host-key generate\n";
		$this->script .= "ip ssh save host-key\n";
		$this->script .= "configure\n";
		$this->script .= "ip ssh server\n";
		$this->script .= "no ip http server\n";
		$this->script .= "no ip telnet server\n";
		$this->script .= "end\n";
		$this->script .= "\n";
	}

	function createScript_Init_Identity()
	{
		$script = '';

		$f = 'prompt '.$this->description($this->lanDeviceCfg['id']);
		if ($this->initMode || !$this->cfgRunningConfig || !in_array($f, $this->cfgRunningConfig['flags']))
			$script .= $f."\n";

		$f = 'hostname '.$this->description($this->lanDeviceCfg['id']);
		if ($this->initMode || !$this->cfgRunningConfig || !in_array($f, $this->cfgRunningConfig['flags']))
			$script .= $f."\n";

		if ($script !== '')
		{
			$this->script .= "configure\n";
			$this->script .= $script;
			$this->script .= "end\n\n";
		}
	}

	function createScript_Init_User()
	{
		if (!$this->initMode)
			return;

		$password = base_convert(time()*mt_rand(10, 20) + mt_rand(100000, 9999999999), 10,36) .
			base_convert(time()*mt_rand(10, 20) + mt_rand(100000, 9999999999), 10,36);

		$user = 'js';

		$this->script .= "configure\n";
		$this->script .= "username ".$user." access-level 15\n";
		$this->script .= "username ".$user." password 0 ".$password."\n";
		$this->script .= "end\n";

		$this->script .= "\n";
		$this->script .= "copy tftp public-key\n";
		$this->script .= $this->lanCfg['mainServerLanControlIp']."\n";

		$sshMode = intval($this->deviceCfg['sshMode'] ?? 0);
		if ($sshMode === 0)
		{ // old DSA mode
			$this->script .= "2\n";
			$this->script .= "shn_ssh_key_dsa.pub\n";
		}
		else
		{ // new RSA mode
			$this->script .= "shn_ssh_key_rsa2048_ec.pub_ec\n";
		}

		$this->script .= $user."\n";
		$this->script .= "\n";
		$this->script .= "\n";

		$this->script .= "\n";
	}

	function createScript_Vlans()
	{
		$script = '';
		foreach ($this->cfgData['vlansList'][0]['params'] as $vp)
		{
			$doIt = 0;
			if ($this->initMode || !$this->cfgRunningConfig)
				$doIt = 1;
			elseif (!in_array($vp, $this->cfgRunningConfig['vlansList'][0]['params']))
				$doIt = 1;
			if (!$doIt)
				continue;

			$script .= $vp . "\n";
		}

		if ($script !== '')
		{
			$this->script .= "configure\n";
			$this->script .= "vlan database\n";
			$this->script .= $script;
			$this->script .= "end\n\n";
		}
	}

	function createScript_DefaultGateway()
	{
		if (!$this->initMode)
			return;

		foreach ($this->lanDeviceCfg['addresses'] as $addressCfg)
		{
			if (!isset($addressCfg['gw']))
				continue;

			$this->script .= "configure\n";
			$this->script .= 'ip default-gateway '.$addressCfg['gw']."\n";
			$this->script .= "end\n";
		}

		$this->script .= "\n";
	}

	function createScript_VLANsAddress()
	{
		$script = '';
		foreach ($this->cfgData['vlansSettings'] as $vsId => $vs)
		{
			$doIt = 0;

			if ($this->initMode || !$this->cfgRunningConfig)
				$doIt = 1;
			elseif (!isset($this->cfgRunningConfig['vlansSettings'][$vsId]))
				$doIt = 1;

			if (!$doIt)
			{
				foreach ($vs['params'] as $vsp)
				{
					if (!in_array($vsp, $this->cfgRunningConfig['vlansSettings'][$vsId]['params']))
						$doIt = 1;
				}
			}

			if (!$doIt)
				continue;

			$script .= $vsId."\n";
			foreach ($vs['params'] as $vsp)
				$script .= $vsp."\n";
			$script .= "exit\n";
		}

		if ($script !== '')
		{
			$this->script .= "configure\n";
			$this->script .= $script;
			$this->script .= "end\n\n";
		}
	}

	function createScript_Ports()
	{
		$script = '';
		foreach ($this->cfgData['ports'] as $portId => $ps)
		{
			$doIt = 0;

			if ($this->initMode || !$this->cfgRunningConfig)
				$doIt = 1;
			elseif (!isset($this->cfgRunningConfig['ports'][$portId]))
				$doIt = 1;

			if (!$doIt)
			{
				foreach ($ps['params'] as $vsp)
				{
					if ($vsp === 'no shutdown' || $vsp === 'no switchport native vlan')
						continue;
					if (!in_array($vsp, $this->cfgRunningConfig['ports'][$portId]['params']))
						$doIt = 1;
				}
			}

			if (!$doIt)
				continue;

			$script .= $portId."\n";
			foreach ($ps['params'] as $vsp)
				$script .= $vsp."\n";
			$script .= "exit\n";
		}

		if ($script !== '')
		{
			$this->script .= "configure\n";
			$this->script .= $script;
			$this->script .= "end\n\n";
		}
	}

	function description($string)
	{
		$s = utils::safeChars($string);
		$s = str_replace('-', '_', $s);
		return $s;
	}

	function interfaceVlansList($vlans)
	{
		$list = [];
		$maxRowLen = 80;

		$s = '';

		$lastVN = -1;
		$intervalBegin = 0;
		$intervalEnd = 0;

		foreach ($vlans as $vn)
		{
			if ($lastVN === -1)
			{
				$intervalBegin = $vn;
				$intervalEnd = $vn;
				$lastVN = $vn;
				continue;
			}

			if ($vn === $lastVN + 1)
			{
				$intervalEnd = $vn;
				$lastVN = $vn;
				continue;
			}

			if ($intervalBegin === $intervalEnd)
			{
				if ($s !== '')
				{
					if (strlen($s) > $maxRowLen)
					{
						$list[] = $s;
						$s = '';
					}
					else
						$s .= ',';
				}
				$s .= $intervalBegin;
				$intervalBegin = $vn;
				$intervalEnd = $vn;
				$lastVN = $vn;
				continue;
			}

			if ($s !== '')
			{
//				$s .= ',';
				if (strlen($s) > $maxRowLen)
				{
					$list[] = $s;
					$s = '';
				}
				else
					$s .= ',';
			}
			$s .= $intervalBegin.'-'.$intervalEnd;
			$intervalBegin = $vn;
			$intervalEnd = $vn;
			$lastVN = $vn;
		}

		if ($s !== '')
		{
			$s .= ',';
		}

		if ($intervalBegin === $intervalEnd)
			$s .= $intervalBegin;
		else
			$s .= $intervalBegin . '-' . $intervalEnd;

		if ($s !== '')
			$list[] = $s;

		return $list;
	}

	function detectPortsVlans($params, &$vlans)
	{
		$vp = '';
		foreach ($params as $p)
		{
			if (substr($p, 0, 28) !== 'switchport allowed vlan add ')
				continue;
			$vp = $p;
			break;
		}

		if ($vp === '')
			return;

		$pp = explode (' ', $vp);
		if (!isset($pp[4]))
			return;

		$vlansParts = explode(',', $pp[4]);

		foreach ($vlansParts as $vp)
		{
			$vpParts = explode('-', $vp);
			if (count($vpParts) === 2)
			{
				for ($vn = intval($vpParts[0]); $vn <= intval($vpParts[1]); $vn++)
				{
					if (!in_array($vn, $vlans))
						$vlans[] = $vn;
				}
				continue;
			}

			$vn = intval($vp);
			if (!in_array($vn, $vlans))
				$vlans[] = $vn;
		}
	}

	function cfgParser()
	{
		return new \mac\lan\libs\cfgScripts\parser\EdgeCore($this->app());
	}
}
