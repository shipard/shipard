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

    if ($tagName === 'uiWidget')
    {
      $uiWidgetRecData = NULL;
      $uiWidgetId = $params['id'] ?? NULL;
      if ($uiWidgetId)
        $uiWidgetRecData = $this->app()->db()->query('SELECT * FROM [e10_ui_uiWidgets] WHERE [widgetId] = %s', $uiWidgetId)->fetch();
      if ($uiWidgetRecData)
      {
        return $this->render($uiWidgetRecData['template']);
      }
    }

    if ($tagName === 'systemWidget')
    {
      $widget = NULL;

      $classId = $params['classId'] ?? NULL;
      if ($classId)
        $widget = $this->app->createObject($classId);
      if ($widget)
      {
        $widget->setDefinition($classId);
        $widget->init();
        $widget->createContent();

        $c = $widget->renderContent(TRUE);
        return $c;
      }
    }

    return parent::resolveCmd($tagCode, $tagName, $params);
  }
}

