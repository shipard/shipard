<?php

namespace Shipard\Base;


class ApiObject extends BaseObject
{
	var $objectClassId = '';
	var $requestParams = [];

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
}
