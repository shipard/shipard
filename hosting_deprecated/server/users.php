<?php

namespace E10Users;
require_once '/var/www/e10-modules/e10/server/php/3rd/dibi/v3/loader.php';
use dibi;

function e10GetAllHeaders()
{
	$headers = [];
	foreach ($_SERVER as $name => $value)
	{
		if (substr($name, 0, 5) == 'HTTP_')
		{
			$headers[str_replace(' ', '-', strtolower(str_replace('_', ' ', substr($name, 5))))] = $value;
		}
	}
	return $headers;
}

function testGetParam ($paramName)
{
	if (isset ($_GET [$paramName]))
		return $_GET [$paramName];
	return FALSE;
}

function testPostParam ($paramName, $defaultValue = '')
{
	if (isset ($_POST [$paramName]))
		return $_POST [$paramName];
	return $defaultValue;
}

function hostingCfg ()
{
	$cfgString = file_get_contents ('/etc/e10-hosting.cfg');
	$cfg = json_decode ($cfgString, true);
	foreach ($cfg as $oneItem)
	{
		return $oneItem;
	}

	return FALSE;
}

function connectDb ()
{
	$cfg = hostingCfg();

	dibi::connect([
			'driver'   => 'mysqli',
			'host'     => 'localhost',
			'username' => $cfg ['dbUser'],
			'password' => $cfg ['dbPassword'],
			'database' => $cfg ['dbDatabase'],
			'charset'  => 'utf8mb4',
	]);
}

function addToLog ($info, $failInfo = NULL)
{
	$headers = e10GetAllHeaders();

	$item = $info;

	$ipAddr =  (isset($headers['e10-remote-ipaddr'])) ? base64_decode($headers['e10-remote-ipaddr']) : '';
	if ($ipAddr === '')
		$ipAddr = (isset($_SERVER ['REMOTE_ADDR'])) ? $_SERVER ['REMOTE_ADDR'] : '0.0.0.0';

	$dsGid =  (isset($headers['e10-remote-dsgid'])) ? base64_decode($headers['e10-remote-dsgid']) : '0';
	$deviceId =  (isset($headers['e10-device-id'])) ? base64_decode($headers['e10-device-id']) : '';

	$item ['created'] = new \DateTime();
	$item ['ipaddress'] = $ipAddr;
	$item ['dsGid'] = $dsGid;
	$item ['deviceId'] = $deviceId;

	dibi::query ('INSERT INTO [e10_base_authLog] ', $item);

	if ($failInfo)
	{
		$logNdx = dibi::getInsertId();
		$fileName = 'tmp/__FAIL_LOGIN_'.$logNdx.'.json';
		file_put_contents($fileName, json_encode($failInfo));
	}
}

function checkPassword($userPassword, $pwdInfo)
{
	if ($pwdInfo['version'] == 0)
	{
		$passwordHash = sha1($userPassword . $pwdInfo ['salt']);
		if ($passwordHash === $pwdInfo['password'])
		{
			$newPassword = password_hash($userPassword, PASSWORD_BCRYPT, ['cost' => 12]);
			dibi::query('UPDATE [e10_persons_userspasswords] SET [version] = %i', 1, ', [password] = %s', $newPassword, ' WHERE [ndx] = %i', $pwdInfo['ndx']);
			return TRUE;
		}
	}
	elseif ($pwdInfo['version'] == 1)
	{
		if (password_verify($userPassword, $pwdInfo['password']))
			return TRUE;
	}
	return FALSE;
}

function checkDevice ($userNdx, $deviceId, $clientType, $info)
{
	if ($clientType === '' || !$info)
		return;

	$headers = e10GetAllHeaders();
	$ipAddr =  (isset($headers['e10-remote-ipaddr'])) ? base64_decode($headers['e10-remote-ipaddr']) : '';

	$timeStamp = new \DateTime();

	$clientInfoString = $clientType. '; ';

	if (isset($info['deviceModel']))
		$clientInfoString .= $info['deviceModel'];
	$clientInfoString .= '; ';

	if (isset($info['osName']))
		$clientInfoString .= $info['osName'];
	$clientInfoString .= '; ';

	if (isset($info['osVersion']))
		$clientInfoString .= $info['osVersion'];
	$clientInfoString .= '; ';

	if (isset($info['userAgent']))
		$clientInfoString .= $info['userAgent'];

	$deviceInfo = ['clientInfo' => $clientInfoString, 'clientTypeId' => $clientType];
	if (isset($info['appVersion']))
		$deviceInfo['clientVersion'] = $info['appVersion'];

	// -- search ip address
	$ipaddressndx = 0;
	$ip = dibi::query('SELECT * FROM e10_base_ipaddr WHERE docState = 4000 AND ipaddress = %s', $ipAddr)->fetch();
	if (isset ($ip['ndx']))
		$ipaddressndx = $ip['ndx'];

	// -- search device
	$device = dibi::query('SELECT * FROM e10_base_devices WHERE id = %s', $deviceId)->fetch();
	if (!$device)
	{
		$deviceRec = [
						'id' => $deviceId, 'currentUser' => $userNdx, 'lastSeenOnline' => $timeStamp,
						'ipaddress' => $ipAddr, 'ipaddressndx' => $ipaddressndx,
						'docState' => 4000, 'docStateMain' => 2] + $deviceInfo;
		dibi::query('INSERT INTO e10_base_devices', $deviceRec);
	}
	else
	{
		$deviceRec = [
						'currentUser' => $userNdx, 'lastSeenOnline' => $timeStamp,
						'ipaddress' => $ipAddr, 'ipaddressndx' => $ipaddressndx
				] + $deviceInfo;
		dibi::query('UPDATE e10_base_devices SET ', $deviceRec, ' WHERE ndx = %i', $device['ndx']);
	}
}

