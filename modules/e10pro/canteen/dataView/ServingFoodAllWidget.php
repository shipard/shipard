<?php

namespace e10pro\canteen\dataView;

use \lib\dataView\DataView, \e10\utils;


/**
 * Class ServingFoodAllWidget
 * @package e10pro\canteen\dataView
 */
class ServingFoodAllWidget extends DataView
{
	/** @var \e10pro\canteen\dataView\ServingFoodQueueWidget */
	var $widgetQueue = NULL;
	/** @var \e10pro\canteen\dataView\ServingFoodStatsWidget */
	var $widgetStats = NULL;

	protected function init()
	{
		$this->requestParams['showAs'] = 'webAppWidget';
		parent::init();
	}

	protected function loadData()
	{
		$this->widgetQueue = new \e10pro\canteen\dataView\ServingFoodQueueWidget($this->app());
		$this->widgetQueue->run();
		$this->data['queue'] = $this->widgetQueue->data;

		$this->widgetStats = new \e10pro\canteen\dataView\ServingFoodStatsWidget($this->app());
		$this->widgetStats->run();
		$this->data['stats'] = $this->widgetStats->data;
	}

	protected function renderDataAs($showAs)
	{
		if ($showAs === 'webAppWidget')
			return $this->renderDataAsWidget();

		return parent::renderDataAs($showAs);
	}

	protected function renderDataAsWidget()
	{
		$c = '';

		$c .= "<div class='row e10-display-panel-group'>";

		$c .= "<div class='col-12 col-sm-12 col-lg-7'>";
		$c .= $this->widgetQueue->data['html'];
		$c .= '</div>';


		$c .= "<div class='col-12 col-sm-12 col-lg-5'>";
		$c .= $this->widgetStats->data['html'];
		$c .= '</div>';

		$c .= '</div>';

		return $c;
	}
}
