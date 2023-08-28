<?php

namespace Shipard\UI\ng;

/**
 * class TemplateUI
 */
class TemplateUI extends \Shipard\Utils\TemplateCore
{
  var $uiRoot = '';
  var $uiData = [];

  public function app() {return $this->app;}

	function resolveCmd ($tagCode, $tagName, $params)
	{
    $uiControlCfg = $this->app()->cfgItem('e10.ui.uiControls.'.$tagName, NULL);

    if ($uiControlCfg)
    {
      /** @var \Shipard\UI\ng\TemplateUIControl $o */
      $o = $this->app()->createObject($uiControlCfg['classId']);
      if ($o)
      {
        $o->uiTemplate = $this;
        return $o->render($tagName, $params);
      }
    }

    //{{{@appUIElement}}}

    if ($tagName === 'appUIElement')
    {
      $o = new \Shipard\UI\ng\UIElement($this->app());
      $o->uiTemplate = $this;
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
        $widget->uiTemplate = $this;
        $widget->setDefinition($classId);
        $widget->init();
        $widget->createContent();

        $c = $widget->renderContent(TRUE);
        return $c;
      }
    }

    if ($tagName === 'systemWidgetNG')
    {
      /** @var \Shipard\UI\Core\UIWidgetBoard */
      $w = NULL;

      $classId = $params['classId'] ?? NULL;
      if ($classId)
        $w = $this->app->createObject($classId);
      if ($w)
      {
        $w->uiTemplate = $this;
        $w->setDefinition($classId);
        $w->setRequestParams(['cgType' => 1]);
        $w->init();

        $responseData = [];
        $w->createResponse($responseData);

        $c = $responseData['hcFull'];
        return $c;
      }
    }


    return parent::resolveCmd($tagCode, $tagName, $params);
  }

  public function checkUIData()
  {
    if (isset($this->uiData['iotElementsMap']))
      unset($this->uiData['iotElementsMap']);
    if (isset($this->uiData['iotElementsMapSIDs']))
      unset($this->uiData['iotElementsMapSIDs']);
  }

  protected function userContextsEnum()
  {
    $uc = $this->app()->uiUserContext();
    if (!$uc || !isset($uc['contexts']))
      return [];
    return array_values($uc['contexts']) ?? [];
  }

  protected function userContextsEnumExist()
  {
    $uc = $this->app()->uiUserContext();
    if (!$uc || !isset($uc['contexts']))
      return FALSE;
    return TRUE;
  }

  protected function userInfo()
  {
    return $this->app()->uiUser;
  }

  protected function activeUserContext()
  {
    $uc = $this->app()->uiUserContext();
    $a = $this->app()->uiUserContextId;
    if (isset($uc['contexts'][$a]))
      return $uc['contexts'][$a];
    return NULL;
  }

  public function subTemplateStr($stId)
	{
		$templateStr = file_get_contents(__SHPD_ROOT_DIR__.'/'.$stId.'.mustache');
		return $templateStr;
	}
}

