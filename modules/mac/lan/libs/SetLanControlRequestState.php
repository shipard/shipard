<?php

namespace mac\lan\libs;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class SetLanControlRequestState
 * @package mac\lan\libs
 */
class SetLanControlRequestState extends Utility
{
	public $result = ['success' => 0];

	public function run ()
	{
		$serverNdx = intval($this->app->requestPath(4));
		if (!$serverNdx)
			return;

		$data = json_decode($this->app()->postData(), TRUE);
		if (!$data || !$data['device'])
			return;

		$lc = new \mac\lan\libs\LanControlCfgUpdater($this->app());
		$lc->setRequestState($data);

		$this->result ['success'] = 1;
	}
}
