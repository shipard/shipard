<?php

namespace mac\lan\libs;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class NodeServerCfgUpdater
 * @package mac\lan\libs
 */
class NodeServerCfgUpdater extends Utility
{
	/** @var \mac\lan\TableDevices */
	var $tableDevices;
	var $devicesKinds;
	/** @var \mac\lan\TableDevicesIOPorts */
	var $tableIOPorts;
	/** @var \mac\iot\TableSensors */
	var $tableSensors;

	var $lanNdx = 0;
	var $serverNdx = 0;

	var $lanCfgs = [];
	var $lanControlDevices = [];
	var $devicesMacs = [];
	var $devicesIPs = [];

	var $changes = NULL;

	var $formatVersion = '2022-02.19';

	var $defaultWebSocketsPort = 8888;
	var $useNewWebsockets = 0;

	public function setLan ($lanNdx)
	{
		$this->lanNdx = $lanNdx;
	}

	public function setServer ($serverNdx)
	{
		$this->serverNdx = $serverNdx;
	}

	public function init()
	{
		//$this->tableLans = $this->app()->table('mac.lan.lans');
		$this->tableDevices = $this->app()->table('mac.lan.devices');
		$this->devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');
		$this->tableIOPorts = $this->app()->table('mac.lan.devicesIOPorts');
		$this->tableSensors = $this->app()->table('mac.iot.sensors');

		$this->defaultWebSocketsPort = 9883;
	}

	function lanCfg($lanNdx)
	{
		if (isset($this->lanCfgs[$lanNdx]))
			return $this->lanCfgs[$lanNdx];

		$lanCfgCreator = new \mac\lan\libs\LanCfgCreator($this->app());
		$lanCfgCreator->init();
		$lanCfgCreator->setLan($lanNdx);
		$lanCfgCreator->load();

		$this->lanCfgs[$lanNdx] = $lanCfgCreator;

		return $this->lanCfgs[$lanNdx];
	}

