<?php

namespace e10pro\hosting\server\dataView;
use \lib\dataView\DataView, \e10pro\hosting\server\TableDatasources, \Shipard\Utils\TableRenderer;


/**
 * Class PricePlans
 * @package e10pro\hosting\server\dataView
 */
class PricePlans extends DataView
{
	/** @var TableDatasources */
	var $tableDataSources;

	var $plansContent;

	protected function init()
	{
		parent::init();
		$this->tableDataSources = $this->app()->table('e10pro.hosting.server.datasources');
	}

	protected function loadData()
	{
		$this->plansContent = $this->tableDataSources->getPlansLegend();
	}

	protected function renderDataAs($showAs)
	{
		$tr = new TableRenderer($this->plansContent['table'], $this->plansContent['header'], ['forceTableClass' => 'default stripped'], $this->app());
		return $tr->render();
	}
}
