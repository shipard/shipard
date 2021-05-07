#!/usr/bin/env php
<?php

define ("__APP_DIR__", getcwd());
require_once 'e10-modules/e10/server/php/e10-cli.php';

use \E10\CLI\Application, \E10\utils;


class CronApp extends Application
{
	public function runModulesCron ($cronType)
	{
		foreach ($this->dataModel->model ['modules'] as $moduleId => $moduleName)
		{
			$fmp = __APP_DIR__.'/e10-modules/'.str_replace('.', '/', $moduleId).'/';

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

	public function checkApiAccess ()
	{
		$dsid = $this->cfgItem('dsid');
		$time = time() - 600;
		$dataForUpload = ['dsid' => $dsid, 'users' => []];

		forEach (glob (__APP_DIR__.'/tmp/api/access/*') as $f)
		{
			$atime = fileatime ($f);
			if ($atime < $time)
				continue;
			$bn = basename($f);
			$data = explode ('_', $bn);
			$userNdx = intval ($data[0]);

			$userRecData = $this->db()->query('SELECT * FROM e10_persons_persons WHERE ndx = %i', $userNdx)->fetch();
			if (!$userRecData)
			{
				error_log ("ERROR: bad user id '$userNdx'/'$dsid' in checkApiAccess: ".$f);
				continue;
			}

			$deviceId = $data[2];
			$timeStamp = new \DateTime(date('Y-m-d H:i:s', $atime));

			// -- search ip address
			$ipaddressndx = 0;
			$ip = $this->db()->query('SELECT * FROM e10_base_ipaddr WHERE docState = 4000 AND ipaddress = %s', $data[1])->fetch();
			if (isset ($ip['ndx']))
				$ipaddressndx = $ip['ndx'];

			// -- search device
			$device = $this->db()->query('SELECT * FROM e10_base_devices WHERE id = %s', $deviceId)->fetch();
			if (!$device)
			{
				$deviceRec = [
						'id' => $deviceId, 'currentUser' => $userNdx, 'lastSeenOnline' => $timeStamp,
						'ipaddress' => $data[1], 'ipaddressndx' => $ipaddressndx,
						'docState' => 4000, 'docStateMain' => 2];
				$this->db()->query('INSERT INTO e10_base_devices', $deviceRec);
			}
			else
			{
				$deviceRec = ['currentUser' => $userNdx, 'lastSeenOnline' => $timeStamp, 'ipaddress' => $data[1], 'ipaddressndx' => $ipaddressndx];
				$this->db()->query('UPDATE e10_base_devices SET ', $deviceRec, ' WHERE ndx = %i', $device['ndx']);
			}

			$logRec = [
					'eventType' => 2, 'user' => $userNdx, 'ipaddress' => $data[1], 'deviceid' => $deviceId,
					'created' => $timeStamp, 'ipaddressndx' => $ipaddressndx, 'last' => 1
			];

			$this->db()->begin();
			$this->db()->query('UPDATE e10_base_docslog SET [last] = 0 WHERE [last] = 1 AND eventType = 2 AND user = %i', intval ($data[0]));
			$this->db()->query('INSERT INTO e10_base_docslog', $logRec);
			$this->db()->commit();

			$uploadUser = ['loginHash' => $userRecData['loginHash'], 'time' => date('Y-m-d H:i:s', $atime), 'ipaddress' => $data[1],
										 'lat' => (isset($ip['lat'])) ? $ip['lat'] : 0, 'lon' => (isset($ip['lon'])) ? $ip['lon'] : 0];

			$dataForUpload['users'][] = $uploadUser;
		}

		$hostingCfg = utils::hostingCfg();
		if (count($dataForUpload['users']))
		{
			$data = json_encode ($dataForUpload);
			$now = new \DateTime();
			$fileName =  $now->format('Ymd-His').'-'.$dataForUpload['dsid'].'-'.mt_rand(100000,999999) .'_default_'.md5($data).'.json';
			file_put_contents("/var/lib/e10/upload/apiaccess-{$hostingCfg['hostingGid']}/".$fileName, $data);
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
				chown ($destPath, utils::wwwUser());
			}

			$path_parts = pathinfo ($r['path']);
			$baseFileName =  utils::safeChars ($path_parts ['filename'].'.'.$path_parts ['extension']);

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
		$this->checkApiAccess ();
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
		$dsid = intval ($this->cfgItem('dsid'));

		$dsStats = new \lib\hosting\DataSourceStats($this);
		$dsStats->loadFromFile();
		$dsStats->data['dsid'] = $dsid;
		$dsStats->data['created'] = new \DateTime();

		$dsStats->data['diskUsage']['created'] = new \DateTime();

		// -- disk usage - files
		$diskUsageFiles = intval(exec ('du -skL --exclude "e10-modules/*" --exclude "sc/*"')) * 1024;
		$dsStats->data['diskUsage']['fs'] = $diskUsageFiles;

		// -- disk usage - database
		$config = utils::loadCfgFile('config/config.json');
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
		$hostingCfg = utils::hostingCfg(['hostingGid', 'serverGid']);
		if ($hostingCfg === FALSE)
			return FALSE;

		$dsid = intval ($this->cfgItem('dsid'));

		$dsStats = new \lib\hosting\DataSourceStats($this);
		$dsStats->loadFromFile();

		$data = json_encode ($dsStats->data);
		$now = new \DateTime();
		$fileName =  $now->format('Ymd-His').'-'.$dsid.'-'.mt_rand(100000,999999).md5($data).'.json';
		file_put_contents("/var/lib/e10/upload/dsStats-{$hostingCfg['hostingGid']}/".$fileName, $data);
	}

	public function run ()
	{
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

