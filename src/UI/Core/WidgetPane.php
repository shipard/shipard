<?php

namespace Shipard\UI\Core;
use \Shipard\Utils\Utils;


class WidgetPane extends \Shipard\UI\Core\Widget
{
	/** @var \E10\Params */
	var $params;
	var $reportParams;
	var $widgetAction = '';
	var $widgetMainClass = 'e10-widget-pane';
	var $widgetContentClass = '';
	var $widgetSystemParams = [];

	var $content = [];

	public function __construct ($app)
	{
		parent::__construct($app);
		$this->createParamsObject ();
		if ($this->app->testGetParam('widgetId'))
			$this->widgetId = $this->app->testGetParam('widgetId');
		else
			$this->widgetId = 'W-'.mt_rand(1000000, 999999999);
	}

	public function init ()
	{
		$this->widgetAction = $this->app->testGetParam('widgetAction');
		$this->reportParams = $this->params->detectValues();
	}

	public function setRequestParams (array $requestParams)
	{
		$this->widgetAction = $requestParams['widgetAction'] ?? '';
		$this->reportParams = $this->params->detectValues();
	}

	public function addContent ($contentPart)
	{
		if ($contentPart === FALSE)
			return;

		$this->content[] = $contentPart;
	}

	public function addContentViewer ($tableId, $viewerId, $params)
	{
		$this->content [] = ['type' => 'viewer', 'table' => $tableId, 'viewer' => $viewerId, 'params' => $params, 'receiver' => $this];
	}

	public function addParam ($paramType, $paramId = FALSE, $options = NULL)
	{
		$this->params->addParam ($paramType, $paramId, $options);
	}

	protected function createParamsObject ()
	{
		$this->params = new \E10\Params ($this->app);
	}

	public function doCacheItem ($cacheItem, $addContent = TRUE)
	{
		if ($cacheItem['invalidate'])
		{
			$age = '';
			if ($cacheItem['changed'])
			{
				$min = Utils::dateDiffMinutes(Utils::createDateTime($cacheItem['changed'], TRUE), new \DateTime());
				if ($min)
					$age = $min . ' min';
			}

			$c = ['type' => 'line', 'line' => ['text' => 'aktualizováno', 'suffix' => $age, 'icon' => 'icon-history', 'class' => 'e10-off e10-small']];
			if ($addContent)
				$this->addContent($c);
			else
				return $c;
		}
		return FALSE;
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

	protected function renderContentContent($fullCode)
	{
		$c = '';

		$c .= $this->renderContentTitle();

		$c .= "<div class='e10-widget-content e10-widget-".$this->widgetType()." {$this->widgetContentClass}'>";
		$cr = new ContentRenderer ($this->app);
		$cr->setWidget($this);
		$c .= $cr->createCode();
		$c .= '</div>';

		return $c;
	}

	protected function renderContentTitle()
	{
		$c = '';

		$title = $this->title();
		if ($title !== FALSE)
			$c .= "<div class='e10-widget-title'>".$this->app()->ui()->composeTextLine($this->title()).'</div>';

		return $c;
	}

	public function createContent ()
	{
	}

	public function createMainCode ()
	{
		$this->setDefinition (NULL);
		$this->init();
		$this->createContent();
		return $this->renderContent();
	}

	public function createResponse (array &$responseData)
	{
		$this->setDefinition (NULL);
		//$this->init();
		//$this->createContent();
		//return $this->renderContent();
	}

	public function createTabsCode (){return'';}
	public function createToolbarCode (){return'';}

	public function isBlank ()
	{
		return (count ($this->content) === 0);
	}

	public function linkParams ()
	{
		return FALSE;
	}

	public function title ()
	{
		if (!isset($this->definition) || !isset($this->definition['name']))
			return FALSE;

		$title = ['icon' => $this->definition['icon'], 'text' => $this->definition['name']];
		return $title;
	}

	public function titleMobile ()
	{
		if (isset($this->definition))
			return $this->definition['name'];
		return '';
	}

	public function fullScreen()
	{
		return 0;
	}

	public function pageType()
	{
		return 'widget';
	}

	public function widgetType()
	{
		if (isset($this->definition['type']))
			return $this->definition['type'];
		return NULL;
	}
}

