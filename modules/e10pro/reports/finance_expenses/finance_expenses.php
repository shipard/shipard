<?php

namespace E10Pro\Reports\Finance_Expenses;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';

use E10\utils, E10Doc\Core\e10utils, e10doc\core\libs\Aggregate, E10Doc\Core\WidgetAggregate;

/**
 * Class Expenses
 * @package E10Pro\Reports\Finance_Expenses
 */
class Expenses extends Aggregate
{
	var $accGroup = FALSE;
	var $accGroups = [];

	function create ()
	{
		if ($this->accGroup === FALSE)
		{
			$q[] = 'SELECT accounts.g2 as accountId, accgroups.shortName as accountName, SUM(journal.moneyDr) as moneyDr, SUM(journal.moneyCr) as moneyCr, ';

			switch ($this->period)
			{
				case self::periodDaily:
					array_push($q, ' journal.dateAccounting as dateAccounting '); break;
				case self::periodMonthly:
					array_push($q, ' YEAR(journal.dateAccounting) as dateAccountingYear, MONTH(journal.dateAccounting) as dateAccountingMonth '); break;
			}

			array_push($q, ' FROM e10doc_debs_journal as journal ');

			array_push($q, ' LEFT JOIN e10doc_debs_accounts AS accounts ON journal.accountId = accounts.id');
			array_push($q, ' LEFT JOIN e10doc_debs_accounts AS accgroups ON accounts.g2 = accgroups.id');

			array_push($q, ' WHERE accounts.accountKind = %i', 2, ' AND journal.fiscalType = %i', 0);
			if ($this->centre !== FALSE)
				array_push($q, ' AND journal.centre = %i', $this->centre);

			e10utils::fiscalPeriodQuery ($q, $this->fiscalPeriod, 'journal.');

			switch ($this->period)
			{
				case self::periodDaily:
					array_push($q, ' GROUP BY journal.dateAccounting, accounts.g2'); break;
				case self::periodMonthly:
					array_push($q, ' GROUP BY dateAccountingYear, dateAccountingMonth, accounts.g2'); break;
			}
		}
		else
		if (0)
		{
			$q[] = 'SELECT accounts.g3 as accountId, accgroups.shortName as accountName, SUM(journal.moneyDr) as moneyDr, SUM(journal.moneyCr) as moneyCr, ';

			switch ($this->period)
			{
				case self::periodDaily:
					array_push($q, ' journal.dateAccounting as dateAccounting '); break;
				case self::periodMonthly:
					array_push($q, ' YEAR(journal.dateAccounting) as dateAccountingYear, MONTH(journal.dateAccounting) as dateAccountingMonth '); break;
			}

			array_push($q, ' FROM e10doc_debs_journal as journal ');

			array_push($q, ' LEFT JOIN e10doc_debs_accounts AS accounts ON journal.accountId = accounts.id');
			array_push($q, ' LEFT JOIN e10doc_debs_accounts AS accgroups ON accounts.g3 = accgroups.id');

			array_push($q, ' WHERE accounts.g2 = %s', $this->accGroup, ' AND accounts.accountKind = %i', 2, ' AND journal.fiscalType = %i', 0);
			if ($this->centre !== FALSE)
				array_push($q, ' AND journal.centre = %i', $this->centre);

			e10utils::fiscalPeriodQuery ($q, $this->fiscalPeriod, 'journal.');

			switch ($this->period)
			{
				case self::periodDaily:
					array_push($q, ' GROUP BY journal.dateAccounting, accounts.g3'); break;
				case self::periodMonthly:
					array_push($q, ' GROUP BY dateAccountingYear, dateAccountingMonth, accounts.g3'); break;
			}
		}
		else
		if (1)
		{
			$q[] = 'SELECT journal.accountId as accountId, accounts.shortName as accountName, SUM(journal.moneyDr) as moneyDr, SUM(journal.moneyCr) as moneyCr, ';

			switch ($this->period)
			{
				case self::periodDaily:
					array_push($q, ' journal.dateAccounting as dateAccounting '); break;
				case self::periodMonthly:
					array_push($q, ' YEAR(journal.dateAccounting) as dateAccountingYear, MONTH(journal.dateAccounting) as dateAccountingMonth '); break;
			}

			array_push($q, ' FROM e10doc_debs_journal as journal ');
			array_push($q, ' LEFT JOIN e10doc_debs_accounts AS accounts ON journal.accountId = accounts.id');

			array_push($q, ' WHERE accounts.g2 = %s', $this->accGroup, ' AND accounts.accountKind = %i', 2, ' AND journal.fiscalType = %i', 0);
			if ($this->centre !== FALSE)
				array_push($q, ' AND journal.centre = %i', $this->centre);

			e10utils::fiscalPeriodQuery ($q, $this->fiscalPeriod, 'journal.');

			switch ($this->period)
			{
				case self::periodDaily:
					array_push($q, ' GROUP BY journal.dateAccounting, accounts.id'); break;
				case self::periodMonthly:
					array_push($q, ' GROUP BY dateAccountingYear, dateAccountingMonth, accounts.id'); break;
			}
		}


		$rows = $this->app->db()->query($q);
		$data = [];
		$total = ['date' => 'CELKEM', 'totalBase' => 0.0];
		$groupNames = [];

		forEach ($rows as $r)
		{
			switch ($this->period)
			{
				case self::periodDaily:
					$dateKey = $r['dateAccounting']->format ('Y-m-d');
					$date = utils::datef ($r['dateAccounting'], '%d');
					break;
				case self::periodMonthly:
					$dateKey = $r['dateAccountingYear'].'-'.$r['dateAccountingMonth'];
					$date = $r['dateAccountingMonth'].'.'.$r['dateAccountingYear'];
					break;
			}

			if (!isset ($data[$dateKey]))
				$data [$dateKey] = ['date' => $date, 'totalBase' => 0.0];

			$opKey = 'A'.$r['accountId'];
			if (!isset ($data [$dateKey][$opKey]))
				$data [$dateKey][$opKey] = 0.0;

			$data [$dateKey][$opKey] += $r['moneyDr'] - $r['moneyCr'];
			$data [$dateKey]['totalBase'] += round($r['moneyDr'] - $r['moneyCr'], 2);

			if (!isset ($total [$opKey]))
			{
				$total [$opKey] = 0.0;
				$groupNames [$opKey] = $r['accountName'];
			}
			$total[$opKey] += $r['moneyDr'] - $r['moneyCr'];
			$total['totalBase'] += round ($r['moneyDr'] - $r['moneyCr'], 2);
		}

		$h = ['date' => ' '.$this->periodColumnName, 'totalBase' => '+CELKEM'];
		$groupOrder = array_merge([], $total);
		unset ($groupOrder['date']);
		unset ($groupOrder['totalBase']);
		arsort($groupOrder, SORT_NUMERIC);

		foreach ($groupOrder as $opk => $opv)
		{
			$h[$opk] = '+'.$groupNames[$opk];
		}

		$maxCols = $this->maxResultParts;
		$totalsCuted = [];
		utils::cutColumns ($data, $this->data, $h, $this->header, $this->graphLegend, $totalsCuted, 2, $maxCols);

		$this->graphBar = ['type' => 'graph', 'graphType' => 'bar', 'XKey' => 'date', 'stacked' => 1,
											 'disabledCols' => ['totalBase'], 'graphData' => $this->data, 'header' => $this->header];
		$this->graphLine = ['type' => 'graph', 'graphType' => 'spline', 'XKey' => 'date',
												'graphData' => $this->data,'header' => $this->header];

		foreach ($this->graphLegend as $legendId => $legendTitle)
			$this->pieData[] = [utils::tableHeaderColName($legendTitle), $totalsCuted[$legendId]];
		$this->graphDonut = ['type' => 'graph', 'graphType' => 'pie', 'graphData' => $this->pieData];
	}
}


