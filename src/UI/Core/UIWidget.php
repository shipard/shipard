<?php

namespace Shipard\UI\Core;
use \Shipard\Utils\Utils;


/**
 * class UIWidget
 */
class UIWidget extends \Shipard\UI\Core\UIElement
{
  /** @var \Shipard\UI\Core\Params */
	var $params;
	var $widgetSystemParams = [];
  var $widgetAction = '';

  var ?\Shipard\UI\ng\TemplateUI $uiTemplate = NULL;

  CONST cgtFullCode = 1, cgtMainCode = 2, cgtParts = 0;
  var $cgType = self::cgtFullCode;



	protected function createParamsObject ()
	{
		$this->params = new \Shipard\UI\Core\Params ($this->app);
	}

	public function addParam ($paramType, $paramId = FALSE, $options = NULL)
	{
		$this->params->addParam ($paramType, $paramId, $options);
	}

  public function createContent ()
	{
	}

	public function renderContent ($forceFullCode = FALSE)
	{
		$fullCode = intval($this->app->testGetParam('fullCode'));
		if ($forceFullCode)
			$fullCode = 1;
		if ($this->forceFullCode)
			$fullCode = 1;
		$c = '';

		if ($fullCode)
		{
			$params = "data-object='widget' data-widget-class='{$this->definition['class']}'";
			$pv = [];
			forEach ($this->params->getParams() as $paramId => $paramContent)
			{
				$pv [$paramId] = $paramId.'='.$this->reportParams [$paramId]['value'];
			}
			$params .= " data-widget-params='".implode('&', $pv)."'";

			if ($this->app()->remote !== '')
				$params .= " data-remote='".$this->app()->remote."'";

			foreach ($this->widgetSystemParams as $wspId => $wspValue)
				$params .= " $wspId='$wspValue'";

			$c .= "<div id='{$this->widgetId}' class='{$this->widgetMainClass} e10-widget-".$this->widgetType()."' $params>";
		}

		$c .= $this->renderContentContent($fullCode);

		if (!$this->app->mobileMode)
		$c .= "<script>
					$('div.e10-widget-content table.main').floatThead({
						scrollContainer: function(table){
							return table.parent();
						},
						useAbsolutePositioning: false,
						zIndex: 101
					});
				</script>
				";
		if ($fullCode)
			$c .= '</div>';

		if (!$this->app()->ngg)
			$c .= "<script>\$(function () {e10WidgetInit('{$this->widgetId}');});</script>";

		return $c;
	}

	/*
	public function createMainCode ()
	{
		$this->setDefinition (NULL);
		$this->init();
		$this->createContent();
		return $this->renderContent();
	}
	*/

  public function setRequestParams (array $requestParams)
	{
    $this->cgType = $requestParams['cgType'] ?? self::cgtParts;
    $this->widgetAction = $requestParams['widgetAction'] ?? '';
		//$this->reportParams = $this->params->detectValues();
	}

  public function init ()
	{
    $this->createParamsObject ();
    if ($this->app->testGetParam('widgetId'))
      $this->widgetId = $this->app->testGetParam('widgetId');
    else
      $this->widgetId = 'W-'.mt_rand(1000000, 999999999);
  }

	public function widgetType()
	{
		if (isset($this->definition['type']))
			return $this->definition['type'];
		return NULL;
	}
}
