<?php

namespace mac\lan\libs\dashboard;



use \Shipard\Base\Utility, \Shipard\Utils\Utils, \mac\data\libs\SensorHelper;


/**
 * Class OverviewData
 * @package mac\lan\libs\dashboard
 */
class OverviewData extends Utility
{
	/** @var \mac\lan\TableLans */
	var $tableLans;
	var $lansRecData = [];

	/** @var \mac\lan\TableDevices */
	var $tableDevices;
	var $deviceKinds;

	var $lanNdx = 0;
	var $macDataSourceNdx = 0;

	var $devices = [];
	var $devicesPks = [];
	var $devicesWithDashboards = [];
	var $devicesWithSnmpRealtime = [];

	/** @var \mac\lan\TableRacks */
	var $tableRacks;
	var $racks = [];
	var $racksPks = [];
	var $devicesInRacks = [];

	// -- dashboard groups
	CONST
		dgiLan = 'lan', dgiServers = 'srv', dgiCamera = 'cams', dgiUPS = 'ups', dgiWiFi = 'wifi', dgiIoT = 'iot',
		dgiNONE = 'none';

	var $dgData = [];
	var $dgCfg;

	var $lanChanges = [];

	public function setLan($lanNdx)
	{
		$this->lanNdx = $lanNdx;
	}

	public function init()
	{
		$this->tableLans = $this->app()->table('mac.lan.lans');
		$this->tableDevices = $this->app()->table('mac.lan.devices');
		$this->tableRacks = $this->app()->table('mac.lan.racks');

		$this->deviceKinds = $this->app->cfgItem ('mac.lan.devices.kinds');

		$this->dgCfg = $this->app()->cfgItem('mac.lan.devices.groupsDashboard');
	}

	function loadData()
	{
		$this->loadData_Racks();
		$this->loadData_Devices();
		$this->loadDGInfo();
		$this->loadChanges();
	}

