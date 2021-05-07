<?php


namespace swdev\dm;

use e10\CfgManager, \e10\utils;


/**
 * Class SwDevCfgManager
 * @package swdev\dm
 */
class SwDevCfgManager extends CfgManager
{
	/** @var \e10\cli\Application */
	var $app = NULL;
	var $devServerCfg = NULL;

	function uploadModule($data)
	{
		$apiData = ['object-class-id' => 'swdev.dm.UploaderDataModel', 'operation' => 'upload', 'type' => 'module', 'data' => $data];
		$result = $this->app->runApiCall($this->devServerCfg['devServerUrl'] . '/api', $this->devServerCfg['devServerApiKey'], $apiData);
		if (!$result || !isset($result['success']) || $result['success'] !== 1)
		{
			$this->app->err("ERROR!!!");
		}
	}

	function uploadTable($data)
	{
		echo "  - {$data['id']} / {$data['name']}\n";
		$apiData = ['object-class-id' => 'swdev.dm.UploaderDataModel', 'operation' => 'upload', 'type' => 'table', 'data' => $data];
		$result = $this->app->runApiCall($this->devServerCfg['devServerUrl'] . '/api', $this->devServerCfg['devServerApiKey'], $apiData);
		if (!$result || !isset($result['success']) || $result['success'] !== 1)
		{
			$this->app->err("ERROR!!!");
		}

	}

	protected function checkSwDev ($operation, $type, $data)
	{
//		$this->checkSwDev ('load', 'module', $moduleCfg);
		//echo " * $operation $type \n";

		switch ($type)
		{
			case 'module': $this->uploadModule($data); break;
			case 'table': $this->uploadTable($data); break;
		}
	}

	public function upload ()
	{
		$this->load();
	}
}

