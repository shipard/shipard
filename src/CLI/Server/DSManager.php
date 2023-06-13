<?php

namespace Shipard\CLI\Server;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Json;
use \Shipard\Base\Utility;


class DSManager extends Utility
{
	var $params = [];
	var $tmpDir = '';
	var $dsPathRemote = '';
	var $dsPathLocal = '';
	var $backupFileNameRemote = '';
	var $todayStr = '';
	var $oldMode = FALSE;

	public function init()
	{
		$this->tmpDir = __SHPD_VAR_DIR__.'tmp/dsops';
		$this->todayStr = Utils::today('Y-m-d');

		if (is_dir($this->tmpDir))
		{
			exec('rm -rf '.$this->tmpDir);
			clearstatcache();
		}

		Utils::mkDir($this->tmpDir);
	}

	protected function appStart()
	{
		$cmd = 'cd '.$this->dsPathLocal.' && shpd-server app-start';
		passthru($cmd);
	}

	protected function appStop()
	{
		$cmd = 'cd '.$this->dsPathLocal.' && shpd-server app-stop';
		passthru($cmd);
	}

	public function getServerInfo()
	{
		echo "# getServerInfo\n";

		// -- get OLD server info
		$cmd = 'ssh '.$this->params['server'].' "[ -s /etc/e10-hosting.cfg ] && cat /etc/e10-hosting.cfg" > '.$this->tmpDir.'/from-e10-hosting.cfg';
		//echo $cmd."\n";
		passthru($cmd);
		if (!is_readable ($this->tmpDir.'/from-e10-hosting.cfg'))
		{
			return $this->app->err('Invalid server response');
		}

		$cmd = 'ssh '.$this->params['server'].' "[ -s /etc/shipard/server.json ] && cat /etc/shipard/server.json" > '.$this->tmpDir.'/from-server.json';
		//echo $cmd."\n";
		passthru($cmd);
		if (!is_readable ($this->tmpDir.'/from-server.json'))
		{
			return $this->app->err('Invalid server response');
		}
		$newServerCfg = \file_get_contents($this->tmpDir.'/from-server.json');
		if ($newServerCfg !== '')
		{ // NEW
			$this->dsPathRemote = '/var/lib/shipard/data-sources/'.$this->params['dsId'].'/';
			$this->backupFileNameRemote = '/var/lib/shipard/backups/'.$this->todayStr.'/bkp-'.$this->params['dsId'].'-'.$this->todayStr.'.tgz';
			$this->oldMode = FALSE;
		}

		$oldServerCfg = \file_get_contents($this->tmpDir.'/from-e10-hosting.cfg');
		if ($oldServerCfg !== '')
		{
			$this->dsPathRemote = '/var/www/data-sources/'.$this->params['dsId'].'/';
			$this->backupFileNameRemote = '/var/lib/e10/backups/'.$this->todayStr.'/bkp-96690501241477-'.$this->params['dsId'].'-'.$this->todayStr.'.tgz';
			$this->oldMode = TRUE;
		}


		$cmd = 'ssh '.$this->params['server'].' "[ -s '.$this->dsPathRemote.'config/config.json ] && cat '.$this->dsPathRemote.'config/config.json" > '.$this->tmpDir.'/from-ds-config.json';
		//echo $cmd."\n";
		passthru($cmd);
		if (!is_readable ($this->tmpDir.'/from-ds-config.json'))
		{
			return $this->app->err('Invalid server response');
		}

		$dsCfg = \file_get_contents($this->tmpDir.'/from-ds-config.json');
		if ($dsCfg === '')
		{
			return $this->app->err('Invalid datasource config');
		}

		$this->dsPathLocal = $dsRoot = $this->app()->cfgServer['dsRoot'].'/'.$this->params['dsId'].'/';

		//echo "REMOTE DS PATH    : ".$this->dsPathRemote."\n";
		//echo "LOCAL DS PATH     : ".$this->dsPathLocal."\n";
		//echo "REMOTE BACKUP FILE: ".$this->backupFileNameRemote."\n";

		return TRUE;
	}

