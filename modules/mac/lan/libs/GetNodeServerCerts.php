<?php

namespace mac\lan\libs;

use e10\Utility;


/**
 * Class GetNodeServerCerts
 * @package mac\lan
 */
class GetNodeServerCerts extends Utility
{
	public $result = ['success' => 0, 'test' => 'abcde'];

	public function run ()
	{
		$serverNdx = intval($this->app->requestPath(4));
		$cfg = $this->db()->query ('SELECT * FROM [mac_lan_devicesCfgNodes] WHERE device = %i', $serverNdx)->fetch();

		if ($cfg)
		{
			$certs = [];

			$this->addCertItem ('/var/lib/shipard/certs/', 'all.shipard.pro', $certs);


			$this->result ['certificates'] = $certs;
			$this->result ['success'] = 1;
		}
	}

	protected function addCertItem ($path, $certId, &$dest)
	{
		$files = [];

		$srcCertPath = $path.'/'.$certId;

		$files['cert.pem'] = file_get_contents($srcCertPath.'/'.'cert.pem'); // --> ssl_certificate
		$files['privkey.pem'] = file_get_contents($srcCertPath.'/'.'privkey.pem'); // --> ssl_certificate_key
		$files['chain.pem'] = file_get_contents($srcCertPath.'/'.'chain.pem'); // --> ssl_trusted_certificate

		$cert = ['files' => $files];

		$dest[$certId] = $cert;
	}
}
