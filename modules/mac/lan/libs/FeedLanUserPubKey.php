<?php

namespace mac\lan\libs;
use \Shipard\Utils\Json, \Shipard\Base\Utility, \Shipard\Application\Response;


/**
 * Class FeedLanUserPubKey
 */
class FeedLanUserPubKey extends Utility
{
	var $result = [];

	public function init()
	{
	}

	public function getKey()
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

		$lanUserNdx = intval($this->app->requestPath(3));
		if (!$lanUserNdx)
    {
      $this->result['msg'] = 'Missing or invalid userNdx `'.$lanUserNdx.'`';
			return;
    }
		$pubKeyNdx = intval($this->app->requestPath(4));
		if (!$pubKeyNdx)
    {
      $this->result['msg'] = 'Missing or invalid pubKeyNdx `'.$pubKeyNdx.'`';
			return;
    }

    $lanUserRecData = $this->app()->loadItem($lanUserNdx, 'mac.admin.lanUsers');
    if (!$lanUserRecData)
    {
      $this->result['msg'] = 'lanUser `'.$lanUserNdx.'` not exist';
			return;
    }

    $pubKeyRecData = $this->app()->loadItem($pubKeyNdx, 'mac.admin.lanUsersPubKeys');
    if (!$pubKeyRecData || $pubKeyRecData['lanUser'] != $lanUserNdx)
    {
      $this->result['msg'] = 'pubKey `'.$pubKeyNdx.'` not exist';
			return;
    }

    $authToken = $this->app->requestPath(5);
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

    $this->result ['key'] = $pubKeyRecData['key'];
	}

	public function run ()
	{
		$this->getKey();

		$response = new Response ($this->app);

    $data = '';
    $status = 200;
    if (!isset($this->result ['key']) || $this->result ['key'] === '')
    {
      $data = Json::encode($this->result);
      $status = 404;
    }
    else
    {
      $data = $this->result ['key'];
    }

		$response->setRawData($data);
    $response->setStatus($status);
		return $response;
	}
}
