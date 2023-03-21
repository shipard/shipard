<?php

namespace mac\iot\libs;

use \Shipard\Base\Utility, \e10\utils, \e10\json, e10\uiutils;


/**
 * Class IotDeviceCfgUpdater
 */
class IotDeviceCfgUpdater extends Utility
{
	var $tableDevices;
	var ?\mac\iot\libs\IotDevicesUtils $iotDevicesUtils = NULL;

	var $dataModel = [];

	var $formatVersion = '1';

	var $iotDeviceRecData = NULL;
	var $deviceSettings = NULL;
	var $iotDeviceCfg = NULL;
	var $iotDeviceModel = NULL;
	var $cfgRecData = NULL;

	public function init()
	{
		$this->tableDevices = $this->app()->table('mac.iot.devices');
		$this->iotDevicesUtils = new \mac\iot\libs\IotDevicesUtils($this->app());
	}

	public function update($iotDeviceRecData, &$update)
	{
		$this->iotDeviceRecData = $iotDeviceRecData;
		$this->iotDeviceCfg = $this->tableDevices->iotDeviceCfgFromRecData($iotDeviceRecData, TRUE);
		$this->deviceSettings = json_decode($this->iotDeviceRecData['deviceSettings'], TRUE);
		$this->iotDeviceModel = $this->iotDevicesUtils->deviceModel($this->iotDeviceRecData);
		$this->cfgRecData = $this->iotDevicesUtils->getIotDeviceCfg($iotDeviceRecData['ndx']);

		if ($iotDeviceRecData['deviceKind'] !== $this->iotDeviceModel['iotKind'])
			$update['deviceKind'] = $this->iotDeviceModel['iotKind'];
	}
}
