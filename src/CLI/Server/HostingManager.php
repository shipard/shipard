<?php

namespace Shipard\CLI\Server;
use \Shipard\Utils\Utils;
use \Shipard\Base\Utility;


class HostingManager extends Utility
{
	public function getHostingInfo ()
	{
		if (!$this->app()->cfgServer)
			return $this->app()->err('server config not found');

		if ($this->app()->cfgServer['useHosting'])
			return $this->getHostingInfoReal();

		return $this->getHostingInfoFake();
	}

	public function getHostingInfoReal ()
	{
		$opts = [
			'http' => [
				'timeout' => 30,
				'method' => "GET",
				'header' =>
					"e10-api-key: " . $this->app()->cfgServer['hostingApiKey'] . "\r\n" .
					"e10-device-id: " . Utils::machineDeviceId() . "\r\n" .
					"Connection: close\r\n"
				]
			];
		$context = stream_context_create($opts);

		$url = 'https://'.$this->app()->cfgServer['hostingDomain'] . '/api/call/e10pro.hosting.server.getHostingInfo';

		$resultCode = file_get_contents($url, FALSE, $context);
		$resultData = json_decode($resultCode, TRUE);

		if ($resultData === FALSE || !isset($resultData['data']))
			return $this->app()->err('invalid server response');

		$hostingInfo = $resultData['data'];

		$e = new \lib\server\SaveHostingInfo($this);
		$e->setData($hostingInfo);
		$e->run();

		return TRUE;
	}

	public function getHostingInfoFake ()
	{
		$url = 'https://download.shipard.org/certs/cert.json';
		$resultCode = file_get_contents($url, FALSE);
		$resultData = json_decode($resultCode, TRUE);

		$e = new \lib\server\SaveHostingInfo($this);
		$e->setData($resultData);
		$e->run();

		return TRUE;
	}
}