	function loadData_Devices()
	{
		$q[] = 'SELECT devices.ndx AS deviceNdx, devices.fullName AS deviceFullName, devices.deviceKind, devices.id AS deviceId, devices.uid AS deviceUid, devices.hideFromDR, devices.monitored, devices.vmId AS deviceVMId,';
		array_push ($q, ' devices.alerts, devices.lan, devices.place, devices.rack, devices.macDataSource, devices.macDeviceCfg, devices.macDeviceType, devices.lan AS deviceLan,');
		array_push ($q, ' devices.hwMode, devices.hwServer, parentDevices.id AS parentDeviceId,');
		array_push ($q, ' lans.mainServerCameras');
		array_push ($q, ' FROM [mac_lan_devices] AS devices');
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON devices.place = places.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON devices.lan = lans.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_devices AS parentDevices ON devices.hwServer = parentDevices.ndx');
		array_push ($q, ' WHERE devices.docStateMain < 3');

		if ($this->lanNdx)
			array_push ($q, ' AND devices.lan = %i', $this->lanNdx);

		array_push ($q, ' ORDER BY lans.[order], lans.[fullName], devices.hwMode, devices.deviceKind, devices.id');
		$rows = $this->app->db()->query($q);
		$counter = 0;
		foreach ($rows as $r)
		{
			$deviceNdx = $r['deviceNdx'];
			$deviceKind = $r['deviceKind'];
			$deviceId = $r['deviceId'];
			$dk = $this->deviceKinds[$deviceKind];

			$deviceRack = $r['rack'];
			/*
			if ($deviceRack && isset($this->racks[$deviceRack]) && isset($dk['showDashboardRacks']) && !$r['hideFromDR'])
			{
				if ($deviceKind === 30) { // UPS
					$this->racks[$deviceRack]['ups'][] = $deviceNdx;
				} else {
					$this->racks[$deviceRack]['devices'][] = $deviceNdx;
				}
			}*/

			$dgId = isset($dk['odg']) ? $dk['odg'] : self::dgiNONE;

			$this->devices[$deviceNdx] = [
				'title' => $r['deviceFullName'], 'deviceId' => $r['deviceId'], 'icon' => $dk['icon'],
				'dk' => $r['deviceKind'], 'lan' => $r['lan'],
				'monitored' => $r['monitored'],
				'macDataSource' => $r['macDataSource'],
				'macDeviceType' => $r['macDeviceType'],
				'hideFromDR' => $r['hideFromDR'],
				'rack' => $deviceRack,
				'lanBadgesUrl' => 'nd-'.Utils::safeChars($r['deviceId'], TRUE).'-'.$r['deviceUid'],
				'dkCfg' => $dk,
				'ifaces' => [], 'sensorsBadges' => []
			];

			$this->devicesPks[] = $deviceNdx;


			//if ($this->devices[$deviceNdx]['macDataSource'] && !in_array($deviceNdx, $this->devicesWithDashboards))
			if (isset($dk['useMonitoring']) && in_array('active', $dk['useMonitoring']) && $this->devices[$deviceNdx]['monitored'])
				$this->devicesWithDashboards[] = $deviceNdx;
			elseif (isset($dk['useMonitoring']) && in_array('passive', $dk['useMonitoring']))
				$this->devicesWithDashboards[] = $deviceNdx;

			//if ($this->devices[$deviceNdx]['macDataSource'] && $this->devices[$deviceNdx]['macDataSource'] === $this->macDataSourceNdx && !in_array($deviceNdx, $this->devicesWithSnmpRealtime))
			if (isset($dk['useMonitoring']) && in_array('passive', $dk['useMonitoring']))
				$this->devicesWithSnmpRealtime[] = $deviceNdx;

			if ($r['macDeviceType'] !== '' && $r['macDeviceCfg'] !== '')
			{
				$macDeviceCfg = json_decode($r['macDeviceCfg'], TRUE);
				if ($macDeviceCfg)
					$this->devices[$deviceNdx]['macDeviceCfg'] = $macDeviceCfg;
			}

			// -- dashboard groups
			if ($dgId !== self::dgiNONE)
			{
				if (!isset($this->dgData[$dgId]))
				{
					$this->dgData[$dgId] = $this->dgCfg[$dgId];
					$this->dgData[$dgId]['devices'] = [];
				}

				$treeLevel = 0;
				if ($r['hwMode'] > 0 && $r['hwServer'])
				{
					$treeOrder = $r['parentDeviceId'];
					$treeOrder .= '-00001-'.$r['deviceId'];
					$treeLevel = 1;
				}
				else
				{
					$treeOrder = $r['deviceId'];
					$treeOrder .= '-00000';
				}
				$this->dgData[$dgId]['devices'][$deviceNdx] = ['ndx' => $deviceNdx, 'treeOrder' => $treeOrder, 'treeLevel' => $treeLevel];
			}

			if ($deviceKind === 10)
			{ // camera
				$camServerNdx = $r['mainServerCameras'];
				$badgeQuantityId = 'statsd_cameras.diskusage.'.$deviceNdx.'_gauge';
				$this->devices[$deviceNdx]['infoBadges'][] = [
					'label' => 'Video',
					'lanBadgesUrl' => $this->devices[$camServerNdx]['lanBadgesUrl'],
					'badgeQuantityId' => $badgeQuantityId,
					'badgeParams' => [
						'units' => 'GB', 'precision' => 1,
						'_title' => 'Celková velikost video archívu této kamery',
						'value_color' => 'COLOR:null|368BC1>0',
					],
				];

				$badgeQuantityId = 'statsd_cameras.avgimgsize.'.$deviceNdx.'_gauge';
				$this->devices[$deviceNdx]['infoBadges'][] = [
					'label' => '1 obrázek',
					'lanBadgesUrl' => $this->devices[$camServerNdx]['lanBadgesUrl'],
					'badgeQuantityId' => $badgeQuantityId,
					'badgeParams' => [
						'units' => 'KB', 'divide' => 1024, 'precision' => 0,
						'_title' => 'Průměrná velikost jednoho obrázku z této kamery',
						'value_color' => 'COLOR:null|368BC1>0',
					],
				];
			}
			elseif ($deviceKind === 8 || $deviceKind === 9)
			{ // switch / router
				$badgeQuantityId = 'snmp_'.$this->devices[$deviceNdx]['deviceId'].'.uptime';
				$this->devices[$deviceNdx]['deviceBadges'][] = [
					'label' => 'uptime',
					'badgeQuantityId' => $badgeQuantityId,
					'badgeParams' => ['dimensions' => 'uptime', 'units' => 'hours', 'divide' => 3600, 'value_color' => 'COLOR:null|red>2400|orange>1200|00A000>=0'],
				];
			}
			elseif ($deviceKind === 11)
			{ // NAS
				if ($this->devices[$deviceNdx]['macDeviceType'] === 'nas-synology')
				{
					$badgeQuantityId = 'snmp_'.$this->devices[$deviceNdx]['deviceId'].'.system_load';
					$this->devices[$deviceNdx]['lanBadges'][] = [
						'label' => 'load15',
						'badgeQuantityId' => $badgeQuantityId,
						'badgeParams' => ['dimensions' => 'load15', 'units' => 'empty', 'value_color' => 'COLOR:null|red>6|orange>3|00A000>=0'],
					];

					$badgeQuantityId = 'snmp_'.$this->devices[$deviceNdx]['deviceId'].'.uptime';
					$this->devices[$deviceNdx]['lanBadges'][] = [
						'label' => 'uptime',
						'badgeQuantityId' => $badgeQuantityId,
						'badgeParams' => ['dimensions' => 'uptime', 'units' => 'hours', 'divide' => 3600, 'value_color' => 'COLOR:null|red>2400|orange>1200|00A000>=0'],
					];

					$this->devices[$deviceNdx]['lanBadges'][] = [
						'label' => 'CPU','badgeQuantityId' => 'snmp_'.$this->devices[$deviceNdx]['deviceId'].'.system_temperature',
						'badgeDataSource' => $this->devices[$deviceNdx]['macDataSource'],
						'badgeParams' => ['dimensions' => 'System', 'units' => '°C', 'value_color' => 'COLOR:null|red>55|orange>45|00A000>10|FFA0FF>=0'],
					];
				}
			}
			elseif ($deviceKind === 7 || $deviceKind === 70)
			{ // servers
				if ($this->devices[$deviceNdx]['monitored'] && ($this->devices[$deviceNdx]['macDeviceType'] === 'server-linux' || $this->devices[$deviceNdx]['macDeviceType'] === 'shipardNode-common'))
				{
					$badgeQuantityId = 'system.load';
					$this->devices[$deviceNdx]['lanBadges'][] = [
						'label' => 'load15',
						'badgeQuantityId' => $badgeQuantityId,
						'lanBadgesUrl' => $this->devices[$deviceNdx]['lanBadgesUrl'],
						'badgeParams' => ['dimensions' => 'load15', 'units' => 'empty', 'precision' => 2, 'value_color' => 'COLOR:null|red>7|orange>4|00A000>=0'],
					];

					$badgeQuantityId = 'system.uptime';
					$this->devices[$deviceNdx]['lanBadges'][] = [
						'label' => 'uptime',
						'badgeQuantityId' => $badgeQuantityId,
						'lanBadgesUrl' => $this->devices[$deviceNdx]['lanBadgesUrl'],
						'badgeParams' => ['dimensions' => 'uptime', 'units' => 'hours', 'divide' => 3600, 'value_color' => 'COLOR:null|red>2400|orange>1200|00A000>=0'],
					];
				}

				if ($r['hwMode'] === 2 && $r['hwServer'] && $r['deviceVMId'] !== '')
				{
					$VMIDND = preg_replace(['~[^0-9a-zA-Z\-]~i'], '_', $r['deviceVMId']);
					$badgeQuantityId = 'cgroup_'.$VMIDND.'.cpu_limit';
					$this->devices[$deviceNdx]['lanBadges'][] = [
						'label' => 'CPU',
						'badgeQuantityId' => $badgeQuantityId,
						'lanBadgesUrl' => $this->devices[$r['hwServer']]['lanBadgesUrl'],
						'badgeParams' => ['dimensions' => 'used', 'units' => '%', 'precision' => 1, 'value_color' => 'COLOR:null|red>20|orange>10|00A000>=0'],
					];

					$badgeQuantityId = 'cgroup_'.$VMIDND.'.mem_usage_limit';
					$this->devices[$deviceNdx]['lanBadges'][] = [
						'label' => 'MEM',
						'badgeQuantityId' => $badgeQuantityId,
						'lanBadgesUrl' => $this->devices[$r['hwServer']]['lanBadgesUrl'],
						'badgeParams' => ['dimensions' => 'used', 'precision' => 1, 'value_color' => 'COLOR:null|red>8000|orange>4000|00A000>=0'],
					];
				}

				if (isset($this->devices[$deviceNdx]['macDeviceCfg']) && $this->devices[$deviceNdx]['macDeviceCfg']['enableCams'])
				{ // video
					$badgeQuantityId = 'system.cpu';
					$this->devices[$deviceNdx]['lanBadges'][] = [
						'label' => 'iowait',
						'badgeQuantityId' => $badgeQuantityId,
						'lanBadgesUrl' => $this->devices[$deviceNdx]['lanBadgesUrl'],
						'badgeParams' => [
							'dimensions' => 'iowait', 'units' => '%', 'precision' => 2, 'after' => -300,
							'value_color' => 'COLOR:null|red>10|orange>2|00A000>=0'
						],
					];
					$badgeQuantityId = 'system.cpu';
					$this->devices[$deviceNdx]['lanBadges'][] = [
						'label' => 'softirq',
						'badgeQuantityId' => $badgeQuantityId,
						'lanBadgesUrl' => $this->devices[$deviceNdx]['lanBadgesUrl'],
						'badgeParams' => [
							'dimensions' => 'softirq', 'units' => '%', 'precision' => 2, 'after' => -300,
							'value_color' => 'COLOR:null|red>8|orange>3|00A000>=0'
						],
					];

					$badgeQuantityId = 'statsd_cameras.archive.diskusage_gauge';
					$this->devices[$deviceNdx]['infoBadges'][] = [
						'label' => 'Video',
						'badgeQuantityId' => $badgeQuantityId,
						'lanBadgesUrl' => $this->devices[$deviceNdx]['lanBadgesUrl'],
						'badgeParams' => [
							'units' => 'TB', 'precision' => 3, 'divide' => 1024,
							'value_color' => 'COLOR:null|red<1|red>700|orange>500|00A000>1',
							'_title' => 'Celková velikost souborů video archívu'
						],
					];

					$badgeQuantityId = 'statsd_cameras.archive.filescount_gauge';
					$this->devices[$deviceNdx]['infoBadges'][] = [
						'label' => 'Soubory',
						'lanBadgesUrl' => $this->devices[$deviceNdx]['lanBadgesUrl'],
						'badgeQuantityId' => $badgeQuantityId,
						'badgeParams' => [
							'precision' => 0, 'units' => 'empty', 'value_color' => 'COLOR:null|red<96|orange<168|00A000>=0',
							'_title' => 'Počet souborů ve video archívu'
						],
					];

					$badgeQuantityId = 'statsd_cameras.archive.len_gauge';
					$this->devices[$deviceNdx]['infoBadges'][] = [
						'label' => 'Doba',
						'lanBadgesUrl' => $this->devices[$deviceNdx]['lanBadgesUrl'],
						'badgeQuantityId' => $badgeQuantityId,
						'badgeParams' => [
							'precision' => 0, 'units' => 'hours', 'value_color' => 'COLOR:null|red<96|orange<168|#00A000>=0',
							'_title' => 'Celková doba, kterou video archív pokrývá'
						],
					];
				}
			}
			elseif ($deviceKind === 30)
			{ // UPS
				if ($this->devices[$deviceNdx]['macDeviceType'] === 'ups-apc')
				{
					$this->devices[$deviceNdx]['sensorsBadges'][] = [
						'label' => 'L','badgeQuantityId' => 'apcupsd_local.load',
						'badgeDataSource' => $this->devices[$deviceNdx]['macDataSource'],
						'badgeParams' => ['dimensions' => 'load', 'label_color' => '4C787E', 'value_color' => 'COLOR:null|red>40|orange>15|00A000>1|FF70FF>=0', 'refresh' => 120],
					];

					$this->devices[$deviceNdx]['sensorsBadges'][] = [
						'label' => 'B','badgeQuantityId' => 'apcupsd_local.charge',
						'badgeDataSource' => $this->devices[$deviceNdx]['macDataSource'],
						'badgeParams' => ['dimensions' => 'charge', 'label_color' => '4C787E', 'value_color' => 'COLOR:null|00A000>=95|orange>50|red>=0', 'refresh' => 75],
					];

					$this->devices[$deviceNdx]['sensorsBadges'][] = [
						'label' => 'T','badgeQuantityId' => 'apcupsd_local.time',
						'badgeDataSource' => $this->devices[$deviceNdx]['macDataSource'],
						'badgeParams' => ['dimensions' => 'time', 'units' => 'minutes', 'label_color' => '#4C787E', 'value_color' => 'COLOR:null|00A000>=45|orange>25|red>=0', 'refresh' => 75],
					];
				}
				elseif ($this->devices[$deviceNdx]['macDeviceType'] === 'ups-nut')
				{
					$this->devices[$deviceNdx]['sensorsBadges'][] = [
						'label' => 'L','badgeQuantityId' => 'nut_'.$deviceId.'.load',
						'badgeDataSource' => $this->devices[$deviceNdx]['macDataSource'],
						'badgeParams' => ['dimensions' => 'load', 'label_color' => '4C787E', 'value_color' => 'COLOR:null|red>40|orange>15|00A000>1|FF70FF>=0', 'refresh' => 120],
					];

					$this->devices[$deviceNdx]['sensorsBadges'][] = [
						'label' => 'B','badgeQuantityId' => 'nut_'.$deviceId.'.charge',
						'badgeDataSource' => $this->devices[$deviceNdx]['macDataSource'],
						'badgeParams' => ['dimensions' => 'charge', 'label_color' => '4C787E', 'value_color' => 'COLOR:null|00A000>=95|orange>50|red>=0', 'refresh' => 75],
					];

					$this->devices[$deviceNdx]['sensorsBadges'][] = [
						'label' => 'T','badgeQuantityId' => 'nut_'.$deviceId.'.runtime',
						'badgeDataSource' => $this->devices[$deviceNdx]['macDataSource'],
						'badgeParams' => ['dimensions' => 'runtime', 'units' => 'seconds', 'label_color' => '#4C787E', 'value_color' => 'COLOR:null|00A000>=45|orange>25|red>=0', 'refresh' => 75],
					];
				}
			}
			$counter++;
		}

		$this->loadDevicesPorts();
		$this->loadDevicesSensors();
	}