	function updateAll()
	{
		$localServers = [];
		$mainServers = [];

		$q[] = 'SELECT devices.*, lans.mainServerCameras, lans.mainServerLanControl, lans.mainServerIot, lans.alertsDeliveryTarget, lans.[domain] AS lanDomain';
		array_push ($q,' FROM [mac_lan_devices] AS devices');
		array_push ($q,' LEFT JOIN mac_lan_lans AS lans ON devices.lan = lans.ndx');
		array_push ($q,' WHERE devices.[deviceKind] = %i', 7, ' AND devices.[nodeSupport] = %i', 1, ' AND devices.[docState] != %i', 9800);
		array_push ($q,' ORDER BY devices.[id], devices.[fullName], devices.[ndx]');
		$rows = $this->app()->db->query ($q);

		foreach ($rows as $r)
		{
			$macDeviceCfg = json_decode($r['macDeviceCfg'], TRUE);
			if (!$macDeviceCfg)
				continue;

			$lanCfg = $this->lanCfg($r['lan']);
			$lanDomain = $r['lanDomain'];

			$s = [
				'ndx' => $r['ndx'], 'id' => $r['id'], 'name' => $r ['fullName'],
				'enableLC' => $macDeviceCfg['enableLC'],
				'enableCams' => $macDeviceCfg['enableCams'],
				'enableRack' => $macDeviceCfg['enableRack'],
				'enableOthers' => $macDeviceCfg['enableOthers'],
				'fqdn' => $macDeviceCfg['serverFQDN'] ?? '',
				'httpsPort' => (isset($macDeviceCfg['httpsPort']) && (intval($macDeviceCfg['httpsPort']))) ? intval($macDeviceCfg['httpsPort']) : 443,
				'lanNdx' => $r['lan'],
				'domain' => $lanDomain,
				'mqttServerHost' => '',
				'mqttServerIPV4' => '',
				'alertsDeliveryTarget' => $r['alertsDeliveryTarget'],
				'alertsDeliveryEmail' => ($r['alertsDeliveryTarget'] !== '') ? $this->app()->cfgItem('dsid', 0) . '--'.$r['alertsDeliveryTarget'].'@shipard.email' : '',
				'lanCfgVer' => $lanCfg->cfgVer,
				'formatVersion' => $this->formatVersion,
			];

			if ($r['mainServerIot'] && !isset($mainServers[$r['mainServerIot']]))
			{
				$mainServers[$r['mainServerIot']] = $this->tableDevices->loadItem($r['mainServerIot']);
				$mainServers[$r['mainServerIot']]['macDeviceCfgX'] = json_decode($mainServers[$r['mainServerIot']]['macDeviceCfg'], TRUE);
			}

			if ($r['mainServerIot'] && isset($mainServers[$r['mainServerIot']]))
			{
				if (isset($mainServers[$r['mainServerIot']]['macDeviceCfgX']['mqttServerFQDN']) && $mainServers[$r['mainServerIot']]['macDeviceCfgX']['mqttServerFQDN'] !== '')
					$s['mqttServerHost'] = $mainServers[$r['mainServerIot']]['macDeviceCfgX']['mqttServerFQDN'];
				else
					$s['mqttServerHost'] = $mainServers[$r['mainServerIot']]['macDeviceCfgX']['serverFQDN'];

				$s['mqttServerIPV4'] = $mainServers[$r['mainServerIot']]['macDeviceCfgX']['mqttServerIPV4'] ?? '';	
			}

			$cfgData = $s;

			$localServers[$r['ndx']] = $s;

			$this->nodeServerConfigLan ($cfgData, $r['ndx']);

			if ($macDeviceCfg['serverFQDN'] !== '')
				$cfgData['fqdn'] = $macDeviceCfg['serverFQDN'];

			if ($macDeviceCfg['enableCams'])
			{
				$cfgData['camerasURL'] = 'https://'.$macDeviceCfg['serverFQDN'] . ($cfgData['httpsPort'] !== 443 ? ':'.$cfgData['httpsPort'].'/' : '/');
				$this->nodeServerConfigCameras($cfgData, $r['ndx'], $r['lan'], $r['mainServerCameras'] === $r['ndx'], $macDeviceCfg);
				$this->nodeServerConfigLanControl($cfgData, $r['ndx'], $r['lan'], $r['mainServerLanControl'] === $r['ndx']);
			}
			if ($macDeviceCfg['enableLC'])
			{
				$cfgData['wsUrl'] =  'wss://' . $macDeviceCfg['serverFQDN'] . ':' . (($macDeviceCfg['wssPort']) ? $macDeviceCfg['wssPort'] : $this->defaultWebSocketsPort);
				$cfgData['wsPort'] = ($macDeviceCfg['wssPort']) ? $macDeviceCfg['wssPort'] : $this->defaultWebSocketsPort;
				$cfgData['lanNdx'] =  $r['lan'];

				$af = explode(' ', $macDeviceCfg['wssAllowedFrom']);
				foreach ($af as $afIP)
					$cfgData['wssAllowedFrom'][] = trim($afIP);

				$this->nodeServerNginxProxies($cfgData, $r['ndx'], $r['lan'], $r['mainServerLanControl'] === $r['ndx']);	
				$this->nodeServerConfigIotBoxes($cfgData, $r['ndx'], $r['lan'], $r['mainServerLanControl'] === $r['ndx']);
				$this->nodeServerConfigIotThings($cfgData, $r['ndx'], $r['lan'], $r['mainServerLanControl'] === $r['ndx']);
				$this->nodeServerConfigLanControl($cfgData, $r['ndx'], $r['lan'], $r['mainServerLanControl'] === $r['ndx']);

				$sensors = $this->tableSensors->sensorsCfg($r['lan']);
				if (count($sensors))
				{
					$cfgData['iotSensors'] = $sensors;
				}

				if ($lanCfg->lanRecData['iotStoreDataSource'])
				{
					$iotDataSource = $this->db()->query('SELECT * FROM [mac_data_sources] WHERE ndx = %i', $lanCfg->lanRecData['iotStoreDataSource'])->fetch();
					if ($iotDataSource)
					{
						$cfgData ['iotDataSource'] = $iotDataSource->toArray();
						unset ($cfgData ['iotDataSource']['docState']);
						unset ($cfgData ['iotDataSource']['docStateMain']);
					}
				}
			}

			$update = ['liveData' => json::lint($cfgData)];
			$update['liveDataVer'] = sha1($update['liveData']);
			$this->updateNodeServerCfg($r['ndx'], $update);
		}

		// -- lan control
		if (count($this->lanControlDevices))
		{
			$lcu = new \mac\lan\libs\LanControlCfgUpdater($this->app());
			$lcu->batchUpdate($this->lanControlDevices);
		}
	}

