<?php

namespace mac\lan\libs;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class LanCfgCreator
 * @package mac\lan\libs
 */
class LanCfgCreator extends Utility
{
	/** @var \mac\lan\TableLans */
	var $tableLans;
	/** @var \mac\lan\TableVlans */
	var $tableVlans;
	/** @var \mac\lan\TableDevices */
	var $tableDevices;
	/** @var \mac\lan\TableDevicesPorts */
	var $tableDevicesPorts;

	var $lanNdx = 0;
	var $lanRecData = NULL;

	var $mainRouterNdx = 0;
	var $mainServerLanControlNdx = 0;

	var $cfg = [];
	var $cfgVer = '';

	public function init()
	{
		$this->tableLans = $this->app()->table('mac.lan.lans');
		$this->tableVlans = $this->app()->table('mac.lan.vlans');
		$this->tableDevices = $this->app()->table('mac.lan.devices');
		$this->tableDevicesPorts = $this->app()->table('mac.lan.devicesPorts');

		$this->cfg['devices'] = [];
		$this->cfg['dhcp'] = [];
	}

	public function setLan ($lanNdx)
	{
		$this->lanNdx = $lanNdx;
	}

	public function load()
	{
		$this->loadLanCore();
		$this->loadVLANs();
		$this->loadWiFi();
		$this->loadDevices();
		$this->loadDHCP();

		$this->loadAddresses();

		$this->applyLoadedWiFi();

		$this->cacheSave();
	}

