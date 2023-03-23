<?php

namespace mac\iot\libs;

use \Shipard\Base\Utility, \Shipard\Utils\Utils, \Shipard\Utils\Json, \Shipard\Utils\Str;


/**
 * Class ZigbeeInfoAnalyzer
 */
class ZigbeeInfoAnalyzer extends Utility
{
	var ?array $data = NULL;
	var ?\mac\iot\libs\IotDevicesUtils $iotDevicesUtils = NULL;
	var \mac\iot\TableDevices $tableIotDevices;

	public function setData(array $data)
	{
		$this->data = $data;
	}

	protected function doEndDevice($serverNdx, $data, $addToDatabase = TRUE)
	{
		if (!isset($data['definition']))
		{
			error_log("#### definition MISSING ".json_encode($data));
			return FALSE;
		}


		if (!isset($data['definition']['vendor']))
		{
			error_log("#### VENDOR MISSING");
			return FALSE;
		}

		$vendor = $this->iotDevicesUtils->searchVendor('zigbee', $data['definition']['vendor']);
		if (!$vendor)
		{
			error_log("#### Vendor not found: `{$data['definition']['vendor']}`");
			return FALSE;
		}
		$model = 	$this->iotDevicesUtils->searchModel ('zigbee', $vendor['id'], $data['definition']['model'] ?? '', $data['model_id'] ?? '');
		if (!$model)
		{
			error_log("#### Model not found: `{$data['model']}`");
			return FALSE;
		}
		if ($addToDatabase)
		{
			$iotDeviceNdx = 0;

			$hwId = $data['ieee_address']	?? '';
			if ($hwId !== '')
			{
				$exist = $this->db()->query('SELECT * FROM [mac_iot_devices] WHERE hwId = %s', $hwId)->fetch();
				if ($exist)
				{ // UPDATE
					$iotDeviceNdx = $exist['ndx'];
					$update = [
						'friendlyId' => Str::upToLen($data['friendly_name'] ?? '', 60),
						'deviceVendor' => Str::upToLen($vendor['id'] ?? '', 20),
						'deviceModel' => Str::upToLen($model['id'] ?? '', 40),
						'nodeServer' => intval($serverNdx),
					];
					$this->db()->query('UPDATE [mac_iot_devices] SET ', $update, ' WHERE [ndx] = %i', $iotDeviceNdx);
					$this->tableIotDevices->docsLog($iotDeviceNdx);
				}
				else
				{
					$newItem = [
						'fullName' => Str::upToLen($data['definition']['description'] ?? '', 120),
						'friendlyId' => Str::upToLen($data['friendly_name'] ?? '', 60),
						'hwId' => Str::upToLen($data['ieee_address'] ?? '', 24),
						'lan' => 0,
						'nodeServer' => intval($serverNdx),
						'deviceType' => 'zigbee',
						'deviceVendor' => Str::upToLen($vendor['id'] ?? '', 20),
						'deviceModel' => Str::upToLen($model['id'] ?? '', 40),
						'docState' => 1000, 'docStateMain' => 0,
					];

					//error_log("--NEW: ".json_encode($newItem));
					$iotDeviceNdx = $this->tableIotDevices->dbInsertRec($newItem);
					$newRecData = $this->tableIotDevices->docsLog($iotDeviceNdx);

					$this->tableIotDevices->checkAfterSave2($newRecData);
				}
			}
			else
			{
				error_log("#### hwId not found: `{$data['ieeeAddr']}`");
			}

			if ($iotDeviceNdx)
			{
				$iotDeviceCfg = $this->iotDevicesUtils->getIotDeviceCfg($iotDeviceNdx);
				if ($iotDeviceCfg)
				{
					$dd = $data;
					if (isset($dd['endpoints']))
						unset($dd['endpoints']);
					if (isset($dd['scenes']))
						unset($dd['scenes']);

					$newDeviceCfg = ['deviceInfoData' => Json::lint($dd), 'deviceInfoTimestamp' => new \DateTime()];
					$newDeviceCfg['deviceInfoVer'] = sha1($newDeviceCfg['deviceInfoData']);
					if ($newDeviceCfg['deviceInfoVer'] !== $iotDeviceCfg['deviceInfoVer'])
						$this->iotDevicesUtils->setIotDeviceCfg($iotDeviceNdx, $newDeviceCfg);


					$drd = $this->tableIotDevices->loadItem($iotDeviceNdx);
					$this->tableIotDevices->checkAfterSave2($drd);
				}
			}
		}

		return TRUE;
	}

	protected function analyze()
	{
		if (!$this->data)
		{
			error_log("!!!FAIL: ".json_encode($this->data));
			return 'FAIL';
		}

		if ($this->data['type'] === 'zigbee-devices-list')
		{
			foreach ($this->data['data'] as $msg)
			{
				if (isset($msg['type']) && $msg['type'] === 'Coordinator')
					continue;

				$this->doEndDevice($this->data['serverId'] ?? 0, $msg);
			}
		}

		return 'OK';
	}

	public function run()
	{
		$this->iotDevicesUtils = new \mac\iot\libs\IotDevicesUtils($this->app());
		$this->tableIotDevices = new \mac\iot\TableDevices($this->app());
		return $this->analyze();
	}
}
