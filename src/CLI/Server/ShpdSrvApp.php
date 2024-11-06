<?php

namespace Shipard\CLI\Server;
use \Shipard\Utils\Utils, \Shipard\Utils\Str;
use \Shipard\CLI\Server\ServerManager;
use \Shipard\CLI\Server\DSManager;


/**
 * class ShpdSrvApp
 */
class ShpdSrvApp extends \Shipard\Application\ApplicationCore
{
	var $arguments;
	var $modulesPath;
	var $quiet = FALSE;

	var $shpdServerCmd = 'shpd-server';
	var $shpdAppCmd = 'shpd-app';

	public function __construct (?array $cfgServer = NULL)
	{
		parent::__construct($cfgServer);
	}

	public function arg ($name, $defaultValue = FALSE)
	{
		if (isset ($this->arguments [$name]))
			return $this->arguments [$name];

		return $defaultValue;
	}

	function getHostingInfo ()
	{
		$hm = new \Shipard\CLI\Server\HostingManager($this);
		return $hm->getHostingInfo();
	}

	public function dsWalk ()
	{
		$dsroot = $this->cfgServer['dsRoot'];
		chdir($dsroot);

		$paramsArray = $_SERVER ['argv'];
		$appCmd = 'shpd-ds';
		array_shift($paramsArray);
		array_shift($paramsArray);
		$cmdArgs = implode (' ', $paramsArray);

		$withFile = $this->arg ('with-file', FALSE);

		forEach (glob ('*', GLOB_ONLYDIR) as $appDir)
		{
			if (is_link ($appDir))
				continue;
//			if (is_file($appDir.'/.disable-upgrade'))
//				continue;
			if ($withFile !== FALSE && !is_file ($appDir.'/'.$withFile))
				continue;
			if (is_file ($appDir.'/config/config.json'))
			{
				$this->msg ("---- $appDir");
				chdir ($appDir);

				$cmdBase = $appCmd;
				$cmd = $cmdBase.' '.$cmdArgs;
				passthru ($cmd);
				//echo ($cmd."\n");
				chdir ('..');
			}
		}
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
			$channelInfo = utils::loadCfgFile($appDir.'/config/_server_channelInfo.json');
			$statusData = FALSE;
			if (is_file ($appDir.'/config/status.data'))
				$statusData = file_get_contents($appDir.'/config/status.data');

			$ds = ['dsid' => $cfg['dsid']];

			if ($dsInfo)
			{
				$ds['name'] = $dsInfo['name'] ?? '---';
				switch ($dsInfo['condition'] ?? 99)
				{
					case 1 : $ds['condition'] = 'trial '.utils::dateage2(new \DateTime($dsInfo['created'])); break;
					case 2 : $ds['condition'] = 'production'; break;
					case 3 : $ds['condition'] = 'expired'; break;
					case 4 : $ds['condition'] = 'stopped'; break;
					case 5 : $ds['condition'] = 'deleted'; break;
					case 99 : $ds['condition'] = '--no-info--'; break;
					default: $ds['condition'] = '--unknown--';
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
				$ds ['channelName'] = $channelInfo['serverInfo']['channelId'];
			else
				$ds ['channelName'] = '???';

			if ($dsInfo && isset($dsInfo['supportName']))
				$ds ['siteName'] = $dsInfo['supportName'];
			else
				$ds ['siteName'] = '';

			$dsList[] = $ds;
		}

		usort ($dsList, function ($a, $b){return strcasecmp($a['name'], $b['name']);});

		$fp=popen('stty size', 'r');
		$b=stream_get_contents($fp);
		$sizes = explode(' ', $b);
		$columns = intval($sizes[1] ?? 80);
		pclose($fp);

		echo (str_repeat('-', $columns)."\n");
		$row = sprintf('%6s', '# | ');
		$row .= sprintf('%20s', 'dsid');
		$row .= ' | '.Str::setWidth('name', 80);
		$row .= ' | '.Str::setWidth('channel', 12);
		$row .= ' | '.Str::setWidth('support', 20);
		$row .= ' | '.Str::setWidth('condition', 20);
		$row .= ' | '.Str::setWidth('status', 10);
		echo $row."\n";
		echo (str_repeat('-', $columns)."\n");

		$ndx = 1;
		foreach ($dsList as $ds)
		{
			$row = sprintf('%3d', $ndx);
			$row .= ' | '.sprintf('%20s', $ds['dsid']);
			$row .= ' | '.Str::setWidth($ds['name'], 80);
			$row .= ' | '.Str::setWidth($ds['channelName'], 12);
			$row .= ' | '.Str::setWidth($ds['siteName'], 20);
			$row .= ' | '.Str::setWidth($ds['condition'], 20);
			$row .= ' | '.Str::setWidth($ds['status'], 10);

			echo $row."\n";
			$ndx++;
		}
		echo (str_repeat('-', $columns)."\n");
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

	function help ()
	{
		$cmd = $this->command(1);
		switch ($cmd)
		{
			case "":
						echo
							"usage: shpd-srv command arguments\r\n\r\n" .
							"commands:\r\n" .
							"   server-backup:  backup this server\r\n" .
							"   server-check:   check this server\r\n" .
							"   server-cleanup: cleanup this server\r\n" .
							"   server-upgrade: upgrade packages & data sources\r\n" .
							"   version:        show version\r\n" .
							"   help:           general help\r\n" .
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
		$showOnly = intval($this->arg ('show-only'));

		$ssc = new \hosting\core\libs\ServerInfoCreator($this);
		if ($showOnly)
			$ssc->showOnly = $showOnly;
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

		$hostingCfg = Utils::hostingCfg(['macDeviceNdx', 'macUrl', 'macApiKey']);
		if ($hostingCfg === FALSE)
			return $this->err ("hosting cfg not found");

		$fileName = $this->arg('file');
		if (!$fileName || !is_readable($fileName))
			return FALSE;

		$eng = new \lib\server\NetDataAlarm($this);
		$eng->macDeviceNdx = intval($hostingCfg['macDeviceNdx']);
		$eng->macMachineDeviceId = Utils::machineDeviceId ();
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

	public function version()
	{
		echo "shpd-srv version `".__E10_VERSION__.'`; shpd-root-dir: `'.__SHPD_ROOT_DIR__."`\n";
		return TRUE;
	}

	public function run ($argv)
	{
		$this->modulesPath = __SHPD_MODULES_DIR__;

		$this->arguments = Utils::parseArgs($argv);

		if (count ($this->arguments) == 0)
			return $this->help ();

		if (!$this->superuser() && in_array($this->command (), ['server-backup', 'server-check', 'server-cleanup', 'server-after-pkgs-upgrade']))
			return $this->err ('Need to be root');

		$this->quiet = $this->arg ("quiet");

		switch ($this->command ())
		{
			case	'ds-ls':										return $this->dsLs ();
			case	'ds-walk':									return $this->dsWalk ();
			case	'ds-copy-from':							return $this->dsCopyFrom ();
			case	'ds-move-from':							return $this->dsCopyFrom (TRUE);

			case	'help':             				return $this->help ();
			case	'version':             			return $this->version ();

			case	'server-backup':						return $this->serverBackup ();
			case	'server-check':							return $this->serverCheck ();
			case	'server-cleanup':						return $this->serverCleanup ();
			case	'server-upgrade':						return $this->serverUpgrade();
			case	'server-info':							return $this->serverInfo ();
			case  'server-after-pkgs-upgrade':return $this->serverAfterPkgsUpgrade();
			case  'server-get-hosting-info':	return $this->getHostingInfo();
			case  'server-create-hosting-ds':	return $this->serverCreateHostingDataSources();
			case	'netdata-alarm':						return $this->netDataAlarm();
		}

		$this->err ('unknown command...');

		return FALSE;
	}
}
