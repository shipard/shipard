<?php

namespace Shipard\Application;



abstract class ApplicationCore 
{
	public ?array $cfgServer = NULL;

	public function __construct (?array $cfgServer = NULL)
	{
		$this->cfgServer = (!$cfgServer) ? $this->loadCfgFile('/etc/shipard/server.json') : $cfgServer;
	}

	public function loadCfgFile ($fileName)
	{
		if (is_file ($fileName))
		{
			$cfgString = file_get_contents ($fileName);
			if (!$cfgString)
				return NULL;
			$cfg = json_decode ($cfgString, true);
			if (!$cfg)
				return NULL;
			return $cfg;
		}
		return NULL;
	}
}