/**
 * checkUserLogin
 */
function checkUserLogin ()
{
	$userPassword = FALSE;
	$headers = e10GetAllHeaders();

	$sessionId =  (isset($headers['e10-login-sid'])) ? base64_decode($headers['e10-login-sid']) : '';
	$clientType =  (isset($headers['e10-client-type'])) ? base64_decode($headers['e10-client-type']) : '';
	$deviceId =  (isset($headers['e10-device-id'])) ? base64_decode($headers['e10-device-id']) : '';
	$deviceInfo = NULL;
	if (isset($headers['e10-device-info']))
		$deviceInfo = json_decode(base64_decode($headers['e10-device-info']), TRUE);

	if ($deviceId === '')
		$deviceId = newDeviceId();

	if ($sessionId !== '')
	{
		$sessionExist = dibi::query('SELECT [person] FROM [e10_persons_sessions] WHERE [ndx] = %s', $sessionId)->fetch();
		if ($sessionExist)
		{
			$user = dibi::query (
					'SELECT * FROM [e10_persons_persons] ',
					'WHERE [ndx] = %s', $sessionExist['person'], ' AND [docState] IN %in', [4000, 8000]
			)->fetch();

			if ($user)
			{
				$data ['success'] = 1;
				$data ['sid'] = $sessionId;
				$data ['user'] = $user['loginHash'];
				$data ['did'] = $deviceId;

				checkDevice($user['ndx'], $deviceId, $clientType, $deviceInfo);

				$r ['data'] = $data;

				addToLog(['eventType' => 3, 'user' => $user['ndx'], 'session' => $sessionId, 'deviceId' => $deviceId]);

				echo json_encode($r);
				return;
			}
		}

		$failLog = [
			'type' => 'sessionId',
			'sessionId' => $sessionId,
			'clientType' => $clientType,
			'deviceId' => $deviceId,
			'deviceInfo' => $deviceInfo,

			'headers' => $headers,
		];

		addToLog(['eventType' => 4, 'session' => $sessionId], $failLog);
	}

	if (isset ($headers['e10-login-user']))
	{
		$userLogin = base64_decode($headers['e10-login-user']);
		$userPassword = base64_decode($headers['e10-login-pw']);
	}
	else
	if (isset($_SERVER['PHP_AUTH_USER']))
	{
		$userLogin = $_SERVER['PHP_AUTH_USER'];
		$userPassword = $_SERVER['PHP_AUTH_PW'];
	}

	$data = ['success' => 0];
	$r = [];
	$r ['objectType'] = 'call';

	if ($userPassword !== FALSE)
	{
		$emailHash = md5(strtolower(trim($userLogin)));
		$user = dibi::query (
				'SELECT pwds.* FROM [e10_persons_userspasswords] AS pwds',
				' LEFT JOIN e10_persons_persons AS persons ON pwds.person = persons.ndx',
				' WHERE pwds.[pwType] = 0 AND pwds.[emailHash] = %s', $emailHash, ' AND persons.[docState] IN %in', [4000, 8000])->fetch ();

		if ($user)
		{
			if (checkPassword($userPassword, $user))
			{
				$newSessionId = newSessionId();
				$newSession = ["ndx" => $newSessionId, "person" => $user['person'], 'created' => new \DateTime()];
				dibi::query ('INSERT INTO [e10_persons_sessions] ', $newSession);

				$data ['success'] = 1;
				$data ['sid'] = $newSessionId;
				$data ['user'] = $user['emailHash'];
				$data ['did'] = $deviceId;

				checkDevice($user['person'], $deviceId, $clientType, $deviceInfo);

				addToLog(['eventType' => 1, 'user' => $user['person'], 'session' => $newSessionId, 'deviceId' => $deviceId]);
			}
			else
			{
				$failLog = [
					'type' => 'login; wrongPassword',
					'sessionId' => $sessionId,
					'clientType' => $clientType,
					'deviceId' => $deviceId,
					'deviceInfo' => $deviceInfo,

					'login' => $userLogin,
					'pw' => $userPassword,

					'headers' => $headers,
				];
				addToLog(['eventType' => 2, 'login' => $userLogin], $failLog);
			}
		}
		else
		{
			$failLog = [
				'type' => 'login; userNotFound',
				'sessionId' => $sessionId,
				'clientType' => $clientType,
				'deviceId' => $deviceId,
				'deviceInfo' => $deviceInfo,

				'login' => $userLogin,
				'pw' => $userPassword,

				'headers' => $headers,
			];

			addToLog(['eventType' => 5, 'login' => $userLogin], $failLog);
		}
	}

	$r ['data'] = $data;
	echo json_encode ($r);
}


