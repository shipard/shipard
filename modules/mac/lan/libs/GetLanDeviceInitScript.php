<?php

namespace mac\lan\libs;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class GetLanDeviceInitScript
 */
class GetLanDeviceInitScript extends Utility
{
	public $result = ['success' => 0];
	var $lanCfg = NULL;

	public function run ()
	{
		$deviceNdx = intval($this->app->requestPath(4));
		if (!$deviceNdx)
			return;

		$saveAsText = intval($this->app()->testGetParam('save'));

		$scriptGenerator = new \mac\lan\libs\LanCfgDeviceScriptGenerator($this->app());
		$scriptGenerator->init();
		$scriptGenerator->setDevice($deviceNdx, NULL, TRUE);

		$initScript = '';
		if ($scriptGenerator->dsg)
			$initScript = $scriptGenerator->dsg->initScriptFinalized();

		if ($saveAsText)
		{
			$this->result ['forceTextData'] = $initScript;
			$this->result ['forceTextDataSaveFileName'] = 'init-'.$deviceNdx.'.rsc';
		}
		else
			$this->result ['initScript'] = $initScript;

		$this->result ['success'] = 1;
	}
}
