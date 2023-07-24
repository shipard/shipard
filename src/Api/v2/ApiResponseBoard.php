<?php

namespace Shipard\Api\v2;


class ApiResponseBoard extends \Shipard\Api\v2\ApiResponse
{
  /** @var \Shipard\UI\Core\UIWidgetBoard */
  var $board;

  protected function checkResponseParams()
  {
  }

  public function run()
  {
    $this->board = $this->app()->createObject($this->requestParam('classId'));
    if (!$this->board)
      return;

    $this->board->setRequestParams($this->requestParams);
		$this->board->init();
    $this->board->createResponse($this->responseData);
  }
}

