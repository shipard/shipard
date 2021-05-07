<?php

namespace lib\server;

use \e10\Utility;


/**
 * Class NetDataAlarm
 * @package lib\server
 */
class NetDataAlarm extends Utility
{
	var $alarmData = [];
	var $macDeviceNdx = 0;
	var $macUrl = '';
	var $macApiKey = '';
	var $macMachineDeviceId = '';

	public function loadFromFile($fileName)
	{
		$data = file_get_contents($fileName);
		if (!$data)
			return;

		$rows = preg_split("/\\r\\n|\\r|\\n/", $data);
		foreach ($rows as $row)
		{
			$parts = explode(':', $row);
			if (count($parts) < 1)
				continue;

			$key = trim($parts[0]);
			if ($key === '')
				continue;

			array_shift($parts);
			$value = implode(':', $parts);
			$this->alarmData[$key] = trim($value);
		}
	}

	public function createAlert()
	{
		$alertSubject = '';
		$alertSubject .= $this->alarmData['host'];
		$alertSubject .= ' ' . $this->alarmData['status_message'];
		$alertSubject .= ': ' . $this->alarmData['info'];
		$deviceNdx = $this->macDeviceNdx;

		$changeState = 0;
		if ($this->alarmData['status'] === 'CRITICAL')
			$changeState = 1;
		elseif ($this->alarmData['status'] === 'CLEAR')
			$changeState = 2;
		else
			$changeState = 1;

		$eventData = [
			'type' => 'mac-lan-netdata-alarm',
			'device' => $deviceNdx,
			'srcDevice' => $deviceNdx,
			'time' => $this->alarmData['when'],
			'state' => $changeState,
			'alarmData' => $this->alarmData,
		];

		// -- alert send
		$alert = [
			'alertType' => 'mac-lan',
			'alertKind' => 'mac-lan-netdata-alarm',
			'alertSubject' => $alertSubject,
			'alertId' => 'mac-lan-netdata-' . $deviceNdx . '-' . $this->alarmData['alarm_id'],
			'payload' => $eventData,
		];

		return $alert;
	}

	public function send()
	{
		$alert = $this->createAlert();

		$requestDataStr = json_encode($alert);
		$url = $this->macUrl.'/api/objects/alert';

		$opts = [
			'http' => [
				'timeout' => 30,
				'method' => "GET",
				'header' =>
					"e10-api-key: " . $this->macApiKey . "\r\n".
					"e10-device-id: " . $this->macMachineDeviceId. "\r\n".
					"Content-type: text/json"."\r\n".
					"Content-Length: " . strlen($requestDataStr). "\r\n".
					"Connection: close\r\n",
				'content' => $requestDataStr,
			]
		];
		$context = stream_context_create($opts);

		$resultCode = file_get_contents ($url, FALSE, $context);
		$resultData = json_decode ($resultCode, TRUE);
	}
}
