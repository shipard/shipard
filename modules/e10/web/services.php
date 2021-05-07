<?php

namespace E10\Web;


class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onAppUpgrade ()
	{
		$s [] = ['end' => '2020-08-30', 'sql' => "UPDATE e10_web_wuKeys SET docState = 4000, docStateMain = 2 WHERE docState = 0"];
		$s [] = ['end' => '2017-11-30', 'sql' => "UPDATE e10_web_pages SET pageMode = 'web' WHERE pageMode = ''"];
		$s [] = ['end' => '2017-11-30', 'sql' => "UPDATE e10_web_urldecorations SET docState = 4000, docStateMain = 2 WHERE docState = 0"];
		$s [] = ['end' => '2017-11-30', 'sql' => "UPDATE e10_web_servers SET serverMode = 'web' WHERE serverMode = ''"];

		// -- decorations
		$servers = $this->app->cfgItem ('e10.web.servers.list', []);
		if (count($servers))
		{
			$server = key($servers);
			$s [] = ['end' => '2017-11-30', 'sql' => "UPDATE e10_web_urldecorations SET server = $server WHERE server = 0"];
		}

		$this->doSqlScripts ($s);

		// -- create nginx configs
		$tableServers = $this->app->table('e10.web.servers');
		$tableServers->createNginxConfigs();
	}

	public function dataSourceStatsCreate()
	{
		$dsStats = new \lib\hosting\DataSourceStats($this);
		$dsStats->loadFromFile();

		// -- web domains
		$dsStats->data['webDomains'] = [];
		$webServers = $this->app->cfgItem('e10.web.servers.list', []);
		foreach ($webServers as $ws)
		{
			if (!isset($ws['domain']) || $ws['domain'] === '' || $ws['domain'] === 'novyweb')
				continue;
			$dsStats->data['webDomains']['primary'][] = $ws['domain'];
		}
		if (isset($ws['drh']))
		{
			foreach ($ws['drh'] as $domain)
				$dsStats->data['webDomains']['primary'][] = $domain;
		}
		if (!count($dsStats->data['webDomains']))
			unset ($dsStats->data['webDomains']);

		$dsStats->saveToFile();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'stats': $this->dataSourceStatsCreate(); break;
		}
		return TRUE;
	}
}
