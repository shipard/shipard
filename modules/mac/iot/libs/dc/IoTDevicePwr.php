<?php

namespace mac\iot\libs\dc;
use Shipard\Utils\Utils;


/**
 * class IoTDevicePwr
 */
class IoTDevicePwr extends \Shipard\Base\DocumentCard
{
	var $scriptGenerator = NULL;

	protected function addPowerInfo()
	{
		$table = [];
		$lastState = '';
		$lastDT = NULL;
		$firstDT = NULL;

		$q = [];
		array_push($q, 'SELECT * FROM mac_iot_devicesInfoPwr');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND device = %i', $this->recData['ndx']);
		array_push($q, ' ORDER BY dateTime');
		$rows = $this->db()->query($q);
		foreach ($rows as $row)
		{
			if (!$firstDT)
				$firstDT = $row['dateTime'];
			$state = $row['pwrBatteryLevel'].'-'.$row['pwrBatteryVoltage'].'-'.$row['pwrCharging'].'-'.$row['pwrChargeCurrent'];
			if ($state != $lastState)
			{
				$item = [
					'dt' => Utils::datef($row['dateTime'], '%d%t'),
					'level' => $row['pwrBatteryLevel'],
					'voltage' => $row['pwrBatteryVoltage'],
					'charging' => $row['pwrCharging'],
					'current' => $row['pwrChargeCurrent'],
				];

				if ($lastDT)
					$table[count($table) - 1]['len'] = Utils::dateDiffShort($lastDT, $row['dateTime']);

				$table[] = $item;
				$lastDT = $row['dateTime'];
			}

			$lastState = $row['pwrBatteryLevel'].'-'.$row['pwrBatteryVoltage'].'-'.$row['pwrCharging'].'-'.$row['pwrChargeCurrent'];
		}

		if (count($table))
		{
			$now = new \DateTime();

			// last row len
			$table[count($table) - 1]['len'] = Utils::dateDiffShort($lastDT, $now);

			// total len
			$itemSum = [
				'dt' => 'Celková doba',
				'len' => Utils::dateDiffShort($firstDT, $now),
				'_options' => ['colSpan' => ['dt' => 2], 'class' => 'sumtotal', 'beforeSeparator' => 'separator']
			];
			$table[] = $itemSum;

			$h = [
				'#' => '#',
				'dt' => 'Datum a čas',
				'level' => ' Stav baterie [%]',
				'voltage' => ' Napětí baterie',
				'len' => ' Doba',
				'charging' => '|Nab.',
				'current' => ' Proud',
			];

			$this->addContent ('body', [
				'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
				'header' => $h, 'table' => $table, 'params' => ['precision' => 3],
			]);
		}
		else
		{
			$this->addContent ('body', [
				'pane' => 'e10-pane e10-pane-table', 'type' => 'line', 'line' => ['text' => 'Záznamy o napájení a stavu baterie nejsou dostupné.'],
			]);
		}
	}

	public function createContentBody ()
	{
		$this->addPowerInfo();
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
