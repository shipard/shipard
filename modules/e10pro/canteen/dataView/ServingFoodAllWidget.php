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

	var $canteenNdx = 1;

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

		$c .= '<script>
		setTimeout (function(){mqttSubscribe(0, "/shpd/canteen-take-food/'.$this->canteenNdx.'")}, 1000);
		</script>';

		return $c;
	}
}
