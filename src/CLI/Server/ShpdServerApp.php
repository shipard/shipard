<?php

namespace Shipard\CLI\Server;
use \Shipard\Utils\Utils;
use \Shipard\CLI\Server\ServerManager;
use \Shipard\CLI\Server\DSManager;



function parseArgs($argv)
{
	// http://pwfisher.com/nucleus/index.php?itemid=45
		array_shift ($argv);
		$out = array();
		foreach ($argv as $arg){
				if (substr($arg,0,2) == '--'){
						$eqPos = strpos($arg,'=');
						if ($eqPos === false){
								$key = substr($arg,2);
								$out[$key] = isset($out[$key]) ? $out[$key] : true;
						} else {
								$key = substr($arg,2,$eqPos-2);
								$out[$key] = substr($arg,$eqPos+1);
						}
				} else if (substr($arg,0,1) == '-'){
						if (substr($arg,2,1) == '='){
								$key = substr($arg,1,1);
								$out[$key] = substr($arg,3);
						} else {
								$chars = str_split(substr($arg,1));
								foreach ($chars as $char){
										$key = $char;
										$out[$key] = isset($out[$key]) ? $out[$key] : true;
								}
						}
				} else {
						$out[] = $arg;
				}
		}
		return $out;
}


class ShpdServerApp extends \Shipard\Application\ApplicationCore
{
	var $arguments;
	var $manager;
	var $modulesPath;
	var $quiet = FALSE;

	var $shpdServerCmd = 'shpd-server';
	var $shpdAppCmd = 'shpd-app';

	public function __construct (?array $cfgServer = NULL)
	{
		parent::__construct($cfgServer);
		$this->manager = new \Shipard\Base\CfgManager();
	}

	public function arg ($name, $defaultValue = FALSE)
	{
		if (isset ($this->arguments [$name]))
			return $this->arguments [$name];

		return $defaultValue;
	}

