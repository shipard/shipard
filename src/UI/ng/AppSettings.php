<?php

namespace Shipard\UI\ng;


/**
 * class AppSettings
 */
class AppSettings extends \Shipard\Base\Utility
{
  var ?\Shipard\UI\ng\TemplateUI $uiTemplate = NULL;
  var $uiSubTemplate = '';
  var $resultData = [];

  protected function createCode()
  {
    $this->renderSubtemplate();
  }

  protected function loadData()
  {
  }

  protected function renderSubtemplate()
  {
    $templateStr = $this->uiTemplate->subTemplateStr($this->uiSubTemplate);
		$code = $this->uiTemplate->render($templateStr);
    $this->resultData[] = [
      'code' => $code,
    ];
  }

  public function run()
  {
    $this->loadData();
    $this->createCode();
  }
}

