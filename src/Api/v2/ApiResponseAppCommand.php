<?php

namespace Shipard\Api\v2;


class ApiResponseAppCommand extends \Shipard\Api\v2\ApiResponse
{
  protected function checkResponseParams()
  {
  }

  public function run()
  {
    $this->responseData['success_test'] = 'yes';

    if ($this->requestParam('actionId') === 'setUserContext')
      $this->setUserContext();
  }

  protected function setUserContext()
  {
    $contextId = $this->requestParam('userContextId');
    $this->app()->setUIUserContext($contextId);
  }
}
