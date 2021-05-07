<?php

namespace lib\cacheItems;

require_once __APP_DIR__ . '/e10-modules/e10doc/core/core.php';

use \e10\utils, \e10doc\core\e10utils;


/**
 * Class OverviewSales
 * @package lib\cacheItems
 */
class OverviewSales extends \Shipard\Base\CacheItem
{
	var $fiscalPeriods = [];
	var $dataSales = [];
	var $categories = [];
	var $docTypeNames = ['invno' => 'Faktury', 'cashreg' => 'Prodejky'];

	function loadSales ()
	{
		// load data
		$q[] = 'SELECT heads.docType, fm.calendarYear, fm.calendarMonth, SUM(heads.sumBaseHc) as baseHc';
		array_push ($q, ' FROM [e10doc_core_heads] as heads');
		array_push ($q, ' LEFT JOIN [e10doc_base_fiscalmonths] AS fm ON heads.fiscalMonth = fm.ndx');
		array_push ($q, ' WHERE [docType] IN %in', ['invno', 'cashreg']);
		array_push ($q, ' AND [docState] = %i', 4000);
		e10utils::fiscalPeriodQuery ($q, $this->fiscalPeriods['fiscalPeriod'], 'heads.');
		array_push ($q, ' GROUP BY 1, 2, 3');

		$rows = $this->app->db()->query ($q);

		// -- fill empty months
		$rows2 = array ();
		foreach ($rows as $r)
		{
			$rows2[] = $r;
		}

		$rows2 = array ();
		foreach ($rows as $r)
		{
			$rows2[$r['docType'].'.'.$r['calendarYear'].'.'.$r['calendarMonth']] = $r;
		}
		foreach ($this->fiscalPeriods['periods'] as $k => $p)
		{
			$calendarYear = intval (explode (".", $this->fiscalPeriods['periods'][$k]['title'])[0]);
			$calendarMonth = intval (explode (".", $this->fiscalPeriods['periods'][$k]['title'])[1]);
			if (!isset($rows2['invno.'.$calendarYear.'.'.$calendarMonth]))
				$rows2['invno.'.$calendarYear.'.'.$calendarMonth] = ['docType' => 'invno', 'calendarYear' => $calendarYear, 'calendarMonth' => $calendarMonth, 'baseHc' => 0];
			if (!isset($rows2['cashreg.'.$calendarYear.'.'.$calendarMonth]))
				$rows2['cashreg.'.$calendarYear.'.'.$calendarMonth] = ['docType' => 'cashreg', 'calendarYear' => $calendarYear, 'calendarMonth' => $calendarMonth, 'baseHc' => 0];
		}

		// prepare for graph
		foreach ($rows2 as $r)
		{
			$dateId = sprintf ('%04d-%02d', $r['calendarYear'], $r['calendarMonth']);
			$monthNdx = $r['calendarMonth'] - 1;
			$periodTitle = utils::$monthSc3[$monthNdx];
			$dt = $r['docType'];

			if (!isset($this->dataSales[$dateId]))
				$this->dataSales[$dateId] = ['period' => $periodTitle, 'id' => $dateId];
			$this->dataSales[$dateId][$dt] = round($r['baseHc']);

			if (!isset ($this->categories[$dt]))
				$this->categories[$dt] = $this->docTypeNames[$dt];
		}
	}

	function createData ()
	{
		e10utils::fiscalPeriods($this->app, 'C-12-M', $this->fiscalPeriods);
		$this->loadSales();

		$this->data['title'] = 'Obrat';
		$this->data['icon'] = 'icon-handshake-o';

		$this->data['sales'] = \E10\sortByOneKey($this->dataSales, 'id', TRUE, TRUE);

		$periodCounter = 0;
		$lastYear = 0;
		foreach ($this->data['sales'] as $periodId => $periodContent)
		{
			$year = intval (substr($periodId, 2, 2));
			if (!$periodCounter || $lastYear !== $year)
				$this->data['sales'][$periodId]['period'] .= ' \''.$year;

			$periodCounter++;
			$lastYear = $year;
		}

		$this->data['categories'] = $this->categories;
		$this->data['categories']['period'] = 'Období';

		parent::createData();
	}
}