	public function checkBlankDSRoot()
	{
		if (is_dir($this->dsPathLocal.'config'))
		{
			$this->appStop();
			return TRUE;
		}
		echo "# init blank datasource folder\n";

		utils::mkDir($this->dsPathLocal);

		$cmd = 'scp '.$this->params['user'].'@'.$this->params['server'].':'.$this->backupFileNameRemote.' '.$this->tmpDir.'/backup.tgz';
		//echo "   --> ".$cmd."\n";
		passthru($cmd);

		$cmd = 'cd '.$this->dsPathLocal.' && tar xf '.$this->tmpDir.'/backup.tgz';
		//echo "  --> ".$cmd."\n";
		passthru($cmd);

		Utils::mkDir($this->dsPathLocal.'config');
		Utils::mkDir($this->dsPathLocal.'config/curr');
		Utils::mkDir($this->dsPathLocal.'config/nginx');

		if (!$this->checkChannel())
			return FALSE;
		if (!$this->checkModules())
			return FALSE;

		$this->appStop();

		$cmd = 'cd '.$this->dsPathLocal.' && shpd-server db-create --replace';
		//echo "  --> ".$cmd."\n";
		passthru($cmd);

		$cmd = 'cd '.$this->dsPathLocal.' && shpd-server app-upgrade';
		//echo "  --> ".$cmd."\n";
		passthru($cmd);

		return TRUE;
	}

	public function checkChannel()
	{
		$channelConfig = $this->loadCfgFile($this->dsPathLocal.'config/_server_channelInfo.json');
		if (!$channelConfig)
			return TRUE;

		$channelPath = $this->app()->channelPath($channelConfig['serverInfo']['channelId']);

		if ($channelPath !== !$channelConfig['serverInfo']['channelPath'])
		{
			echo "   ! channelPath for `{$channelConfig['serverInfo']['channelId']}` changed: `{$channelConfig['serverInfo']['channelPath']}` --> `$channelPath`\n";
			$channelConfig['serverInfo']['channelPath'] = $channelPath;
			file_put_contents($this->dsPathLocal.'config/_server_channelInfo.json', json_encode($channelConfig));
		}

		return TRUE;
	}

	public function checkModules()
	{
		echo "# checking modules:\n";
		$replaceModules = [
			'pkgs/apps/big' => 'install/apps/shipard-economy',
			'pkgs/apps/small' => 'install/apps/shipard-economy',
			'pkgs/install/apps/shipard-economy' => 'install/apps/shipard-economy',
			'pkgs/apps/npo' => 'install/apps/shipard-npo',
		];
		$ignoreModules = ['e10pro/install/other-kb', 'locshare/server', 'gdpr/base'];

		$oldModules = Utils::loadCfgFile($this->dsPathLocal.'config/modules.json');
		if (!$oldModules)
			return $this->app->err('Invalid config/modules.json file');

		$newModules = [];
		foreach ($oldModules as $oldModuleId)
		{
			echo '  - '.$oldModuleId.': ';

			if (isset($replaceModules[$oldModuleId]))
			{
				$newModules[] = $replaceModules[$oldModuleId];
				echo ' ==> '.$replaceModules[$oldModuleId]."\n";
				continue;
			}

			if (in_array($oldModuleId, $ignoreModules))
			{
				echo 'IGNORE'."\n";
				continue;
			}

			if (!is_readable(__SHPD_MODULES_DIR__.$oldModuleId.'/module.json'))
			{
				echo ' ERROR: module `'.__SHPD_MODULES_DIR__.$oldModuleId.'/module.json'.'`not found'."\n";
				continue;
			}

			$newModules[] = $oldModuleId;

			echo "OK\n";
		}

		file_put_contents($this->dsPathLocal.'config/modules.json', Json::lint($newModules));

		return TRUE;
	}