	function nodeServerConfigLan (&$cfgData, $serverNdx)
	{
		if ($this->app()->model()->table ('mac.lan.lansAddrRanges') === FALSE)
			return;

		$lsc = [];

		$rangesPks = [];
		$ranges = $this->db()->query('SELECT * FROM mac_lan_lansAddrRanges WHERE serverMonitoring = %i', $serverNdx);
		foreach ($ranges as $range)
		{
			$lsc['ranges'][$range['ndx']] = [
				'range' => $range['range'], 'note' => $range['note'],
				'dhcpPoolBegin' => $range['dhcpPoolBegin'], 'dhcpPoolEnd' => $range['dhcpPoolEnd']
			];

			$rangesPks[] = $range['ndx'];
		}

		$qa[] = 'SELECT ifaces.*, devices.ndx AS deviceNdx, devices.lan as deviceLan, devices.deviceKind as deviceKind, devices.alerts AS deviceAlerts, devices.macDeviceType';
		array_push ($qa, ' FROM [mac_lan_devicesIfaces] AS ifaces');
		array_push ($qa, ' LEFT JOIN mac_lan_devices AS devices ON ifaces.device = devices.ndx');
		array_push ($qa, ' WHERE devices.docState IN %in', [4000, 8000], ' AND ifaces.range IN %in', $rangesPks);
		$rows = $this->db()->query($qa);
		foreach ($rows as $r)
		{
			$dk = $this->devicesKinds[$r['deviceKind']];
			$alerts = $r['deviceAlerts'] ? $r['deviceAlerts'] : $dk['alerts'];

			$newItem = [
				'ip' => $r['ip'], 'mac' => $r['mac'], 't' => $r['addrType'],
				'd' => $r['deviceNdx'], 'dk' => $r['deviceKind'], 'r' => $r['range'], 'a' => $alerts,
			];

			if ($r['macDeviceType'] !== '')
				$newItem['mdt'] = $r['macDeviceType'];

			if ($r['ip'] !== '')
			{
				$lsc['ip'][$r['ip']] = $newItem;
				$this->devicesIPs[$r['deviceNdx']][] = $r['ip'];
			}
			if ($r['mac'] !== '')
			{
				$lsc['mac'][$r['mac']] = $newItem;
				$this->devicesMacs[$r['deviceNdx']][] = strtolower($r['mac']);
			}
		}

		$cfgData['lan'] = $lsc;
	}

	function nodeServerConfigCameras (&$cfgData, $serverNdx, $lanNdx, $isDefaultServer, $serverMacDeviceCfg)
	{
		$cameras = [];
		$q[] = 'SELECT * FROM [mac_lan_devices] WHERE [deviceKind] = 10 AND [docState] != 9800 ';

		if ($isDefaultServer)
			array_push ($q,'AND (localServer = %i', $serverNdx, ' OR (localServer = %i', 0, ' AND lan = %i))', $lanNdx);
		else
			array_push ($q,'AND localServer = %i', $serverNdx);

		array_push ($q,'ORDER BY [id], [fullName], [ndx]');
		$rows = $this->app()->db->query ($q);

		foreach ($rows as $r)
		{
			$macDeviceCfg = json_decode($r['macDeviceCfg'], TRUE);
			if (!$macDeviceCfg)
				continue;

			$cam = ['ndx' => $r['ndx'], 'id' => $r['id'], 'name' => $r ['fullName'], 'localServer' => $serverNdx, 'cfg' => $macDeviceCfg];
			if (isset($this->devicesIPs[$r['ndx']][0]))
				$cam['ip'] = $this->devicesIPs[$r['ndx']][0];
			if (!isset($cam['cfg']['camLogin']) || $cam['cfg']['camLogin'] === '')
				$cam['cfg']['camLogin'] = $serverMacDeviceCfg['camLogin'];
			if (!isset($cam['cfg']['camPasswd']) || $cam['cfg']['camPasswd'] === '')
				$cam['cfg']['camPasswd'] = $serverMacDeviceCfg['camPasswd'];

			if (isset($macDeviceCfg['enableVehicleDetect']) && $macDeviceCfg['enableVehicleDetect'])
			{ // make camera mqtt topic
				$topic = $this->tableIOPorts->mqttTopicBegin().'readers/vd/camera-'.$r['ndx'];
				$cam['cfg']['vdTopic'] = $topic;
			}

			$cameras [$r['ndx']] = $cam;
		}

		if (count($cameras))
			$cfgData['cameras'] = $cameras;
	}

