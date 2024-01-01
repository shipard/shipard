<?php

namespace Shipard\CLI\Server;
use \Shipard\Utils\Utils;
use \Shipard\Base\Utility;


class ServerManager extends Utility
{
	public function serverBackup ()
	{
		$thisHostName = utils::cfgItem($this->app->cfgServer, 'serverDomain', gethostname());
		$timeBegin = time();

		// -- data sources
		$localBackupDir = utils::cfgItem($this->app->cfgServer, 'localBackupDir', '/var/lib/shipard/backups');
		$thisLocalBackupDir = $localBackupDir . '/' . date ('Y-m-d');

		// -- data sources
		$cmd = "shpd-server app-walk app-backup --move-to=$localBackupDir";
		if ($this->app()->quiet)
			$cmd .= ' --quiet';
		passthru ($cmd);

		// -- prepare backup/ds info
		$dsBackupInfo = [];
		$dsBackupInfoFileName = $thisLocalBackupDir . '/backupInfo.json';
		if (is_file($dsBackupInfoFileName))
		{
			$bistr = file_get_contents($dsBackupInfoFileName);
			$dsBackupInfo = json_decode ($bistr, TRUE);
		}

		// -- /etc
		$etcFileName = $thisLocalBackupDir."/etc-$thisHostName-" . date ('Y-m-d') . '.tgz';
		exec ("cd / && tar -Pczf $etcFileName /etc/");
		$dsBackupInfo['hostFiles'][] = ['fileName' => $etcFileName, 'fileSHA256' => hash_file('SHA256', $etcFileName)];

		// -- static folders
		$staticFolders = utils::cfgItem($this->app->cfgServer, 'staticFolders', []);
		forEach ($staticFolders as $sf)
		{
			$bfn = str_replace('/', '-', substr($sf, 1, -1));
			$sfFullFileName = "$thisLocalBackupDir/$bfn-" . date ('Y-m-d') . '.tgz';
			exec ("cd / && tar -Pczf $sfFullFileName $sf");
			$dsBackupInfo['hostFiles'][] = ['fileName' => $sfFullFileName, 'fileSHA256' => hash_file('SHA256', $sfFullFileName)];
		}

		// -- save backup/ds info
		$timeEnd = time();
		$dsBackupInfo['timeBegin'] = $timeBegin;
		$dsBackupInfo['timeEnd'] = $timeEnd;
		$dsBackupInfo['done'] = 1;
		file_put_contents ($dsBackupInfoFileName, json_encode($dsBackupInfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

		// -- set owner/group
		exec ("chown -R root:shpd $thisLocalBackupDir");

		// -- remove backup week ago
		$thisLocalBackupDir = $localBackupDir . '/' . date ('Y-m-d', strtotime('-1 week'));
		if (is_dir($thisLocalBackupDir))
			exec ('rm -rf '.$thisLocalBackupDir);

		// -- host cleanup
		$this->serverCleanup ();

		return TRUE;
	}

	public function serverCleanup ()
	{
		$oldDir = getcwd();
		$cmdCleanOldFiles = 'find . -mtime +1 -type f -delete';

		if (is_dir('/var/lib/shipard/email'))
		{
			chdir ('/var/lib/shipard/email');
			passthru ($cmdCleanOldFiles);
		}

		if (is_dir('/var/lib/shipard/tmp'))
		{
			chdir ('/var/lib/shipard/tmp');
			passthru ('find . -mtime +3 -type f -delete');
		}

		chdir ($oldDir);

		return TRUE;
	}

	public function checkServerConfig()
	{
		if (!is_readable(__SHPD_ETC_DIR__.'/server.json'))
		{
			return $this->app()->err('Server configuration file `' . __SHPD_ETC_DIR__ . '/server.json' . '`not found...');
		}

		return TRUE;
	}

	public function checkFilesystem()
	{
		$this->mkDir(__SHPD_VAR_DIR__);
		$this->mkDir(__SHPD_VAR_DIR__.'/dscmd');
		$this->mkDir(__SHPD_VAR_DIR__.'/tmp');
		$this->mkDir(__SHPD_VAR_DIR__.'/upload');
		$this->mkDir(__SHPD_VAR_DIR__.'/upload/dsStats');
		$this->mkDir(__SHPD_VAR_DIR__.'/shpd');

		if (!isset($this->app()->cfgServer['dsRoot']))
			return $this->app()->err('Value `dsRoot` not found in server configuration...');
		$dsRoot = $this->app()->cfgServer['dsRoot'];
		$this->mkDir($dsRoot);

		if (!is_readable($dsRoot . '/index.html'))
			file_put_contents($dsRoot . '/index.html', '');

		$this->app->machineDeviceId();

		return TRUE;
	}

	public function mkDir($dir)
	{
		if (!is_dir($dir))
			mkdir($dir, 0770, TRUE);

		chmod($dir, 0770);
		chown($dir, utils::wwwUser());
		chgrp($dir, utils::wwwGroup());
	}

	public function checkServer ()
	{
		$develHost = $this->app()->cfgServer['develMode'];
		$restart = 0;

		// -- nginx
		if (is_dir ('/etc/nginx'))
		{
			$fn = '/etc/nginx/conf.d/shpd-server.conf';
			if (!is_file($fn))
			{
				symlink(__SHPD_ROOT_DIR__.'/etc/nginx/shpd-server.conf', $fn);
				echo "# `$fn` - nginx restart required!\n";
				$restart = 1;
			}
		}

		if (!is_dir ('/etc/php/8.3/fpm'))
		{
			$cmd = 'apt install --assume-yes --quiet php8.3-cli php8.3-mysql php8.3-fpm php8.3-imap php8.3-xml php8.3-curl php8.3-intl php8.3-zip php8.3-bcmath php8.3-gd php8.3-mbstring php8.3-soap php8.3-mailparse php8.3-yaml php8.3-redis';
			echo "### UPGRADE TO PHP 8.3 ###\n";
			echo '* '.$cmd."\n";
			passthru($cmd);
			$restart = 1;
		}

		// -- PHP
		if (is_dir ('/etc/php/8.3'))
		{
			$fn = '/etc/php/8.3/fpm/pool.d/zz-shpd-php-fpm.conf';
			if (!is_file($fn))
			{
				symlink(__SHPD_ROOT_DIR__.'/etc/php/zz-shpd-php-fpm.conf', $fn);
				echo "# `$fn` - service php8.3-fpm restart required!\n";
			}
			$fn = '/etc/php/8.3/fpm/conf.d/95-shpd-php.ini';
			if (!is_file($fn))
			{
				symlink(__SHPD_ROOT_DIR__.'/etc/php/95-shpd-php.ini', $fn);
				echo "# `$fn` - service php8.3-fpm restart required!\n";
			}
			$fn = '/etc/php/8.3/cli/conf.d/95-shpd-php.ini';
			if (!is_file($fn))
			{
				symlink(__SHPD_ROOT_DIR__.'/etc/php/95-shpd-php.ini', $fn);
				echo "# `$fn` - service php8.3-fpm restart required!\n";
			}
			$restart = 1;
		}

		if (is_dir ('/etc/php/8.2'))
		{
			$fn = '/etc/php/8.2/fpm/pool.d/zz-shpd-php-fpm.conf';
			if (!is_file($fn))
			{
				symlink(__SHPD_ROOT_DIR__.'/etc/php/zz-shpd-php-fpm.conf', $fn);
				echo "# `$fn` - service php8.2-fpm restart required!\n";
			}
			$fn = '/etc/php/8.2/fpm/conf.d/95-shpd-php.ini';
			if (!is_file($fn))
			{
				symlink(__SHPD_ROOT_DIR__.'/etc/php/95-shpd-php.ini', $fn);
				echo "# `$fn` - service php8.2-fpm restart required!\n";
			}
			$fn = '/etc/php/8.2/cli/conf.d/95-shpd-php.ini';
			if (!is_file($fn))
			{
				symlink(__SHPD_ROOT_DIR__.'/etc/php/95-shpd-php.ini', $fn);
				echo "# `$fn` - service php8.2-fpm restart required!\n";
			}
		}

		if (is_dir ('/etc/php/8.1'))
		{
			$fn = '/etc/php/8.1/fpm/pool.d/zz-shpd-php-fpm.conf';
			if (!is_file($fn))
			{
				symlink(__SHPD_ROOT_DIR__.'/etc/php/zz-shpd-php-fpm.conf', $fn);
				echo "# `$fn` - service php8.1-fpm restart required!\n";
			}
			$fn = '/etc/php/8.1/fpm/conf.d/95-shpd-php.ini';
			if (!is_file($fn))
			{
				symlink(__SHPD_ROOT_DIR__.'/etc/php/95-shpd-php.ini', $fn);
				echo "# `$fn` - service php8.1-fpm restart required!\n";
			}
			$fn = '/etc/php/8.1/cli/conf.d/95-shpd-php.ini';
			if (!is_file($fn))
			{
				symlink(__SHPD_ROOT_DIR__.'/etc/php/95-shpd-php.ini', $fn);
				echo "# `$fn` - service php8.1-fpm restart required!\n";
			}
		}

		// -- crontab
		if (!$develHost)
		{
			$fn = '/etc/cron.d/shpd-server';
			if (!is_file($fn))
				symlink(__SHPD_SERVER_ROOT_DIR__.'/etc/cron.d/shpd-server.conf', $fn);
		}

		// -- apt upgrades
		if (is_dir ('/etc/apt/apt.conf.d'))
		{
			$fn = '/etc/apt/apt.conf.d/99zz-shp-upgrades';
			if (!is_file($fn))
			{
				symlink(__SHPD_ROOT_DIR__.'/etc/apt/99zz-shp-upgrades', $fn);
			}
		}

		/*
		foreach ($hostingCfg as $hcId => $hc)
		{

			if (!is_dir('/var/lib/e10/upload/apiaccess-'.$hcId))
				mkdir('/var/lib/e10/upload/apiaccess-'.$hcId, 0770, TRUE);
			if (!is_dir('/var/lib/e10/upload/monc-hosting-'.$hcId))
				mkdir('/var/lib/e10/upload/monc-hosting-'.$hcId, 0770, TRUE);
			if (!is_dir('/var/lib/e10/upload/dsStats-'.$hcId))
				mkdir('/var/lib/e10/upload/dsStats-'.$hcId, 0770, TRUE);

			// -- api access upload settings
			$fn = "/var/lib/e10/upload/apiaccess-$hcId/.settings";
			if (!is_file($fn)) {
				$aadata = ['dsUrl' => $hc['hostingServerUrl'], 'table' => 'e10pro.hosting.server.usersds'];
				file_put_contents($fn, json_encode($aadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			}

			// -- dsStats upload settings
			$fn = "/var/lib/e10/upload/dsStats-$hcId/.settings";
			if (!is_file($fn)) {
				$dsdata = ['dsUrl' => $hc['hostingServerUrl'], 'table' => 'e10pro.hosting.server.datasourcesStats'];
				file_put_contents($fn, json_encode($dsdata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			}

			$this->checkHostService ('e10-services', '/var/www/e10-server/e10/server/etc/services');
			$this->checkHostService ('e10-service-cache', '/var/www/e10-server/e10/server/etc/services');
			$this->checkHostService ('e10-ds-cmds', '/var/www/e10-server/e10/server/etc/services');
		}
		*/

		if ($restart)
		{
			passthru('systemctl restart php8.3-fpm');
			passthru('systemctl restart nginx');
		}

		$this->checkService ('shpd-ds-cmds', '/etc/services');
		$this->checkService ('shpd-ds-services', '/etc/services');
		$this->checkService ('shpd-headless-browser', '/etc/services');

		if ($this->app()->cfgServer['useHosting'] && isset($this->app()->cfgServer['serverId']) && $this->app()->cfgServer['serverId'] !== '')
		{
			$this->checkService ('shpd-hosting-create-ds', '/etc/services');
			$this->checkService ('shpd-hosting-upload', '/etc/services');
		}
	}

	protected function checkService ($serviceBaseFileName, $serviceFileSrcFolder)
	{
		$serviceDstFileName = '/etc/systemd/system/'.$serviceBaseFileName.'.service';
		$serviceSrcFileName = __SHPD_SERVER_ROOT_DIR__.$serviceFileSrcFolder.'/'.$serviceBaseFileName.'.service';

		if (is_file($serviceDstFileName))
		{ // new version?
			$srcCheckSum = md5_file($serviceSrcFileName);
			$dstCheckSum = md5_file($serviceDstFileName);
			if ($srcCheckSum !== $dstCheckSum)
			{
				copy ($serviceSrcFileName, $serviceDstFileName);
				$cmd = "systemctl daemon-reload && systemctl stop {$serviceBaseFileName}.service && systemctl start $serviceBaseFileName";
				shell_exec($cmd);
			}
			return;
		}

		// -- install
		copy ($serviceSrcFileName, $serviceDstFileName);
		$cmd = "cd /etc/systemd/system/ && systemctl enable {$serviceBaseFileName}.service && systemctl start $serviceBaseFileName";
		shell_exec($cmd);
	}

	public function checkServerNginxCfg()
	{
		if (!$this->app()->cfgServer)
			return $this->app()->err('server config not found');

		$configFileName = '/etc/nginx/sites-available/shpd-server.conf';

		$serverName = $this->app()->cfgServer['serverDomain'];

		$serverNameParts = explode('.', $serverName);
		while (count($serverNameParts) > 2)
			array_shift($serverNameParts);
		if (count($serverNameParts) !== 2)
			return $this->app()->err('invalid server name/domain');

		$dsRoot = $this->app()->cfgServer['dsRoot'];
		$certId = 'all.'.implode('.', $serverNameParts);

		$cfg = '';

		// -- web via https
		$cfg .= "# ".$serverName."; cfg ver 0.7\n";

		$cfg .= "server {\n";
		$cfg .= "\tlisten 443 ssl http2;\n";
		$cfg .= "\tserver_name {$serverName};\n";
		$cfg .= "\troot {$dsRoot};\n";
		$cfg .= "\tindex index.php;\n";

		$cfg .= "\tssl_certificate /var/lib/shipard/certs/$certId/chain.pem;\n";
		$cfg .= "\tssl_certificate_key /var/lib/shipard/certs/$certId/privkey.pem;\n";
		if (is_readable('/etc/ssl/dhparam.pem'))
			$cfg .= "\tssl_dhparam /etc/ssl/dhparam.pem;\n";

		$cfg .= "\tinclude /usr/lib/shipard/etc/nginx/shpd-global-host.conf;\n";
		$cfg .= "\tinclude /usr/lib/shipard/etc/nginx/shpd-https.conf;\n";
		$cfg .= "}\n\n";

		// -- save
		$verExist = '';
		if (is_readable($configFileName))
			$verExist = md5_file($configFileName);

		$verNew = md5($cfg);

		if ($verExist !== $verNew)
		{
			file_put_contents($configFileName, $cfg);
			// -- symlink
			$link = '/etc/nginx/sites-enabled/shpd-server.conf';
			if (!is_readable($link))
				symlink($configFileName, $link);

			echo "NGINX restart requiered\n";
		}
	}

	public function serverUpgrade()
	{
		// -- update sources
		foreach ($this->app->cfgServer ['channels'] as $channelId => $channel)
		{
			$this->serverUpgrade_Channel($channelId, $channel);
		}

		// -- host check
		passthru ('shpd-server server-check');

		// -- app-upgrade
		passthru ('shpd-server app-walk app-upgrade');

		// -- server info
		passthru ('shpd-server server-info');
	}

	protected function serverUpgrade_Channel(string $channelId, array $channel)
	{
		echo "# $channelId\n";
		if (is_dir($channel['path']))
		{
			if (is_dir($channel['path'].'/.git'))
			{
				passthru("cd {$channel['path']} && git pull");
			}
			else
			{
				error_log ("ERROR: channel `$channelId` (`{$channel['path']}`) is not git repository");
			}
		}
		else
		{
			error_log ("ERROR: dir `{$channel['path']}` for channel `$channelId` not found");
		}
	}

	public function checkAll()
	{
		if (!$this->checkServerConfig())
			return FALSE;

		if (!$this->checkFilesystem())
			return FALSE;

		$this->checkServer();

		$this->checkServerNginxCfg();

		return TRUE;
	}
}
