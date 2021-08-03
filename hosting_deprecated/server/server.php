<?php

namespace E10Pro\Hosting\Server;

require_once __APP_DIR__ . '/e10-modules/e10pro/hosting/server/tables/modules.php';
require_once __APP_DIR__ . '/e10-modules/e10/persons/persons.php';

use \E10\Application, \E10\utils;


/**
 * confirmNewDataSource
 *
 */

function confirmNewDataSource ($app)
{
	$dsid = $app->testGetParam ('dsid');
	$serverId = intval ($app->testGetParam ('serverId'));

	$code = '';

	$r = new \E10\Response ($app);
	$r->add ("objectType", "call");

	if ($dsid != '')
	{
		$ds = $app->db()->query ("SELECT * FROM [e10pro_hosting_server_datasources] WHERE [gid] = %i", $dsid)->fetch ();
		$server = $app->db()->query ("SELECT * FROM [e10pro_hosting_server_servers] WHERE [ndx] = %i", $serverId)->fetch ();

		if ($ds)
		{
			$urlApp = 'https://' . $server ['fqdn'] . '/' . $ds ['gid'] . '/';
			$urlWeb = 'https://' . $server ['fqdn'] . '/' . $ds ['gid'] . '/';

			// set data source state and server
			$app->db()->query ("UPDATE [e10pro_hosting_server_datasources] SET [inProgress] = 0, [docState] = 4000, [docStateMain] = 2, [server] = %i, [urlWeb] = %s, [urlApp] = %s WHERE [ndx] = %i",
												 $serverId, $urlWeb, $urlApp, $ds ['ndx']);

			// check admin connect
			$userdsRecData = $app->db()->query ('SELECT * FROM [e10pro_hosting_server_usersds] WHERE [user] = %i AND [datasource] = %i',
																					$ds['admin'], $ds['ndx'])->fetch();
			if (!$userdsRecData)
			{
				$newLinkedDataSource = ['user' => $ds['admin'], 'datasource' => $ds['ndx'],
																'created' => new \DateTime(), 'docState' => 4000, 'docStateMain' => 2];
				$app->db()->query ('INSERT INTO [e10pro_hosting_server_usersds]', $newLinkedDataSource);
			}

			// -- docsLog
			/** @var \e10pro\hosting\server\TableDatasources $tableDataSources */
			$tableDataSources = $app->table('e10pro.hosting.server.datasources');
			$tableDataSources->docsLog($ds['ndx']);

			$code = "done.";
		}
		else
		{
			$code = 'Invalid dsid value.';
		}
	}
	else
	{
		$code = 'Missing dsid value.';
	}

	$r->add ("code", $code);

	return $r;
}


/**
 * getNewDataSource
 *
 */

function getNewDataSource ($app)
{
	$serverId = $app->testGetParam ('serverId');

	$doIt = TRUE;
	$serverItem = NULL;

	if ($serverId != '')
		$serverItem = $app->db()->query ('SELECT * FROM [e10pro_hosting_server_servers] WHERE [docState] = 4000 AND [ndx] = %i', $serverId)->fetch ();

	if (!$serverItem || $serverItem['creatingDataSources'] === 0)
		$doIt = FALSE;

	$data = ['count' => 0];

	$r = new \E10\Response ($app);
	$r->add ('objectType', 'call');

	if ($doIt)
	{
		$q[] = 'SELECT * FROM [e10pro_hosting_server_datasources]';
		array_push ($q, ' WHERE [docState] = %i', 1100);
		array_push ($q, ' AND [inProgress] = %i', 0);

		if ($serverItem['creatingDataSources'] === 2) // all
			array_push ($q, ' AND ([server] = %i', 0, ' OR [server] = %i)', $serverItem['ndx']);
		elseif ($serverItem['creatingDataSources'] === 1) // own only
			array_push ($q, ' AND [server] = %i', $serverItem['ndx']);

		array_push ($q, ' ORDER BY [ndx] LIMIT 0, 1');

		$request = $app->db()->query ($q)->fetch ();

		if ($request)
		{
			$app->db()->query ('UPDATE [e10pro_hosting_server_datasources] SET [inProgress] = %i', $serverItem['ndx'], ' WHERE [ndx] = %i', $request['ndx']);

			$data ['count'] = 1;
			$data ['request'] = $request;

			$data ['installModule'] = $app->cfgItem ('e10pro.hosting.modules.'.$request ['installModule'].'.id');

			$tablePersons = new \E10\Persons\TablePersons ($app);
			$data ['admin'] = $tablePersons->loadDocument ($request ['admin']);
			$data ['owner'] = $tablePersons->loadDocument ($request ['owner']);
		}
	}

	$r->add ('data', $data);

	return $r;
}

