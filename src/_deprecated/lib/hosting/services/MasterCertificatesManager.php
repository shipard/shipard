<?php

namespace lib\hosting\services;
use e10\Utility, e10\json, \e10\utils;


/**
 * Class MasterCertificatesManager
 * @package lib\hosting\services
 */
class MasterCertificatesManager extends Utility
{
	var $infoCurrentVersion = 1;
	var $basePath = '/var/lib/shipard/hosting/certs/';

	public function scan()
	{
		$activeDir = $this->basePath.'active';
		if (!is_dir($activeDir))
			mkdir($activeDir, 0750, TRUE);
		chgrp($activeDir, utils::wwwGroup());

		$scanMask = $this->basePath . 'crt/*';
		forEach (glob($scanMask, GLOB_ONLYDIR) as $certDir)
		{
			$certName = substr(strrchr($certDir, '/'), 1);
			//echo $certName.": ".$certDir."\n";

			$this->certToActive($certName);
		}

		$this->createCertsPack('hosting', ['all.shipard.app', 'all.shipard.pro', 'all.shipard.cz', 'all.shipard.com']);
		$this->createDataSourcesPacks();
	}

	function certShipardInfo($certName)
	{
		$infoFileName = $this->basePath.'crt/'.$certName.'/_shipard.info';
		$info = utils::loadCfgFile($infoFileName);

		if (!$info || $info['ver'] !== $this->infoCurrentVersion)
			$info = $this->certShipardInfoCreate($certName);

		return $info;
	}

	function certShipardInfoCreate($certName)
	{
		$info = ['ver' => $this->infoCurrentVersion];
		$infoFileName = $this->basePath.'crt/'.$certName.'/_shipard.info';

		$files = $this->loadSrcCertFiles($certName);

		$all = '';
		foreach ($files as $f)
			$all .= $f;

		$info['filesCheckSum'] = md5($all);
		file_put_contents($infoFileName, json::lint($info));

		return $info;
	}

	function loadSrcCertFiles($certName)
	{
		$files = [];

		$srcCertPath = $this->basePath.'crt/'.$certName;

		$files['cert.pem'] = file_get_contents($srcCertPath.'/'.'cert.pem'); // --> ssl_certificate
		$files['privkey.pem'] = file_get_contents($srcCertPath.'/'.'privkey.pem'); // --> ssl_certificate_key
		$files['chain.pem'] = file_get_contents($srcCertPath.'/'.'fullchain.pem'); // --> ssl_trusted_certificate

		return $files;
	}

	function certToActive ($certName)
	{
		$doIt = FALSE;

		$srcInfo = $this->certShipardInfo($certName);

		$dstCertPath = $this->basePath.'active/'.$certName;

		$dstInfoFileName = $dstCertPath.'/_shipard.info';
		$dstInfo = utils::loadCfgFile($dstInfoFileName);

		if (!$dstInfo || $srcInfo['filesCheckSum'] !== $dstInfo['filesCheckSum'] || $dstInfo['filesCheckSum'] !== $this->infoCurrentVersion)
			$doIt = 1;

		if (!$doIt)
			return;

		$cert = ['filesCheckSum' => $srcInfo['filesCheckSum']];
		$cert['files'] = $this->loadSrcCertFiles($certName);

		if (!is_dir($dstCertPath))
			mkdir($dstCertPath, 0750, TRUE);

		chgrp($dstCertPath, utils::wwwGroup());

		file_put_contents($dstCertPath.'/cert.json', json::lint($cert));
		file_put_contents($dstInfoFileName, json::lint($srcInfo));

		chgrp($dstCertPath.'/cert.json', utils::wwwGroup());
	}

	public function loadCertificates($certs)
	{
		$data = [];
		foreach ($certs as $certName)
		{
			$activeCertPath = $this->basePath.'active/'.$certName;

			$cert = utils::loadCfgFile($activeCertPath.'/cert.json');
			if ($cert)
				$data[$certName] = $cert;
		}

		return $data;
	}

	public function createCertsPack ($name, $certs)
	{
		$package = $this->loadCertificates($certs);
		if (!count($package))
			return;

		$fn = '/var/lib/shipard/hosting/certs/pkg-'.$name.'.json';
		file_put_contents($fn, json::lint($package));

		$ver = ['checkSum' => sha1_file($fn)];
		$fnVer = '/var/lib/shipard/hosting/certs/pkg-'.$name.'.info';
		file_put_contents($fnVer, json::lint($ver));
	}

	function createDataSourcesPacks()
	{
		$q [] = 'SELECT certs.*, ds.gid AS dsGid FROM [mac_inet_certs] AS certs';
		array_push ($q, ' LEFT JOIN [hosting_core_dataSources] AS ds ON certs.dataSource = ds.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND certs.[docState] = %i', 4000);
		array_push ($q, ' AND ds.[docState] = %i', 4000);
		array_push ($q, ' AND [dataSource] != %i', 0);
		array_push ($q, ' ORDER BY dataSource, certs.ndx');

		$rows = $this->db()->query($q);
		$lastDS = -1;
		$dsGid = -1;
		$dsPackCerts = [];
		foreach ($rows as $r)
		{
			$dsGid = $r['dsGid'];
			if ($dsGid !== $lastDS && $lastDS !== -1)
			{
				$this->createCertsPack ('ds-'.$lastDS, $dsPackCerts);
				$dsPackCerts = [];
			}

			$dsPackCerts[] = ($r['fileId'] !== '') ? $r['fileId'] : $r['hostAscii'];

			$lastDS = $r['dsGid'];
		}

		if (count($dsPackCerts))
			$this->createCertsPack ('ds-'.$lastDS, $dsPackCerts);
	}
}
