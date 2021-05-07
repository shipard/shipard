<?php

namespace E10Pro\Reports\Finance_Takings;

use E10\Utility, E10\utils, e10doc\core\libs\E10Utils, E10Doc\Core\Aggregate, E10Doc\Core\WidgetAggregate;

/**
 * Class Takings
 * @package E10Pro\Reports\Finance_Takings
 */
class Takings extends \e10doc\core\libs\Aggregate
{
	function create ()
	{
		$q[] = 'SELECT journal.accountId as accountId, accounts.shortName as accountName, SUM(journal.moneyDr) as moneyDr, SUM(journal.moneyCr) as moneyCr, ';

		switch ($this->period)
		{
			case Takings::periodDaily:
				array_push($q, ' journal.dateAccounting as dateAccounting '); break;
			case Takings::periodMonthly:
				array_push($q, ' YEAR(journal.dateAccounting) as dateAccountingYear, MONTH(journal.dateAccounting) as dateAccountingMonth '); break;
		}

		array_push($q, ' FROM e10doc_debs_journal as journal LEFT JOIN e10doc_debs_accounts AS accounts ON journal.accountId = accounts.id');
		array_push($q, ' WHERE accounts.accountKind = %i', 3, ' AND journal.fiscalType = %i', 0);
		if ($this->centre !== FALSE)
			array_push($q, ' AND journal.centre = %i', $this->centre);

		E10Utils::fiscalPeriodQuery ($q, $this->fiscalPeriod, 'journal.');

		switch ($this->period)
		{
			case Takings::periodDaily:
				array_push($q, ' GROUP BY journal.dateAccounting, journal.accountId'); break;
			case Takings::periodMonthly:
				array_push($q, ' GROUP BY dateAccountingYear, dateAccountingMonth, journal.accountId'); break;
		}

		$rows = $this->app->db()->query($q);
		$data = [];
		$total = ['date' => 'CELKEM'];
		$groupNames = [];

		forEach ($rows as $r)
		{
			switch ($this->period)
			{
				case Takings::periodDaily:
					$dateKey = $r['dateAccounting']->format ('Y-m-d');
					$date = utils::datef ($r['dateAccounting'], '%d');
					break;
				case Takings::periodMonthly:
					$dateKey = $r['dateAccountingYear'].'-'.$r['dateAccountingMonth'];
					$date = $r['dateAccountingMonth'].'.'.$r['dateAccountingYear'];
					break;
			}

			if (!isset ($data[$dateKey]))
				$data [$dateKey] = ['date' => $date, 'totalBase' => 0.0];

			$opKey = 'A'.$r['accountId'];
			if (!isset ($data [$dateKey][$opKey]))
				$data [$dateKey][$opKey] = 0.0;

			$data [$dateKey][$opKey] += $r['moneyCr'] - $r['moneyDr'];
			$data [$dateKey]['totalBase'] += round($r['moneyCr'] - $r['moneyDr'], 2);

			if (!isset ($total [$opKey]))
			{
				$total [$opKey] = 0.0;
				$groupNames [$opKey] = $r['accountName'];
			}
			$total[$opKey] += $r['moneyCr'] - $r['moneyDr'];
			if (isset($total['totalBase']))
				$total['totalBase'] += round ($r['moneyCr'] - $r['moneyDr'], 2);
			else
				$total['totalBase'] = round ($r['moneyCr'] - $r['moneyDr'], 2);
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
		$dataCuted = [];
		$headerCuted = [];
		$legendCuted = [];
		$totalsCuted = [];
		utils::cutColumns ($data, $dataCuted, $h, $headerCuted, $legendCuted, $totalsCuted, 2, $maxCols);


		$donutData = [];
		foreach ($legendCuted as $legendId => $legendTitle)
			$donutData[] = [utils::tableHeaderColName($legendTitle), $totalsCuted[$legendId]];

		$this->data = $dataCuted;
		$this->pieData = $donutData;
		$this->graphLegend = $legendCuted;
		$this->header = $headerCuted;

		$this->graphBar = ['type' => 'graph', 'graphType' => 'bar', 'XKey' => 'date', 'stacked' => 1,
											 'disabledCols' => ['totalBase'], 'graphData' => $this->data, 'header' => $this->header];
		$this->graphLine = ['type' => 'graph', 'graphType' => 'spline', 'XKey' => 'date',
												'graphData' => $this->data, 'header' => $this->header];
		$this->graphDonut = ['type' => 'graph', 'graphType' => 'pie', 'graphData' => $donutData];
	}
}


/**
 * Class reportTakings
 * @package E10Pro\Reports\Finance_Takings
 */
class reportTakings extends \e10doc\core\libs\reports\GlobalReport
{
	var $period;

	var $centre = FALSE;