	function nodeServerConfigIotBoxes (&$cfgData, $serverNdx, $lanNdx, $isDefaultServer)
	{

		$iotBoxes = [];

		$q[] = 'SELECT iotDevices.*, ibCfg.cfgData FROM [mac_iot_devices] AS [iotDevices]';
		array_push($q, ' LEFT JOIN [mac_iot_devicesCfg] AS ibCfg ON [iotDevices].ndx = ibCfg.[iotDevice]');
		array_push($q, ' WHERE [deviceType] = %s', 'shipard', ' AND [docState] != 9800');
		array_push($q,'ORDER BY [friendlyId], [fullName], [ndx]');
		$rows = $this->app()->db->query ($q);

		foreach ($rows as $r)
		{
			$iotDeviceCfg = json_decode($r['cfgData'], TRUE);
			$iotBoxCfg = $iotDeviceCfg['iotBoxCfg'] ?? NULL;
			if (!$iotBoxCfg)
				continue;

			$iotBox = [
				'ndx' => $r['ndx'],
				'id' => $r['friendlyId'],
				'name' => $r ['fullName'],
				'localServer' => $serverNdx,
				'mac' => [strtolower($r['hwId'])],
				'cfg' => $iotBoxCfg
			];

			$iotBoxes [$r['ndx']] = $iotBox;
		}



		if (count($iotBoxes))
			$cfgData['iotBoxes'] = $iotBoxes;
	}

	function nodeServerConfigIotThings (&$cfgData, $serverNdx, $lanNdx, $isDefaultServer)
	{
		$iecc = new \mac\iot\libs\IotEngineCfgCreator($this->app());
		$iecc->init();
		$iecc->run();
		$cfgData['iotThings'] = $iecc->cfg;
	}

	function nodeServerConfigLanControl (&$cfgData, $serverNdx, $lanNdx, $isDefaultServer)
	{
		$devices = [];

		$q [] = 'SELECT devices.*';
		array_push($q, ' FROM [mac_lan_devices] AS [devices]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND devices.[macDeviceType] != %s', '');

		//array_push($q, ' AND devices.[lan] = %i', $lanNdx);
		if ($isDefaultServer)
			array_push ($q,'AND (localServer = %i', $serverNdx, ' OR (localServer = %i', 0, ' AND lan = %i))', $lanNdx);
		else
			array_push ($q,'AND localServer = %i', $serverNdx);

		array_push($q, ' AND devices.[docStateMain] <= %i', 2);
		array_push($q, ' ORDER BY devices.ndx');

		$rows = $this->app()->db->query ($q);

		foreach ($rows as $r)
		{
			$macDeviceCfg = json_decode($r['macDeviceCfg'], TRUE);
			if (!$macDeviceCfg)
				continue;

			$macDeviceType = $this->app()->cfgItem('mac.devices.types.'.$r['macDeviceType'], NULL);
			if (!$macDeviceType || !isset($macDeviceType['sgClassId']))
				continue;

			$device = [
				'ndx' => $r['ndx'], 'id' => $r['id'], 'name' => $r ['fullName'],
				'macDeviceType' => $r['macDeviceType'], 'cfg' => $macDeviceCfg
			];

			$lanCfg = $this->lanCfg($lanNdx);
			$device['ipManagement'] = $lanCfg->cfg['devices'][$r['ndx']]['ipManagement'];

			$devices [$r['ndx']] = $device;

			$this->lanControlDevices[$lanNdx][] = $r['ndx'];
		}

		if (count($devices))
			$cfgData['lanControlDevices'] = $devices;
	}

