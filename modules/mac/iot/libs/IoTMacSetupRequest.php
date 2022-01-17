<?php

namespace mac\iot\libs;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class IoTMacSetupRequest
 */
class IoTMacSetupRequest extends Utility
{
	public $result = ['success' => 0];
	var $requestParams = NULL;

	function init()
	{
		$this->now = new \DateTime();
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

		if (!isset($this->requestParams['srcPayload']))
			return $this->setResult('Missing `srcPayload` param');

		if (!isset($this->requestParams['request']))
			return $this->setResult('Missing `request` param');

		if (!isset($this->requestParams['setup']))
			return $this->setResult('Missing `setup` param');

		return TRUE;
	}

	protected function doRequest()
	{
		$setupRecData = $this->app()->loadItem(intval($this->requestParams['setup']), 'mac.iot.setups');
		if (!$setupRecData)
			return $this->setResult('Invalid `setup` param `'.$this->requestParams['setup'].'`');
		
		$setupType = $this->app()->cfgItem('mac.iot.setups.types.'.$setupRecData['setupType'], NULL);
		if (!$setupType)
			return $this->setResult('Invalid setupType `'.$setupRecData['setupType'].'` for setup `'.$this->requestParams['setup'].'`');
		
		$requestType = $this->requestParams['request'];	
		if (!isset($setupType['requests'][$requestType]))
			return $this->setResult('Invalid request `'.$requestType.'` for setup `'.$this->requestParams['setup'].'`');

		$classId = $setupType['requests'][$requestType]['classId'] ?? NULL;
		if (!$classId)
			return $this->setResult('Missing classId for request `'.$requestType.'` in setup `'.$this->requestParams['setup'].'`');
		
		$requestObject = $this->app()->createObject ($classId);
		if (!$requestObject)
			return $this->setResult('Wrong classId for request `'.$requestType.'` in setup `'.$this->requestParams['setup'].'` - server error');

		$requestObject->setRequestParams($this->requestParams);
		$requestObject->run();

		$this->result = $requestObject->result;

		return TRUE;	
	}

	public function run ()
	{
		$this->init();

		if (!$this->setRequestParams(NULL))
			return;
		$this->doRequest();	
		//$this->requestParamsValid = $this->check();

		//if ($this->requestParamsValid)
		//	$this->check();
	}
}