/**
 * Class reportExpenses
 * @package E10Pro\Reports\Buy_Expenses
 */
class reportExpenses extends \e10doc\core\libs\reports\GlobalReport
{
	var $period;
	var $periodColumnName;
	var $currencies;

	var $centre = FALSE;
	var $accGroup = FALSE;
	var $accGroups = [];

	function init ()
	{
		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['quarters', 'halfs', 'years', 'enableAll'], 'defaultValue' => e10utils::todayFiscalMonth($this->app)]);
		if ($this->app->cfgItem ('options.core.useCentres', 0))
			$this->addParam ('centre');
		$this->addParamAccGroups ();
		$this->addParam ('hidden', 'mainTabs');

		parent::init();

		$this->period = Expenses::periodDaily;
		if ($this->reportParams ['fiscalPeriod']['value'] == 0 || $this->reportParams ['fiscalPeriod']['value'][0] === 'Y' || strstr ($this->reportParams ['fiscalPeriod']['value'], ',') !== FALSE)
			$this->period = Expenses::periodMonthly;

		$this->setInfo('icon', 'icon-thumbs-o-down');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);

		if (isset($this->reportParams ['centre']['activeTitle']) && $this->reportParams ['centre']['activeTitle'] != '-')
		{
			$this->setInfo('param', 'Středisko', $this->reportParams ['centre']['activeTitle']);
			$this->centre = $this->reportParams ['centre']['value'];
		}
		if ($this->reportParams ['accGroup']['value'] !== '-')
		{
			$this->setInfo('param', 'Skupina', $this->reportParams ['accGroup']['activeTitle']);
			$this->accGroup = $this->reportParams ['accGroup']['value'];
		}
	}

	protected function addParamAccGroups ()
	{
		$q[] = 'SELECT accgroups.ndx as groupNdx, accgroups.id as groupId, accgroups.shortName as groupName from e10doc_debs_accounts as accounts';
		array_push($q, ' LEFT JOIN e10doc_debs_accounts AS accgroups ON accounts.g2 = accgroups.id');
		array_push($q, ' WHERE accounts.accountKind = %i', 2, ' AND accounts.docState != %i', 9800, ' AND accounts.accGroup = %i', 0);
		array_push($q, ' GROUP BY accounts.g2');

		$this->accGroups ['-'] = 'Všechny';

		$rows = $this->app->db()->query($q);
		foreach ($rows as $r)
			$this->accGroups [$r['groupId']] = $r['groupId'].' - '.$r['groupName'];

		$this->addParam('switch', 'accGroup', array ('title' => 'Skupina', 'switch' => $this->accGroups));
	}

	function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'sum': $this->createContent_Summary (); break;
		}
	}

	function createContent_Summary ()
	{
		$engine = new Expenses($this->app);
		$engine->setFiscalPeriod($this->reportParams ['fiscalPeriod']['value']);
		$engine->setReportPeriod($this->period);
		$engine->setCentre($this->centre);
		$engine->accGroup = $this->accGroup;

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
			case Expenses::periodDaily:
				$this->setInfo('title', 'Denní přehled nákladů');
				break;
			case Expenses::periodMonthly:
				$this->setInfo('title', 'Měsíční přehled nákladů');
				break;
		}
		$this->setInfo('note', '1', 'Všechny částky jsou bez DPH');
	}

	public function subReportsList ()
	{
		$d[] = array ('id' => 'sum', 'icon' => 'icon-plus-square', 'title' => 'Sumárně');
		return $d;
	}
}
