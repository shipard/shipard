<?php

namespace e10\users\libs;


use \Shipard\Base\Utility;


/**
 * class UserContext
 */
class UserContext extends Utility
{
  var \e10\users\libs\UserContextCreator $contextCreator;

  public function setContextCreator(\e10\users\libs\UserContextCreator $contextCreator)
  {
    $this->contextCreator = $contextCreator;
  }

  public function run()
  {
  }
}
