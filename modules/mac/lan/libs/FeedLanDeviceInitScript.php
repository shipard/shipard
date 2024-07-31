<?php

namespace mac\lan\libs;
use \Shipard\Utils\Json, \Shipard\Base\Utility, \Shipard\Application\Response;


/**
 * Class FeedLanDeviceInitScript
 */
class FeedLanDeviceInitScript extends Utility
{
	var $result = [];

	public function init()
	{
	}

	public function createScript()
	{
		$deviceNdx = intval($this->app->requestPath(2));
		if (!$deviceNdx)
    {
      $this->result['msg'] = 'Missing or invalid deviceNdx `'.$deviceNdx.'`';
			return;
    }

    $deviceRecData = $this->app()->loadItem($deviceNdx, 'mac.lan.devices');
    if (!$deviceRecData)
    {
      $this->result['msg'] = 'Device `'.$deviceNdx.'` not exist';
			return;
    }

    $authToken = $this->app->requestPath(3);
		if ($authToken === '')
    {
      $this->result['msg'] = 'No auth token';
			return;
    }

    $te = new \mac\admin\libs\TokensEngine($this->app());
    $validTokens = $te->loadLANValidTokens($deviceRecData['lan']);
    if (!in_array($authToken, $validTokens))
    {
      $this->result['msg'] = 'Invalid auth token';
			return;
    }

		$scriptGenerator = new \mac\lan\libs\LanCfgDeviceScriptGenerator($this->app());
		$scriptGenerator->init();
		$scriptGenerator->setDevice($deviceNdx, NULL, TRUE);

		$initScript = '';
		if ($scriptGenerator->dsg)
			$initScript = $scriptGenerator->dsg->initScriptFinalized();

    $this->result ['initScript'] = $initScript;
	}

	public function run ()
	{
		$this->createScript();

		$response = new Response ($this->app);

    $data = '';
    $status = 200;
    if (!isset($this->result ['initScript']) || $this->result ['initScript'] === '')
    {
      $data = Json::encode($this->result);
      $status = 404;
    }
    else
    {
      $data = $this->result ['initScript'];
    }

		$response->setRawData($data);
    $response->setStatus($status);
		return $response;
	}
}
