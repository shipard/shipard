<?php

namespace hosting\core\libs\api;
use \e10\base\libs\UtilsBase;
use e10\Response;
use \Shipard\Utils\Utils;


/**
 * confirmNewDataSource
 *
 */
function confirmNewDataSource ($app)
{
	$dsid = $app->testGetParam ('dsid');
	$serverId = intval ($app->testGetParam ('serverId'));

	$code = '';

	$r = new \Shipard\Application\Response ($app);
	$r->setMimeType('application/json');
	$r->add ('objectType', 'call');

	if ($dsid != '')
	{
		$ds = $app->db()->query ('SELECT * FROM [hosting_core_dataSources] WHERE [gid] = %s', $dsid)->fetch ();
		$server = $app->db()->query ('SELECT * FROM [hosting_core_servers] WHERE [ndx] = %i', $serverId)->fetch ();

		if ($ds)
		{
			$urlApp = 'https://' . $server ['fqdn'] . '/' . $ds ['gid'] . '/';

			// set data source state and server
			$app->db()->query ('UPDATE [hosting_core_dataSources] SET [inProgress] = 0, [docState] = 4000, [docStateMain] = 2, [condition] = 1, [server] = %i, ', $serverId,
							'[urlApp] = %s ', $urlApp, ' WHERE [ndx] = %i', $ds ['ndx']);

			// check admin connect
			$userdsRecData = $app->db()->query ('SELECT * FROM [hosting_core_dsUsers] WHERE [user] = %i AND [dataSource] = %i',
																					$ds['admin'], $ds['ndx'])->fetch();
			if (!$userdsRecData)
			{
				$newLinkedDataSource = [
					'user' => $ds['admin'], 'dataSource' => $ds['ndx'],
					'created' => new \DateTime(), 'docState' => 4000, 'docStateMain' => 2
				];
				$app->db()->query ('INSERT INTO [hosting_core_dsUsers]', $newLinkedDataSource);
			}

			// -- docsLog
			/** @var \hosting\core\TableDataSources $tableDataSources */
			$tableDataSources = $app->table('hosting.core.dataSources');
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
 * Funtion getDataSourceInfo
 * @param mixed $app
 * @return Response
 */
function getDataSourceInfo ($app)
{
	$tablePartners = $app->table ('hosting.core.partners');

	$data = ['count' => 0, 'datasources' => []];

	$response = new \Shipard\Application\Response ($app);
	$response->setMimeType('application/json');
	$response->add ('objectType', 'call');
	$dsid = $app->testGetParam ('dsid');

	$q[] = 'SELECT * FROM [hosting_core_dataSources] WHERE 1';
	array_push ($q, ' AND [gid] = %s', $dsid);
	array_push ($q, ' ORDER BY [ndx]');

	$rows = $app->db()->query ($q);
	foreach ($rows as $r)
	{
		$hostingGid = '96690501241477'; // TODO: remove in next generation
		$hostingDomain = $app->cfgItem ('e10pro.hosting.domain');

		$partnerNdx = ($r['partner']) ? $r ['partner'] : 1;
		$partnerInfo = $tablePartners->partnerInfo ($partnerNdx);
		$portalInfo = ['supportPhone' => '+420 774 020 522', 'supportEmail' => 'podpora@shipard.cz', 'supportUrl' => 'https://shipard.cz/', 'name' => 'Shipard'];

		$image = UtilsBase::getAttachmentDefaultImage ($app, 'hosting.core.dataSources', $r ['ndx']);
		$newds = [
			'dsid' => strval($r['gid']),
			'name' => $r['name'], 'shortName' => (($r['shortName'] !== '') ? $r['shortName'] : $r['name']),
			'domain' => $r['dsId1'], 'dsId1' => $r['dsId1'],
			'hosting' => $hostingGid, 'hostingDomain' => $hostingDomain,

			'dsType' => $r['dsType'], 'condition' => $r['condition'], 'helpdeskMode' => $r['helpdeskMode'],
			'created' => Utils::dateIsBlank($r['dateCreated']) ? NULL : $r['dateCreated']->format ('Y-m-d'),

			'supportName' => ($partnerInfo['name'] !== '') ? $partnerInfo['name'] : $portalInfo['name'],

			'supportPhone' => ($partnerInfo['supportPhone'] !== '') ? $partnerInfo['supportPhone'] : $portalInfo['supportPhone'],
			'supportEmail' => ($partnerInfo['supportEmail'] !== '') ? $partnerInfo['supportEmail'] : $portalInfo['supportEmail'],
			'supportUrl' => ($partnerInfo['webUrl'] !== '') ? $partnerInfo['webUrl'] : $portalInfo['supportUrl'],
			'supportSection' => 0,

			'dsIconServerUrl' => $r['dsIconServerUrl'], 'dsIconFileName' => $r['dsIconFileName'],

			'partner' => $partnerInfo, 'portalInfo' => $portalInfo
		];

		if ($newds['dsIconServerUrl'] === '')
			$newds['dsIconServerUrl'] = 'https://shipard.com/';
		if ($newds['dsIconFileName'] === '')
			$newds['dsIconFileName'] = 'e10-modules/e10templates/web/shipard1/files/shipard/icon-page-hp.svg';

		switch ($r['appWarning'])
		{
			case 1: $newds['appWarning'] = ['text' => 'Provoz aplikace dosud nebyl uhrazen. Prosím zkontrolujte Vaše platby. Děkujeme.', 'icon' => 'system/iconWarning'];
							break;
			case 2: $newds['appWarning'] = ['text' => 'Uhraďte prosím neprodleně dlužnou částku za provoz aplikace. Děkujeme.', 'icon' => 'system/iconWarning'];
							break;
			case 3: $newds['appWarning'] = ['text' => 'Zkušební lhůta vyprší '.Utils::datef($r['dateTrialEnd']).'. Pokud chcete databázi zachovat, napište nám na podpora@shipard.cz. Děkujeme.', 'icon' => 'system/iconWarning'];
							break;
			case 4: $newds['appWarning'] = ['text' => 'Zkušební lhůta vypršela. Pokud chcete databázi zachovat, napište nám na podpora@shipard.cz. Děkujeme.', 'icon' => 'system/iconWarning'];
							break;
		}

		if (isset($image['fileName']))
			$newds['dsimage'] = $app->cfgItem ('hostingServerUrl').'imgs/-w256/att/'.$image['fileName'];
		else
			$newds['dsimage'] = 'https://shipard.com/e10-modules/e10templates/web/shipard1/files/shipard/icon-page-hp.svg';

		// -- ds certs
		$certsBaseFileName = '/var/lib/shipard/hosting/certs/pkg-ds-'.$r['gid'];
		$certsContent = Utils::loadCfgFile($certsBaseFileName.'.json');
		if ($certsContent)
		{
			$certsInfo = Utils::loadCfgFile($certsBaseFileName.'.info');
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


/**
 * Function getHostingInfo
 *
 * @param mixed $app
 * @return Response
 */
function getHostingInfo ($app)
{
	$response = new \Shipard\Application\Response ($app);
	$response->setMimeType('application/json');
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
	$data = ['count' => 0];

	$r = new \Shipard\Application\Response ($app);
	$r->setMimeType('application/json');
	$r->add ('objectType', 'call');

	if ($addressId != '')
	{
		$request = $app->db()->query ('SELECT * FROM [hosting_core_dataSources] WHERE ([gid] = %s OR [dsId1] = %s OR [dsId2] = %s) AND docState IN (4000, 8000) LIMIT 0, 1', $addressId, $addressId, $addressId)->fetch ();

		if ($request)
		{
			$data ['url'] = $request ['urlApp'];
		}
	}

	$r->add ('data', $data);

	return $r;
}
