<?php

namespace e10pro\purchase\libs\apps;
use \e10\base\libs\UtilsBase;


/**
 * class AppSettings
 */
class AppSettings extends \Shipard\UI\ng\AppSettings
{
  protected function loadData()
  {
    parent::loadData();

    $personNdx = $this->app->userNdx();
    if ($personNdx)
    {
      $this->loadPersonInfo($personNdx);
    }
  }

  public function run()
  {
    $this->uiSubTemplate = 'modules/e10pro/purchase/libs/apps/subtemplates/appSettings';
    parent::run();
  }
}