	public function appBackup ()
	{
		$backupDir = __APP_DIR__ . '/tmp/bkp' . time ();
		mkdir ($backupDir);

		$dsid = $this->manager->cfgItem ('dsid');

		$hostName = utils::cfgItem($this->cfgServer, 'serverDomain', gethostname());
		$backupName = $dsid.'-'.date ("Y-m-d");
		// --database
		$cmd = "mysqldump --default-character-set=utf8mb4 -u {$this->manager->cfgItem ('db.login')} -p{$this->manager->cfgItem ('db.password')} {$this->manager->cfgItem ('db.database')} -r $backupDir/database.sql";
		exec ($cmd);

		// --config
		mkdir ($backupDir.'/config');
		Utils::copy_r (__APP_DIR__ . '/config', $backupDir.'/config');

		// --templates
		mkdir ($backupDir.'/templates');
		Utils::copy_r (__APP_DIR__ . '/templates', $backupDir.'/templates');

		// --themes
		mkdir ($backupDir.'/themes');
		Utils::copy_r (__APP_DIR__ . '/themes', $backupDir.'/themes');

		// -- attachments?
		$syncAttachments = FALSE;
		if (!is_file('att/.disable-backup'))
			symlink ('../../att', $backupDir.'/att');
		else
			$syncAttachments = TRUE;

		// -- index.php
		copy (__APP_DIR__ . '/index.php', $backupDir.'/index.php');

		// --tar
		$backupFileName = __APP_DIR__ . '/tmp/bkp-' . $backupName . ".tgz";
		$cmd = "tar -h -czf $backupFileName -C $backupDir .";
		exec ($cmd);

		// -- move backup file?
		$moveBackupTo = $this->arg ("move-to");
		if ($moveBackupTo != '')
		{
			$destBkpFolder = $moveBackupTo.'/'.date ("Y-m-d").'/';
			if (!is_dir($destBkpFolder))
				mkdir ($destBkpFolder, 0770, true);

			$finalBkpFileName = $destBkpFolder . 'bkp-' . $backupName . '.tgz';
			rename ($backupFileName, $finalBkpFileName);

			// backup/ds info
			$dsBackupInfo = [];
			$dsBackupInfoFileName = $destBkpFolder . 'backupInfo.json';
			if (is_file($dsBackupInfoFileName))
			{
				$bistr = file_get_contents($dsBackupInfoFileName);
				$dsBackupInfo = json_decode ($bistr, TRUE);
			}

			$backupFileSize = filesize($finalBkpFileName);
			$backupCheckSum = hash_file('SHA256', $finalBkpFileName);
			$dsbi = [
				'dsid' => $dsid,
				'serverPath' => __APP_DIR__,
				'bkpFileName' => $finalBkpFileName,
				'bkpFileSize' => $backupFileSize,
				'bkpSHA256' => $backupCheckSum,
				'syncAttachments' => $syncAttachments, 
			];
			$dsBackupInfo ['dataSources'][] = $dsbi;
			file_put_contents ($dsBackupInfoFileName, json_encode($dsBackupInfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

			if ($backupFileSize > 1000 * 1000 * 100) // 100MB
				touch ('att/.disable-backup');
		}

		// -- remove tmp dir
		exec ("rm -rf ".$backupDir);
	}

	public function appCreate ()
	{
		$appName = $this->arg ('name');
		if (!$appName)
			return $this->err ('application name not specified.');

		$moduleName = $this->arg ('module');
		if (!$moduleName)
			return $this->err ('installation module not specified.');

		$installModulePath = $this->modulesPath . '/' . $moduleName;
		$cfgString = file_get_contents ($installModulePath . '/' . 'module.json');
		if (!$cfgString)
			return $this->err ("no module found in $installModulePath");

		$installModule = json_decode ($cfgString, true);

		$newDbUser = base_convert($appName, 10, 36) . $this->generatePassword(3);
		$newDbPassword = $this->generatePassword();

		$configTxt = "{
	\"db\": {
		\"database\": \"$appName\",
		\"login\": \"$newDbUser\",
		\"password\": \"$newDbPassword\"
	},
	\"dsid\": \"$appName\"
}
";
		$appDir = __APP_DIR__ . '/' . $appName;
		mkdir ($appDir);
		$this->checkDirectory ($appDir, 'config');
		$this->checkDirectory ($appDir, 'tmp');

		file_put_contents ($appDir . '/config/config.json', $configTxt);

		// -- init modules list
		file_put_contents ($appDir . '/config/modules.json', "[\"$moduleName\"]");

		// -- createApp info
		if ($this->cfgServer['develMode'] || !$this->cfgServer['useHosting'])
		{
			$cfgServer = $this->cfgServer;
			// -- createApp.json
			$createApp = [];
			$createApp ['createRequest'] = [
				'name' => $appName,
				'installModule' => $moduleName,
				'companyName' => 'První testovací s.r.o.',
				'street' => 'Pokusná 7',
				'city' => 'Praha',
				'zipcode' => '11000',
				'country' => 'cz',
				'companyId' => '012345678X',
				'vatId' => 'CZ012345678X',
			];
			$createApp ['admin'] = [
				'firstName' => $cfgServer['userFirstName'],
				'lastName' => $cfgServer['userLastName'],
				'login' => $cfgServer['userEmail'],
			];
			
			file_put_contents ($appDir . '/config/createApp.json', json_encode ($createApp, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

			// -- dataSourceInfo.json
			$dsInfo = [
							'dsid' => $appName,
							'name' => 'Test '.$appName,
							'hosting' => 1,
							'supportName'=> 'forum.shipard.org',
							'supportUrl'=> 'https://forum.shipard.org',
							'supportPhone'=> '',
							'supportEmail'=> 'admin@shipard.org',
							'dsimage' => 'https://shipard.app/imgs/-w256/att/2019/05/07/e10pro.wkf.documents/shipard-logo-2-134r9o1.png'
			];
			file_put_contents ($appDir . '/config/dataSourceInfo.json', json_encode ($dsInfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
		}
	}

	public function appNew ()
	{
		if (!$this->checkServerConfig())
			return FALSE;

		$appType = $this->arg ('type');
		if (!$appType)
			return $this->err ('missing argument --type=core|economy|mac');

		// -- install/apps/shipard-core
		$dsid = '03'.mt_rand(10000000, 88888888).'777';
		
		if (strstr($appType, '/') == FALSE)
			$module = 'install/apps/shipard-'.$appType;
		else
			$module = $appType;	

		if (!is_dir(__SHPD_MODULES_DIR__.$module))
			return $this->err ('ERROR: invalid argument --type; module `'.$module.'` not found. use core, economy or mac');

		$cmd = $this->shpdServerCmd." app-create --name=$dsid --module=$module";
		passthru($cmd);

		$cmd = "cd $dsid && ".$this->shpdServerCmd." app-init && ".$this->shpdServerCmd." app-fullupgrade";
		passthru($cmd);

		if (!$this->cfgServer['useHosting'])
		{
			$cmd = "cd $dsid && ".$this->shpdAppCmd." localAccount --email=".$this->cfgServer['userEmail'];
			passthru($cmd);
		}

		$url = 'https://'.$this->cfgServer['serverDomain'].'/'.$dsid.'/';
		echo "DONE. New dsid: $dsid, login: ".$this->cfgServer['userEmail'].', URL: '.$url."\n";

		return TRUE;
	}

	public function appConfig ()
	{
		$this->manager->appCompileConfig ();
	}

	public function appCron ()
	{
		$cronType = $this->arg ('type');
		if (!$cronType)
			return $this->err ("missing argument type");

		passthru(__SHPD_ROOT_DIR__."/tools/shpd-cron.php ".$cronType);
	}

	public function appDSCmd ()
	{
		$fileName = $this->arg ('file');
		if (!$fileName)
			return $this->err ("missing argument file");
		if (!is_file($fileName))
			return $this->err ("file '$fileName' not exist");

		$cmdCfg = utils::loadCfgFile($fileName);
		if ($cmdCfg === FALSE)
			return $this->err ("file '$fileName' is invalid");

		$dsroot = $this->cfgServer['dsRoot'];
		$dsroot .= $cmdCfg['dsid'];
		if (!is_dir($dsroot))
			return $this->err ("invalid dsid in file '$fileName'; directory '$dsroot' not found");

		chdir ($dsroot);

		$cmd = '';
		$logFileName = 'tmp/x-' . time() . '-' . mt_rand (1000000, 999999999) . '.log';
		switch ($cmdCfg['cmd'])
		{
			case 'resetDataSource':
				$cmd = $this->shpdServerCmd." app-reset";
				break;
			case 'installDemoData':
				$cmd = $this->shpdAppCmd." appDemo --type=".$cmdCfg['params']['type'];
				break;
			case 'appTask':
				$cmd = $this->shpdAppCmd." appTask --taskNdx=".$cmdCfg['params']['taskNdx'];
				break;
		}

		if ($cmd === '')
			return $this->err ("invalid command in file '$fileName'");

		unlink ($fileName);

		//$command = 'nohup nice -n 10 '.$cmd.' > '.$logFileName.' & printf "%u" $!';
		$command = 'nohup '.$cmd.' > '.$logFileName.' & printf "%u" $!';
		echo ("appDSCmd COMMAND: ".$command."\n");
		$pid = shell_exec($command);
	}

	function appDSCmdAll ()
	{
		forEach (glob (__SHPD_VAR_DIR__.'dscmd/*.json') as $cmdfn)
		{
			$cmd = $this->shpdServerCmd.' app-dscmd --file='.$cmdfn;
			echo $cmd."\n";
			passthru ($cmd);
		}
	}

	public function appGetDSInfo ()
	{
		$dsid = $this->manager->cfgItem ('dsid');

		$opts = array(
			'http'=>array(
				'timeout' => 30,
				'method'=>"GET",
				'header'=>
					"e10-api-key: " . $this->cfgServer['hostingApiKey'] . "\r\n".
					"e10-device-id: " . $this->machineDeviceId (). "\r\n".
					"Connection: close\r\n"
			)
		);
		$context = stream_context_create($opts);

		$url =  'https://'.$this->cfgServer['hostingDomain'].'/api/call/e10pro.hosting.server.getDataSourceInfo?serverId='.$this->cfgServer['serverId'].'&dsid='.$dsid;
		$resultCode = file_get_contents ($url, FALSE, $context);
		$resultData = json_decode ($resultCode, TRUE);

		if ($resultData === FALSE || !isset($resultData['data']) || $resultData['data']['count'] === 0)
			return $this->err ('invalid server response');

		$dsinfo = $resultData['data']['datasources'][0];
		if ($dsinfo['dsid'] == $dsid)
		{
			$this->saveDSCerts($dsinfo);

			file_put_contents('config/dataSourceInfo.json', json_encode($dsinfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

			$this->createDSNginxConfigs();
		}
		else
			return $this->err ('invalid dsid in response');

		return TRUE;
	}

	function appHttpdDS()
	{
		$dsInfo = utils::loadCfgFile('config/dataSourceInfo.json');
		if (!$dsInfo)
			return FALSE;
		$dsid = $dsInfo['dsid'];

		$run = $fileName = $this->arg ('run');

		$coreCfgFileName = $dsid.'-app.conf';

		$cfgFileNameHttpd = '/etc/nginx/sites-enabled/'.$coreCfgFileName;
		$currentConfigHttpd = '';
		if (is_readable($cfgFileNameHttpd))
			$currentConfigHttpd = file_get_contents($cfgFileNameHttpd);
		$currentConfigHttpdCheckSum = sha1($currentConfigHttpd);

		$cfgFileNameDS = __APP_DIR__.'/config/nginx/'.$coreCfgFileName;
		$currentConfigDS = '';
		if (is_readable($cfgFileNameDS))
			$currentConfigDS = file_get_contents($cfgFileNameDS);
		$currentConfigDSCheckSum = sha1($currentConfigDS);

		$doIt = 0;

		if ($currentConfigHttpd === '' && $currentConfigDS !== '')
		{
			if (!$run)
				echo "NEW datasource cfg `$cfgFileNameDS`\n";
			$doIt = 1;
		}
		else
		if ($currentConfigHttpd !== '' && $currentConfigDS === '')
		{
			if (!$run)
				echo "REMOVE datasource cfg `$cfgFileNameHttpd`\n";
			$doIt = 1;
		}
		else
		if ($currentConfigHttpd !== '' && $currentConfigDS !== '' && $currentConfigHttpdCheckSum !== $currentConfigDSCheckSum)
		{
			if (!$run)
				echo "CHANGED datasource cfg `$cfgFileNameHttpd`\n";
			$doIt = 1;
		}

		if ($doIt && !$run)
		{
			echo "use --run param to apply changes...\n";
			return TRUE;
		}

		if ($doIt)
		{
			if ($currentConfigDS !== '')
			{ // add
				copy($cfgFileNameDS, $cfgFileNameHttpd);
			}
			else
			{ // remove
				unlink($cfgFileNameHttpd);
			}
		}

		return TRUE;
	}

	function createDSNginxConfigs()
	{
		$baseDomain = 'shipard.app';
		$certId = 'all.shipard.app';

		$dsInfo = utils::loadCfgFile('config/dataSourceInfo.json');
		if (!$dsInfo)
			return FALSE;
		$dsid = $dsInfo['dsid'];

		array_map ('unlink', glob (__APP_DIR__.'/config/nginx/'.$dsid.'-app*'));

		if (!isset($dsInfo['dsId1']) || $dsInfo['dsId1'] === '')
			return FALSE;

		$domains = $dsInfo['dsId1'].'.'.$baseDomain;
		if (isset($dsInfo['dsId2']) && $dsInfo['dsId2'] !== '')
			$domains .= ' '.$dsInfo['dsId1'].'.'.$baseDomain;

		$cfg = '';

		// -- web via https
		$cfg .= "# ".$dsInfo['name']."; app cfg ver 0.5\n";

		$cfg .= "server {\n";
		$cfg .= "\tlisten 443 ssl http2;\n";
		$cfg .= "\tserver_name $domains;\n";
		$cfg .= "\troot ".$this->cfgServer['dsRoot']."$dsid;\n";
		$cfg .= "\tindex index.php;\n";

		$cfg .= "\tssl_certificate /var/lib/shipard/certs/$certId/chain.pem;\n";
		$cfg .= "\tssl_certificate_key /var/lib/shipard/certs/$certId/privkey.pem;\n";
		$cfg .= "\tssl_trusted_certificate /var/lib/shipard/certs/$certId/chain.pem;\n";
		
		if (is_readable('/etc/ssl/dhparam.pem'))
			$cfg .= "\tssl_dhparam /etc/ssl/dhparam.pem;\n";

		$cfg .= "\tinclude ".__SHPD_ROOT_DIR__."etc/nginx/shpd-one-app.conf;\n";
		$cfg .= "\tinclude ".__SHPD_ROOT_DIR__."etc/nginx/shpd-https.conf;\n";
		$cfg .= "}\n\n";

		// -- save
		$configFileName = __APP_DIR__.'/config/nginx/'.$dsid.'-app.conf';
		file_put_contents($configFileName, $cfg);

		return TRUE;
	}

	function saveDSCerts(&$dsInfo)
	{
		if (!isset($dsInfo['certsCheckSum']))
			return;

		$dstPath = __APP_DIR__.'/config/nginx';

		if (!is_dir($dstPath))
			Utils::mkDir ($dstPath, 0750);
		$dstPath .= '/certs';			
		if (!is_dir($dstPath))
			Utils::mkDir ($dstPath, 0750);

		foreach ($dsInfo['certs'] as $certId => $certContent)
		{
			$certPath = $dstPath.'/'.$certId;
			if (!is_dir($certPath))
				Utils::mkDir ($certPath, 0750);

			$certInfo = [
				'filesCheckSum' => $certContent['filesCheckSum'],
				'info' => openssl_x509_parse($certContent['files']['cert.pem'], 0)
			];
			file_put_contents($certPath.'/info.json', json_encode($certInfo));

			foreach ($certContent['files'] as $fileName => $certFileContent)
			{
				file_put_contents($certPath.'/'.$fileName, $certFileContent);
				chmod($certPath.'/'.$fileName, 0600);
			}
		}

		unset ($dsInfo['certsCheckSum']);
		unset ($dsInfo['certs']);
	}

	function getHostingInfo ()
	{
		$hm = new \Shipard\CLI\Server\HostingManager($this);
		return $hm->getHostingInfo();
	}

	public function appInit ()
	{
		$this->appUpgrade_ChannelInfo();

		$this->dbCreate ();

		$this->checkFilesystem (__APP_DIR__);
		$this->manager->appCompileConfig ();
		$this->manager->dbCheck ();

		$this->runModuleServices ('onCreateDataSource');
		$this->manager->appCompileConfig ();

		$this->checkFilesystem (__APP_DIR__);
	}

	public function appModules ()
	{
		foreach ($this->manager->modules as $m)
		{
			echo "* ".$m['id'].' / '.$m['name'];
			echo "\n";
		}
	}

	public function appPublish ()
	{
		$this->runModuleServices ('onAppPublish');
		$this->appGetDSInfo();
	}

	public function appReset ()
	{
		$this->dbCreate (TRUE);

		$channelInfo = utils::loadCfgFile(__APP_DIR__.'/config/_server_channelInfo.json');

		exec ('rm -rf '.__APP_DIR__.'/att/');
		array_map ("unlink", glob (__APP_DIR__.'/config/_*'));

		if (is_file('.demo'))
			unlink('.demo');
		if (is_file('config/modules-demo.json'))
			unlink('config/modules-demo.json');

		if ($channelInfo)
		{
			file_put_contents(__APP_DIR__.'/config/_server_channelInfo.json', json_encode($channelInfo));
		}

		$this->checkFilesystem (__APP_DIR__);
		$this->manager->appCompileConfig ();
		$this->manager->dbCheck ();

		$this->runModuleServices ('onCreateDataSource');
		$this->manager->appCompileConfig ();

		$this->checkFilesystem (__APP_DIR__);

		passthru($this->shpdAppCmd.' moduleService --service=checkSystemData');
		passthru($this->shpdAppCmd.' cfgUpdate');
		passthru($this->shpdServerCmd.' app-upgrade');
		passthru($this->shpdServerCmd.' app-fullupgrade');

		if ($this->arg ('demo'))
		{
			$this->appStatus ('DEMO');
			if ($this->arg('fast'))
				passthru($this->shpdAppCmd." appDemo --fast");
			else
				passthru($this->shpdAppCmd." appDemo");
		}
		$this->appStatus ('');
	}

	public function appStatus ($status)
	{
		Utils::setAppStatus ($status);
	}

	public function appRights ()
	{
		passthru ('chgrp -R '.utils::wwwGroup().' att');
	}

	public function appTest ()
	{
		passthru('e10-test all');
	}

	public function appUpgrade ()
	{
		if (is_file('.disable-upgrade'))
			return;
		$this->checkFilesystem (__APP_DIR__ . '/');

		$this->appUpgrade_ServerInfo ();
		$this->appUpgrade_ChannelInfo ();
		$this->appUpgrade_DetectVersion();

		if ($this->manager->appCompileConfig ())
		{
			$this->manager->dbCheck ();
		}

		$this->runModuleServices ('onAppUpgrade');

		file_put_contents (__APP_DIR__ . "/config/E10_VERSION_ID", strval (__E10_VERSION_ID__));

		$this->createDSNginxConfigs();

		$this->createDSIcons();
	}

	public function appUpgradeFull ()
	{
		if (is_file('.disable-upgrade'))
			return;
		$this->appUpgrade ();
		passthru($this->shpdAppCmd." moduleService --service=checkSystemData");
		passthru($this->shpdAppCmd." cfgUpdate");
		passthru($this->shpdServerCmd." app-upgrade");
	}

	public function appUpgrade_ServerInfo ()
	{
		$serverInfo = [];

		$serverInfo['httpServer'] = 1; // nginx
		$cfg = ['serverInfo' => $serverInfo];
		file_put_contents(__APP_DIR__ . '/config/_serverInfo.json', json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
	}

	public function appUpgrade_ChannelInfo ($appDir = '')
	{
		if ($appDir === '')
			$appDir = __APP_DIR__;

		$channelConfigFileName = $appDir . '/config/_server_channelInfo.json';

		$doIt = FALSE;

		if (!is_file($channelConfigFileName))
			$doIt = TRUE;

		$e10channel = $this->arg ('set-channel');
		if ($e10channel)
		{
			$doIt = TRUE;
		}

		if (!$doIt)
			return;

		if (!$e10channel)
		{
			$e10channel = isset($this->cfgServer['defaultChannel']) ? $this->cfgServer['defaultChannel'] : NULL;			
			if (!$e10channel)
			{
				echo(json_encode($this->cfgServer))."\n----\n";
				return $this->err("ERROR: Invalid default channel...");
			}
		}
		
		$this->msg("set shpdChannel to $e10channel");

		$channelCfg = isset($this->cfgServer['channels'][$e10channel]) ? $this->cfgServer['channels'][$e10channel] : NULL;
		if (!$channelCfg)
		{
			return $this->err("ERROR: Invalid channel...");
		}

		$cfg = [
			'serverInfo' => ['channelId' => $e10channel, 'channelPath' => $channelCfg['path']],
		];
		
		file_put_contents($channelConfigFileName, json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

		$this->appUpgrade_DetectVersion();

		return TRUE;
	}

	public function appUpgrade_DetectVersion ()
	{
		// -- detect commit
		if (is_dir(__SHPD_ROOT_DIR__.'/.git'))
			system("cd ".__SHPD_ROOT_DIR__." && git log --pretty=format:'%h' -n 1 > ".__APP_DIR__.'/tmp/___commit.txt');
		else
			file_put_contents(__APP_DIR__. '/tmp/___commit.txt', '');

		$commitConfig = ['serverInfo' => ['e10version' => __E10_VERSION__, 'e10commit' => file_get_contents('tmp/___commit.txt')]];
		file_put_contents(__APP_DIR__ . '/config/_e10_commitInfo.json', json_encode($commitConfig));
	}

	public function createDSIcons()
	{
		$im = new \Shipard\CLI\Server\IconsManager($this);
		$im->createModulesIcons();
		return TRUE;
	}

	public function appWalk ()
	{
		$dsroot = $this->cfgServer['dsRoot'];
		chdir($dsroot);

		$paramsArray = $_SERVER ['argv'];
		$appCmd = $paramsArray [0];
		array_shift($paramsArray);
		array_shift($paramsArray);
		$cmdArgs = implode (' ', $paramsArray);

		$withFile = $this->arg ('with-file', FALSE);

		forEach (glob ('*', GLOB_ONLYDIR) as $appDir)
		{
			if (is_link ($appDir))
				continue;
			if (is_file($appDir.'/.disable-upgrade'))
				continue;
			if ($withFile !== FALSE && !is_file ($appDir.'/'.$withFile))
				continue;
			if (is_file ($appDir.'/config/config.json'))
			{
				$this->msg ("---- $appDir");
				chdir ($appDir);

				$cmdBase = $appCmd;
				$cmd = $cmdBase.' '.$cmdArgs;
				passthru ($cmd);
				chdir ('..');
			}
		}
	}

	public function dbCreate ($deleteBeforeCreate = FALSE)
	{
		$dbUser = $this->arg ('user', 'root');
		$dbPassword = $this->arg ('password', '');
		$replace = $this->arg ('replace', FALSE);

		if ($dbPassword === '' && isset ($this->cfgServer['dbPassword']))
			$dbPassword = $this->cfgServer['dbPassword'];

		$sqlCmd = "\"";
		if ($deleteBeforeCreate || $replace)
			$sqlCmd .= "DROP DATABASE IF EXISTS \`{$this->manager->cfgItem ('db.database')}\`; ";
		$sqlCmd .= "CREATE DATABASE \`{$this->manager->cfgItem ('db.database')}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; " .
							 "GRANT ALL ON \`{$this->manager->cfgItem ('db.database')}\`.* TO '{$this->manager->cfgItem ('db.login')}'@'localhost' IDENTIFIED BY '{$this->manager->cfgItem ('db.password')}';";
		$sqlCmd .= "\"";

		$cmd = "mysql -u $dbUser -p\"$dbPassword\" -e " . $sqlCmd;

		$result = exec ($cmd);
		//echo "r: '$result'\r\n";
	}

	public function dbConnect ()
	{
		$db = NULL;
		
		$dbPassword = $this->cfgServer['dbPassword'];
		$dbUser = $this->cfgServer['dbUser'];


		$dboptions = [
				'driver'   => $this->manager->cfgItem ('db.driver', 'mysqli'),
				'host'     => $this->manager->cfgItem ('db.host', 'localhost'),
				'username' => $dbUser,
				'password' => $dbPassword,
				'database' => $this->manager->cfgItem ('db.database'),
				'charset'  => $this->manager->cfgItem ('db.charset', 'utf8mb4'),
				'resultDetectTypes' => TRUE
		];

		try
		{
			$db = new \Dibi\Connection ($dboptions);
		}
		catch (\Dibi\Exception $e)
		{
			$this->err (get_class ($e) . ': ' . $e->getMessage());
			return NULL;
		}

		return $db;
	}

	public function dbOptimize ()
	{
		$db = $this->dbConnect();
		if (!$db)
			return;

		$run = $this->arg ('run', FALSE);

		$database = new \lib\database\DbMysql();
		$database->setDb($db, $this->manager->cfgItem ('db.database'));
		$database->loadStructure();
		$database->setModel($this->manager->tables);
		$database->optimizeModel($run);
	}

	public function dbRepair ()
	{
		$db = $this->dbConnect();
		if (!$db)
			return;

		$run = $this->arg ('run', FALSE);

		$database = new \lib\database\DbMysql();
		$database->setDb($db, $this->manager->cfgItem ('db.database'));
		$database->loadStructure();
		$database->setModel($this->manager->tables);
		$database->repairModel($run);
	}

	public function dbBackup ()
	{
		$dsid = $this->manager->cfgItem ('dsid');

		$dstPath = $this->arg ('path', 'tmp/');		
		$dstFileName = $this->arg ('filename', $dsid.'-'.date ("Y-m-d").'.sql');

		$backupDbFileName = $dstPath.$dstFileName;
		$cmd = "mysqldump --default-character-set=utf8mb4 -u {$this->manager->cfgItem ('db.login')} -p{$this->manager->cfgItem ('db.password')} {$this->manager->cfgItem ('db.database')} -r $backupDbFileName";
		exec ($cmd);
	}

	public function dbRestore ()
	{
		$fileName = $this->arg ('file');
		if (!$fileName)
			return $this->err ("missing argument file");
		if (!is_file($fileName))
			return $this->err ("file '$fileName' not exist");

		$cmd = "mysql --default-character-set=utf8mb4 -u {$this->manager->cfgItem ('db.login')} -p{$this->manager->cfgItem ('db.password')} {$this->manager->cfgItem ('db.database')} -e \"source $fileName\"";

		passthru ($cmd);
	}

	public function checkDirectory ($appDir, $d)
	{
		$dirName = "$appDir/$d/";
		if (is_dir($dirName) == FALSE)
		{
			Utils::mkDir ($dirName);
		}
	}

	public function checkFile ($fullFileName)
	{
		if (!is_file ($fullFileName))
			file_put_contents ($fullFileName, "");
		Utils::checkFilePermissions ($fullFileName);
	}

	public function checkFilesystem ($appDir)
	{
		$this->checkDirectory ($appDir, 'config');
		$this->checkDirectory ($appDir, 'config/nginx');
		$this->checkDirectory ($appDir, 'tmp');
		$this->checkDirectory ($appDir, 'tmp/api');
		$this->checkDirectory ($appDir, 'tmp/api/access');
		$this->checkDirectory ($appDir, 'includes');
		$this->checkDirectory ($appDir, 'includes/documentation');
		$this->checkDirectory ($appDir, 'res');
		$this->checkDirectory ($appDir, 'att');
		$this->checkDirectory ($appDir, 'imgcache');
		$this->checkDirectory ($appDir, 'templates');
		$this->checkDirectory ($appDir, 'themes');
		$this->checkFile ($appDir.'/config/modules.json');

		forEach (glob ($appDir.'/config/appOptions.*') as $ffn)
			$this->checkFile ($ffn);
		forEach (glob ($appDir.'/config/_*.json') as $ffn)
			$this->checkFile ($ffn);

		$this->checkWebRoot($appDir);

		// -- check device id
		$this->machineDeviceId ();
	}

	public function dsCopyFrom ($moveMode = FALSE)
	{
		$params = [];

		$dsId = $this->arg ('dsId');
		if (!$dsId)
		{
			return $this->err ('param `--dsId` not found');
		}
		$params['dsId'] = $dsId;

		$server = $this->arg ('server');
		if (!$server)
		{
			return $this->err ('param `--server` not found');
		}
		$params['server'] = $server;

		$user = $this->arg ('user');
		if (!$user)
			$user = '$USER';
		$params['user'] = $user;

		$disableAtt = $this->arg ('disable-att');
		if ($disableAtt)
			$params['disableAtt'] = 1;

		$dsm = new DSManager($this);
		$dsm->init();
		
		if ($moveMode)
			return $dsm->moveFrom($params);
		else
			return $dsm->copyFrom($params);
	}

	public function dsFixPerms ()
	{
		$dsm = new DSManager($this);
		$dsm->init();
		
		return $dsm->fixPerms();
	}

	public function dsLs ()
	{
		$dsList = [];
		forEach (glob ('*', GLOB_ONLYDIR) as $appDir)
		{
			if (is_link ($appDir))
				continue;
			if (!is_file ($appDir.'/config/config.json'))
				continue;

			$cfg = utils::loadCfgFile($appDir.'/config/config.json');
			$dsInfo = utils::loadCfgFile($appDir.'/config/dataSourceInfo.json');
			$channelInfo = utils::loadCfgFile($appDir.'/config/_e10_channelInfo.json');
			$statusData = FALSE;
			if (is_file ($appDir.'/config/status.data'))
				$statusData = file_get_contents($appDir.'/config/status.data');

			$ds = ['dsid' => $cfg['dsid']];

			if ($dsInfo)
			{
				$ds['name'] = $dsInfo['name'];
				switch ($dsInfo['condition'])
				{
					case 0 : $ds['condition'] = 'trial '.utils::dateage2(new \DateTime($dsInfo['created'])); break;
					case 1 : $ds['condition'] = ''; break;
					case 2 : $ds['condition'] = 'demo'; break;
					case 3 : $ds['condition'] = 'expired'; break;
					case 4 : $ds['condition'] = 'internal'; break;
					default: $ds['condition'] = '???';
				}
			}
			else
			{
				$ds['name'] = 'INFO MISSING!';
				$ds['condition'] = '';
			}

			if ($statusData)
				$ds['status'] = $statusData;
			else
				$ds['status'] = '';

			if ($channelInfo)
				$ds ['channelName'] = $channelInfo['serverInfo']['e10channelName'];
			else
				$ds ['channelName'] = '???';

			if ($dsInfo && isset($dsInfo['supportName']))
				$ds ['siteName'] = $dsInfo['supportName'];
			else
				$ds ['siteName'] = '???';

			$dsList[] = $ds;
		}

		usort ($dsList, function ($a, $b){return strcasecmp($a['name'], $b['name']);});

		$fp=popen("resize", "r");
		$b=stream_get_contents($fp);
		preg_match("/COLUMNS=([0-9]+)/", $b, $matches);$columns = $matches[1];
		preg_match("/LINES=([0-9]+)/", $b, $matches);$rows = $matches[1];
		pclose($fp);

		echo (str_repeat('-', $columns)."\n");
		$row = sprintf('%6s', '# | ');
		$row .= sprintf('%20s', 'dsid');
		$row .= ' | '.str::setWidth('name', 80);
		$row .= ' | '.str::setWidth('channel', 12);
		$row .= ' | '.str::setWidth('site', 20);
		$row .= ' | '.str::setWidth('condition', 20);
		$row .= ' | '.str::setWidth('status', 10);
		echo $row."\n";
		echo (str_repeat('-', $columns)."\n");

		$ndx = 1;
		foreach ($dsList as $ds)
		{
			$row = sprintf('%3d', $ndx);
			$row .= ' | '.sprintf('%20s', $ds['dsid']);
			$row .= ' | '.str::setWidth($ds['name'], 80);
			$row .= ' | '.str::setWidth($ds['channelName'], 12);
			$row .= ' | '.str::setWidth($ds['siteName'], 20);
			$row .= ' | '.str::setWidth($ds['condition'], 20);
			$row .= ' | '.str::setWidth($ds['status'], 10);

			echo $row."\n";
			$ndx++;
		}
		echo (str_repeat('-', $columns)."\n");
	}

	public function checkWebRoot($appDir)
	{
		Utils::copy_r (__SHPD_MODULES_DIR__.'/e10/server/skeleton', $appDir);

		$channelConfig = $this->loadCfgFile($appDir.'/config/_server_channelInfo.json');
		if (!$channelConfig)
		{
			//return $this->err('ERROR: invalid channel info');
			return FALSE;
		}

		$channelPath = $this->channelPath($channelConfig['serverInfo']['channelId']);

		// -- reset index.php
		$index = '';
		$index .= "<?php\n";
		$index .= "if (is_file('config/status.data')) {\n";
		$index .= "\trequire_once 'appStatus.php';\n";
		$index .= "\t\$page = new pageStatus();\n";
		$index .= "\t\$page->show();\n";
		$index .= "}\n";
		$index .= "else {\n";
		$index .= "\tdefine ('__SHPD_APP_DIR__', __DIR__);\n";
		$index .= "\tdefine ('__SHPD_ROOT_DIR__', '".$channelPath."');\n";
		$index .= "\trequire_once __SHPD_ROOT_DIR__.'src/boot.php';\n";
		$index .= "";
		$index .= "\t\$myApp = new \\Shipard\\Application\\Application ();\n";
		$index .= "\t\$myApp->run();\n";
		$index .= "}\n";

		file_put_contents($appDir.'/index.php', $index);


		// --- www-root dir
		clearstatcache();
		$wwwTargetPath = $channelPath.'www-root/';
		$existedLink = (is_readable('www-root')) ? readlink ('www-root') : FALSE;
		//echo "    -> check www-root `{$wwwTargetPath}`; existedLink is `".json_encode($existedLink)."`\n";
		if (!$existedLink || $existedLink !== $wwwTargetPath)
		{
			if ($existedLink)
			{
				//echo "    -> unlink `www-root`\n";
				unlink('www-root');
			}
			//echo "    -> symlink (`$wwwTargetPath`, `www-root`)\n";
			symlink($wwwTargetPath, 'www-root');
		}
		return TRUE;
	}

	public function channelPath(string $channelId) : string
	{
		if (isset($this->cfgServer['channels'][$channelId]))
			return $this->cfgServer['channels'][$channelId]['path'];

		$defaultChannelId = $this->cfgServer['defaultChannel'];

		$channelCfg = $this->cfgServer['channels'][$defaultChannelId] ?? NULL;
		if ($channelCfg)
			return $channelCfg['path'];
		
		return '/usr/lib/shipard';
	}

	public function machineDeviceId ()
	{
		if (!is_file('/etc/shipard/device-id.json'))
		{
			$deviceId = md5(json_encode(posix_uname()).mt_rand (1000000, 999999999).'-'.time().'-'.mt_rand (1000000, 999999999));
			file_put_contents('/etc/shipard/device-id.json', $deviceId);
		}
		else
		{
			$deviceId = file_get_contents('/etc/shipard/device-id.json');
		}

		return $deviceId;
	}

	public function command ($idx = 0)
	{
		if (isset ($this->arguments [$idx]))
			return $this->arguments [$idx];

		return "";
	}

	public function currentUser ()
	{
		if (isset ($_SERVER ['USER']))
			return $_SERVER ['USER'];
		return 'johndoe';
	}

	public function superuser ()
	{
		return (0 == posix_getuid());
	}

	public function err ($msg)
	{
		echo $msg . "\r\n";
		return false;
	}

	protected function generatePassword ($len = 9)
	{
		$r = '';
		for($i = 0; $i < $len; $i++)
			$r .= chr (rand (0, 25) + ord('a'));
		return $r;
	}

	function help ()
	{
		$cmd = $this->command(1);
		switch ($cmd)
		{
			case "":
						echo
							"usage: shpd-server command arguments\r\n\r\n" .
							"commands:\r\n" .
							"   app-backup:  backup application (database, config files and attachments)\r\n" .
							"   app-config:  generate config files\r\n" .
							"   app-create:  create new application\r\n" .
							"   app-doc:     create documentation\r\n" .
							"   app-init:    initialize new application\r\n" .
							"   app-publish: send email with login info\r\n" .
							"   app-upgrade: upgrade configs and db tables\r\n" .
							"   app-walk:    apply commands to all apps in folder\r\n" .
							"   db-create:   create database\r\n" .
							"   db-check:    check tables in database (create nonexists tables, add missing columns)\r\n" .
							"   db-optimize: optimize database (defragment tables, ...): use --run for run commands\r\n" .
							"   db-restore:  restore database from file (--file=some/path/database.sql)\r\n" .
							"   db-repair:   repair database (charsets, collations, ...): use --run for run commands\r\n" .
							"   host-backup: backup this host\r\n" .
							"   host-check:  check this host\r\n" .
							"   host-cleanup:cleanup this host\r\n" .
							"   host-upgrade:upgrade e10 packages\r\n" .
							"   help:        general help\r\n" .
							"\r\nSee 'shpd-server help <command>' for more information on a specific command.\r\n" .
							"\r\n";
						return true;
			case "app-create":
						echo
							"Create new application\r\n\r\n" .
							"   usage: shpd-server app-create --name=application-name --module=module/for/install --user=dbuser --password=dbpassword\r\n" .
							"   example: shpd-server app-create --name=test1 --module=e10/install/one --user=root\r\n" .
							"\r\n";
						return true;

		}

		return $this->err ("command '$cmd' not exist; try 'help commands'");
	}

	public function serverBackup ()
	{
		$sm = new ServerManager($this);
		return $sm->serverBackup();
	}

	public function serverCleanup ()
	{
		$sm = new ServerManager($this);
		return $sm->serverCleanup();
	}

	public function hostCheck_CmdSymlink ($cmd, $path)
	{
		$fileName = '/bin/'.$cmd;
		if (!is_file($fileName))
		{
			echo "$fileName --> {$path}\n";
			symlink($path, $fileName);
		}
	}

	public function serverUpgrade ()
	{
		$sm = new ServerManager($this);
		return $sm->serverUpgrade();
	}

	public function serverInfo ()
	{
		$ssc = new \hosting\core\libs\ServerInfoCreator($this);
		$ssc->run();

		return TRUE;
	}

	public function serverAfterPkgsUpgrade()
	{
		$cmd = "/usr/sbin/service shpd-headless-browser restart";
		passthru($cmd);
		$cmd = "shpd-server server-info";
		passthru($cmd);
	}

	public function serverCreateHostingDataSources()
	{
		$dsCreator = new \Shipard\CLI\Server\DSCreator($this);
		$dsCreator->debug = intval($this->arg('debug'));
		$dsCreator->run();

		return TRUE;
	}

	public function hostingCfg ($requiredFields = NULL)
	{
		if (!is_file('/etc/e10-hosting.cfg'))
		{
			Utils::debugBacktrace();
			return $this->err("file2 '/etc/e10-hosting.cfg' not found");
		}

		$cfgAll = json_decode (file_get_contents('/etc/e10-hosting.cfg'), TRUE);
		if (!$cfgAll)
			return $this->err ("invalid e10-hosting.cfg settings (syntax error?)");

		if ($requiredFields === FALSE)
			return $cfgAll;

		//$dsCfg = $this->dsCfg();
		//if (!$dsCfg)
		//	return FALSE;
		//$hostingId = isset($dsCfg['hosting']) ? $dsCfg['hosting'] : 1;
		$hostingId = key($cfgAll);
		$cfg = FALSE;
		if (isset($cfgAll[$hostingId]))
			$cfg = $cfgAll[$hostingId];
		else
		if (isset($cfgAll['1']))
			$cfg = $cfgAll['1'];

		if ($cfg === FALSE)
			return $this->err ("hosting info #{$hostingId} not found in /etc/e10-hosting.cfg");

		if ($requiredFields === NULL)
			return $cfg;

		foreach ($requiredFields as $rf)
		{
			if (!isset ($cfg[$rf]))
				return $this->err ("invalid e10-hosting.cfg: missing {$rf} value");
		}

		return $cfg;
	}

	public function dsCfg ()
	{
		if (!is_file('config/dataSourceInfo.json'))
			return $this->err ("file 'config/dataSourceInfo.json' not found (1)");

		$cfg = json_decode (file_get_contents('config/dataSourceInfo.json'), TRUE);
		if (!$cfg)
			return $this->err ("invalid config/dataSourceInfo.json settings (syntax error?)");

		return $cfg;
	}

	function netDataAlarm()
	{
		require_once __DIR__ . '/../../../lib/server/NetDataAlarm.php';

		$hostingCfg = $this->hostingCfg(['macDeviceNdx', 'macUrl', 'macApiKey']);
		if ($hostingCfg === FALSE)
			return $this->err ("hosting cfg not found");

		$fileName = $this->arg('file');
		if (!$fileName || !is_readable($fileName))
			return FALSE;

		$eng = new \lib\server\NetDataAlarm($this);
		$eng->macDeviceNdx = intval($hostingCfg['macDeviceNdx']);
		$eng->macMachineDeviceId = $this->machineDeviceId ();
		$eng->macUrl = $hostingCfg['macUrl'];
		$eng->macApiKey = $hostingCfg['macApiKey'];
		$eng->loadFromFile($fileName);
		$eng->send();

		return TRUE;
	}

	public function msg ($msg)
	{
		if (!$this->quiet)
			echo '* ' . $msg . "\r\n";
	}

	public function serverCheck()
	{
		$sm = new ServerManager($this);
		return $sm->checkAll();
	}

	public function checkServerConfig()
	{
		if (!$this->cfgServer)
			return $this->err('Server config not exist');

		if ($this->cfgServer['develMode'] || !$this->cfgServer['useHosting'])
		{
			if (!isset($this->cfgServer['userFirstName']) || $this->cfgServer['userFirstName'] === '')
				return $this->err('User first name not set [userFirstName]');
			if (!isset($this->cfgServer['userLastName']) || $this->cfgServer['userLastName'] === '')
				return $this->err('User last name not set [userLastName]');
			if (!isset($this->cfgServer['userEmail']) || $this->cfgServer['userEmail'] === '')
				return $this->err('User email not set [userEmail]');
		}	

		return TRUE;
	}

	public function run ($argv)
	{
		$this->modulesPath = __SHPD_MODULES_DIR__;

		$this->arguments = parseArgs($argv);

		if (count ($this->arguments) == 0)
			return $this->help ();

		if (!$this->superuser() && in_array($this->command (), ['server-backup', 'server-check', 'server-cleanup', 'server-after-pkgs-upgrade']))
			return $this->manager->err ('Need to be root');

		$this->quiet = $this->arg ("quiet");

		switch ($this->command ())
		{
			case	"app-create":       return $this->appCreate ();
			case	"app-new":       		return $this->appNew();
			case	"app-dscmd":       	return $this->appDSCmd ();
			case	"app-dscmd-all":    return $this->appDSCmdAll ();
			case	"app-walk":					return $this->appWalk ();

			case	"ds-ls":						return $this->dsLs ();
			case	"ds-copy-from":			return $this->dsCopyFrom ();
			case	"ds-move-from":			return $this->dsCopyFrom (TRUE);
			case	"ds-fix-perms":			return $this->dsFixPerms ();

			case	"help":             return $this->help ();
			
			case	"server-backup":						return $this->serverBackup ();
			case	"server-check":							return $this->serverCheck ();
			case	"server-cleanup":						return $this->serverCleanup ();
			case	"server-upgrade":						return $this->serverUpgrade();
			case	"server-info":							return $this->serverInfo ();
			case  "server-after-pkgs-upgrade":return $this->serverAfterPkgsUpgrade();
			case  "server-get-hosting-info":	return $this->getHostingInfo();
			case  "server-create-hosting-ds":	return $this->serverCreateHostingDataSources();
			case	'netdata-alarm':						return $this->netDataAlarm();
		}

		if (!$this->manager->load ())
			return false;

		switch ($this->command ())
		{
			case	"app-backup":			return $this->appBackup ();
			case	"app-config":			return $this->appConfig ();
			case	"app-cron":				return $this->appCron ();
			case	"app-fullupgrade":return $this->appUpgradeFull ();
			case	"app-getdsinfo":	return $this->appGetDSInfo ();
			case	"app-httpd-ds":		return $this->appHttpdDS ();
			case	"app-init":				return $this->appInit ();
			case	"app-modules":    return $this->appModules();
			case	"app-publish":		return $this->appPublish ();
			case	"app-reset":			return $this->appReset ();
			case	"app-start":			return $this->appStatus ('');
			case	"app-stop":				return $this->appStatus ('STOP');
			case	"app-test":				return $this->appTest ();
			case	"app-rights":			return $this->appRights ();
			case	"app-upgrade":		return $this->appUpgrade ();


			case	"db-backup":			return $this->dbBackup ();
			case	"db-check":				return $this->manager->dbCheck ();
			case	"db-optimize":		return $this->dbOptimize ();
			case	"db-repair":			return $this->dbRepair ();
			case	"db-create":			return $this->dbCreate ();
			case	"db-restore":			return $this->dbRestore ();
		}

		$this->manager->err ("unknown command...");

		return FALSE;
	}

	public function runModuleServices ($serviceType)
	{
		$tempApplication = new \Shipard\CLI\Application ($_SERVER ['argv']);

		foreach ($this->manager->modules as $m)
		{
			$msfn = $m ['fullPath'].'services.php';
			if (!is_file ($msfn))
				continue;
			include_once ($msfn);
			$className = str_replace ('.', '\\', $m ['id'] . '.ModuleServices');
			if (!class_exists ($className))
				continue;
			$moduleService = new $className ($tempApplication);

			switch ($serviceType)
			{
				case 'onAppPublish' : $moduleService->onAppPublish (); break;
				case 'onAppUpgrade' : $moduleService->onAppUpgrade (); break;
				case 'onCreateDataSource' : $moduleService->onCreateDataSource (); break;
			}

			//echo  $srcPath. "\r\n";
		}
	}
}
