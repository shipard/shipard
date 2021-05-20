<?php

namespace lib\server;


use \e10\Utility, \Shipard\Utils\Utils;


/**
 * Class SaveHostingInfo
 * @package lib\server
 */
class SaveHostingInfo extends Utility
{
	var $certsBasePath = '/var/lib/shipard/certs/';

	var $data = NULL;

	public function setData ($hostingInfo)
	{
		$this->data = $hostingInfo;
	}

	function check()
	{
		if (!is_dir($this->certsBasePath))
		{
			Utils::mkDir($this->certsBasePath, 0755);
		}
	}

	function installCertificates()
	{
		if (!isset($this->data['certificates']) || !count($this->data['certificates']))
			return;

		foreach ($this->data['certificates'] as $certName => $cert)
		{
			$certPath = $this->certsBasePath.$certName.'/';
			if (!is_dir($certPath))
			{
				Utils::mkDir($certPath, 0755);
			}

			foreach ($cert['files'] as $fileName => $fileContent)
			{
				file_put_contents($certPath.$fileName, $fileContent);
			}
		}
	}

	public function run()
	{
		$this->check();
		$this->installCertificates();
	}
}
