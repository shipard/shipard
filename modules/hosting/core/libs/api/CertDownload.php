<?php

namespace hosting\core\libs\api;

use \Shipard\Utils\Utils, \Shipard\Base\Utility, \Shipard\Application\Response;


/**
 * Class CertDownload
 */
class CertDownload extends Utility
{
  var $apiKey = '';
  var $certId = '';
  var $certRecData = NULL;
  var $cert = NULL;

	var $object = [];

	protected function getCertificate ()
	{
    $cr = $this->db()->query('SELECT * FROM [mac_inet_certs] WHERE [apiDownloadEnabled] = %i', 1,
                              ' AND [apiDownloadKey] = %s', $this->apiKey,
                              ' AND [apiDownloadID] = %s', $this->certId,
                              ' AND [docState] = %i', 4000)->fetch();
    if (!$cr)
    {
      $this->object['error'] = 1;
      $this->object['errMsg'] = 'cert id not found';
      return;
    }

    $this->certRecData = $cr->toArray();

		$fn = '/var/lib/shipard/hosting/certs/active/';
		if ($this->certRecData['fileId'] !== '')
			$fn .= $this->certRecData['fileId'];
		else
			$fn .= $this->certRecData['host'];
		$fn .= '/cert.json';

		$this->cert = Utils::loadCfgFile($fn);
		if (!$this->cert)
		{
      $this->object['error'] = 2;
      $this->object['errMsg'] = 'cert file not exist';
			return;
		}

    $this->object['error'] = 0;
    $this->object['cert'] = $this->cert;
	}

	public function init ()
	{
    $this->apiKey = $this->app->testGetParam('key');
    $this->certId = $this->app->testGetParam('id');
	}

	public function run ()
	{
		$this->getCertificate();

		$response = new Response ($this->app);
		$response->add ('objectType', 'cert');
		$response->add ('object', $this->object);
		return $response;
	}
}
