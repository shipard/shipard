<?php

namespace Shipard\UI\ng;

/**
 * class TemplateUI
 */
class TemplateUI extends \Shipard\Utils\TemplateCore
{
  public function app() {return $this->app;}

	function resolveCmd ($tagCode, $tagName, $params)
	{
    $uiControlCfg = $this->app()->cfgItem('e10.ui.uiControls.'.$tagName, NULL);

    if ($uiControlCfg)
    {
      /** @var \Shipard\UI\ng\TemplateUIControl $o */
      $o = $this->app()->createObject($uiControlCfg['classId']);
      if ($o)
        return $o->render($tagName, $params);
    }
    return parent::resolveCmd($tagCode, $tagName, $params);
  }
}

