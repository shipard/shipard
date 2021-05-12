<?php

namespace Shipard\CLI\Server;
use \Shipard\Utils\Utils;
use \Shipard\Base\Utility;


class ServerManager extends Utility
{
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

		// -- commands
		/*
		$this->hostCheck_CmdSymlink('e10-cron', '/var/www/e10-server/e10/server/php/e10-cron.php');
		$this->hostCheck_CmdSymlink('e10-test', '/var/www/e10-server/tests/e10-test.php');
		//$this->hostCheck_CmdSymlink('hostinge10-createdatasource', '/var/www/e10-server/e10pro/hosting/node/tools/hostinge10-createdatasource.php');
		*/


		// -- nginx
		/*
		if (is_dir ('/etc/nginx'))
		{
			$fn = '/etc/nginx/conf.d/shpd-server.conf';
			if (!is_file($fn)) {
				symlink('/etc/nginx/shpd-server.conf', $fn);
				echo "# nginx restart required!\n";
			}
		}
		*/

		// -- crontab
		if (!$develHost)
		{
			$fn = '/etc/cron.d/shpd-server';
			if (!is_file($fn))
				symlink(__SHPD_SERVER_ROOT_DIR__.'/etc/cron.d/shpd-server.conf', $fn);
		}

		/*
		foreach ($hostingCfg as $hcId => $hc)
		{
			if (!isset($hc['hostingServerUrl']) || !isset($hc['moncServerUrl']))
			{
				$this->msg("invalid 'hostingServerUrl' or 'moncServerUrl' option at /etc/e10-hosting.cfg#{$hcId}");
				continue;
			}

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
			$this->checkHostService ('e10-monc-node-upload', '/var/www/e10-server/monc/node/systemd-scripts');
		}
		*/

		$this->checkService ('shpd-ds-cmds', '/etc/services');
		$this->checkService ('shpd-headless-browser', '/etc/services');
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
