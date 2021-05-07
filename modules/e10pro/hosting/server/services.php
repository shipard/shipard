<?php

namespace E10Pro\Hosting\Server;


class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onAppUpgrade ()
	{
		$s [] = ['end' => '2017-11-30', 'sql' => "UPDATE e10pro_hosting_server_datasources SET [dsType] = 3, [condition] = 0 WHERE [condition] = 4"];

		$s [] = ['end' => '2017-06-30', 'sql' => "UPDATE e10pro_hosting_server_datasources SET [dsId1] = [domain] WHERE [domain] != '' AND [dsId1] = ''"];

		$s [] = array ('version' => 0, 'sql' => "UPDATE e10pro_hosting_server_datasources SET dateTrialEnd = '2015-06-30' WHERE dateTrialEnd IS NULL");

		$s [] = array ('version' => 0, 'sql' => "update e10pro_hosting_server_usersds set docState = 4000, docStateMain = 2 where docState = 0");
		$s [] = array ('version' => 0, 'sql' => "DELETE FROM e10pro_hosting_server_usersds WHERE datasource IS NULL");
		$s [] = array ('version' => 0, 'sql' => "DELETE FROM e10pro_hosting_server_usersds WHERE [user] = 0");
		$s [] = array ('version' => 0, 'sql' => "UPDATE e10pro_hosting_server_datasources SET created = dateStart WHERE created IS NULL AND dateStart IS NOT NULL");
		//$s [] = array ('version' => 0, 'sql' => "UPDATE e10pro_hosting_server_datasources SET site = 1 WHERE site = 0");

		$s [] = array ('version' => 0, 'sql' => "UPDATE e10pro_hosting_server_datasources SET gidstr = gid WHERE gidstr = ''");
		$s [] = array ('version' => 0, 'sql' => "update e10pro_hosting_server_servers set docState = 4000, docStateMain = 2 where docState = 0");
		$s [] = array ('version' => 0, 'sql' => "update e10pro_hosting_server_modules set docState = 4000, docStateMain = 2 where docState = 0");

		$this->doSqlScripts ($s);

		$rows = $this->app->db()->query ('SELECT * FROM e10pro_hosting_server_usersds WHERE created IS NULL');
		foreach ($rows as $r)
		{
			$ds = $this->app->loadItem ($r['datasource'], 'e10pro.hosting.server.datasources');
			if ($ds)
			{
				if ($ds['created'])
					$this->app->db()->query ('UPDATE e10pro_hosting_server_usersds SET created = %t WHERE ndx = %i', $ds['created'], $r['ndx']);
				else
				if ($ds['dateStart'])
					$this->app->db()->query ('UPDATE e10pro_hosting_server_usersds SET created = %t WHERE ndx = %i', $ds['dateStart'], $r['ndx']);
				else
					$this->app->db()->query ('UPDATE e10pro_hosting_server_usersds SET created = NOW() WHERE ndx = %i', $r['ndx']);
			}
		}

		$this->checkSymlinks();
		$this->checkUdsOptions();
	}

	public function checkSymlinks ()
	{
		if (!is_link (__APP_DIR__.'/users.php'))
			symlink ('e10-modules/e10pro/hosting/server/users.php', 'users.php');
	}

	function checkUdsOptions()
	{
		$rows = $this->app->db()->query ('SELECT * FROM [e10pro_hosting_server_usersds] WHERE udsOptions = 0');

		$tableUsersDS = $this->app->table('e10pro.hosting.server.usersds');
		foreach ($rows as $r)
		{
			$tableUsersDS->createUdsOptions($r);
		}
	}

	public function onCronMorning ()
	{
		// -- get servers statistics
		$tableServerStats = $this->app->table('e10pro.hosting.server.serversStats');
		$tableServerStats->downloadStats();

		// -- create bigQuery log table
		$tomorrow = new \DateTime(date('Ymd', strtotime('+1 day')));
		$tableName = 'log'.$tomorrow->format ('Ymd');
		$cmd = "cd /var/www/e10-server/utils/logServer && node createTable.js ".$tableName;
		exec($cmd);
	}

	public function downloadDemoPortals ()
	{
		$url = 'https://shipard-demo.cz/info-portals';

		$opts = ['http'=> ['timeout' => 10, 'method'=>'GET', 'header'=> "Connection: close\r\n"]];
		$context = stream_context_create($opts);
		$resultString = file_get_contents ($url, FALSE, $context);
		if (!$resultString)
			return;
		$resultData = json_decode ($resultString, TRUE);
		if (!$resultData)
			return;

		file_put_contents(__APP_DIR__.'/tmp/demo-portals.json', $resultString);
	}

	public function onCronEver ()
	{
		$this->downloadDemoPortals();
	}

	public function domainsImportFromAccount ()
	{
		$accountNdx = intval($this->app->arg('account'));
		if (!$accountNdx)
		{
			return FALSE;
		}

		$engine = new \e10pro\hosting\server\DomainsApiEngine($this->app);
		$engine->setAccountNdx($accountNdx);

		if (!$engine->login())
		{
			echo "!!! Login failed...\n";
			return FALSE;
		}

		$engine->importDomains();

		return TRUE;
	}

	public function domains()
	{
		$engine = new \e10pro\hosting\server\DomainsApiEngine($this->app);
		$engine->run();

		return TRUE;
	}

	function masterCertsScan()
	{
		$e = new \lib\hosting\services\MasterCertificatesManager($this->app);
		$e->scan();

		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			//case 'domains-import-from-account': return $this->domainsImportFromAccount();
			case 'master-certs-scan': return $this->masterCertsScan();
			case 'domains': return $this->domains();
		}

		parent::onCliAction($actionId);
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'morning': $this->onCronMorning(); break;
			case 'ever': $this->onCronEver(); break;
		}
		return TRUE;
	}
}
