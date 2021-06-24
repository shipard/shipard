<?php


namespace lib\hosting;


use e10\Utility, e10\utils, \e10\Application;


/**
 * Class UserLoginChangeHosting
 * @package lib\hosting
 */
class UserLoginChangeHosting extends Utility
{
	var $srcLogin = '';
	var $dstLogin = '';
	var $personId = '';
	var $personNdx = 0;
	var $changedDbList = '';

	public function checkParams()
	{
		if ($this->personId !== '')
		{
			$personRec = $this->db()->query('SELECT * FROM [e10_persons_persons] WHERE [id] = %s', $this->personId,
				' AND [accountType] = 1 AND [accountState] = 1 AND [docState] IN %in', [4000, 8000])->fetch();
			if ($personRec)
			{
				$this->personNdx = $personRec['ndx'];
				if ($this->srcLogin === '')
					$this->srcLogin = $personRec['login'];
			}
		}
		else
		if ($this->srcLogin !== '')
		{
			$personRec = $this->db()->query('SELECT * FROM [e10_persons_persons] WHERE [login] = %s', $this->srcLogin,
				' AND [accountType] = 1 AND [accountState] = 1 AND [docState] IN %in', [4000, 8000])->fetch();
			if ($personRec)
				$this->personNdx = $personRec['ndx'];
		}

		if ($this->personNdx === 0)
		{
			$this->addMessage('user not found');
			return;
		}

		if ($this->dstLogin === '')
		{
			$this->addMessage('dstLogin is blank');
			return;
		}

		$personRec = $this->db()->query('SELECT * FROM [e10_persons_persons] WHERE [login] = %s', $this->dstLogin,
			' AND [accountType] = 1 AND [accountState] = 1 AND [docState] IN %in', [1000, 4000, 8000])->fetch();
		if ($personRec && $personRec['ndx'] !== $this->personNdx)
		{
			$this->addMessage("dstLogin '{$this->dstLogin}' exist in person #{$personRec['id']}");
			return;
		}
	}

	public function setParams ($params)
	{
		if (isset($params['srcLogin']))
		{
			$this->srcLogin = $params['srcLogin'];
		}
		if (isset($params['dstLogin']))
		{
			$this->dstLogin = trim($params['dstLogin']);
		}
		if (isset($params['personId']))
		{
			$this->personId = $params['personId'];
		}
	}

	function changeLocal()
	{
		$loginHash = md5(strtolower(trim($this->dstLogin)));

		$update = ['login' => $this->dstLogin, 'loginHash' => $loginHash];
		$this->db()->query('UPDATE [e10_persons_persons] SET ', $update, ' WHERE ndx = %i', $this->personNdx);

		$loginHashOld = md5(strtolower(trim($this->srcLogin)));
		$this->db()->query('UPDATE [e10_persons_userspasswords] SET emailHash = %s', $loginHash, ' WHERE emailHash = %s', $loginHashOld);
	}

	function changeRemote()
	{
		$dsList = [];

		if ($this->app()->cfgItem ('dsMode') === Application::dsmDevel)
		{
			$scanMask = '/var/www/data-sources/' . '*';
			forEach (glob($scanMask, GLOB_ONLYDIR) as $dsDir)
			{
				if (is_link($dsDir))
					continue;

				$dsCfg = utils::loadCfgFile($dsDir.'/config/dataSourceInfo.json');
				if (!$dsCfg)
					continue;

				$pathParts = explode ('/', $dsDir);
				$dsDir = array_pop($pathParts);

				$ds = [];
				$ds ['name'] = $dsCfg ['name'];
				$ds ['url'] = 'https://sebik-ds.shipard.pro/'.$dsDir;
				$dsList[] = $ds;
			}
		}
		else
		{
			$q[] = 'SELECT usersds.*, ds.name AS dsName, ds.shortName AS dsShortName, ds.gid as dsid, ds.docState AS docState,';
			array_push ($q, ' ds.docStateMain AS docStateMain, usersds.lastLogin AS lastLogin,');
			array_push ($q, ' ds.urlWeb AS urlWeb, ds.urlApp AS urlApp, ds.urlApp2 AS urlApp2, ds.docStateMain AS docStateMain, ds.imageUrl AS dsImageUrl,');
			array_push ($q, ' owners.fullName as ownerName, servers.id as serverId');
			array_push ($q, ' FROM [e10pro_hosting_server_usersds] AS usersds');
			array_push ($q, ' RIGHT JOIN e10pro_hosting_server_datasources as ds ON usersds.datasource = ds.ndx');
			array_push ($q, ' RIGHT JOIN e10_persons_persons as owners ON ds.owner = owners.ndx');
			array_push ($q, ' RIGHT JOIN e10pro_hosting_server_servers as servers ON ds.server = servers.ndx');
			array_push ($q, ' WHERE usersds.user = %i', $this->personNdx,' AND ds.docState = 4000 AND usersds.docState = 4000');
			array_push ($q, ' ORDER BY dsName');

			$rows = $this->db()->query($q);
			foreach ($rows as $r)
			{
				$url = 'https://'.$r['serverId'].'.shipard.com/'.$r['dsid'];
				$dsList[] = ['name' => $r['dsName'], 'url' => $url];
			}
		}

		foreach ($dsList as $ds)
		{
			$src = bin2hex($this->srcLogin);
			$dst = bin2hex($this->dstLogin);

			$url = $ds['url'].'/feed/hosting-op/change-login/'.$src.'/'.$dst;;
			echo "- {$ds['name']} \n";

			$opts = ['http'=>['timeout' => 30, 'method'=>'GET', 'header'=> "Connection: close\r\n"]];
			$context = stream_context_create($opts);
			$resultCode = file_get_contents ($url, FALSE, $context);
			echo '  '.$resultCode."\n";

			$this->changedDbList .= '* '.$ds['name']."\n";
		}
	}

	public function sendEmailNotification ()
	{
		$hostingCfg = utils::hostingCfg(['hostingServerUrl']);

		$hostingName = $hostingCfg ['hostingName'];
		$hostingEmail = $hostingCfg ['hostingEmail'];
		$hostingPhone = $hostingCfg ['hostingPhone'];
		$hostingWeb = $hostingCfg ['hostingWeb'];

		$message = "Dobrý den, \n\nVáš email pro přihlášení byl změněn na '{$this->dstLogin}'.\n\n" .
			$this->changedDbList."\n\n" .
			"\nS pozdravem\n\n-- \n email: $hostingEmail | hotline: $hostingPhone | $hostingWeb \n";

		$msg = new \Shipard\Report\MailMessage($this->app);
		$msg->setFrom ('Technická podpora '.$hostingName, $hostingEmail);
		$msg->setTo($this->dstLogin);
		$msg->setSubject('Změna přihlašovacího emailu '.$hostingName);
		$msg->setBody($message);

		$msg->sendMail();
	}

	public function run ()
	{
		$this->checkParams();

		if ($this->personNdx === 0)
		{
			$this->addMessage('User not found');
			return;
		}

		if (count($this->messagess))
			return;

		$this->changeLocal();
		$this->changeRemote();
		$this->sendEmailNotification();
	}
}
