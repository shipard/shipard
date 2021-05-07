<?php

namespace mac\lan;

require_once __APP_DIR__ . '/e10-modules/e10/web/web.php';


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

	public function run ()
	{
		if (!isset($this->data['type']))
			return 'FALSE';

		$this->tableUnknowns = $this->app->table ('mac.lan.unknowns');

		if ($this->data['type'] === 'e10-nl-unkip')
			return $this->doUnknowns ();

		if ($this->data['type'] === 'e10-nl-snmp')
			return $this->doInfo ();

		return 'FALSE';
	}
}
