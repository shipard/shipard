<?php

namespace terminals\vs;

use e10\utils;


/**
 * Class ModuleServices
 * @package E10Pro\Purchase
 */
class ModuleServices extends \E10\CLI\ModuleServices
{

	public function downloadVideoArchives ()
	{
		$cameras = $this->app->cfgItem('e10.terminals.cameras');
		$servers = $this->app->cfgItem('e10.terminals.servers');
		$doneServers = [];

		foreach ($cameras as $camNdx => $cam)
		{
			if (in_array($cam['localServer'], $doneServers))
				continue;

			$srv = $servers[$cam['localServer']];
			$url = $srv['camerasURL'].'archive';

			$opts = ['http'=> ['timeout' => 30, 'method'=>'GET', 'header'=> "Connection: close\r\n"]];
			$context = stream_context_create($opts);
			$resultString = file_get_contents ($url, FALSE, $context);
			if (!$resultString)
				continue;
			$resultData = json_decode ($resultString, TRUE);
			if (!$resultData)
				continue;

			file_put_contents(__APP_DIR__.'/tmp/e10-vs-archive-'.$cam['localServer'].'.json', $resultString);

			$doneServers[] = $cam['localServer'];
		}
	}

	public function onCronEver ()
	{
		$this->downloadVideoArchives();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'ever': $this->onCronEver(); break;
		}
		return TRUE;
	}
}
