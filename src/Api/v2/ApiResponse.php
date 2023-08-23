<?php

namespace Shipard\Api\v2;


class ApiResponse extends \Shipard\Base\Utility
{
  var array $requestParams;
  var array $responseData = [];

  var $uiRouter = NULL;

  public function setRequestParams(array $requestParams)
  {
    $this->requestParams = $requestParams;
  }

  protected function checkRequestParams()
  {
  }

  protected function requestParam($paramKey)
  {
    return $this->requestParams[$paramKey] ?? NULL;
  }

  public function run()
  {

  }
}

