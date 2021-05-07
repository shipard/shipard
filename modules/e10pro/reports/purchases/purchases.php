<?php

namespace E10Pro\Reports\Purchases;

use E10\utils, E10Doc\Core\e10utils, \E10\uiutils, E10Doc\Core\Aggregate, E10Doc\Core\AggregateDocRows, E10Doc\Core\WidgetAggregate;


/**
 * Class Purchases
 * @package E10Pro\Reports\Purchases
 */
class Purchases extends AggregateDocRows
{
	public function init()
	{
		$this->enabledDocTypes = ['purchase'];
		$this->enabledOperations = [1040001];
		parent::init();
	}
}

/**
 * Class reportPurchases
 * @package E10Pro\Reports\Purchases
 */
class reportPurchases extends \E10Doc\Core\GlobalReport
{
	var $period;

	function init ()
	{
		$this->addParam ('fiscalPeriod', 'fiscalPeriod', array('flags' => ['enableAll', 'quarters', 'halfs', 'years']));

		$this->addParam ('switch', 'groupBy', ['title' => 'Přehled dle',
										 'switch' => ['1' => 'Položek', '2' => 'Účetních skupin', '3' => 'Typů', '4' => 'Značek']]);
		$this->addParam ('hidden', 'mainTabs');

		parent::init();

		$this->setInfo('icon', 'e10-docs-purchase');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
		$this->setInfo('note', '1', 'Všechny částky jsou bez DPH');
	}

	function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'sum': $this->createContent_Sum (); break;
		}
	}

	function createContent_Sum ()
	{
		$this->period = Aggregate::periodDaily;
		if ($this->reportParams ['fiscalPeriod']['value'] == 0 || $this->reportParams ['fiscalPeriod']['value'][0] === 'Y' || strstr ($this->reportParams ['fiscalPeriod']['value'], ',') !== FALSE)
			$this->period = Aggregate::periodMonthly;

		$engine = new Purchases($this->app);
		$engine->setFiscalPeriod($this->reportParams ['fiscalPeriod']['value']);
		$engine->setReportPeriod($this->period);
		$engine->groupBy = intval($this->reportParams ['groupBy']['value']);

		$engine->init();
		$engine->create();

		$this->addContent(['tabsId' => 'mainTabs', 'selectedTab' => $this->reportParams ['mainTabs']['value'], 'tabs' => [
			['title' => ['icon' => 'icon-table', 'text' => 'Tabulka'], 'content' => [['type' => 'table', 'header' => $engine->header, 'table' => $engine->data, 'main' => TRUE]]],
			['title' => ['icon' => 'icon-bar-chart-o', 'text' => 'Sloupce'], 'content' => [$engine->graphBar]],
			['title' => ['icon' => 'icon-line-chart', 'text' => 'Čáry'], 'content' => [$engine->graphLine]],
			['title' => ['icon' => 'icon-pie-chart', 'text' => 'Podíly'], 'content' => [$engine->graphDonut]],
			['title' => ['icon' => 'icon-file', 'text' => 'Vše'], 'content' => [['type' => 'table', 'header' => $engine->header, 'table' => $engine->data], $engine->graphBar, $engine->graphDonut]]
		]]);

		switch ($this->period)
		{
			case Aggregate::periodDaily:
				$this->setInfo('title', 'Denní přehled výkupů');
				break;
			case Aggregate::periodMonthly:
				$this->setInfo('title', 'Měsíční přehled výkupů');
				break;
		}

		$this->setInfo('note', '1', 'Všechny částky jsou bez DPH');
	}
}
