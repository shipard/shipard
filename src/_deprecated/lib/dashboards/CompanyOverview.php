<?php

namespace lib\dashboards;


use \Shipard\Base\Content;


/**
 * Class CompanyOverview
 * @package lib\dashboards
 */
class CompanyOverview extends Content
{
	var $code = '';

	public function createBalanceReceivables ()
	{
		if ($this->app->model()->module ('e10doc.balance') === FALSE)
			return;
		$this->addContent (['type' => 'widget', 'id' => 'lib.dashboards.widgets.BalanceReceivables', 'class' => 'e10-fx-col e10-fx-4 e10-fx-sm-fw pa1']);
	}

	public function createBalanceObligations ()
	{
		if ($this->app->model()->module ('e10doc.balance') === FALSE)
			return;
		$this->addContent (['type' => 'widget', 'id' => 'lib.dashboards.widgets.BalanceObligations', 'class' => 'e10-fx-col e10-fx-4 e10-fx-sm-fw pa1']);
	}

	public function createMoney ()
	{
		$this->addContent (['type' => 'widget', 'id' => 'lib.dashboards.widgets.OverviewMoney', 'class' => 'e10-fx-col e10-fx-4 e10-fx-sm-fw pa1']);
	}

	function createSales()
	{
		$this->addContent (['type' => 'widget', 'id' => 'lib.dashboards.widgets.OverviewSales', 'class' => 'e10-fx-col e10-fx-6 e10-fx-sm-fw e10-fx-grow e10-fx-wrap e10-fx-sp-between e10-widget-graph-pane']);
	}

	function createCompanyResults()
	{
		if ($this->app->model()->module ('e10doc.balance') === FALSE)
			return;

		$this->addContent (['type' => 'widget', 'id' => 'lib.dashboards.widgets.OverviewCompanyResults', 'class' => 'e10-fx-col e10-fx-6 e10-fx-sm-fw e10-fx-grow e10-fx-wrap e10-fx-sp-between e10-widget-graph-pane']);
	}

	function createDailyBar()
	{
		$this->addContent (['type' => 'widget', 'id' => 'lib.dashboards.widgets.DailyBar', 'class' => 'e10-fx-row e10-fx-wrap e10-widget-top-bar']);
	}

	function createTodayOverview()
	{
		$dailySummaryCfg = $this->app()->cfgItem ('e10.dashboardInfo.dailySummary', []);
		foreach ($dailySummaryCfg as $dailySummaryItem)
		{
			$this->addContent (
				[
					'type' => 'widget', 'id' => 'lib.dashboards.widgets.DailyInfo',
					'params' => ['infoClass' => $dailySummaryItem['class']],
					'class' => 'e10-fx-col e10-fx-4 e10-fx-sm-fw pa1 e10-doc-list'
			]);
		}
	}

	public function createCode()
	{
		$this->app()->cache->invalidate('e10doc.core.heads', 'cash');

		$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-col e10-fx-align-stretch']);

		$this->createDailyBar();

		$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-row e10-fx-align-stretch e10-fx-wrap e10-widget-top-bar-ext']);
			$this->createMoney();
			$this->createBalanceReceivables();
			$this->createBalanceObligations();
		$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);

		$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-row e10-fx-grow e10-fx-wrap']);
			$this->createTodayOverview();
		$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);

		$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-row e10-fx-wrap e10-fx-align-end e10-fx-align-stretch']);
			$this->createSales();
			$this->createCompanyResults();
		$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);

		$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);

		$cr = new \e10\ContentRenderer($this->app);
		$cr->content = $this->content;
		$this->code .= $cr->createCode('body');
	}

	public function run ()
	{
		$this->createCode();
	}
}
