<?php

namespace mac\lan\libs\cfgScripts;

use e10\Utility, \e10\utils;


/**
 * Class Aruba
 * @package mac\lan\libs\cfgScripts
 */
class Aruba extends \mac\lan\libs\cfgScripts\CoreCfgScript
{
	CONST arAP = 0, arController = 1;

	var $apRole = self::arAP;

	public function setDevice($deviceRecData, $lanCfg)
	{
		parent::setDevice($deviceRecData, $lanCfg);
		if (isset ($this->deviceCfg['apRole']))
			$this->apRole = intval($this->deviceCfg['apRole']);
	}

	function createData()
	{
		$this->createData_Identity();
		$this->createData_WLANS();
	}

	function createData_Identity()
	{
		$this->cfgData['flags'][] = 'virtual-controller-country CZ';
		//$this->cfgData['flags'][] = 'name '.$this->description($this->lanDeviceCfg['id']);
	}

	function createData_WLANS()
	{
		if (!isset($this->lanDeviceCfg['wlans']))
			return;
		if ($this->apRole !== self::arController)
			return;

		$this->cfgData['ssids'] = [];

		foreach ($this->lanDeviceCfg['wlans'] as $wlanNdx)
		{
			$wlan = $this->lanCfg['wlans'][$wlanNdx];

			$ssidId = 'wlan ssid-profile '.$wlan['ssid'];
			$ssidParams = [];

			$ssidParams[] = 'essid '.$wlan['ssid'];
			$ssidParams[] = 'vlan '.$wlan['vlan'];
			$ssidParams[] = 'opmode wpa2-psk-aes';
			$ssidParams[] = 'wpa-passphrase "'.$wlan['wpaPassphrase'].'"';
			$ssidParams[] = 'set-role-unrestricted';

			$this->cfgData['ssids'][$ssidId] = ['id' => $ssidId, 'params' => $ssidParams];
		}
	}

	public function createScript($initMode = FALSE)
	{
		parent::createScript($initMode);

		$this->createData();

		$this->createScript_Identity();
		$this->createScript_IP();
		$this->createScript_WLANS();
	}

	function createScript_Identity()
	{
		$script = '';

		if ($this->apRole === self::arController)
		{
			$f = 'virtual-controller-country CZ';
			if ($this->initMode || !$this->cfgRunningConfig || !in_array($f, $this->cfgRunningConfig['flags']))
				$script .= $f . "\n";

			if ($script !== '')
			{
				$this->script .= "configure\n";
				$this->script .= $script;
				$this->script .= "end\n\n";
			}
		}

		$f = 'hostname '.$this->description($this->lanDeviceCfg['id']);
		if ($this->initMode || !$this->cfgRunningConfig || !in_array($f, $this->cfgRunningConfig['flags']))
			$this->script .= 'hostname '.$this->description($this->lanDeviceCfg['id'])."\n\n";
	}

	function createScript_IP()
	{
		if (!$this->initMode)
			return;

		foreach ($this->lanDeviceCfg['addresses'] as $addressCfg)
		{
			if (!isset($addressCfg['gw']))
				continue;

			$isc = '';
			$isc .= 'ip-address '.$addressCfg['ip'].' '.$addressCfg['maskLong'].' '.$addressCfg['gw'].' '.$addressCfg['gw'];
			$this->script .= $isc."\n";

			$this->script .= 'uplink-vlan '.$addressCfg['vlan']."\n";
		}

		// -- uplink-vlan
		foreach ($this->lanDeviceCfg['addresses'] as $addressCfg)
		{
			if (!isset($addressCfg['vlan']))
				continue;

			$this->script .= 'uplink-vlan '.$addressCfg['vlan']."\n";
			break;
		}

		$this->script .= "\n";
	}

	function createScript_WLANS()
	{
		if (!isset($this->lanDeviceCfg['wlans']))
			return;

		$script = '';
		if (isset($this->cfgData['ssids']))
		{
			foreach ($this->cfgData['ssids'] as $ssidId => $ss) {
				$doIt = 0;

				if ($this->initMode || !$this->cfgRunningConfig)
					$doIt = 1;
				elseif (!isset($this->cfgRunningConfig['ssids'][$ssidId]))
					$doIt = 1;

				if (!$doIt) {
					foreach ($ss['params'] as $vsp) {
						if (substr($vsp, 0, 14) === 'wpa-passphrase' || $vsp === 'set-role-unrestricted')
							continue;
						if (!in_array($vsp, $this->cfgRunningConfig['ssids'][$ssidId]['params']))
							$doIt = 1;
					}
				}

				if (!$doIt)
					continue;

				$script .= $ssidId . "\n";
				foreach ($ss['params'] as $vsp)
					$script .= $vsp . "\n";
				$script .= "exit\n\n";
			}
		}

		if ($script !== '')
		{
			$this->script .= "config\n";
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

	function cfgParser()
	{
		return new \mac\lan\libs\cfgScripts\parser\Aruba($this->app());
	}
}
