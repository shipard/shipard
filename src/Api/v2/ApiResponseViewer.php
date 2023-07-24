<?php

namespace Shipard\Api\v2;


class ApiResponseViewer extends \Shipard\Api\v2\ApiResponse
{
  /** @var \Shipard\Viewer\TableView */
  var $viewer;

  protected function checkResponseParams()
  {
  }

  public function run()
  {
    /*
    $this->board = $this->app()->createObject($this->requestParam('classId'));
    if (!$this->board)
      return;

    $this->board->setRequestParams($this->requestParams);
		$this->board->init();
    $this->board->createResponse($this->responseData);
    */
  }
}
