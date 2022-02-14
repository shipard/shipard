<?php

namespace mac\vs;


/**
 * Class ModuleServices
 * @package mac\vs
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function downloadVideoArchives ()
	{
		$cameras = $this->app->cfgItem('mac.cameras', []);
		$servers = $this->app->cfgItem('mac.localServers', []);
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
			{
				error_log("Download video archive for server #{$cam['localServer']} from `$url` failed...");
				continue;
			}	
			$resultData = json_decode ($resultString, TRUE);
			if (!$resultData)
			{
				error_log("Invalid video archive data for server #{$cam['localServer']} from `$url` (wrong json syntax?)");
				continue;
			}

			file_put_contents(__APP_DIR__.'/tmp/e10-vs-archive-'.$cam['localServer'].'.json', $resultString);

			$doneServers[] = $cam['localServer'];
		}

		return TRUE;
	}

	public function onCronEver ()
	{
		$this->downloadVideoArchives();
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'download-video-archives': return $this->downloadVideoArchives();
		}

		parent::onCliAction($actionId);
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
