<?php

namespace mac\lan\libs;

use \Shipard\Base\Utility;


/**
 * Class GetLanAuthTokens
 */
class GetLanAuthTokens extends Utility
{
	public $result = ['success' => 0];

	public function run ()
	{
		$serverNdx = intval($this->app->requestPath(4));
		if (!$serverNdx)
    {
      $this->result['msg'] = 'Invalid / missing `serverNdx` param';
      return;
    }

    $serverRecData = $this->app()->loadItem($serverNdx, 'mac.lan.devices');
    if (!$serverRecData)
    {
      $this->result['msg'] = 'Server `'.$serverNdx.'` not exist';
      return;
    }

    $te = new \mac\admin\libs\TokensEngine($this->app());
    $validTokens = $te->loadLANValidTokens($serverRecData['lan']);

    $this->result ['tokens'] = $validTokens;

		$this->result ['success'] = 1;
	}
}
