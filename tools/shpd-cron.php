#!/usr/bin/env php
<?php

define ("__APP_DIR__", getcwd());

$cfgServerString = file_get_contents ('config/_server_channelInfo.json');
if (!$cfgServerString)
{
	echo "ERROR: file `config/_server_channelInfo.json` not found.\n";
	exit(100);
}
$cfgServer = json_decode ($cfgServerString, true);
if (!$cfgServer)
{
	echo "ERROR: file `config/_server_channelInfo.json` is not valid.\n";
	exit(101);
}

define('__SHPD_ROOT_DIR__', $cfgServer['serverInfo']['channelPath']);

require_once __SHPD_ROOT_DIR__ . '/src/boot.php';


use \Shipard\CLI\Application, \Shipard\Utils\Utils;



class CronApp extends Application
{
	public function runModulesCron ($cronType)
	{
		foreach ($this->dataModel->model ['modules'] as $moduleId => $moduleName)
		{
			$fmp = __SHPD_MODULES_DIR__.str_replace('.', '/', $moduleId).'/';

			$msfn = $fmp.'services.php';
			if (!is_file ($msfn))
				continue;
			include_once ($msfn);
			$className = str_replace ('.', '\\', $moduleId . '.ModuleServices');
			if (!class_exists ($className))
				continue;

			$moduleService = new $className ($this);
			$moduleService->onCron ($cronType);
		}
	}

	public function checkRemoteAttachments ()
	{
		$failed = 0;
		$q = 'SELECT * FROM [e10_attachments_files] WHERE [attplace] = 2 AND [deleted] = 0';
		$rows = $this->db()->query ($q);
		forEach ($rows as $r)
		{
			$path = strftime ('%Y/%m/%d/');
			$destPath = __APP_DIR__ . '/att/' . $path;

			if (!is_dir($destPath))
			{
				mkdir ($destPath, 0775, true);
				chmod ($destPath, Utils::wwwGroup());
			}

			$path_parts = pathinfo ($r['path']);
			$baseFileName =  Utils::safeChars ($path_parts ['filename'].'.'.$path_parts ['extension']);

			$fullFileName = $destPath.$baseFileName;

			if (@copy ($r['path'], $fullFileName) === TRUE)
			{
				$updateAtt = ['path' => $path, 'filename' => $baseFileName,  'attplace' =>  /* TableAttachments::apLocal */ 0];
				$this->db()->query ('UPDATE [e10_attachments_files] SET ', $updateAtt, ' WHERE [ndx] = %i', $r['ndx']);
			}
			else
				$failed++;
		}

		if ($failed)
		{
			$limit = new \DateTime('1 day ago');
			$this->db()->query ('UPDATE [e10_attachments_files] SET [deleted] = 1',
				' WHERE [attPlace] = 2 AND [deleted] = 0 AND [created] < %d', $limit);
		}
	}

	public function cleanTempFiles ()
	{
		$oldDir = getcwd();
		$cmdCleanOldFiles = 'find . -mtime +1 -type f -delete';

		chdir (__APP_DIR__.'/tmp');
		passthru ($cmdCleanOldFiles);
		chdir (__APP_DIR__.'/tmp/api/access');
		passthru ($cmdCleanOldFiles);

		// -- imgcache
		if (is_dir(__APP_DIR__ . '/imgcache/att'))
		{
			$cmdCleanImgCache = 'find . -mtime +1095 -type f -delete'; // 3 * 365 - 3 years
			chdir(__APP_DIR__ . '/imgcache/att');
			passthru($cmdCleanImgCache);
		}
		if (is_dir(__APP_DIR__ . '/imgcache/pdf'))
		{
			$cmdCleanImgCache = 'find . -mtime +365 -type f -delete'; // 1 year
			chdir(__APP_DIR__ . '/imgcache/pdf');
			passthru($cmdCleanImgCache);
		}

		chdir ($oldDir);
	}

	public function clearUserSessions ()
	{
		$q = 'DELETE FROM [e10_persons_sessions] WHERE [created] IS NULL'; // TODO: delete in some next version
		$this->db()->query ($q);

		$time = time() - 10*24*60*60;
		$q = 'DELETE FROM [e10_persons_sessions] WHERE [created] < %t';
		$this->db()->query ($q, $time);
	}

	public function clearNotifications ()
	{
		$time = time() - 7*24*60*60;
		$q = 'DELETE FROM [e10_base_notifications] WHERE [created] < %t';
		$this->db()->query ($q, $time);
	}

	public function cronHourly ()
	{
	}

	public function cronEver ()
	{
		$this->checkRemoteAttachments ();
	}

	public function cronMorning ()
	{
		$this->cleanTempFiles ();
		$this->clearUserSessions();
		$this->clearNotifications();
	}

	public function dataSourceStatsCreate ()
	{
		$dsid = $this->cfgItem('dsid');

		$dsStats = new \lib\hosting\DataSourceStats($this);
		$dsStats->loadFromFile();
		$dsStats->data['dsid'] = $dsid;
		$dsStats->data['created'] = new \DateTime();

		$dsStats->data['diskUsage']['created'] = new \DateTime();

		// -- disk usage - files
		$diskUsageFiles = intval(exec ('du -skL')) * 1024;
		$dsStats->data['diskUsage']['fs'] = $diskUsageFiles;

		// -- disk usage - database
		$config = Utils::loadCfgFile('config/config.json');
		$q = 'SELECT table_schema, SUM(data_length + index_length) as dbsize FROM information_schema.TABLES' .
				 ' WHERE table_schema = %s GROUP BY table_schema';
		$dbinfo = $this->db()->query ($q, $config['db']['database'])->fetch();
		if ($dbinfo)
		{
			$dsStats->data['diskUsage']['db'] = $dbinfo['dbsize'];
		}

		$dsStats->saveToFile();
	}

	public function dataSourceStatsUpload ()
	{
		if (!$this->production())
			return;

		$dsid = intval ($this->cfgItem('dsid'));

		$dsStats = new \lib\hosting\DataSourceStats($this);
		$dsStats->loadFromFile();

		$data = json_encode ($dsStats->data);
		$now = new \DateTime();
		$fileName =  $now->format('Ymd-His').'-'.$dsid.'-'.mt_rand(100000,999999).md5($data).'.json';
		file_put_contents(__SHPD_VAR_DIR__."/upload/dsStats/".$fileName, $data);
	}

	public function run ()
	{
		if (Utils::appStatus() !== TRUE)
			return;

		$cronType = $this->command ();
		if ($cronType !== '')
		{
			switch ($this->command ())
			{
				case	'ever':			$this->cronEver (); break;
				case	'hourly':		$this->cronHourly (); break;
				case	'morning':	$this->cronMorning (); break;
				case	'stats':		$this->dataSourceStatsCreate (); break;
			}
			$this->runModulesCron ($cronType);

			if ($this->command () === 'stats')
				$this->dataSourceStatsUpload();
		}
	}
}

$myApp = new CronApp ($argv);
$myApp->run ();