function getDataSourceInfo ($app)
{
	$tablePartners = $app->table ('e10pro.hosting.server.partners');

	$data = ['count' => 0, 'datasources' => []];

	$response = new \E10\Response ($app);
	$response->add ('objectType', 'call');
	$dsid = $app->testGetParam ('dsid');

	$q[] = 'SELECT * FROM [e10pro_hosting_server_datasources] WHERE 1';
	array_push ($q, ' AND [gid] = %i', $dsid);
	array_push ($q, ' ORDER BY [ndx]');

	$rows = $app->db()->query ($q);
	foreach ($rows as $r)
	{
		$hostingGid = $app->cfgItem ('e10pro.hosting.gid');
		$hostingDomain = $app->cfgItem ('e10pro.hosting.domain');
		$site = $app->loadItem ($r['site'], 'e10pro.hosting.server.sites');

		$partnerNdx = ($r['partner']) ? $r ['partner'] : 1;
		$partnerInfo = $tablePartners->partnerInfo ($partnerNdx);
		$portalInfo = $app->cfgItem ('e10pro.hosting.portals.portals.'.$partnerInfo['portal']); //$cfg ['e10pro']['hosting']['portals']['portals']

		$supportKind = ['name' => 'Nedostupné', 'forumLevel' => 0];
		if ($r['supportKind'])
		{
			$sk = $app->loadItem($r['supportKind'], 'e10pro.hosting.server.supportsKinds');
			if ($sk)
			{
				$supportKind['name'] = $sk['name'];
				$supportKind['forumLevel'] = $sk['forumLevel'];
			}
		}

		$image = \E10\Base\getAttachmentDefaultImage ($app, 'e10pro.hosting.server.datasources', $r ['ndx']);
		$stats = $app->db()->query ('SELECT * FROM e10pro_hosting_server_datasourcesStats WHERE datasource = %i', $r ['ndx'])->fetch();
		$newds = [
			'dsid' => strval($r['gid']),
			'name' => $r['name'], 'shortName' => (($r['shortName'] !== '') ? $r['shortName'] : $r['name']),

			'domain' => $r['dsId1'], 'dsId1' => $r['dsId1'],

			'site' => $site['gid'], 'siteDomain' => $portalInfo['portalDomain'], // todo: remove

			'hosting' => $hostingGid, 'hostingDomain' => $hostingDomain,

			'dsType' => $r['dsType'], 'condition' => $r['condition'],
			'created' => utils::dateIsBlank($r['created']) ? NULL : $r['created']->format ('Y-m-d'),

			'supportName' => ($partnerInfo['name'] !== '') ? $partnerInfo['name'] : $portalInfo['name'],
			//'supportUrl' => $site['supportUrl'],
			'supportPhone' => ($partnerInfo['supportPhone'] !== '') ? $partnerInfo['supportPhone'] : $portalInfo['supportPhone'],
			'supportEmail' => ($partnerInfo['supportEmail'] !== '') ? $partnerInfo['supportEmail'] : $portalInfo['supportEmail'],
			'supportKind' => $supportKind,
			'supportSection' => $r['supportSection'],

			'dsIconServerUrl' => $r['dsIconServerUrl'], 'dsIconFileName' => $r['dsIconFileName'],

			'partner' => $partnerInfo, 'portalInfo' => $portalInfo
		];

		if ($newds['dsIconServerUrl'] === '')
			$newds['dsIconServerUrl'] = 'https://shipard.com/';
		if ($newds['dsIconFileName'] === '')
			$newds['dsIconFileName'] = 'e10-modules/e10templates/web/shipard1/files/shipard/icon-page-hp.svg';

		/*
		$portalInfo = $app->cfgItem ('e10pro.hosting.sites.portals.'.$r['site'], FALSE);
		if ($portalInfo === FALSE)
			$portalInfo = $app->cfgItem ('e10pro.hosting.sites.portals.1', []);
		$newds['portalInfo'] = $portalInfo;
		*/

		if ($stats)
		{
			$dsStats = $stats->toArray();
			unset ($dsStats['ndx']);
			unset ($dsStats['datasource']);
			$dsStats['dateUpdate'] = $dsStats['dateUpdate']->format('Y-m-d H:i:s');
			$newds['dsStats'] = $dsStats;
			unset ($newds['dsStats']['data']);
		}

		switch ($r['appWarning'])
		{
			case 1: $newds['appWarning'] = ['text' => 'Provoz aplikace dosud nebyl uhrazen. Prosím zkontrolujte Vaše platby. Děkujeme.', 'icon' => 'icon-warning'];
							break;
			case 2: $newds['appWarning'] = ['text' => 'Uhraďte prosím neprodleně dlužnou částku za provoz aplikace. Děkujeme.', 'icon' => 'icon-warning'];
							break;
		}

		if (isset($image['fileName']))
			$newds['dsimage'] = $app->cfgItem ('hostingServerUrl').'imgs/-w256/att/'.$image['fileName'];
		else
			$newds['dsimage'] = 'https://shipard.com/e10-modules/e10templates/web/shipard1/files/shipard/icon-page-hp.svg';

		// -- ds certs
		$certsBaseFileName = '/var/lib/shipard/hosting/certs/pkg-ds-'.$r['gid'];
		$certsContent = utils::loadCfgFile($certsBaseFileName.'.json');
		if ($certsContent)
		{
			$certsInfo = utils::loadCfgFile($certsBaseFileName.'.info');
			if ($certsInfo)
			{
				$newds['certsCheckSum'] = $certsInfo['checkSum'];
				$newds['certs'] = $certsContent;
			}
		}

		$data['datasources'][] = $newds;
		$data ['count']++;
	}

	$response->add ('data', $data);
	return $response;
}