	function loadDevicesPorts()
	{
		if (!count($this->devicesPks))
		{
			return;
		}
		$addrTypes = $this->app->cfgItem('mac.lan.ifacesAddrTypes');

		$q[] = 'SELECT ports.*, ';
		array_push ($q,' connectedDevices.id AS connectedDeviceId, connectedDevices.id AS connectedDeviceId, connectedDevices.hideFromDR AS connectedDeviceHideFromDR,');
		array_push ($q,' connectedPorts.ndx AS portNdx, connectedPorts.portId AS connectedPortId, connectedPorts.note AS connectedPortNote, connectedPorts.portNumber AS connectedPortNumber, connectedPorts.portRole AS connectedPortRole,');
		array_push ($q,' portDevices.hideFromDR AS portDeviceHideFromDR');
		array_push ($q,' FROM [mac_lan_devicesPorts] AS ports');
		array_push ($q,' LEFT JOIN [mac_lan_devices] AS connectedDevices ON ports.connectedToDevice = connectedDevices.ndx');
		array_push ($q,' LEFT JOIN [mac_lan_devicesPorts] AS connectedPorts ON ports.connectedToPort = connectedPorts.ndx');
		array_push ($q,' LEFT JOIN [mac_lan_devices] AS portDevices ON ports.device = portDevices.ndx');
		array_push ($q,' WHERE 1');
		array_push ($q,' AND ports.device IN %in', $this->devicesPks);
		array_push ($q,' ORDER BY ports.rowOrder, ports.ndx');
		$rows = $this->app->db()->query($q);

		foreach ($rows as $r)
		{
			$deviceNdx = $r['device'];
			$deviceKind = $this->devices[$deviceNdx]['dk'];
			$disableConnectedDevice = FALSE;

			if ($r['portRole'] === 90 && $deviceKind === 8)
			{ // WAN/Internet port
				$label = $r['note'];
				if ($label === '')
					$label = 'internet';
				//$badgeValueId = 'maclan_'.$this->devices[$deviceNdx]['lan'].'_D'.$deviceNdx.'.bandwidth_port'.$r['portNumber'];
				$badgeValueId = 'snmp_'.$this->devices[$deviceNdx]['deviceId'].'.bandwidth_port_'.$r['portNumber'];
				$this->devices[$deviceNdx]['uplinkPortsBadges'][] = [
					'label' => $label, 'ndx' => $r['portNdx'],
					'badgeQuantityId' => $badgeValueId,
					'badgeParams' => [
						'dimensions' => 'in|out', 'label_color' => '47556C', 'options' => 'abs', 'units' => 'Mb/s',
						'divide' => '1024', 'precision' => 2, 'value_color' => 'COLOR:null|lightgray<1|red>100|orange>75|00A000>5',
						'_title' => $this->devices[$deviceNdx]['deviceId'].' / '.$r['portId'],
					],
				];
				//$disableConnectedDevice = TRUE;
			}
			elseif ($r['portRole'] === 20 && $deviceKind === 9)
			{ // uplink - switch
				if (!isset($this->devices[$deviceNdx]['uplinkPortsBadges']))
					$this->devices[$deviceNdx]['uplinkPortsBadges'] = [];

				//$badgeValueId = 'maclan_'.$this->devices[$deviceNdx]['lan'].'_D'.$deviceNdx.'.bandwidth_port'.$r['portNumber'];
				$badgeValueId = 'snmp_'.$this->devices[$deviceNdx]['deviceId'].'.bandwidth_port_'.$r['portNumber'];
				$this->devices[$deviceNdx]['uplinkPortsBadges'][] = [
					'label' => $r['portId'],
					'badgeQuantityId' => $badgeValueId,
					'badgeParams' => [
						'dimensions' => 'in|out', 'label_color' => '273539', 'options' => 'abs',
						'units' => 'Mb/s', 'divide' => '1024', 'precision' => 2, 'value_color' => 'COLOR:null|lightgray<1|red>100|orange>75|00A000>5',
						'_title' => '→ '.$r['connectedDeviceId'].' / '.$r['connectedPortId'],
					],
				];
				//$this->devices[$deviceNdx]['badgesTitle'] = '→ '.$r['connectedDeviceId'].' / '.$r['connectedPortId'];
				//$disableConnectedDevice = TRUE;
			}
			elseif (/*$r['portRole'] === 10 &&*/ $r['connectedPortRole'] === 90)
			{ // /*access port*/ to router WAN port
				if (!isset($this->devices[$deviceNdx]['uplinkPortsBadges']))
					$this->devices[$deviceNdx]['uplinkPortsBadges'] = [];

				//$badgeValueId = 'maclan_'.$this->devices[$deviceNdx]['lan'].'_D'.$deviceNdx.'.bandwidth_port'.$r['portNumber'];
				$badgeValueId = 'snmp_'.$this->devices[$deviceNdx]['deviceId'].'.bandwidth_port_'.$r['portNumber'];
				$this->devices[$deviceNdx]['uplinkPortsBadges'][] = [
					'label' => $r['portId'],
					'badgeQuantityId' => $badgeValueId,
					'badgeParams' => [
						'dimensions' => 'in|out', 'label_color' => '652739', 'options' => 'abs',
						'units' => 'Mb/s', 'divide' => '1024', 'precision' => 2, 'value_color' => 'COLOR:null|lightgray<1|red>100|orange>75|00A000>5',
						'_title' => '→ '.$r['connectedDeviceId'].' / '.$r['connectedPortId'],
					],
				];
			}

			if ($r['connectedDeviceHideFromDR'] || $r['portDeviceHideFromDR'] || $r['connectedPortRole'] === 90)
				$disableConnectedDevice = 1;

			if (!$disableConnectedDevice)
			{
				//$label = $r['connectedPortNote'];
				//if ($label === '')
				$label = $r['connectedPortId'];
				//$badgeQuantityId = $this->devices[$deviceNdx]['lan'].'_D'.$deviceNdx.'.bandwidth_port'.$r['portNumber'];
				$badgeQuantityId = 'snmp_'.$this->devices[$deviceNdx]['deviceId'].'.bandwidth_port_'.$r['portNumber'];
				$this->devices[$r['connectedToDevice']]['lanBadges'][] = [
						'label' => $label,
						'badgeQuantityId' => $badgeQuantityId,
						'badgeParams' => [
							'dimensions' => 'in|out', 'label_color' => '476C55', 'options' => 'abs', 'units' => 'Mb/s', 'divide' => '1024',
							'precision' => 2, 'value_color' => 'COLOR:null|lightgray<1|red>100|orange>75|AABB00>6|00A000>5',
							'_title' => '→ '.$this->devices[$deviceNdx]['deviceId'].' / '.$r['portId'],
						],
				];
			}
		}
	}

