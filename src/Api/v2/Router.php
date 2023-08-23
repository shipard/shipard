<?php

namespace Shipard\Api\v2;


/**
 * class Router
 */
class Router extends \Shipard\Base\Utility
{
  var ?array $requestParams = NULL;
  var $uiRouter = NULL;

	public function setRequestParams(?array $requestParams)
	{
    $this->requestParams = $requestParams;
  }

  protected function requestParam($paramKey)
  {
    return $this->requestParams[$paramKey] ?? NULL;
  }

  protected function checkUserLogin()
	{
		$a = new \e10\users\libs\Authenticator($this->app());
		return $a->checkSession();
	}


  public function run()
  {
    if (!$this->checkUserLogin())
      return new \Shipard\Application\Response ($this->app(), 'need authentication', 403);

    $requestType = $this->requestParam('requestType');
    if (!$requestType)
      return new \Shipard\Application\Response ($this->app(), 'no requestType param', 404);

    /** @var \Api\v2\ApiResponse  */
    $apiResponseObject = NULL;

    switch ($requestType)
    {
      case 'widgetBoard': $apiResponseObject = new \Shipard\Api\v2\ApiResponseBoard($this->app()); break;
      case 'dataViewer': $apiResponseObject = new \Shipard\Api\v2\ApiResponseViewer($this->app()); break;
      case 'appCommand': $apiResponseObject = new \Shipard\Api\v2\ApiResponseAppCommand($this->app()); break;
    }

    if (!$apiResponseObject)
      return new \Shipard\Application\Response ($this->app(), 'invalid requestType param', 404);

    $apiResponseObject->uiRouter = $this->uiRouter;
    $apiResponseObject->setRequestParams($this->requestParams);
    $apiResponseObject->run();

    $response = new \Shipard\Api\v2\ResponseHTTP ($this->app());
    $response->add ('response', $apiResponseObject->responseData);
    return $response;
  }
}