	function loadLanCore()
	{
		$this->lanRecData = $this->tableLans->loadItem($this->lanNdx);
		if (!$this->lanRecData)
			return;

		$this->mainRouterNdx = $this->lanRecData['mainRouter'];
		$this->mainServerLanControlNdx = $this->lanRecData['mainServerLanControl'];

		$this->cfg['mainServerLanControl'] = $this->mainServerLanControlNdx;

		// -- management VLAN
		$this->cfg['vlanManagementNdx'] = $this->lanRecData['vlanManagement'];
		$this->cfg['vlanManagement'] = 0;
		if ($this->cfg['vlanManagementNdx'])
		{
			$v = $this->tableVlans->loadItem($this->cfg['vlanManagementNdx']);
			if ($v)
				$this->cfg['vlanManagement'] = $v['num'];
		}

		// -- admin VLAN
		$this->cfg['vlanAdminsNdx'] = $this->lanRecData['vlanAdmins'];
		$this->cfg['vlanAdmins'] = 0;
		if ($this->cfg['vlanAdminsNdx'])
		{
			$v = $this->tableVlans->loadItem($this->cfg['vlanAdminsNdx']);
			if ($v)
				$this->cfg['vlanAdmins'] = $v['num'];
		}

		// -- WiFi management VLANs
		$this->cfg['vlanManagementWiFi'] = [];

		$ql[] = 'SELECT * FROM [e10_base_doclinks] AS docLinks';
		array_push($ql, ' WHERE srcTableId = %s', 'mac.lan.lans', 'AND dstTableId = %s', 'mac.lan.vlans');
		array_push($ql, ' AND docLinks.linkId = %s', 'mac-lans-wifi-mng-vlans', 'AND srcRecId = %i', $this->lanNdx);

		$rows = $this->db()->query($ql);
		foreach ($rows as $r)
		{
			$v = $this->tableVlans->loadItem($r['dstRecId']);
			if ($v)
				$this->cfg['vlanManagementWiFi'][] = $v['num'];
		}

		// --  management IP ranges
		$this->cfg['ipRangesManagement'] = [];
		// --  admins IP ranges
		$this->cfg['ipRangesAdmins'] = [];
		// --  WiFi management IP ranges
		$this->cfg['ipRangesManagementWiFi'] = [];

		$q [] = 'SELECT ranges.range as vlanRange, ranges.addressGateway, ranges.addressPrefix, vlans.num AS vlanNumber ';
		array_push($q, ' FROM [mac_lan_lansAddrRanges] AS [ranges]');
		array_push($q, ' LEFT JOIN [mac_lan_vlans] AS [vlans] ON ranges.vlan = vlans.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND ranges.[lan] = %i', $this->lanNdx);
		array_push($q, ' AND ranges.[docState] = %i', 4000);
		array_push($q, ' AND vlans.[num] IN %in', array_merge ([$this->cfg['vlanManagement'], $this->cfg['vlanAdmins']], $this->cfg['vlanManagementWiFi']));
		array_push($q, ' ORDER BY vlans.num, INET_ATON(ranges.range)');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$vlanNumber = intval($r['vlanNumber']);
			switch ($vlanNumber)
			{
				case $this->cfg['vlanManagement']:
					$this->cfg['ipRangesManagement'][] = $r['vlanRange'];
					break;
				case $this->cfg['vlanAdmins']:
					$this->cfg['ipRangesAdmins'][] = $r['vlanRange'];
					break;
				default:
					if (in_array($r['vlanNumber'], $this->cfg['vlanManagementWiFi']))
					{
						$this->cfg['ipRangesManagementWiFi'][] = $r['vlanRange'];
						if (!isset($this->cfg['mainServerWifiControlIp']))
							$this->cfg['mainServerWifiControlIp'] = $r['addressPrefix'].'2';
					}
					break;
			}
		}
	}

	function loadDevices()
	{
		$q [] = 'SELECT devices.*';
		array_push($q, ' FROM [mac_lan_devices] AS [devices]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND devices.[macDeviceType] != %s', '');
		array_push($q, ' AND devices.[lan] = %i', $this->lanNdx);
		array_push($q, ' AND devices.[docStateMain] <= %i', 2);
		array_push($q, ' ORDER BY devices.ndx');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->cfg['devices'][$r['ndx']] = ['id' => $this->description($r['id'])];
			$this->loadDevicePorts($r['ndx']);
			$this->loadDeviceAddresses($r['ndx']);
		}
	}

	function loadDevicePorts($deviceNdx)
	{
		$q [] = 'SELECT ports.*, connectedDevices.id AS connectedDeviceId, connectedPorts.portId AS connectedPortId, wallSockets.id AS wallSocketId';
		array_push($q, ' FROM [mac_lan_devicesPorts] AS [ports]');
		array_push ($q,' LEFT JOIN [mac_lan_wallSockets] AS wallSockets ON ports.connectedToWallSocket = wallSockets.ndx');
		array_push ($q,' LEFT JOIN [mac_lan_devices] AS connectedDevices ON ports.connectedToDevice = connectedDevices.ndx');
		array_push ($q,' LEFT JOIN [mac_lan_devicesPorts] AS connectedPorts ON ports.connectedToPort = connectedPorts.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND ports.[device] = %i', $deviceNdx);
		array_push($q, ' ORDER BY ports.ndx');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$portKind = $r['portKind'];
			$portRole = $r['portRole'];

			$portDesc = '';
			if ($r['connectedTo'] === 1)
			{ // wall socket
				$portDesc = $this->description('Z '.$r['wallSocketId']);
			}
			elseif ($r['connectedTo'] === 2)
			{ // device port
				$portDesc = $this->description($r['connectedDeviceId'].' -> '.$r['connectedPortId']);
			}

			$portItem = [
				'portId' => $r['portId'], 'portRole' => $portRole, 'portKind' => $portKind, 'number' => $r['portNumber'], 'desc' => $portDesc,
			];

			if ($portKind === 5 || $portKind === 6)
			{ // eth/sfp port
				$portItem['vlans'] = [];
				if ($portRole === 10) // access port
				{
					$portItem['vlans'][] = $this->vlanNumber($r['vlan'], $deviceNdx, $r['portId']);
				}
				elseif ($portRole === 15) // hybrid port
				{
					$portItem['vlans'][] = $this->vlanNumber($r['vlan'], $deviceNdx, $r['portId']);
					$this->devicePortVlansList ($r['device'], $r['ndx'],$portItem['vlans']);
				}
				elseif ($portRole === 20) // trunk - uplink
				{
					$dvcs = [];
					$this->deviceDownLinkVlans ($deviceNdx,$portItem['vlans'], $dvcs);
				}
				elseif ($portRole === 30) // trunk - downlink
				{
					if ($r['vlan'])
					{
						$portItem['untaggedVlan'] = $this->vlanNumber($r['vlan'], $deviceNdx, $r['portId']);
						$portItem['vlans'][] = $this->vlanNumber($r['vlan'], $deviceNdx, $r['portId']);
					}
					$dvcs = [];
					$this->deviceDownLinkPortVlans($r['device'], $r['ndx'], $portItem['vlans'], $dvcs);
				}
				elseif ($portRole === 40) // VLAN list
				{
					$this->devicePortVlansList ($r['device'], $r['ndx'],$portItem['vlans']);
				}
			}
			elseif ($portKind === 10)
			{ // VLAN
				$portItem['vlans'][] = $this->vlanNumber($r['vlan'], $deviceNdx, $r['portId']);
			}

			if (isset($portItem['vlans']) && count($portItem['vlans']))
				asort($portItem['vlans']);

			$this->cfg['devices'][$deviceNdx]['ports'][$r['ndx']] = $portItem;
		}
	}

	function loadDeviceAddresses($deviceNdx)
	{
		$q [] = 'SELECT ifaces.*,';
		array_push($q, ' ports.portKind, ports.portRole, ports.portId, ports.vlan AS portVlan,');
		array_push($q, ' ranges.range AS addressRange, ranges.addressPrefix AS addressPrefix, ranges.addressGateway AS rangeAddressGateway, ranges.vlan AS rangeVlan');
		array_push($q, ' FROM [mac_lan_devicesIfaces] AS [ifaces]');
		array_push($q, ' LEFT JOIN [mac_lan_devicesPorts] AS [ports] ON ifaces.devicePort = ports.ndx');
		array_push($q, ' LEFT JOIN [mac_lan_lansAddrRanges] AS [ranges] ON ifaces.range = ranges.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND ifaces.[device] = %i', $deviceNdx);
		array_push($q, ' ORDER BY ifaces.ndx');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			//if (!$r['range'] || !$r['addressRange'] || $r['addressRange'] === '')
			//	continue;

			if ($r['addrType'] === 2)
			{ // DHCP client
				continue;
			}

			$portKind = $r['portKind'];
			$portRole = $r['portRole'];

			$addressItem = [
				'ip' => $r['ip'],
			];

			if ($r['rangeVlan'])
				$addressItem['vlan'] = $this->vlanNumber($r['rangeVlan'], $r['device'], $r['devicePort']);

			$addrInfo = NULL;
			if ($portRole === 70 || $portRole === 90)
			{ // local port /WAN port / manual address
				$addrInfo = $this->ipv4AddressInfo($r['ip']);
				//$addressItem['ip'] = $addrInfo['ip'];
				$addressItem['portId'] = $r['portId'];
			}
			elseif ($r['range'])
			{
				$addrInfo = $this->ipv4AddressInfo($r['addressRange']);
			}

			if ($portRole === 90)
			{ // WAN/Internet
				if ($r['addressGateway'] !== '')
				{
					$this->cfg['devices'][$deviceNdx]['gateways'][] = [
						'addr' => $r['addressGateway'], 'priority' => $r['priority'],
						'desc' => $this->description($r['note']),
					];
				}
			}

			if ($addrInfo)
			{
				$addressItem['network'] = $addrInfo['network'];
				$addressItem['maskLong'] = $addrInfo['maskLong'];
			}

			if ($portKind === 10)
			{ // VLAN
				if ($r['portVlan'])
					$addressItem['vlan'] = $this->vlanNumber($r['portVlan'], $r['device'], $r['devicePort']);
				$addressItem['gw'] = ($r['rangeAddressGateway'] !== '') ? $r['rangeAddressGateway'] : $r['addressPrefix'].'1';
				if (strstr($addressItem['ip'], '/') == FALSE)
					$addressItem['ip'] .= '/24';
			}

			if (isset($addressItem['vlan']) &&
				($addressItem['vlan'] === $this->cfg['vlanManagement'] || in_array($addressItem['vlan'], $this->cfg['vlanManagementWiFi']) || !isset($this->cfg['devices'][$deviceNdx]['ipManagement'])))
			{
				if (strstr($addressItem['ip'], '/') == FALSE)
					$this->cfg['devices'][$deviceNdx]['ipManagement'] = $addressItem['ip'];
				else
					$this->cfg['devices'][$deviceNdx]['ipManagement'] = strchr($addressItem['ip'], '/', TRUE);
				if ($deviceNdx === $this->mainServerLanControlNdx)
					$this->cfg['mainServerLanControlIp'] = $addressItem['ip'];
			}

			$this->cfg['devices'][$deviceNdx]['addresses'][$r['ndx']] = $addressItem;
		}
	}

	function loadVLANs()
	{
		$this->cfg['vlans'] = [];
		$this->cfg['vlansPublic'] = [];
		$this->cfg['vlansGroups'] = [];

		// -- VLANs
		$q [] = 'SELECT vlans.*';
		array_push($q, ' FROM [mac_lan_vlans] AS [vlans]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND vlans.[lan] = %i', $this->lanNdx);
		array_push($q, ' AND vlans.[docState] = %i', 4000);
		array_push($q, ' ORDER BY vlans.num, vlans.ndx');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['isGroup'])
				$this->cfg['vlansGroups'][$r['ndx']] = ['name' => $r['fullName'], 'vlans' => []];
			else
			{
				$this->cfg['vlans'][$r['ndx']] = [
					'num' => $r['num'], 'name' => $r['fullName'],
					'incomingVlans' => [], 'desc' => $this->description($r['fullName'])
				];

				if ($r['isPublic'])
					$this->cfg['vlansPublic'][] = $this->cfg['vlans'][$r['ndx']];
			}
		}

		// -- VLANs IN groups
		if (count($this->cfg['vlansGroups']))
		{
			$ql[] = 'SELECT * FROM [e10_base_doclinks] AS docLinks ';
			array_push($ql, 'WHERE srcTableId = %s', 'mac.lan.vlans', 'AND dstTableId = %s', 'mac.lan.vlans');
			array_push($ql, ' AND docLinks.linkId = %s', 'mac-lan-vlans-groups', 'AND dstRecId IN %in', array_keys($this->cfg['vlansGroups']));

			$rows = $this->db()->query($ql);
			foreach ($rows as $r) {
				$this->cfg['vlansGroups'][$r['dstRecId']]['vlans'][] = $r['srcRecId'];
			}
		}

		// -- incoming VLANs
		$ql = [];
		$ql[] = 'SELECT * FROM [e10_base_doclinks] AS docLinks ';
		array_push ($ql, 'WHERE srcTableId = %s', 'mac.lan.vlans', 'AND dstTableId = %s', 'mac.lan.vlans');
		array_push ($ql, ' AND docLinks.linkId = %s', 'mac-lan-vlans-incoming', 'AND srcRecId IN %in', array_keys($this->cfg['vlans']));

		$rows = $this->db()->query($ql);
		foreach ($rows as $r)
		{
			if (!isset($this->cfg['vlans'][$r['srcRecId']]['incomingVlans']))
				$this->cfg['vlans'][$r['srcRecId']]['incomingVlans'] = [];

			$this->appendVlansNumbers($r['dstRecId'], $this->cfg['vlans'][$r['srcRecId']]['incomingVlans'], 0, 0);
		}
	}

	function loadDHCP()
	{
		$q [] = 'SELECT ranges.*, vlans.num AS vlanNumber ';
		array_push($q, ' FROM [mac_lan_lansAddrRanges] AS [ranges]');
		array_push($q, ' LEFT JOIN [mac_lan_vlans] AS [vlans] ON ranges.vlan = vlans.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND ranges.[lan] = %i', $this->lanNdx);
		array_push($q, ' AND ranges.[docState] = %i', 4000);
		array_push($q, ' ORDER BY vlans.num, INET_ATON(ranges.range)');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			//if (!$r['dhcpPoolBegin'] && !$r['dhcpPoolEnd'])
			//	continue;

			$vlanNumber = intval($r['vlanNumber']);
			if ($vlanNumber)
				$thisDHCPServerId = 'DHCP_VLAN'.$vlanNumber;
			else
				$thisDHCPServerId = 'DHCP_Default';

			if (!isset($this->cfg['dhcp']['pools']))
				$this->cfg['dhcp']['pools'] = [];

			$poolId = 'POOL_'.$thisDHCPServerId.'_'.$r['ndx'];

			// -- check dhcp server
			if (!isset($this->cfg['dhcp']['servers'][$thisDHCPServerId]))
			{
				if ($r['dhcpPoolBegin'] && $r['dhcpPoolEnd'])
				{
					if ($vlanNumber)
						$thisDHCPInterfaceId = 'IFB_VLAN' . $vlanNumber;
					else
					{
						$thisDHCPInterfaceId = 'IF_Bridge_Default';
						$this->addMessage("Není nastavena VLAN pro rozsah adres ".$r['fullName'].' - '.$r['range']);
					}

					$this->cfg['dhcp']['servers'][$thisDHCPServerId] = [
						'id' => $thisDHCPServerId, 'vlan' => $vlanNumber, 'pool' => $poolId,
						'interface' => $thisDHCPInterfaceId
					];
				}
			}

			$nextPoolId = '';

			// -- add pool
			$this->cfg['dhcp']['pools'][$poolId] = [
				'id' => $poolId, 'server' => $thisDHCPServerId, 'vlan' => $vlanNumber, 'pool' => $r['ndx'],
				'addressPrefix' => $r['addressPrefix'], 'poolBegin' => $r['dhcpPoolBegin'], 'poolEnd' => $r['dhcpPoolEnd'],
				'addressRange' => $r['range'],
				'desc' => $this->description($r['fullName']),
			];
		}
	}

	function loadAddresses()
	{
		$q[] = 'SELECT ifaces.*,  ';
		array_push ($q, ' devices.ndx AS deviceNdx, devices.fullName as deviceFullName, devices.deviceKind,');
		array_push ($q, ' devices.id as deviceId, ranges.vlan AS rangeVlan, ranges.id AS rangeId');
		array_push ($q, ' FROM [mac_lan_devicesIfaces] AS ifaces');
		array_push ($q, ' LEFT JOIN mac_lan_devices AS devices ON ifaces.device = devices.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_lansAddrRanges] AS [ranges] ON ifaces.range = ranges.ndx');
		array_push ($q, ' WHERE devices.docStateMain < 3');
		//array_push ($q, ' AND devices.lan = %i', $this->lanNdx);
		array_push ($q, ' AND [ranges].lan = %i', $this->lanNdx);
		array_push ($q, ' ORDER BY INET_ATON(ranges.range), INET_ATON(ifaces.ip), devices.fullName');
		$rows = $this->app->db()->query($q);

		foreach ($rows as $r)
		{
			$thisRouter = $this->mainRouterNdx;
			if (!$r['rangeVlan'])
				continue;

			$dhcpServerId = 'DHCP_VLAN'.$this->vlanNumber($r['rangeVlan']);

			$desc = '-'.$r['deviceNdx'].'-; ';
			if (strpos ($r['deviceFullName'], $r['deviceId']) !== FALSE)
				$desc .= $r['deviceFullName'];
			else
				$desc .= $r['deviceId'].'; '.$r['deviceFullName'];
			if ($r['id'] !== '')
				$desc .= ' '.$r['id'];

			$addressItem = [
				'mac' => $r['mac'],
				'ip' => $r['ip'],
				//'dhcpServer' => $dhcpServerId,
				'addrType' => $r['addrType'],
				'desc' => $this->description($desc),
			];

			if ($r['addrType'] === 1 && $r['mac'] && $r['mac'] !== '')
			{ // dhcp fix
				$this->cfg['dhcp']['servers'][$dhcpServerId]['staticLeases'][] = $addressItem;
			}
		}
	}

	function loadWiFi()
	{
		// -- load WLANS / SSIDs
		$q[] = 'SELECT wlans.*';
		array_push ($q, ' FROM [mac_lan_wlans] AS wlans');
		array_push ($q, ' WHERE wlans.docStateMain < 3');
		array_push ($q, ' AND wlans.lan = %i', $this->lanNdx);
		array_push ($q, ' ORDER BY wlans.ndx');

		$wlans = [];
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r['ndx'], 'ssid' => $r['ssid'],
				'vlanNdx' => $r['vlan'], 'vlan' => $this->vlanNumber($r['vlan'], 0, 0, 'SSID: '.$r['ssid']),
				'onAPs' => $r['onAPs'],
				'wpaPassphrase' => $r['wpaPassphrase'],
				'devices' => [],
			];

			$wlans[$r['ndx']] = $item;
		}


		// -- load APS
		$q = [];
		$q [] = 'SELECT devices.*';
		array_push($q, ' FROM [mac_lan_devices] AS [devices]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND devices.[deviceKind] IN %in', [14, 15]); // active device / [OLD] AP
		array_push($q, ' AND devices.[lan] = %i', $this->lanNdx);
		array_push($q, ' AND devices.[docStateMain] <= %i', 2);
		array_push($q, ' ORDER BY devices.ndx');

		$devices = [];
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$enabled = 0;
			if ($r['deviceKind'] == 15)
				$enabled = 1;
			else
			{
				$macDeviceCfg = json_decode($r['macDeviceCfg'], TRUE);
				if (isset($macDeviceCfg['capsmanClient']) && intval($macDeviceCfg['capsmanClient']))
					$enabled = 1;
			}
			if (!$enabled)
				continue;

			$item = ['ndx' => $r['ndx']];

			foreach ($wlans as $wlanNdx => $wlan)
			{
				if ($wlan['onAPs'] === 0)
					$wlans[$wlanNdx]['devices'][] = $r['ndx'];
			}

			$devices[$r['ndx']] = $item;
		}

		// -- load exceptions
		$rows = $this->app()->db->query (
			'SELECT doclinks.srcRecId, doclinks.dstRecId, doclinks.linkId FROM [e10_base_doclinks] AS doclinks WHERE 1',
			' AND dstTableId = %s', 'mac.lan.devices', ' AND srcTableId = %s', 'mac.lan.wlans',
			' AND doclinks.dstRecId IN %in', array_keys($devices)
		);
		foreach ($rows as $r)
		{
			$wlanNdx = $r['srcRecId'];
			$deviceNdx = $r['dstRecId'];
			$linkId = $r['linkId'];

			if ($linkId === 'mac-wlans-disabled-ap')
			{
				if (($key = array_search($deviceNdx, $wlans[$wlanNdx]['devices'])) !== FALSE)
					unset($wlans[$wlanNdx]['devices'][$key]);
			}
			elseif ($linkId === 'mac-wlans-enabled-ap')
			{
				$wlans[$wlanNdx]['devices'][] = $deviceNdx;
			}
		}

		// -- set
		$this->cfg['wlans'] = $wlans;

		foreach ($this->cfg['wlans'] as $wlanNdx => $wlan)
		{
			foreach ($wlan['devices'] as $devNdx)
			{
				$this->cfg['wlansByDevices'][$devNdx][] = $wlanNdx;
			}
		}
	}

	function applyLoadedWiFi()
	{
		foreach ($this->cfg['wlans'] as $wlanNdx => $wlan)
		{
			foreach ($wlan['devices'] as $devNdx)
			{
				$this->cfg['devices'][$devNdx]['wlans'][] = $wlanNdx;
			}
		}
	}

	function vlanNumber($vlanNdx, $deviceNdx = 0, $portNdx = 0, $description = '')
	{
		if (isset($this->cfg['vlans'][$vlanNdx]))
			return intval($this->cfg['vlans'][$vlanNdx]['num']);

		if (is_int($deviceNdx) && $deviceNdx)
		{
			$device = $this->tableDevices->loadItem($deviceNdx);
			if ($device)
				$this->addMessage("Chyba v nastavení VLAN ndx #{$vlanNdx}; zařízení `{$device['id']}`, port=`{$portNdx}`; {$description}");
		}
		else
		{
			if (!strlen($description))
				utils::debugBacktrace();
			$this->addMessage("Chyba v nastavení VLAN ndx #{$vlanNdx}; zařízení #{$deviceNdx}, port={$portNdx}; {$description}");
		}

		return 0;
	}

	function deviceDownLinkPortVlans ($deviceNdx, $portNdx, &$vlans, &$devices)
	{
		$portRecData = $this->tableDevicesPorts->loadItem($portNdx);

		if ($portRecData['connectedTo'] != 2)
			return;

		$this->deviceDownLinkVlans($portRecData['connectedToDevice'],$vlans,$devices);
	}

	function deviceDownLinkVlans ($deviceNdx, &$vlans, &$devices)
	{
		if (in_array($deviceNdx, $devices))
			return;
		$devices[] = $deviceNdx;

		$q [] = 'SELECT ports.*';
		array_push($q, ' FROM [mac_lan_devicesPorts] AS [ports]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND ports.[device] = %i', $deviceNdx);
		array_push($q, ' ORDER BY ports.ndx');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$portKind = $r['portKind'];
			$portRole = $r['portRole'];

			if ($portKind === 5 || $portKind === 6)
			{ // eth/sfp port
				if ($portRole === 10) // access port
				{
					$vlanNumber = $this->vlanNumber($r['vlan'], $deviceNdx, $r['ndx']);
					if (!in_array($vlanNumber, $vlans))
						$vlans[] = $vlanNumber;
				}
				if ($portRole === 15) // hybrid port
				{
					$vlanNumber = $this->vlanNumber($r['vlan'], $deviceNdx, $r['ndx']);
					if (!in_array($vlanNumber, $vlans))
						$vlans[] = $vlanNumber;
					$this->devicePortVlansList ($r['connectedToDevice'], $r['ndx'],$vlans);
				}
				elseif ($portRole === 20) // trunk - uplink
				{

				}
				elseif ($portRole === 30) // trunk - downlink
				{
					if ($r['connectedTo'] === 2)
					{
						if ($r['vlan'])
						{
							$vlanNumber = $this->vlanNumber($r['vlan'], $deviceNdx, $r['ndx']);
							if (!in_array($vlanNumber, $vlans))
								$vlans[] = $vlanNumber;
						}

						$this->deviceDownLinkVlans($r['connectedToDevice'],$vlans,$devices);
					}
				}
				elseif ($portRole === 40) // VLAN list
				{
					$this->devicePortVlansList ($r['connectedToDevice'], $r['ndx'],$vlans);
				}
			}
			elseif ($portKind === 10)
			{ // VLAN
				$vlanNumber = $this->vlanNumber($r['vlan'], $deviceNdx, $r['portId']);
				if (!in_array($vlanNumber, $vlans))
					$vlans[] = $vlanNumber;
			}
		}

		// -- wlans
		if (isset($this->cfg['wlansByDevices'][$deviceNdx]))
		{
			foreach ($this->cfg['wlansByDevices'][$deviceNdx] as $wlanNdx)
			{
				$wlan = $this->cfg['wlans'][$wlanNdx];
				$vlanNumber = $wlan['vlan'];
				if (!in_array($vlanNumber, $vlans))
					$vlans[] = $vlanNumber;
			}
		}
	}

	function devicePortVlansList ($deviceNdx, $portNdx, &$vlans)
	{
		$rows = $this->app()->db->query (
			'SELECT doclinks.dstRecId FROM [e10_base_doclinks] AS doclinks',
			' WHERE doclinks.linkId = %s', 'mac-lan-devicePorts-vlans',
			' AND dstTableId = %s', 'mac.lan.vlans', ' AND srcTableId = %s', 'mac.lan.devicesPorts',
			' AND doclinks.srcRecId = %i', $portNdx
		);

		foreach ($rows as $r)
		{
			$this->appendVlansNumbers($r['dstRecId'], $vlans, $deviceNdx, $portNdx);
		}
	}

	public function ipv4AddressInfo ($range)
	{
		$parts = explode('/', $range);
		if (count($parts) === 1)
			$parts[1] = '24';

		$ip_address = $parts[0];
		$ip_nmask = long2ip(-1 << (32 - (int)$parts[1]));
		$ip_count = 1 << (32 - (int)$parts[1]);

		$hosts = [];

		//convert ip addresses to long form
		$ip_address_long = ip2long($ip_address);
		$ip_nmask_long = ip2long($ip_nmask);

		//calculate network address
		$ip_net = $ip_address_long & $ip_nmask_long;

		//calculate first usable address
		$ip_host_first = ((~$ip_nmask_long) & $ip_address_long);
		$ip_first = ($ip_address_long ^ $ip_host_first) + 1;

		//calculate last usable address
		$ip_broadcast_invert = ~$ip_nmask_long;
		////$ip_last = ($ip_address_long | $ip_broadcast_invert) - 1;
		$ip_last = $ip_first + $ip_count - 2;

		//calculate broadcast address
		$ip_broadcast = $ip_address_long | $ip_broadcast_invert;

		$block_info = [
			'network' => long2ip($ip_net),
			'first_host' => $ip_first,
			'last_host' => $ip_last,
			'broadcast' => $ip_broadcast,
			'maskLong' => $ip_nmask,
			'ip' => $ip_address,
		];

		return $block_info;
	}

	function appendVlansNumbers ($vlanNdx, &$vlans, $deviceNdx, $portNdx)
	{
		if (isset($this->cfg['vlansGroups'][$vlanNdx]))
		{
			foreach ($this->cfg['vlansGroups'][$vlanNdx]['vlans'] as $vn)
			{
				if (isset($this->cfg['vlans'][$vn]))
				{
					$vlanNumber = $this->cfg['vlans'][$vn]['num'];
					if (!in_array($vlanNumber, $vlans))
						$vlans[] = $vlanNumber;
				}
				else
				{ // invalid
					$this->addMessage("Chyba v nastavení VLAN ndx #{$vlanNdx}; zařízení `{$deviceNdx}`, port=`{$portNdx}`");
				}
			}
			return;
		}

		if (!isset($this->cfg['vlans'][$vlanNdx]))
		{
			$this->addMessage("Chyba v nastavení VLAN ndx #{$vlanNdx}; zařízení `{$deviceNdx}`, port=`{$portNdx}`");
		}

		$vlanNumber = $this->vlanNumber($vlanNdx, $deviceNdx, $portNdx);
		if (!in_array($vlanNumber, $vlans))
			$vlans[] = $vlanNumber;
	}


	function description($string, $keepSpaces = TRUE)
	{
		$d = str_replace ([':', '?', '/', '*', '"', '\'', '[', ']', ',', '#'], '-', trim($string));
		$d = strtr( $d, utils::$transDiacritic);

		if ($keepSpaces)
			return $d;

		$d = str_replace(' ', '_', $d);

		return $d;
	}

	function cacheClear()
	{
	}

	function cacheLoad()
	{
	}

	function cacheSave()
	{
		$baseFileName = '___mac.lan.'.$this->lanNdx.'.lanCgfCache';
		$coreFileName = __APP_DIR__.'/tmp/'.$baseFileName;
		$cfgStr = json::lint($this->cfg);
		file_put_contents($coreFileName.'.json', $cfgStr);
		$this->cfgVer = sha1($cfgStr);
		$verInfo = ['lan' => $this->lanNdx, 'cfgVer' => $this->cfgVer];
		file_put_contents($coreFileName.'.info.json', json::lint($verInfo));
	}
}