	function loadDevicesSensors()
	{
		$q [] = 'SELECT sensorsToShow.*';
		array_push ($q, ' FROM [mac_lan_devicesSensorsShow] AS sensorsToShow');
		array_push ($q, ' LEFT JOIN [mac_iot_sensors] AS sensors ON sensorsToShow.sensor = sensors.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS devices ON sensorsToShow.device = devices.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND sensorsToShow.[device] IN %in', $this->devicesPks);
		array_push ($q, ' ORDER BY sensorsToShow.[rowOrder]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$deviceNdx = $r['device'];

			$sh = new SensorHelper($this->app());
			$sh->setSensor($r['sensor']);
			$sensorCode = $sh->badgeCode(1);

			$this->devices[$deviceNdx]['sensors'][] = [
				'code' => $sensorCode,
			];
		}
	}

	function loadData_Racks()
	{
		$q [] = "SELECT racks.*, places.fullName as placeFullName, lans.shortName as lanShortName FROM [mac_lan_racks] AS racks";
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON racks.place = places.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON racks.lan = lans.ndx');
		//array_push ($q, ' LEFT JOIN e10pro_property_property AS property ON racks.property = property.ndx');
		array_push ($q, ' WHERE 1');

		if ($this->lanNdx)
			array_push ($q, ' AND racks.lan = %i', $this->lanNdx);

		array_push ($q, ' AND racks.docStateMain <= %i', 2);

		array_push ($q, ' ORDER BY racks.rackKind, racks.fullName');

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$rackNdx = $r['ndx'];
			$rack = ['ndx' => $rackNdx, 'title' => $r['fullName'], 'icon' => $this->tableRacks->tableIcon($r), 'devices' => [], 'ups' => [], 'titleSensors' => []];

			$this->racks[$rackNdx] = $rack;
			$this->racksPks[] = $rackNdx;
		}

