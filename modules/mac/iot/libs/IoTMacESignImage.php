<?php

namespace mac\iot\libs;
use \Shipard\Base\Utility;


/**
 * class IoTMacESignImage
 */
class IoTMacESignImage extends Utility
{
	public $result = ['success' => 0];
	var $requestParams = NULL;

	function init()
	{
	}

	function setResult($msg)
	{
		$this->result ['msg'] = $msg;
		return FALSE;
	}

	public function setRequestParams($params)
	{
		if ($params === NULL)
		{
			$requestParamsStr = $this->app()->postData();
			$requestParams = json_decode ($requestParamsStr, TRUE);
			if ($requestParams)
			{
				$this->requestParams = $requestParams;
				return TRUE;
			}
		}

		$this->requestParams = $params;

		return FALSE;
	}

	function checkRequestParams()
	{
		if (!$this->requestParams)
			return $this->setResult('Missing request params');

		if (!isset($this->requestParams['esignNdx']))
			return $this->setResult('Missing `esignNdx` param');

		return TRUE;
	}

	protected function doRequest()
	{
		$esignRecData = $this->app()->loadItem(intval($this->requestParams['esignNdx']), 'mac.iot.esigns');
		if (!$esignRecData)
			return $this->setResult('Invalid `esignNdx` param `'.$this->requestParams['esignNdx'].'`');

    $esignImageRecData = $this->app()->loadItem(intval($this->requestParams['esignNdx']), 'mac.iot.esignsImgs');
		if (!$esignImageRecData)
			return $this->setResult('Invalid imageData for esign `'.$this->requestParams['esignNdx'].'`');

		// -- refresh image?
		if (1)
		{
			$e = new \mac\iot\libs\ESignImageEngine($this->app());
			$e->setESign(intval($this->requestParams['esignNdx']));
			$e->doIt();

			$esignImageRecData = $this->app()->loadItem(intval($this->requestParams['esignNdx']), 'mac.iot.esignsImgs');
			if (!$esignImageRecData)
				return $this->setResult('Invalid imageData for esign `'.$this->requestParams['esignNdx'].'`');
		}

		$this->result['esignImg'] = $esignImageRecData;
    $this->result['success'] = 1;

		return TRUE;
	}

	public function run ()
	{
		$this->init();

		if (!$this->setRequestParams(NULL))
			return;

		$this->doRequest();
	}
}
