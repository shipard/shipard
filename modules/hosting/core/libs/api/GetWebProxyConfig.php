<?php

namespace hosting\core\libs\api;
use \Shipard\Base\Utility, \Shipard\Application\Response;


/**
 * class GetWebProxyConfig
 */
class GetWebProxyConfig extends Utility
{
	var $object = [];

	public function init ()
	{
	}

	public function run ()
	{
		$serverGID = strval($this->app->requestPath(2));
		$serverApiKey = strval($this->app->requestPath(3));

    $serverItem = NULL;
    $serverItem = $this->db()->query ('SELECT * FROM [hosting_core_servers] WHERE [docState] = 4000 ',
																			'AND [gid] = %s', $serverGID, ' AND [apiDownloadKey] = %s', $serverApiKey)->fetch ();

    if (!$serverItem)
    {
      $this->object['error'] = 1;
      $this->object['errMsg'] = 'Server `'.$serverGID.'` not found or invalid API key';
    }
		else
		{
			$c = new \hosting\core\libs\WebProxyCfgCreator($this->app());
			$c->setServer($serverItem['ndx']);
			$c->create();

			$this->object['error'] = 0;
			$this->object['webProxyConfig'] = $c->cfg;
		}

		$response = new Response ($this->app);
		$response->setMimeType('application/json');
		$response->add ('objectType', 'webProxyConfig');
		$response->add ('object', $this->object);

		return $response;
	}
}
