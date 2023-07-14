<?php

namespace mac\iot\libs;

use \Shipard\Base\Utility;
use \mac\iot\TableCams;
use \Shipard\Utils\Json;


/**
 * class CamsCfgCreator
 */
class CamsCfgCreator extends Utility
{
  var $lanDevicesIPs = [];
  var $lanCfg = NULL;

  var $lanNdx = 0;
  var $lanRecData = NULL;

  var $camServerNdx = 0;
  var $camServerRecData = NULL;
  var $camServerMacDeviceCfg = NULL;

  var $cfgs = [];


  protected function lanDeviceIPs($lanDeviceNdx)
  {
    if (isset($this->lanDevicesIPs[$lanDeviceNdx]))
      return $this->lanDevicesIPs[$lanDeviceNdx];

    $q = [];
		array_push($q, 'SELECT ifaces.*');
    array_push ($q, ' FROM [mac_lan_devicesIfaces] AS ifaces');
		array_push ($q, ' WHERE ifaces.device = %i', $lanDeviceNdx);
		$rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $this->lanDevicesIPs[$r['device']][] = $r['ip'];
    }

    if (isset($this->lanDevicesIPs[$lanDeviceNdx]))
      return $this->lanDevicesIPs[$lanDeviceNdx];

    return NULL;
  }

  protected function createLanDevice($camDef)
  {
    $camCfg = [
      'type' => 0,
      'ndx' => $camDef['ndx'],
      'id' => $camDef['lanDeviceId'],
      'localServer' => $this->camServerNdx,
    ];

    $camIPs = $this->lanDeviceIPs($camDef['lanDevice']);
    if (isset($camIPs[0]))
      $camCfg['ip'] = $camIPs[0];

    $camCfg['cfg'] = [];

    $camCfg['cfg']['camLogin'] = $camDef['macDeviceCfg']['camLogin'] ?? '';
    if ($camCfg['cfg']['camLogin'] === '')
      $camCfg['cfg']['camLogin'] = $this->camServerMacDeviceCfg['camLogin'] ?? '';

    $camCfg['cfg']['camPasswd'] = $camDef['macDeviceCfg']['camPasswd'] ?? '';
    if ($camCfg['cfg']['camPasswd'] === '')
      $camCfg['cfg']['camPasswd'] = $this->camServerMacDeviceCfg['camPasswd'] ?? '';

    $camCfg['cfg']['picturesFolder'] = $camDef['macDeviceCfg']['picturesFolder'] ?? strval($camDef['ndx']);

    $camCfg['cfg']['streamURL'] = $this->camServerMacDeviceCfg['streamURL'] ?? '';
    if ($camCfg['cfg']['streamURL'] === '')
    {
      $camCfg['cfg']['streamURL'] = 'rtsp://';
      $camCfg['cfg']['streamURL'] .= $camCfg['cfg']['camLogin'].':'.$camCfg['cfg']['camPasswd'].'@';
      $camCfg['cfg']['streamURL'] .= $camCfg['ip'] ?? '--no-ip-address--';
      $camCfg['cfg']['streamURL'] .= ':554/';
    }

    if ($camDef['enableVehicleDetect'])
    {
      $camCfg['cfg']['enableVehicleDetect'] = $camDef['enableVehicleDetect'];
      $topic = 'shp/'.'readers/vd/camera-'.$camDef['ndx'];
      $camCfg['cfg']['vdTopic'] = $topic;
    }

    $this->cfgs[$camDef['ndx']] = $camCfg;

    /*
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
    */
  }

  protected function createIBEsp32($camDef)
  {
    $camCfg = [
      'type' => 1,
      'ndx' => $camDef['ndx'],
      'id' => $camDef['iotDeviceId'],
      'localServer' => $this->camServerNdx,
    ];

    $camCfg['cfg'] = [];
    $camCfg['cfg']['picturesFolder'] = 'ib'.strval($camDef['iotDevice']);

    $this->cfgs[$camDef['ndx']] = $camCfg;
  }

  public function create($camServerNdx, $lanNdx, $isDefaultServer, $camNdx = 0)
  {
    $this->camServerNdx = $camServerNdx;
    $this->lanNdx = $lanNdx;

    $q = [];
    array_push($q, 'SELECT cams.*, ');
    array_push($q, ' [lanDevices].[macDeviceCfg], [lanDevices].[id] AS lanDeviceId,');
    array_push($q, ' [iotDevices].[friendlyId] AS iotDeviceId');
		array_push($q, ' FROM [mac_iot_cams] AS [cams]');
    array_push($q, ' LEFT JOIN [mac_lan_devices] AS [lanDevices] ON [cams].[lanDevice] = [lanDevices].[ndx]');
    array_push($q, ' LEFT JOIN [mac_iot_devices] AS [iotDevices] ON [cams].[iotDevice] = [iotDevices].[ndx]');
    array_push($q, ' WHERE 1');

    if ($camNdx)
      array_push($q, ' AND [cams].[ndx] = %i', $camNdx);

    if ($lanNdx)
      array_push($q, ' AND [cams].[lan] = %i', $lanNdx);

    array_push($q, ' AND [cams].[docState] != %i', 9800);

    /*
		if ($isDefaultServer)
			array_push ($q,'AND (localServer = %i', $serverNdx, ' OR (localServer = %i', 0, ' AND lan = %i))', $lanNdx);
		else
			array_push ($q,'AND localServer = %i', $serverNdx);
    */

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $this->checkLan($r);

      $camDef = $r->toArray();
      if ($camDef['camType'] == TableCams::ctLanIP)
      {
        $camDef['macDeviceCfg'] = Json::decode($r['macDeviceCfg']);

        $this->createLanDevice($camDef);
      }
      elseif ($camDef['camType'] == TableCams::ctIBEsp32)
      {
        $this->createIBEsp32($camDef);
      }
    }
  }

  protected function checkLan($camRecData)
  {
    if (!$this->lanNdx)
      $this->lanNdx = $camRecData['lan'];

    if (!$this->lanRecData && $this->lanNdx)
      $this->lanRecData = $this->app()->loadItem($this->lanNdx, 'mac.lan.lans');

    if (!$this->camServerNdx && $this->lanRecData)
      $this->camServerNdx = $this->lanRecData['mainServerCameras'];

    if (!$this->camServerRecData && $this->camServerNdx)
    {
      $this->camServerRecData = $this->app()->loadItem($this->camServerNdx, 'mac.lan.devices');
      if ($this->camServerRecData)
        $this->camServerMacDeviceCfg = Json::decode($this->camServerRecData['macDeviceCfg']) ;
    }
  }
}
