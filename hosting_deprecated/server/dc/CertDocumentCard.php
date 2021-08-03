<?php

namespace e10pro\hosting\server\dc;

use \e10\utils;


/**
 * Class CertDocumentCard
 * @package e10pro\hosting\server\dc
 */
class CertDocumentCard extends \e10\DocumentCard
{
	var $certInfo = [];

	public function createContent ()
	{
		$this->loadData();
		$this->createContentBody();
	}

	function loadData()
	{
		$fn = '/var/lib/shipard/hosting/certs/active/';
		if ($this->recData['fileId'] !== '')
			$fn .= $this->recData['fileId'];
		else
			$fn .= $this->recData['host'];
		$fn .= '/cert.json';

		$certContent = utils::loadCfgFile($fn);
		if ($certContent)
		{
			$this->certInfo['data'] = openssl_x509_parse($certContent['files']['cert.pem'], 0);
		}
	}

	public function createContentBody ()
	{
		$this->createCertProperties();
		$this->addContentAttachments ($this->recData ['ndx']);
	}

	function createCertProperties()
	{
		if ($this->certInfo['data'])
		{
			$certInfoTable = [];
			$this->addCertInfo ($this->certInfo['data'], $certInfoTable);

			if (isset($this->certInfo['info']))
			{
				$t = [];
				foreach ($this->certInfo['info'] as $key => $value)
				{
					$t[] = ['c1' => $value['title'], 'c2' => $value['text']];
				}
				$h = ['c1' => 'c1', 'c2' => 'c2'];
				$this->addContent('body', [
					'pane' => 'e10-pane e10-pane-table e10-pane-top', 'type' => 'table', 'table' => $t, 'header' => $h,
					'params' => ['forceTableClass' => 'dcInfo dcInfoB fullWidth', 'hideHeader' => 1]
				]);

			}

			if (count($certInfoTable))
			{
				$h = ['c1' => 'c1', 'c2' => 'c2'];
				$this->addContent('body', [
					'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'table' => $certInfoTable, 'header' => $h,
					'params' => ['forceTableClass' => 'properties fullWidth', 'hideHeader' => 1],
					'title' => ['text' => 'Detaily certifikátu', 'class' => 'h2']
				]);
			}

		}
		else
		{
			$this->addContent('body', [
				'pane' => 'e10-pane e10-pane-table e10-pane-top', 'type' => 'line',
				'line' => [
					['text' => 'Informace o certifikátu nelze získat.', 'icon' => 'system/iconWarning', 'class' => 'h1 e10-error'],
					['text' => 'Certifikát patrně ještě nebyl vystaven...', 'class' => 'block']
				]
			]);
		}
	}

	function addCertInfo ($data, &$dstTable)
	{
		foreach ($data as $key => $value)
		{
			if ($key === 'purposes')
			{
				continue;
			}
			elseif ($key === 'validFrom_time_t')
			{
				$dt = new \DateTime('@' . $value);
				$this->certInfo['info']['validFrom'] = ['v' => $dt, 'title' => 'Platnost od', 'text' => utils::datef($dt, '%d, %T')];
			}
			elseif ($key === 'validTo_time_t')
			{
				$dt = new \DateTime('@' . $value);
				$this->certInfo['info']['validTo'] = ['v' => $dt, 'title' => 'Platnost do', 'text' => utils::datef($dt, '%d, %T')];
			}
			if (is_array($value))
			{
				$dstTable[] = ['c1' => $key, '_options' => ['class' => 'header']];

				$this->addCertInfo($value, $dstTable);
				continue;
			}

			$rows = explode("\n", $value);
			if (count($rows) <= 1)
				$dstTable[] = ['c1' => $key, 'c2' => $value];
			else
			{
				$v = [];
				foreach ($rows as $row)
				{
					$v[] = ['text' => $row, 'class' => 'block'];
				}
				$dstTable[] = ['c1' => $key, 'c2' => $v];
			}
		}
	}
}

