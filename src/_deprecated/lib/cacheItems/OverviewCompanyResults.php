<?php

namespace lib\cacheItems;


use \Shipard\Utils\Utils, \e10doc\core\libs\E10Utils;


/**
 * Class OverviewCompanyResults
 * @package lib\cacheItems
 */
class OverviewCompanyResults extends \Shipard\Base\CacheItem
{
	var $fiscalPeriods = [];
	var $dataResults = [];
	var $categories = [];
	var $docTypeNames = [2 => 'Náklady', 3 => 'Výnosy'];
	var $resultsMonth = [];
	var $monthRecapitulation;

	function loadSales ()
	{
		// load data
		$q[] = 'SELECT journal.fiscalMonth, accounts.accountKind as ak, fm.calendarYear, fm.calendarMonth,';
		array_push ($q, ' SUM(journal.moneyDr) as moneyDr, SUM(journal.moneyCr) as moneyCr');
		array_push ($q, ' FROM [e10doc_debs_journal] as journal');
		array_push ($q, ' LEFT JOIN [e10doc_debs_accounts] AS accounts ON journal.accountId = accounts.id');
		array_push ($q, ' LEFT JOIN [e10doc_base_fiscalmonths] AS fm ON journal.fiscalMonth = fm.ndx');
		array_push ($q, ' WHERE accounts.[accountKind] IN %in', [2, 3]);

		if (isset($this->fiscalPeriods['accMethods']['sebs']))
			array_push ($q, ' AND accounts.[excludeFromReports] = %i', 0);

		E10Utils::fiscalPeriodQuery ($q, $this->fiscalPeriods['fiscalPeriod'], 'journal.');
		array_push ($q, ' GROUP BY 1, 2');

		$rows = $this->app->db()->query ($q);

		// -- fill empty months
		$rows2 = array ();
		foreach ($rows as $r)
		{
			$rows2[$r['fiscalMonth'].'.'.$r['ak']] = $r;
		}
		foreach ($this->fiscalPeriods['periods'] as $k => $p)
		{
			$calendarYear = intval (explode (".", $this->fiscalPeriods['periods'][$k]['title'])[0]);
			$calendarMonth = intval (explode (".", $this->fiscalPeriods['periods'][$k]['title'])[1]);
			if (!isset($rows2[$k.'.'.'2']))
				$rows2[$k.'.'.'2'] = ['fiscalMonth' => $k, 'ak' => 2, 'calendarYear' => $calendarYear, 'calendarMonth' => $calendarMonth, 'moneyDr' => 0, 'moneyCr' => 0];
			if (!isset($rows2[$k.'.'.'3']))
				$rows2[$k.'.'.'3'] = ['fiscalMonth' => $k, 'ak' => 3, 'calendarYear' => $calendarYear, 'calendarMonth' => $calendarMonth, 'moneyDr' => 0, 'moneyCr' => 0];
		}

		// prepare for graph
		foreach ($rows2 as $r)
		{
			$dateId = sprintf ('%04d-%02d', $r['calendarYear'], $r['calendarMonth']);
			$monthNdx = $r['calendarMonth'] - 1;
			$periodTitle = Utils::$monthSc3[$monthNdx];
			$ak = $r['ak'];

			if ($ak === 2)
				$amount = $r['moneyDr'] - $r['moneyCr'];
			else
				$amount = $r['moneyCr'] - $r['moneyDr'];

			if (!isset($this->dataResults[$dateId]))
				$this->dataResults[$dateId] = ['period' => $periodTitle, 'id' => $dateId];
			$this->dataResults[$dateId][$ak] = round($amount);

			if (!isset ($this->categories[$ak]))
				$this->categories[$ak] = $this->docTypeNames[$ak];

			if (!isset($this->resultsMonth[$dateId]))
				$this->resultsMonth[$dateId] = ['id' => $dateId, 'title' => $periodTitle, 'a2' => 0.0, 'a3' => 0.0, 'b' => 0.0];
			$this->resultsMonth[$dateId]['a'.$ak] = $amount;
		}

		// -- calc months results
		foreach ($this->resultsMonth as $dateId => $res)
		{
			$this->resultsMonth[$dateId]['b'] = round($res['a3'] - $res['a2']);
		}

		// -- last 4 months
		$omr = \e10\sortByOneKey($this->resultsMonth, 'id', TRUE, TRUE);
		while (count ($omr) > 4)
			array_shift($omr);
		foreach ($omr as $dateId => $res)
		{
			$amount = '';
			if ($res['b'] > 0.0)
				$amount .= '+';
			$amount .= Utils::nf ($res['b'], 0);
			$this->monthRecapitulation[] = ['prefix' => $res['title'], 'text' => $amount, 'class' => 'padd5'];
		}
	}

	function createData ()
	{
		E10Utils::fiscalPeriods($this->app, 'C-12-M', $this->fiscalPeriods);
		if (isset($this->fiscalPeriods['accMethods']['debs']) && isset($this->fiscalPeriods['accMethods']['sebs']))
			$this->docTypeNames = [2 => 'Náklady/Příjmy', 3 => 'Výnosy/Výdaje'];
		elseif (isset($this->fiscalPeriods['accMethods']['sebs']))
			$this->docTypeNames = [2 => 'Výdaje', 3 => 'Příjmy'];

		$this->loadSales();

		$this->data['title'] = 'Výsledky';
		$this->data['icon'] = 'icon-thumbs-up';

		$this->data['monthRecapitulation'] = $this->monthRecapitulation;
		$this->data['results'] = \E10\sortByOneKey($this->dataResults, 'id', TRUE, TRUE);

		$periodCounter = 0;
		$lastYear = 0;
		foreach ($this->data['results'] as $periodId => $periodContent)
		{
			$year = intval (substr($periodId, 2, 2));
			if (!$periodCounter || $lastYear !== $year)
				$this->data['results'][$periodId]['period'] .= ' \''.$year;

			$periodCounter++;
			$lastYear = $year;
		}

		$this->data['categories'] = $this->categories;
		$this->data['categories']['period'] = 'Období';

		parent::createData();
	}
}
