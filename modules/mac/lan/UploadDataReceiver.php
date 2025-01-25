<?php

namespace mac\lan;
use e10\DbTable, e10\utils, e10\Utility;


/**
 * Class TableLans
 * @package mac\lan
 */
class UploadDataReceiver extends Utility
{
	var $data;

	/** @var  DbTable */
	var $tableUnknowns;

	public function setData ($data)
	{
		$this->data = $data;
	}

	protected function doUnknowns ()
	{
		return 'OK'; // TODO: remove
	}

	protected function doInfo ()
	{
		$device = $this->data['data']['device'];
		$infoType = $this->data['data']['type'];
		$exist = $this->db()->query ('SELECT * FROM [mac_lan_devicesInfo] WHERE [device] = %i', $device, ' AND infoType = %s', $infoType)->fetch();
		if ($exist)
		{
			$item = [
					'dateUpdate' => $this->data['data']['datetime'],
					'data' => json_encode($this->data['data']),
					'checked' => 0
			];
			$this->db()->query ('UPDATE [mac_lan_devicesInfo] SET ', $item, ' WHERE ndx = %i', $exist['ndx']);
		}
		else
		{
			$item = [
					'infoType' => $infoType, 'device' => $device, 'checked' => 0,
					'dateCreate' => $this->data['data']['datetime'], 'dateUpdate' => $this->data['data']['datetime'],
					'data' => json_encode($this->data['data'])
			];
			$this->db()->query ('INSERT INTO [mac_lan_devicesInfo] ', $item);
		}

		if ($infoType === 'counters')
		{
			$date = utils::createDateTime($this->data['data']['datetime']);
			$dateId = $date->format ('Y-m-d').'D';
			foreach ($this->data['data']['items'] as $counterInfo)
			{
				$exist = $this->db()->query('SELECT * FROM [mac_lan_counters] WHERE [device] = %i', $device,
					' AND [counterKind] = %s', $counterInfo['id'], ' AND [dateId] = %s', $dateId)->fetch();
				if ($exist)
				{
					$this->db()->query('UPDATE [mac_lan_counters] SET [value] = %i', $counterInfo['val'], ' WHERE [ndx] = %i', $exist['ndx']);
				}
				else
				{
					$counterItem = [
						'device' => $device, 'dateId' => $dateId,
						'counterKind' => $counterInfo['id'], 'counterTimeStamp' => $this->data['data']['datetime'],
						'value' => intval($counterInfo['val'])
						];
					$this->db()->query('INSERT INTO [mac_lan_counters] ', $counterItem);
				}
			}
		}

		return 'OK';
	}

	protected function doShnIbInfo ()
	{
		$deviceRecData = NULL;
		if (isset($this->data['devId']))
			$deviceRecData = $this->db()->query('SELECT * FROM [mac_iot_devices] WHERE [friendlyId] = %s', $this->data['devId'])->fetch();
		if (!$deviceRecData && intval($this->data['devNdx'] ?? 0))
			$deviceRecData = $this->db()->query('SELECT * FROM [mac_iot_devices] WHERE [ndx] = %i', intval($this->data['devNdx']))->fetch();

		if (!$deviceRecData)
			return FALSE;

		$deviceInfoNdx = 0;
		$deviceInfoRecData = $this->db()->query('SELECT * FROM [mac_iot_devicesInfo] WHERE [device] = %i', $deviceRecData['ndx'])->fetch();
		if (!$deviceInfoRecData)
		{
			$info = ['device' => $deviceRecData['ndx'], 'dateCreate' => new \DateTime()];
			$this->db()->query('INSERT INTO [mac_iot_devicesInfo] ', $info);
			$deviceInfoNdx = $this->db()->getInsertId();
		}
		else
			$deviceInfoNdx = $deviceInfoRecData['ndx'];

		$update = [
			'dateUpdate' => new \DateTime(),
		];

		if (isset($this->data['items']['verFW']))
			$update['fwVersion'] = $this->data['items']['verFW'];
		if (isset($this->data['items']['verOS']))
			$update['osVersion'] = $this->data['items']['verOS'];
		if (isset($this->data['items']['devType']))
			$update['devType'] = $this->data['items']['devType'];

		if (intval($this->data['uptime'] ?? 0))
			$update['uptime'] = intval($this->data['uptime'] ?? 0);

		if (isset($this->data['battery'])) // zigbee devices
			$update['pwrBatteryLevel'] = $this->data['battery'];
		if (isset($this->data['linkquality'])) // zigbee devices
			$update['signalLevel'] = intval($this->data['linkquality']);

		if (isset($this->data['pwr-batt-perc']))
			$update['pwrBatteryLevel'] = $this->data['pwr-batt-perc'];
		if (isset($this->data['pwr-batt-voltage']))
			$update['pwrBatteryVoltage'] = $this->data['pwr-batt-voltage'];

		$this->db()->query('UPDATE [mac_iot_devicesInfo] SET ', $update, ' WHERE [ndx] = %i', $deviceInfoNdx);

		return 'OK';
	}

	public function run ()
	{
		if (isset($this->data['infoType']))
		{
			if ($this->data['infoType'] === 'shn-ib-info')
				return $this->doShnIbInfo ();

			return 'FALSE';
		}

		if (isset($this->data['type']))
		{
			if ($this->data['type'] === 'e10-nl-unkip')
			{
				$this->tableUnknowns = $this->app->table ('mac.lan.unknowns');
				return $this->doUnknowns ();
			}

			if ($this->data['type'] === 'e10-nl-snmp')
				return $this->doInfo ();


			if ($this->data['type'] === 'shn-ib-info')
				return $this->doShnIbInfo ();

			return 'FALSE';
		}

		return 'FALSE';
	}
}
