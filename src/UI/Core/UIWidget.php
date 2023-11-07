<?php

namespace Shipard\UI\Core;
use \Shipard\Utils\Utils;


/**
 * class UIWidget
 */
class UIWidget extends \Shipard\UI\Core\UIElement
{
  /** @var \Shipard\UI\Core\Params */
	var $params = NULL;
	var $widgetSystemParams = [];
  var $widgetAction = '';
	var $widgetMainClass = 'shp-widget-simple';
	var $requestParams = NULL;

  var ?\Shipard\UI\ng\TemplateUI $uiTemplate = NULL;
	var ?\Shipard\UI\ng\Router $uiRouter = NULL;

  CONST cgtFullCode = 1, cgtMainCode = 2, cgtParts = 0;
  var $cgType = self::cgtFullCode;

	CONST swpNone = 0, swpLeft = 1, swpRight = 2;

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

	public function createCodeAll ($fullCode = FALSE)
	{
		$c = '';

		if ($fullCode)
		{
			$params = '';
			$params .= " data-object-type='data-widget-board'";
			$params .= " data-request-type='widgetBoard'";
			$cid = str_replace('\\', '.', get_class($this));
			$params .= " data-class-id='$cid'";

			//foreach ($this->widgetSystemParams as $wspId => $wspValue)
			//	$params .= " $wspId='$wspValue'";

			$c .= "<div id='{$this->widgetId}' class='{$this->widgetMainClass}' $params>";
		}

		$c .= $this->createCodeToolbar();
		$c .= $this->createCodeContent($fullCode);

		if ($fullCode)
			$c .= '</div>';

		return $c;
	}

	function createCodeToolbar ()
	{
		return '';
	}

  public function setRequestParams (array $requestParams)
	{
    $this->cgType = $requestParams['cgType'] ?? self::cgtParts;
    $this->widgetAction = $requestParams['widgetAction'] ?? '';
		$this->requestParams = $requestParams;
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

	public function createResponse (array &$responseData)
	{
		$responseData ['cgType'] = $this->cgType;
		$this->init();
		$this->prepareData();
		//$this->renderData($responseData);

		if ($this->cgType === self::cgtFullCode)
		{
			$this->createContent();
			$responseData ['hcFull'] = $this->createCodeAll(TRUE);
			return;
		}

		$this->createContent();

    //$responseData ['hcToolbar'] = $this->createToolbarCode ();
		//$responseData ['hcDetails'] = $this->createTabsCode ();
		$responseData ['hcMain'] = $this->createCodeAll(FALSE);

    $responseData ['uiData'] = $this->uiTemplate->uiData;
	}

	protected function createCodeContent()
	{
		$cr = new ContentRenderer ($this->app);
		$cr->setWidget($this);

		$c = '';

		$c .= "<div class='shp-wb-content'>";
		$c .= $cr->createCode();
		$c .= '</div>';

		return $c;
	}
}