	public function syncAttachments()
	{
		if (isset($this->params['disableAtt']))
		{
			echo "# syncing attachments is disable\n";
			return TRUE;
		}

		echo "# syncing attachments\n";
		utils::mkDir($this->dsPathLocal.'att');

		$cmd = 'rsync -azk --info=progress2 -e "ssh " '.$this->params['user'].'@'.$this->params['server'].':'.$this->dsPathRemote.'att '.$this->dsPathLocal;
		//echo $cmd."\n";
		passthru($cmd);

		return TRUE;
	}

	public function stopRemote()
	{
		if ($this->oldMode)
			$cmd = 'ssh '.$this->params['server'].' "cd '.$this->dsPathRemote.' && e10 app-stop"';
		else
			$cmd = 'ssh '.$this->params['server'].' "cd '.$this->dsPathRemote.' && shpd-server app-stop"';
		echo $cmd."\n";
		passthru($cmd);
	}

	public function downloadLiveBackup()
	{
		echo "# download live db backup\n";
		//if ($this->oldMode)
		//	$cmd = 'ssh '.$this->params['server'].' "cd '.$this->dsPathRemote.' && e10 app-stop"';
		//else
		//	$cmd = 'ssh '.$this->params['server'].' "cd '.$this->dsPathRemote.' && shpd-server app-stop"';
		$dsCfg = Utils::loadCfgFile($this->tmpDir.'/from-ds-config.json');

		$cmd = 'ssh -C '.$this->params['server']. ' "'. "mysqldump --default-character-set=utf8mb4 -u {$dsCfg['db']['login']} -p{$dsCfg['db']['password']} {$dsCfg['db']['database']}".'" > '.$this->dsPathLocal.'/database.sql';
		//echo $cmd."\n";
		passthru($cmd);
	}

	public function restoreDb()
	{
		echo "# restore db\n";
		$cmd = 'cd '.$this->dsPathLocal.' && shpd-server db-restore --file=database.sql';
		passthru($cmd);
		$cmd = 'cd '.$this->dsPathLocal.' && shpd-server app-upgrade';
		passthru($cmd);
		$cmd = 'cd '.$this->dsPathLocal.' && shpd-server app-fullupgrade';
		passthru($cmd);

		unlink ($this->dsPathLocal.'/database.sql');
	}

	public function doFixPerms()
	{
		$cmd = 'cd '.$this->dsPathLocal.' && shpd-server ds-fix-perms && shpd-server app-start';
		if ($this->app->superuser())
		{
			passthru($cmd);
		}
		else
		{
			echo "PLEASE RUN:\nsudo sh -c \"{$cmd}\"\nto fix file permissions\n";
		}
	}

	public function copyFrom (array $params)
	{
		$this->params = $params;

		if (!$this->getServerInfo())
			return FALSE;

		if (!$this->checkBlankDSRoot())
			return FALSE;

		if (!$this->syncAttachments())
			return FALSE;

		$this->restoreDb();

		$this->doFixPerms();

		return TRUE;
	}

	public function moveFrom (array $params)
	{
		$this->params = $params;

		if (!$this->getServerInfo())
			return FALSE;

		if (!$this->checkBlankDSRoot())
			return FALSE;

		if (!$this->syncAttachments())
			return FALSE;

		//$this->stopRemote();
		$this->downloadLiveBackup();
		$this->restoreDb();

		$this->doFixPerms();

		return TRUE;
	}

	public function fixPermsDir($dir)
	{
		if (!is_dir($dir))
			return;

		$cmd = 'find "'.$dir.'" -type d -print0 | xargs -0 chmod 770';
		passthru($cmd);

		$cmd = 'find "'.$dir.'" -type f -print0 | xargs -0 chmod 660';
		passthru($cmd);

		$cmd = 'chown -R shpd:shpd "'.$dir.'"';
		passthru($cmd);
	}

	public function fixPerms()
	{
		$this->fixPermsDir('att');
		$this->fixPermsDir('config');
		$this->fixPermsDir('templates');
		$this->fixPermsDir('tmp');
		$this->fixPermsDir('imgcache');
		$this->fixPermsDir('res');
	}
}
