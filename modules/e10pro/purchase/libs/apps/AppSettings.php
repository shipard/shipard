<?php

namespace e10pro\purchase\libs\apps;

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
    $this->uiSubTemplate = 'modules/e10pro/purchase/libs/apps/subtemplates/appSettings';
    parent::run();
  }
}
