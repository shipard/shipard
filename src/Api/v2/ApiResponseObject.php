<?php

namespace Shipard\Api\v2;


/**
 * class ApiResponseObject
 */
class ApiResponseObject extends \Shipard\Api\v2\ApiResponse
{
	const psOK = 0, psBadFunctionId = 1;

	protected $functionId;
	protected $functionClass = FALSE;
	protected $status = self::psOK;

	protected function setStatus ($status, $msg = '')
	{
		$this->status = $status;
		$this->responseData ['errorCode'] = $status;
		$this->responseData ['errorMsg'] = $msg;

		return $status;
	}

	protected function checkRequestParams ()
	{
		$this->functionId = $this->requestParam('classId');
		if (!$this->functionId)
		{
			$this->setStatus(self::psBadFunctionId, 'Missing classId param');
			return;
		}

		$this->functionClass = $this->app->cfgItem ('registeredClasses.objectsCalls.'.$this->functionId, NULL);

		if (!$this->functionClass)
			$this->setStatus(self::psBadFunctionId, 'Invalid function id');
	}

	public function run ()
	{
		$this->responseData ['status'] = 0;
		$this->checkRequestParams();

		if (!$this->functionClass)
			return;

		$classId = $this->functionClass['classId'] ?? '';
		$this->responseData ['classId'] = $this->functionId;

		$object = $this->app->createObject($classId);
		$object->requestParams = $this->requestParams;

		$object->run ();
		if (is_array($object->result))
			$this->responseData = array_merge($this->responseData, $object->result);
	}
}