	function nodeServerNginxProxies (&$cfgData, $serverNdx, $lanNdx, $isDefaultServer)
	{
		$proxies = [];

		$q [] = 'SELECT devices.*';
		array_push($q, ' FROM [mac_lan_devices] AS [devices]');
		array_push($q, ' WHERE 1');
		//array_push($q, ' AND devices.[monitored] = %i', 1);
		array_push($q, ' AND devices.[deviceKind] = %i', 7);

		if ($isDefaultServer)
			array_push ($q,'AND (localServer = %i', $serverNdx, ' OR (localServer = %i', 0, ' AND lan = %i))', $lanNdx);
		else
			array_push ($q,'AND localServer = %i', $serverNdx);

		array_push($q, ' AND devices.[docStateMain] <= %i', 2);
		array_push($q, ' ORDER BY devices.ndx');

		$rows = $this->app()->db->query ($q);

		foreach ($rows as $r)
		{
			$macDeviceCfg = json_decode($r['macDeviceCfg'], TRUE);
			if (!$macDeviceCfg)
				continue;

			$macDeviceType = $this->app()->cfgItem('mac.devices.types.'.$r['macDeviceType'], NULL);
			if (!$macDeviceType)
				continue;

			if (intval($macDeviceCfg['monNetdataEnabled']))
			{
				$proxyId = 'nd-'.utils::safeChars($r['id'], TRUE).'-'.$r['uid'];
				$destPort = intval($macDeviceCfg['monNetdataPort']);
				if (!$destPort)
					$destPort = 19999;
				$proxy = [
					'id' => $proxyId, 
					'type' => 'netdata',
					'destIP' => $macDeviceCfg['monNetdataIPAddress'], 
					'destPort' => $destPort, 
				];
				$proxies[] = $proxy;
			}

			if (intval($macDeviceCfg['zigbee2MQTTEnabled']))
			{
				$proxyId = 'z2m-'.utils::safeChars($r['id'], TRUE).'-'.$r['uid'];
				$destPort = intval($macDeviceCfg['zigbee2MQTTUIPort']);
				if (!$destPort)
					$destPort = 8099;
				$destAddress = $macDeviceCfg['zigbee2MQTTUIIPAddress'];	
				if ($destAddress === '')
					$destAddress = 'localhost';
				$proxy = [
					'id' => $proxyId, 
					'type' => 'inside',
				];

				$idef = "\tlocation /z2m/{$proxyId} {\n";
				$idef .= "\t\tproxy_pass http://{$destAddress}:{$destPort}/;\n";
				$idef .= "\t\t".'proxy_set_header Host $host;'."\n";
				$idef .= "\t\t".'proxy_set_header X-Real-IP $remote_addr;'."\n";
				$idef .= "\t\t".'proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;'."\n";
				$idef .= "\t}\n";
				
				$idef .= "\tlocation /z2m/$proxyId/api {\n";
				$idef .= "\t\tproxy_pass http://{$destAddress}:{$destPort}/;\n";
				$idef .= "\t\t".'proxy_set_header Host $host;'."\n";
				
				$idef .= "\t\t".'proxy_http_version 1.1;'."\n";
				$idef .= "\t\t".'proxy_set_header Upgrade $http_upgrade;'."\n";
				$idef .= "\t\t".'proxy_set_header Connection "upgrade";'."\n";
				$idef .= "\t}\n\n";
				$proxy['insideDef'] = $idef;
				$proxies[] = $proxy;
			}
		}

		if (count($proxies))
			$cfgData['httpProxies'] = $proxies;
	}

	function getNodeServerCfg($serverNdx)
	{
		$exist = $this->db()->query('SELECT * FROM [mac_lan_devicesCfgNodes] WHERE [device] = %i', $serverNdx)->fetch();
		if ($exist)
			return $exist->toArray();

		$insert = ['device' => $serverNdx];
		$this->db()->query('INSERT INTO [mac_lan_devicesCfgNodes] ', $insert);

		$exist = $this->db()->query('SELECT * FROM [mac_lan_devicesCfgNodes] WHERE [device] = %i', $serverNdx)->fetch();
		if ($exist)
			return $exist->toArray();

		return NULL;
	}

	function updateNodeServerCfg($serverNdx, $updateData)
	{
		$currentCfg = $this->getNodeServerCfg($serverNdx);
		if (!$currentCfg)
			return 0;
		if ($currentCfg['liveDataVer'] == $updateData['liveDataVer'])
			return 0;
		$updateData['changed'] = 1;
		$updateData['liveTimestamp'] = new \DateTime();

		$this->db()->query('UPDATE [mac_lan_devicesCfgNodes] SET ', $updateData, ' WHERE [ndx] = %i', $currentCfg['ndx']);

		return 1;
	}