		//$this->loadData_RacksSensors();
	}

	function loadData_RacksSensors()
	{
		return;
		if (!count($this->racksPks))
			return;

		$q [] = 'SELECT sensorsToShow.*, ';
		array_push ($q, ' sensors.fullName AS sensorFullName, sensors.srcDataSourceQuantityId, sensors.srcDataSourceValuesIds, sensors.sensorBadgeLabel, sensors.sensorBadgeUnits,');
		array_push ($q, ' dataSources.fullName AS dsFullName, dataSources.url AS srcDataSourceUrl');
		array_push ($q, ' FROM [mac_lan_racksSensorsShow] AS sensorsToShow');
		array_push ($q, ' LEFT JOIN [mac_iot_sensors] AS sensors ON sensorsToShow.sensor = sensors.ndx');
		array_push ($q, ' LEFT JOIN [mac_data_sources] AS dataSources ON sensors.srcDataSource = dataSources.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_racks] AS racks ON sensorsToShow.rack = racks.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND sensorsToShow.[rack] IN %in', $this->racksPks);
		array_push ($q, ' ORDER BY sensorsToShow.[rowOrder]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$rackNdx = $r['rack'];
			$sensor = ['info' => $r->toArray()];
			$this->racks[$rackNdx]['titleSensors'][] = $sensor;
		}
	}

	function loadDGInfo()
	{
		// -- wifi
		$this->dgData[self::dgiWiFi]['dpInfo'] = [];

		$q[] = 'SELECT * FROM [mac_lan_wlans] WHERE 1';
		array_push ($q, ' AND [docState] = %i', 4000);
		if ($this->lanNdx)
			array_push ($q, ' AND [lan] = %i', $this->lanNdx);
		array_push ($q, ' ORDER BY [ssid]');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->dgData[self::dgiWiFi]['dpInfo'][] = ['label' => $r['ssid'], 'ndx' => $r['ndx'], 'id' => $r['ssid']];
		}
	}

	function loadChanges()
	{
		// -- node servers
		$une = new \mac\lan\libs\NodeServerCfgUpdater($this->app());
		$une->init();
		$une->getChanges();
		if ($une->changes && $une->changes['cnt'])
		{
			$this->lanChanges['nodeServers'] = $une->changes;
		}

		// -- lanControl
		$lcu = new \mac\lan\libs\LanControlCfgUpdater($this->app());
		$lcu->getRequestsStates();
		if ($lcu->requestsStates && $lcu->requestsStates['cnt'])
		{
			$this->lanChanges['lanControl'] = $lcu->requestsStates;
		}
	}

	function lanRecData ($lanNdx)
	{
		if (isset($this->lansRecData[$lanNdx]))
			return $this->lansRecData[$lanNdx];

		$this->lansRecData[$lanNdx] = $this->tableLans->loadItem ($lanNdx);
		return $this->lansRecData[$lanNdx];
	}

	public function run()
	{
		$this->init();
		$this->loadData();
	}
}
