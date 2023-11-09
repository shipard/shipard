<?php

namespace e10pro\zus\libs\ezk;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use \e10pro\zus\zusutils;

/**
 * class AppSettings
 */
class AppSettings extends \Shipard\UI\ng\AppSettings
{
  protected function loadData()
  {
  }

  public function run()
  {
    $this->uiSubTemplate = 'modules/e10pro/zus/libs/ezk/subtemplates/appSettings';
    parent::run();
  }
}
