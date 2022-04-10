<?php

namespace services\persons\libs;
use \Shipard\Base\Utility;

/** 
 * class Log
 */
class Log extends Utility
{
  public function newLogRecord()
  {
    $newLogItem = new \services\persons\libs\LogRecord($this->app());

    return $newLogItem;
  }
}
