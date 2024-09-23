<?php

namespace Shipard\Base;


/**
 * class ApiObject2
 */
class ApiObject2 extends Utility
{
	var $requestParams = [];
	var $result = ['success' => 0];

	public function setRequestParams($params)
	{
		foreach ($params as $key => $value)
			$this->requestParams[$key] = $value;
	}

	public function createResponseContent($response)
	{
	}

	public function requestParam($paramKey, $defaultValue = NULL)
	{
		if (isset($this->requestParams[$paramKey]))
			return $this->requestParams[$paramKey];

		return $defaultValue;
	}

	public function run()
	{
	}
}
