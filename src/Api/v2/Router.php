<?php

namespace Shipard\Api\v2;


/**
 * class Router
 */
class Router extends \Shipard\Base\Utility
{
  var ?array $requestParams = NULL;

	public function setRequestParams(?array $requestParams)
	{
    $this->requestParams = $requestParams;
  }

  protected function requestParam($paramKey)
  {
    return $this->requestParams[$paramKey] ?? NULL;
  }

  public function run()
  {
    $requestType = $this->requestParam('requestType');
    if (!$requestType)
      return new \Shipard\Application\Response ($this->app(), 'no requestType param', 404);

    /** @var \Api\v2\ApiResponse  */
    $apiResponseObject = NULL;

    switch ($requestType)
    {
      case 'widgetBoard': $apiResponseObject = new \Shipard\Api\v2\ApiResponseBoard($this->app()); break;
      case 'viewer': $apiResponseObject = new \Shipard\Api\v2\ApiResponseViewer($this->app()); break;
    }

    if (!$apiResponseObject)
      return new \Shipard\Application\Response ($this->app(), 'invalid requestType param', 404);

    $apiResponseObject->setRequestParams($this->requestParams);
    $apiResponseObject->run();

    $response = new \Shipard\Api\v2\ResponseHTTP ($this->app());
    $response->add ('response', $apiResponseObject->responseData);
    return $response;
  }
}