	function init ()
	{
		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['quarters', 'halfs', 'years', 'enableAll'], 'defaultValue' => E10Utils::todayFiscalMonth($this->app)]);
		if ($this->app->cfgItem ('options.core.useCentres', 0))
			$this->addParam ('centre');
		$this->addParam ('hidden', 'mainTabs');

		parent::init();

		$this->period = Takings::periodDaily;
		if ($this->reportParams ['fiscalPeriod']['value'] == 0 || $this->reportParams ['fiscalPeriod']['value'][0] === 'Y' || strstr ($this->reportParams ['fiscalPeriod']['value'], ',') !== FALSE)
			$this->period = Takings::periodMonthly;

		$this->setInfo('icon', 'icon-thumbs-o-up');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);

		if (isset($this->reportParams ['centre']['activeTitle']) && $this->reportParams ['centre']['activeTitle'] != '-')
		{
			$this->setInfo('param', 'Středisko', $this->reportParams ['centre']['activeTitle']);
			$this->centre = $this->reportParams ['centre']['value'];
		}
	}

	function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'sum': $this->createContent_Summary (); break;
		}
	}
/*
	function createContent_Summary_OLD ()
	{
		$q[] = 'SELECT rows.invDirection as invDirection, SUM(rows.taxBaseHc) as taxBase,';

		switch ($this->period)
		{
			case reportTakings::periodDaily:
				array_push($q, ' heads.dateAccounting as dateAccounting '); break;
			case reportTakings::periodMonthly:
				array_push($q, ' YEAR(heads.dateAccounting) as dateAccountingYear, MONTH(heads.dateAccounting) as dateAccountingMonth '); break;
		}

		array_push($q, ' FROM e10doc_core_rows as rows LEFT JOIN e10doc_core_heads AS heads ON rows.document = heads.ndx');
		array_push($q, ' WHERE heads.docState = 4000 AND docType IN %in', ['cashreg', 'invno']);

		e10utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);

		switch ($this->period)
		{
			case reportTakings::periodDaily:
				array_push($q, ' GROUP BY heads.dateAccounting, rows.invDirection'); break;
			case reportTakings::periodMonthly:
				array_push($q, ' GROUP BY dateAccountingYear, dateAccountingMonth, rows.invDirection'); break;
		}

		$rows = $this->app->db()->query($q);

		$data = array ();

		$total = array ('date' => 'CELKEM', 'servicesBase' => 0.0, 'invBase' => 0.0, 'totalBase' => 0.0);

		forEach ($rows as $r)
		{
			switch ($this->period)
			{
				case reportTakings::periodDaily:
					$dateKey = $r['dateAccounting']->format ('Y-m-d');
					$date = utils::datef ($r['dateAccounting'], '%n %d');
					break;
				case reportTakings::periodMonthly:
					$dateKey = $r['dateAccountingYear'].'-'.$r['dateAccountingMonth'];
					$date = $r['dateAccountingMonth'].'.'.$r['dateAccountingYear'];
					break;
			}

			if (!isset ($data[$dateKey]))
				$data [$dateKey] = array ('date' => $date, 'servicesBase' => 0.0, 'invBase' => 0.0, 'totalBase' => 0.0);

			if ($r['invDirection'] != 0)
			{
				$data [$dateKey]['invBase'] += $r['taxBase'];
				$data [$dateKey]['totalBase'] += round($r['taxBase'], 2);
				$total['invBase'] += $r['taxBase'];
				$total['totalBase'] += round ($r['taxBase'], 2);
			}
			else
			{
				$data [$dateKey]['servicesBase'] += $r['taxBase'];
				$data [$dateKey]['totalBase'] += round($r['taxBase'], 2);
				$total['servicesBase'] += $r['taxBase'];
				$total['totalBase'] += round ($r['taxBase'], 2);
			}
		}

		$h = array ('date' => ' '.$this->periodColumnName, 'totalBase' => '+CELKEM', 'invBase' => '+Zásoby', 'servicesBase' => '+Služby');
		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data));

		switch ($this->period)
		{
			case reportTakings::periodDaily:
				$this->setInfo('title', 'Denní přehled tržeb');
				break;
			case reportTakings::periodMonthly:
				$this->setInfo('title', 'Měsíční přehled tržeb');
				break;
		}

		$this->setInfo('note', '1', 'Všechny částky jsou bez DPH');
	} */

	function createContent_Summary ()
	{
		$engine = new Takings($this->app);
		$engine->setFiscalPeriod($this->reportParams ['fiscalPeriod']['value']);
		$engine->setReportPeriod($this->period);
		$engine->setCentre($this->centre);

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
			case Takings::periodDaily:
				$this->setInfo('title', 'Denní přehled výnosů');
				break;
			case Takings::periodMonthly:
				$this->setInfo('title', 'Měsíční přehled výnosů');
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