	public function update($confirmApply = FALSE)
	{
		$this->updateAll();
	}

	public function getChanges()
	{
		$this->changes = ['cnt' => 0, 'cntChanged' => 0, 'table' => [], 'labels' => []];

		$this->changes['header'] = ['#' => '#', 'deviceId' => 'ID', 'deviceName' => 'Server', 'status' => 'Stav'];

		$this->changes['title'] = [
				['text' => 'Změny v nastavení node serverů', 'class' => 'h2'],
		];

		// -- load changed settings
		$q[] = 'SELECT cfgNodes.*, devices.[fullName] AS deviceName, devices.[id] AS deviceId';
		array_push ($q,' FROM [mac_lan_devicesCfgNodes] AS cfgNodes');
		array_push ($q,' LEFT JOIN mac_lan_devices AS devices ON cfgNodes.device = devices.ndx');
		array_push ($q,' WHERE 1');
		array_push ($q,' AND (cfgNodes.[changed] = %i', 1, ' OR cfgNodes.[applyNewData] = %i)', 1);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$labelClass = 'label-default';
			$labelSuffix = '';

			$item = ['deviceId' => $r['deviceId'], 'deviceName' => $r['deviceName'], 'status' => []];

			if ($r['changed'])
			{
				$item['status'][] = ['text' => 'Lokální změny', 'class' => 'label label-default'];
				$labelSuffix = 'Lokální změny';
				$this->changes['cntChanged']++;
			}
			if ($r['applyNewData'])
			{
				$item['status'][] = ['text' => 'Odesílání na server', 'class' => 'label label-info'];
				$labelClass = 'label-info';
				$labelSuffix = 'Odesílání na server';
			}

			$this->changes['cnt']++;
			$this->changes['table'][] = $item;

			$this->changes['labels'][] = ['text' => $r['deviceId'], 'suffix' => $labelSuffix, 'class' => 'label '.$labelClass];
		}
	}

	public function confirmChanges()
	{
		$q[] = 'SELECT cfgNodes.*, devices.[fullName] AS deviceName, devices.[id] AS deviceId';
		array_push ($q,' FROM [mac_lan_devicesCfgNodes] AS cfgNodes');
		array_push ($q,' LEFT JOIN mac_lan_devices AS devices ON cfgNodes.device = devices.ndx');
		array_push ($q,' WHERE 1');
		array_push ($q,' AND cfgNodes.[changed] = %i', 1);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($r['changed'])
			{
				$this->db()->query('UPDATE [mac_lan_devicesCfgNodes] SET newData = liveData, newDataVer = liveDataVer, ',
					'newTimestamp = NOW(), changed = 0, applyNewData = 1',
					' WHERE [ndx] = %i', $r['ndx']);
			}
		}

		$this->saveConfig();
	}

	public function saveConfig()
	{
		$q[] = 'SELECT cfgNodes.*, devices.[fullName] AS deviceName, devices.[id] AS deviceId';
		array_push ($q,' FROM [mac_lan_devicesCfgNodes] AS cfgNodes');
		array_push ($q,' LEFT JOIN mac_lan_devices AS devices ON cfgNodes.device = devices.ndx');
		array_push ($q,' WHERE 1');
		array_push ($q,' AND devices.docState != %i', 9800);
		array_push ($q,' ORDER BY devices.id, devices.fullName, devices.ndx');

		$localServers = [];
		$cameras = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$serverCfg = json_decode($r['newData'], TRUE);
			unset ($serverCfg['lan']);
			if (isset($serverCfg['cameras']))
			{
				$cameras = $cameras + $serverCfg['cameras'];
				unset ($serverCfg['cameras']);
			}

			$localServers[$r['device']] = $serverCfg;
		}

		$cfg ['mac']['localServers'] = $localServers;
		file_put_contents(__APP_DIR__ . '/config/_mac.localServers.json', utils::json_lint (json_encode ($cfg)));

		$cfgCameras ['mac']['cameras'] = $cameras;
		file_put_contents(__APP_DIR__ . '/config/_mac.cameras.json', utils::json_lint (json_encode ($cfgCameras)));

		\E10\compileConfig ();
	}
}
