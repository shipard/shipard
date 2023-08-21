<?php

namespace e10\users\libs;


use \Shipard\Base\Utility;


/**
 * class UserContextCreator
 */
class UserContextCreator extends Utility
{
  var $userNdx = 0;
  var $userRecData = NULL;
  var $contextData = [];

  public function setUserNdx($userNdx)
  {
    $this->userNdx = $userNdx;
    $this->userRecData = $this->app()->loadItem($this->userNdx, 'e10.users.users');
  }

  protected function creatAllContexts()
  {
    $allContexts = $this->app()->cfgItem('e10.usersContexts', NULL);
    if (!$allContexts)
      return;

    foreach ($allContexts as $c)
    {
      if (!isset($c['classId']))
        continue;

      /** @var \e10\users\libs\UserContext */
      $userContext = $this->app()->createObject($c['classId']);
      if (!$userContext)
        continue;
      $userContext->setContextCreator($this);
      $userContext->run();
    }
  }

  public function run()
  {
    $this->creatAllContexts();
  }
}