function createToken($len)
{
	$id = '';
	while (1)
	{
		$part = base_convert(strval(mt_rand (1000000, 40000000000)), 10, 36);
		for ($i = 0; $i < strlen($part); $i++)
		{
			$id .= $part[$i];
			if (strlen ($id) === $len)
				return $id;
		}
	}
}

function newSessionId()
{
	return createToken(40);
}

function newDeviceId()
{
	return createToken(40);
}


function userDataSources ()
{
	$userPassword = FALSE;
	$headers = e10GetAllHeaders();

	if (isset ($headers['e10-login-user']))
	{
		$userLogin = base64_decode($headers['e10-login-user']);
		$userPassword = base64_decode($headers['e10-login-pw']);
	}
	else
	if (isset($_SERVER['PHP_AUTH_USER']))
	{
		$userLogin = $_SERVER['PHP_AUTH_USER'];
		$userPassword = $_SERVER['PHP_AUTH_PW'];
	}

	$data = array ('success' => 0);
	$r = array ();
	$r ['objectType'] = 'call';

	if ($userPassword !== FALSE)
	{
		$emailHash = md5(strtolower(trim($userLogin)));
		$user = dibi::query ("SELECT * FROM [e10_persons_persons] WHERE [loginHash] = %s", $emailHash)->fetch ();

		if ($user)
		{
			$pw = dibi::query ('SELECT * FROM [e10_persons_userspasswords] WHERE [pwType] = 0 AND [person] = %i', $user['ndx'])->fetch ();
			if ($pw)
			{
				if (checkPassword($userPassword, $pw))
				{
					$data ['success'] = 1;

					$site = 0;

					$q[] = 'SELECT usersds.*, ds.name AS dsName, ds.shortName AS dsShortName, ds.docState AS docState, ds.urlWeb AS urlWeb, ds.urlApp AS urlApp,';
					array_push ($q, ' ds.docStateMain AS docStateMain, ds.gidstr as dsGid, ds.imageUrl as dsImageUrl ');
					array_push ($q, ' FROM [e10pro_hosting_server_usersds] as usersds ');
					array_push ($q, ' RIGHT JOIN e10pro_hosting_server_datasources as ds ON usersds.datasource = ds.ndx ');
					array_push ($q, ' WHERE usersds.user = %i', $user ['ndx'], ' AND ds.docState = 4000', ' AND usersds.docState = 4000');
					if ($site)
						array_push ($q, ' AND site = '.$site);
					array_push ($q, ' ORDER BY ds.name, ds.ndx');

					$dataSources = dibi::query ($q);
					forEach ($dataSources as $ds)
					{
						$data ['dataSources'][] = [
								'name' => $ds ['dsName'], 'shortName' => $ds ['dsShortName'], 'urlApp' => $ds ['urlApp'],
								'dsGid' => $ds ['dsGid'], 'imageUrl' => $ds ['dsImageUrl']
						];
					}
					$data ['userName'] = $user ['fullName']; // TODO: remove some next version
					$data ['userInfo'] = ['name' => $user ['fullName'], 'login' => $user ['login']];
				}
			}
		}
	}

	$r ['data'] = $data;

	echo json_encode ($r);
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: e10-login-user, e10-login-pw');

connectDb ();

$operation = testGetParam ('op');

switch ($operation)
{
	case 'checkLogin':
					checkUserLogin (); return;
	case 'userDataSources':
					userDataSources (); return;
}

$data = array ('success' => 0);
$r = array ();
$r ['objectType'] = 'call';
$r ['data'] = $data;
echo json_encode ($r);