function getHostingInfo ($app)
{
	$response = new \E10\Response ($app);
	$response->add ('objectType', 'call');

	$e = new \lib\hosting\services\GetHostingInfo($app);
	$e->run();

	$response->add ('data', $e->data);
	return $response;
}

/**
 * getUploadUrl
 *
 */

function getUploadUrl ($app)
{
	$addressId = $app->testGetParam ('address');
	$data = array ('count' => 0);

	$r = new \E10\Response ($app);
	$r->add ("objectType", "call");

	if ($addressId != '')
	{
		if (is_numeric($addressId))
			$request = $app->db()->query ("SELECT * FROM [e10pro_hosting_server_datasources] WHERE [gid] = %i AND docState IN (4000, 8000) LIMIT 0, 1", $addressId)->fetch ();
		else
			$request = $app->db()->query ("SELECT * FROM [e10pro_hosting_server_datasources] WHERE ([dsId1] = %s OR [dsId2] = %s) AND docState IN (4000, 8000) LIMIT 0, 1", $addressId, $addressId)->fetch ();

		if ($request)
		{
			$data ['url'] = $request ['urlApp'];
		}
	}

	$r->add ("data", $data);

	return $r;
}

function redirectToDataSource ($app)
{
	$dsid = intval ($app->requestPath (0));
	$ds = $app->db()->query ('SELECT * FROM [e10pro_hosting_server_datasources] WHERE [gid] = %i AND docState IN (4000, 8000) LIMIT 0, 1', $dsid)->fetch ();
	if ($ds)
	{
		$part = $app->requestPath (1);
		if ($part === '')
			$part = 'app';
		$url = $ds['urlApp'].$part;
		header ('Location: ' . $url);
		die();
	}

	return ['status' => 404, 'code' => 'invalid url'];
}
